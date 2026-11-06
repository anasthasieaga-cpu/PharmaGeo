<?php
// ============================================================
// PharmaGeo — Espace patient : Historique
// Affiche l'historique des recherches et réservations avec filtres et pagination.
// ============================================================

// ===== ACTIVATION DES ERREURS =====
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ===== DÉFINITION DES CONSTANTES =====
define('BASE_URL', 'http://localhost/pharmageo/');

// ===== PAGINATION (10 éléments par page) =====
require_once __DIR__ . '/../../includes/pagination.php';

// ===== CONNEXION À LA BASE DE DONNÉES =====
try {
    $pdo = new PDO("mysql:host=localhost;dbname=gestion_soutenance;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Erreur de connexion à la base de données : " . $e->getMessage());
}

// ===== GESTION DES SESSIONS =====
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== VÉRIFICATION CONNEXION =====
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'connexion.php');
    exit();
}

// ===== RÉCUPÉRATION DES INFORMATIONS UTILISATEUR =====
try {
    $stmt = $pdo->prepare("SELECT * FROM utilisateur WHERE Id_utilisateur = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        session_destroy();
        header('Location: ' . BASE_URL . 'connexion.php');
        exit();
    }
} catch(PDOException $e) {
    die("Erreur : " . $e->getMessage());
}

// ===== FILTRES =====
$filtre_type = isset($_GET['type']) ? $_GET['type'] : 'tous';
$filtre_statut = isset($_GET['statut']) ? $_GET['statut'] : 'tous';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$pagination = pagination_calculer(0);

// ===== RÉCUPÉRATION DE L'HISTORIQUE =====
$historique = [];
$total_historique = 0;

try {
    // Requête pour les réservations
    $sql_reservations = "
        SELECT 
            'reservation' as type,
            r.Id_reservation as id,
            r.Date_reservation as date,
            r.Statut as statut,
            r.Quantite,
            r.Montant,
            s.Prix as prix_unitaire,
            m.Nom_commercial as nom,
            m.DCI,
            p.Nom as pharmacie,
            p.Adresse as adresse,
            p.Quartier,
            p.Telephone,
            NULL as mot_recherche
        FROM reservation r
        JOIN stock s ON r.Id_stock = s.Id_stock
        JOIN medicament m ON s.Id_medicament = m.Id_medicament
        JOIN pharmacie p ON s.Id_pharmacie = p.Id_pharmacie
        WHERE r.Id_utilisateur = :user_id
    ";

    // Requête pour les recherches
    // (mêmes colonnes que $sql_reservations : l'UNION ALL exige
    //  exactement le même nombre de colonnes dans chaque branche)
    $sql_recherches = "
        SELECT 
            'recherche' as type,
            Id_recherche as id,
            Date_recherche as date,
            NULL as statut,
            NULL as Quantite,
            NULL as Montant,
            NULL as prix_unitaire,
            Mot_recherche as nom,
            NULL as DCI,
            NULL as pharmacie,
            NULL as adresse,
            NULL as Quartier,
            NULL as Telephone,
            Mot_recherche as mot_recherche
        FROM recherche
        WHERE Id_utilisateur = :user_id
    ";

    // Appliquer les filtres
    $where_reservations = "";
    $where_recherches = "";
    $params = [':user_id' => $_SESSION['user_id']];

    if ($filtre_type === 'reservations') {
        $where_recherches = " AND 1=0"; // Exclure les recherches
        if ($filtre_statut !== 'tous') {
            $where_reservations .= " AND r.Statut = :statut";
            $params[':statut'] = $filtre_statut;
        }
    } elseif ($filtre_type === 'recherches') {
        $where_reservations = " AND 1=0"; // Exclure les réservations
    } elseif ($filtre_type === 'tous' && $filtre_statut !== 'tous') {
        $where_reservations .= " AND r.Statut = :statut";
        $params[':statut'] = $filtre_statut;
    }

    // Recherche par mot-clé
    if (!empty($search)) {
        $search_param = '%' . $search . '%';
        $where_reservations .= " AND (m.Nom_commercial LIKE :search OR p.Nom LIKE :search)";
        $where_recherches .= " AND Mot_recherche LIKE :search";
        $params[':search'] = $search_param;
    }

    // Union des deux requêtes
    $sql_union = "
        SELECT * FROM (
            $sql_reservations $where_reservations
            UNION ALL
            $sql_recherches $where_recherches
        ) as historique
    ";

    // Compter le total correspondant aux filtres (pagination)
    $stmt_count = $pdo->prepare("SELECT COUNT(*) as total FROM ($sql_union) as historique_total");
    $stmt_count->bindValue(':user_id', $_SESSION['user_id']);
    if (!empty($search)) {
        $stmt_count->bindValue(':search', $search_param);
    }
    if (isset($params[':statut'])) {
        $stmt_count->bindValue(':statut', $params[':statut']);
    }
    $stmt_count->execute();
    $total_historique = (int) $stmt_count->fetchColumn();

    $pagination = pagination_calculer($total_historique);

    // Seule la page courante est chargée.
    // LIMIT/OFFSET injectés en dur : cette version de MariaDB refuse les
    // placeholders préparés dans LIMIT (cf. includes/pagination.php).
    $sql = "$sql_union ORDER BY date DESC" . pagination_sql_limite($pagination);

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_id', $_SESSION['user_id']);

    if (!empty($search)) {
        $stmt->bindValue(':search', $search_param);
    }
    if (isset($params[':statut'])) {
        $stmt->bindValue(':statut', $params[':statut']);
    }

    $stmt->execute();
    $historique = $stmt->fetchAll();

    // ===== RÉCUPÉRATION DES STATISTIQUES (CORRIGÉ) =====
    // Total des réservations
    $stmt_reservations = $pdo->prepare("SELECT COUNT(*) as total FROM reservation WHERE Id_utilisateur = :id");
    $stmt_reservations->execute([':id' => $_SESSION['user_id']]);
    $total_reservations = $stmt_reservations->fetch()['total'] ?? 0;

    // Total des recherches
    $stmt_recherches = $pdo->prepare("SELECT COUNT(*) as total FROM recherche WHERE Id_utilisateur = :id");
    $stmt_recherches->execute([':id' => $_SESSION['user_id']]);
    $total_recherches = $stmt_recherches->fetch()['total'] ?? 0;

    // Réservations en attente
    $stmt_attente = $pdo->prepare("SELECT COUNT(*) as total FROM reservation WHERE Id_utilisateur = :id AND Statut = 'en_attente'");
    $stmt_attente->execute([':id' => $_SESSION['user_id']]);
    $total_attente = $stmt_attente->fetch()['total'] ?? 0;

    // Réservations confirmées
    $stmt_confirmees = $pdo->prepare("SELECT COUNT(*) as total FROM reservation WHERE Id_utilisateur = :id AND Statut = 'confirmee'");
    $stmt_confirmees->execute([':id' => $_SESSION['user_id']]);
    $total_confirmees = $stmt_confirmees->fetch()['total'] ?? 0;

    // Regrouper les statistiques
    $stats = [
        'total' => $total_historique,
        'reservations' => $total_reservations,
        'recherches' => $total_recherches,
        'en_attente' => $total_attente,
        'confirmees' => $total_confirmees
    ];

} catch(PDOException $e) {
    $historique = [];
    $total_historique = 0;
    $pagination = pagination_calculer(0);
    $stats = ['total' => 0, 'reservations' => 0, 'recherches' => 0, 'en_attente' => 0, 'confirmees' => 0];
}

// ===== STATUTS POUR AFFICHAGE =====
$statuts_labels = [
    'en_attente' => ['label' => 'En attente', 'class' => 'en_attente', 'icon' => 'fa-clock'],
    'confirmee' => ['label' => 'Confirmée', 'class' => 'confirmee', 'icon' => 'fa-check-circle'],
    'refusee' => ['label' => 'Refusée', 'class' => 'refusee', 'icon' => 'fa-times-circle'],
    'expiree' => ['label' => 'Expirée', 'class' => 'expiree', 'icon' => 'fa-hourglass-end'],
    'retiree' => ['label' => 'Retirée', 'class' => 'retiree', 'icon' => 'fa-shopping-bag'],
    'annulee' => ['label' => 'Annulée', 'class' => 'annulee', 'icon' => 'fa-ban']
];

// ===== PAGINATION =====

$page_title = 'Historique - PharmaGeo';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        /* ===== VARIABLES ===== */
        :root {
            --primary-green: #16A34A;
            --primary-orange: #166534;
            --white: #ffffff;
            --light-bg: #F0FDF4;
            --dark-text: #1F2937;
            --gray-text: #64748B;
            --shadow-sm: 0 2px 20px rgba(0,0,0,0.04);
            --shadow-md: 0 8px 40px rgba(0,0,0,0.08);
            --shadow-lg: 0 20px 60px rgba(0,0,0,0.12);
            --shadow-glow-green: 0 8px 40px rgba(22,163,74, 0.20);
            --shadow-glow-orange: 0 8px 40px rgba(21,128,61, 0.20);
            --radius: 16px;
            --radius-lg: 24px;
            --transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        /* ===== BASE ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--light-bg);
            color: var(--dark-text);
            overflow-x: hidden;
        }

        /* ===== SCROLLBAR ===== */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: var(--light-bg); }
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            border-radius: 10px;
        }

        /* ===== ANIMATIONS ===== */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-30px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .animate-fade-up { animation: fadeInUp 0.6s ease-out forwards; }
        .animate-slide-left { animation: slideInLeft 0.5s ease-out forwards; }
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }

        /* ===== NAVBAR ===== */
        .navbar-custom {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 2px 30px rgba(0,0,0,0.06);
            padding: 12px 0;
            transition: var(--transition);
            border-bottom: 1px solid rgba(0,0,0,0.02);
        }

        .navbar-custom.scrolled {
            box-shadow: 0 4px 40px rgba(0,0,0,0.1);
            padding: 8px 0;
        }

        .navbar-brand-custom {
            font-weight: 800;
            font-size: 1.6rem;
            color: var(--dark-text);
            letter-spacing: -0.5px;
        }

        .navbar-brand-custom .brand-icon {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            padding: 8px 12px;
            border-radius: 12px;
            color: white;
            margin-right: 10px;
            box-shadow: var(--shadow-glow-green);
        }

        .navbar-brand-custom .brand-text {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .nav-link-custom {
            font-weight: 500;
            color: var(--dark-text);
            padding: 8px 18px !important;
            border-radius: 12px;
            transition: var(--transition);
            position: relative;
        }

        .nav-link-custom:hover,
        .nav-link-custom.active {
            color: var(--primary-green);
            background: rgba(22,163,74,0.08);
        }

        .nav-link-custom::after {
            content: '';
            position: absolute;
            bottom: 2px;
            left: 50%;
            width: 0;
            height: 3px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            border-radius: 10px;
            transition: var(--transition);
            transform: translateX(-50%);
        }

        .nav-link-custom:hover::after,
        .nav-link-custom.active::after {
            width: 60%;
        }

        .btn-nav-logout {
            background: linear-gradient(135deg, #DC2626, #B91C1C);
            color: white !important;
            padding: 8px 24px !important;
            border-radius: 50px !important;
            font-weight: 600;
            transition: var(--transition);
            border: none;
            box-shadow: 0 4px 15px rgba(220,38,38,0.2);
        }

        .btn-nav-logout:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(220,38,38,0.35);
            color: white !important;
        }

        /* ===== LAYOUT / SIDEBAR ===== */
        .layout { display: flex; min-height: 100vh; }
        .sidebar-admin {
            width: 250px; background: linear-gradient(180deg, #0F2E19, #14532D);
            color: #fff; flex-shrink: 0; display: flex; flex-direction: column;
            position: fixed; top: 0; bottom: 0; left: 0; overflow-y: auto; z-index: 100;
        }
        .brand-admin { padding: 22px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .brand-admin .brand-icon { width: 40px; height: 40px; border-radius: 12px; background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); display: flex; align-items: center; justify-content: center;  color: #fff;}
        .brand-admin h4 { margin: 0; font-weight: 800; font-size: 1.1rem; color: #fff; 
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            color: #fff;
        }
        
        .brand-admin small { color: rgba(255,255,255,0.6); display: block; }
        .nav-menu { padding: 16px 12px; flex: 1; }
        .nav-label { color: rgba(255,255,255,0.4); font-size: 0.72rem; text-transform: uppercase; letter-spacing: 1px; padding: 12px 12px 6px; }
        .nav-item { display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.75); text-decoration: none; padding: 11px 14px; border-radius: 10px; margin-bottom: 2px; font-size: 0.92rem; transition: all 0.25s ease; }
        .nav-item i { width: 20px; text-align: center; }
        .nav-item:hover { background: rgba(255,255,255,0.08); color: #fff; }
        .nav-item.active { background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); color: #fff; font-weight: 600; }
        .user-profile { padding: 16px; border-top: 1px solid rgba(255,255,255,0.08); display: flex; align-items: center; gap: 12px; }
        .user-profile .avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); display: flex; align-items: center; justify-content: center; font-weight: 800; }
        .user-profile .name { font-size: 0.9rem; font-weight: 600; }
        .user-profile .role { font-size: 0.75rem; color: rgba(255,255,255,0.6); }
        a.user-profile { text-decoration: none; color: inherit; transition: background-color 0.2s ease; }
        a.user-profile:hover { background-color: rgba(255,255,255,0.07); }
        a.user-profile .profile-chevron { margin-left: auto; font-size: 0.7rem; color: rgba(255,255,255,0.45); }
        a.user-profile:hover .profile-chevron { color: #fff; }
        @media (max-width: 991px) {
            .sidebar-admin { position: fixed; transform: translateX(-100%); transition: transform 0.3s ease; }
            .sidebar-admin.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
        }

        /* ===== MAIN CONTENT ===== */
        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 24px 30px 30px;
            min-height: 100vh;
        }

        /* ===== TOP BAR ===== */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .top-bar .page-title h1 {
            font-size: 1.6rem;
            font-weight: 800;
            margin: 0;
        }

        .top-bar .page-title h1 .highlight {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .top-bar .page-title p {
            color: var(--gray-text);
            margin: 0;
            font-size: 0.9rem;
        }

        /* ===== STATS CARDS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 18px 20px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            border: 1px solid rgba(0,0,0,0.02);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            opacity: 0;
            transition: var(--transition);
        }

        .stat-card:hover::before { opacity: 1; }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
        }

        .stat-card .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .stat-card .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .stat-card .stat-icon.green {
            background: rgba(22,163,74,0.1);
            color: var(--primary-green);
        }

        .stat-card .stat-icon.orange {
            background: rgba(21,128,61,0.1);
            color: var(--primary-orange);
        }

        .stat-card .stat-icon.blue {
            background: rgba(22,83,52,0.1);
            color: #166534;
        }

        .stat-card .stat-icon.purple {
            background: rgba(155,89,182,0.1);
            color: #1F2937;
        }

        .stat-card .stat-number {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--dark-text);
            line-height: 1.2;
        }

        .stat-card .stat-label {
            color: var(--gray-text);
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* ===== FILTERS ===== */
        .filters-bar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            align-items: center;
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 16px 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid rgba(0,0,0,0.02);
        }

        .filters-bar .filter-btn {
            padding: 6px 18px;
            border-radius: 50px;
            border: 2px solid #D9E9DF;
            background: transparent;
            font-weight: 500;
            font-size: 0.8rem;
            transition: var(--transition);
            color: var(--gray-text);
            text-decoration: none;
            cursor: pointer;
        }

        .filters-bar .filter-btn:hover {
            border-color: var(--primary-green);
            color: var(--primary-green);
        }

        .filters-bar .filter-btn.active {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white;
            border-color: transparent;
            box-shadow: var(--shadow-glow-green);
        }

        .filters-bar .filter-btn .count {
            background: rgba(255,255,255,0.2);
            padding: 0 8px;
            border-radius: 50px;
            font-size: 0.65rem;
            margin-left: 4px;
        }

        .filters-bar .filter-btn.active .count {
            background: rgba(255,255,255,0.3);
        }

        .search-box {
            display: flex;
            gap: 8px;
            align-items: center;
            background: var(--light-bg);
            border-radius: 50px;
            padding: 4px 4px 4px 16px;
            border: 2px solid transparent;
            transition: var(--transition);
            flex: 1;
            min-width: 180px;
            max-width: 350px;
            margin-left: auto;
        }

        .search-box:focus-within {
            border-color: var(--primary-green);
            background: var(--white);
            box-shadow: 0 0 0 4px rgba(22,163,74,0.1);
        }

        .search-box .form-control {
            border: none;
            padding: 6px 0;
            font-size: 0.85rem;
            background: transparent;
            flex: 1;
        }

        .search-box .form-control:focus {
            box-shadow: none;
        }

        .search-box .btn-search {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white;
            border: none;
            padding: 6px 16px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.8rem;
            transition: var(--transition);
        }

        .search-box .btn-search:hover {
            transform: scale(1.05);
        }

        /* ===== TABLE ===== */
        .table-container {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 20px;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            border: 1px solid rgba(0,0,0,0.02);
        }

        .table-custom {
            margin: 0;
        }

        .table-custom thead th {
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-text);
            border-bottom: 2px solid #F0FDF4;
            padding: 12px 10px;
        }

        .table-custom tbody td {
            padding: 14px 10px;
            vertical-align: middle;
            border-bottom: 1px solid #F0FDF4;
            font-size: 0.9rem;
        }

        .table-custom tbody tr {
            transition: var(--transition);
        }

        .table-custom tbody tr:hover {
            background: rgba(22,163,74,0.02);
        }

        .table-custom tbody tr:last-child td {
            border-bottom: none;
        }

        /* ===== BADGES ===== */
        .badge-status {
            padding: 4px 14px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .badge-status.en_attente {
            background: rgba(21,128,61,0.12);
            color: var(--primary-orange);
        }

        .badge-status.confirmee {
            background: rgba(22,163,74,0.12);
            color: var(--primary-green);
        }

        .badge-status.refusee {
            background: rgba(220,38,38,0.12);
            color: #DC2626;
        }

        .badge-status.expiree {
            background: rgba(155,89,182,0.12);
            color: #1F2937;
        }

        .badge-status.retiree {
            background: rgba(22,83,52,0.12);
            color: #166534;
        }

        .badge-status.annulee {
            background: rgba(108,117,125,0.12);
            color: #64748B;
        }

        .badge-type {
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-type.reservation {
            background: rgba(22,163,74,0.1);
            color: var(--primary-green);
        }

        .badge-type.recherche {
            background: rgba(22,83,52,0.1);
            color: #166534;
        }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 16px;
        }

        .empty-state h5 {
            font-weight: 700;
            color: var(--dark-text);
        }

        .empty-state p {
            color: var(--gray-text);
            max-width: 400px;
            margin: 0 auto;
        }

        /* ===== PAGINATION ===== */
        .pagination-custom {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            justify-content: center;
            margin-top: 20px;
        }

        .pagination-custom .page-link-custom {
            padding: 8px 16px;
            border-radius: 10px;
            border: 2px solid #D9E9DF;
            background: transparent;
            color: var(--gray-text);
            font-weight: 500;
            font-size: 0.85rem;
            transition: var(--transition);
            text-decoration: none;
        }

        .pagination-custom .page-link-custom:hover {
            border-color: var(--primary-green);
            color: var(--primary-green);
        }

        .pagination-custom .page-link-custom.active {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white;
            border-color: transparent;
            box-shadow: var(--shadow-glow-green);
        }

        .pagination-custom .page-link-custom.disabled {
            opacity: 0.4;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 991px) {
            .main-content { padding: 15px; }
            .top-bar .page-title h1 { font-size: 1.4rem; }
            .filters-bar { flex-direction: column; align-items: stretch; }
            .search-box { max-width: 100%; margin-left: 0; }
        }

        @media (max-width: 768px) {
            .top-bar { flex-direction: column; align-items: stretch; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .table-container { padding: 12px; overflow-x: auto; }
            .table-custom { font-size: 0.8rem; min-width: 600px; }
            .table-custom thead th,
            .table-custom tbody td { padding: 8px 6px; }
            .filters-bar .filter-btn { font-size: 0.7rem; padding: 4px 12px; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 10px; }
            .top-bar .page-title h1 { font-size: 1.2rem; }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card { padding: 12px; }
            .stat-card .stat-number { font-size: 1.3rem; }
            .stat-card .stat-label { font-size: 0.7rem; }
            .stat-card .stat-icon { width: 32px; height: 32px; font-size: 0.9rem; }
            .navbar-brand-custom { font-size: 1.3rem; }
            .pagination-custom .page-link-custom { padding: 6px 12px; font-size: 0.75rem; }
        }

        /* ===== TYPE ICON ===== */
        .type-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
        }

        .type-icon.reservation {
            background: rgba(22,163,74,0.1);
            color: var(--primary-green);
        }

        .type-icon.recherche {
            background: rgba(22,83,52,0.1);
            color: #166534;
        }
    </style>
    <link rel="stylesheet" href="../../assets/css/responsive.css">    <!-- Palette verte PharmaGeo -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/palette.css">
    <!-- Favicon & identité visuelle PharmaGéo -->
    <link rel="icon" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon.ico" sizes="any">
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo BASE_URL; ?>assets/identite/favicons/apple-touch-icon.png">
    <link rel="manifest" href="<?php echo BASE_URL; ?>assets/identite/favicons/site.webmanifest">
    <meta property="og:image" content="<?php echo BASE_URL; ?>assets/identite/reseaux-sociaux/og-image-1200x630.png">
</head>
<body>

    <div class="layout">

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar-admin" id="sidebar">
        <a href="<?php echo BASE_URL; ?>index.php" class="brand-admin" style="text-decoration: none;">
            <div class="brand-icon"><img class="pg-brand-logo" src="<?php echo BASE_URL; ?>assets/identite/icones/pharmageo-icone-blanc.svg" alt=""></div>
            <div><h4>PharmaGeo</h4><small>Patient</small></div>
        </a>
        <nav class="nav-menu">
            <div class="nav-label">Navigation</div>
            <a href="<?php echo BASE_URL; ?>pages/patient/dashboard.php" class="nav-item"><i class="fas fa-tachometer-alt"></i><span>Tableau de bord</span></a>
            <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="nav-item"><i class="fas fa-search"></i><span>Rechercher</span></a>
            <a href="<?php echo BASE_URL; ?>pages/patient/carte.php" class="nav-item"><i class="fas fa-map-marker-alt"></i><span>Carte</span></a>
            <a href="<?php echo BASE_URL; ?>pages/patient/historique.php" class="nav-item active"><i class="fas fa-history"></i><span>Historique</span></a>
            <a href="<?php echo BASE_URL; ?>pages/patient/profil.php" class="nav-item"><i class="fas fa-user"></i><span>Profil</span></a>
            <div class="nav-label" style="margin-top: 20px;">Système</div>
            <a href="<?php echo BASE_URL; ?>deconnexion.php" class="nav-item" style="color: #FEF2F2; margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.15); margin-bottom: 12px;"><i class="fas fa-sign-out-alt"></i><span>Déconnexion</span></a>
        </nav>
<a class="user-profile" href="<?php echo BASE_URL; ?>pages/patient/profil.php" title="Voir mon profil">
                <div class="avatar"><?php echo strtoupper(substr(($_SESSION['prenom'] ?? 'A'), 0, 1) . substr(($_SESSION['nom'] ?? 'D'), 0, 1)); ?></div>
                <div>
                    <div class="name"><?php echo htmlspecialchars(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')); ?></div>
                    <div class="role"><i class="fas fa-user me-1" style="color:#DC2626;"></i> Patient</div>
                </div>
    <i class="fas fa-chevron-right profile-chevron"></i>
</a>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">

        <!-- ===== TOP BAR ===== -->
        <div class="top-bar animate-fade-up">
            <div class="page-title">
                <h1>Mon <span class="highlight">historique</span></h1>
                <p>
                    <i class="fas fa-history me-1" style="color: var(--primary-green);"></i>
                    Consultez toutes vos recherches et réservations
                </p>
            </div>
        </div>

        <!-- ===== STATS ===== -->
        <div class="stats-grid animate-fade-up delay-1">
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">Total</div>
                    </div>
                    <div class="stat-icon blue"><i class="fas fa-list"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['reservations']; ?></div>
                        <div class="stat-label">Réservations</div>
                    </div>
                    <div class="stat-icon green"><i class="fas fa-calendar-check"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['recherches']; ?></div>
                        <div class="stat-label">Recherches</div>
                    </div>
                    <div class="stat-icon blue"><i class="fas fa-search"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['en_attente']; ?></div>
                        <div class="stat-label">En attente</div>
                    </div>
                    <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
                </div>
            </div>
        </div>

        <!-- ===== FILTRES ===== -->
        <div class="filters-bar animate-fade-up delay-2">
            <!-- Filtre par type -->
            <a href="<?php echo BASE_URL; ?>pages/patient/historique.php?type=tous&statut=<?php echo $filtre_statut; ?>&search=<?php echo urlencode($search); ?>" 
               class="filter-btn <?php echo $filtre_type === 'tous' ? 'active' : ''; ?>">
                Tous <span class="count"><?php echo $stats['total']; ?></span>
            </a>
            <a href="<?php echo BASE_URL; ?>pages/patient/historique.php?type=reservations&statut=<?php echo $filtre_statut; ?>&search=<?php echo urlencode($search); ?>" 
               class="filter-btn <?php echo $filtre_type === 'reservations' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-check"></i> Réservations
                <span class="count"><?php echo $stats['reservations']; ?></span>
            </a>
            <a href="<?php echo BASE_URL; ?>pages/patient/historique.php?type=recherches&statut=<?php echo $filtre_statut; ?>&search=<?php echo urlencode($search); ?>" 
               class="filter-btn <?php echo $filtre_type === 'recherches' ? 'active' : ''; ?>">
                <i class="fas fa-search"></i> Recherches
                <span class="count"><?php echo $stats['recherches']; ?></span>
            </a>

            <!-- Filtre par statut (pour les réservations) -->
            <a href="<?php echo BASE_URL; ?>pages/patient/historique.php?type=<?php echo $filtre_type; ?>&statut=tous&search=<?php echo urlencode($search); ?>" 
               class="filter-btn <?php echo $filtre_statut === 'tous' ? 'active' : ''; ?>">
                Tous statuts
            </a>
            <a href="<?php echo BASE_URL; ?>pages/patient/historique.php?type=<?php echo $filtre_type; ?>&statut=en_attente&search=<?php echo urlencode($search); ?>" 
               class="filter-btn <?php echo $filtre_statut === 'en_attente' ? 'active' : ''; ?>">
                <i class="fas fa-clock" style="color: var(--primary-orange);"></i> En attente
            </a>
            <a href="<?php echo BASE_URL; ?>pages/patient/historique.php?type=<?php echo $filtre_type; ?>&statut=confirmee&search=<?php echo urlencode($search); ?>" 
               class="filter-btn <?php echo $filtre_statut === 'confirmee' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle" style="color: var(--primary-green);"></i> Confirmées
            </a>

            <!-- Recherche -->
            <form method="GET" class="search-box">
                <input type="hidden" name="type" value="<?php echo $filtre_type; ?>">
                <input type="hidden" name="statut" value="<?php echo $filtre_statut; ?>">
                <input type="text" name="search" class="form-control" placeholder="Rechercher..." 
                       value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i>
                </button>
                <?php if (!empty($search)): ?>
                    <a href="<?php echo BASE_URL; ?>pages/patient/historique.php?type=<?php echo $filtre_type; ?>&statut=<?php echo $filtre_statut; ?>" class="btn-search" style="background: #DC2626; padding: 6px 12px;">
                        <i class="fas fa-times"></i>
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- ===== TABLEAU ===== -->
        <div class="table-container animate-fade-up delay-3">
            <?php if (!empty($historique)): ?>
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Détail</th>
                                <th>Pharmacie</th>
                                <th>Statut</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historique as $item): ?>
                                <tr>
                                    <td>
                                        <span class="type-icon <?php echo $item['type']; ?>">
                                            <i class="fas fa-<?php echo $item['type'] === 'reservation' ? 'calendar-check' : 'search'; ?>"></i>
                                        </span>
                                        <span class="badge-type <?php echo $item['type']; ?>">
                                            <?php echo ucfirst($item['type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($item['type'] === 'reservation'): ?>
                                            <div>
                                                <strong><?php echo htmlspecialchars($item['nom']); ?></strong>
                                                <?php if ($item['DCI']): ?>
                                                    <small class="text-muted d-block"><?php echo htmlspecialchars($item['DCI']); ?></small>
                                                <?php endif; ?>
                                                <small class="text-muted">
                                                    <i class="fas fa-box me-1"></i> Quantité: <?php echo $item['Quantite']; ?>
                                                </small>
                                                <?php $montantDisplay = $item['Montant'] !== null && $item['Montant'] !== '' ? (float)$item['Montant'] : ((!empty($item['prix_unitaire'])) ? (float)$item['prix_unitaire'] * (int)$item['Quantite'] : null); ?>
                                                <?php if ($montantDisplay !== null): ?>
                                                    <small class="d-block fw-semibold" style="color: #16A34A;">
                                                        <i class="fas fa-money-bill-wave me-1"></i> Montant: <?php echo number_format($montantDisplay, 0, ',', ' ') . ' FCFA'; ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <div>
                                                <strong><?php echo htmlspecialchars($item['mot_recherche']); ?></strong>
                                                <small class="text-muted d-block">
                                                    <i class="fas fa-search me-1"></i> Recherche
                                                </small>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($item['type'] === 'reservation'): ?>
                                            <div>
                                                <strong><?php echo htmlspecialchars($item['pharmacie']); ?></strong>
                                                <small class="text-muted d-block">
                                                    <i class="fas fa-map-marker-alt me-1" style="font-size: 0.6rem;"></i>
                                                    <?php echo htmlspecialchars($item['Quartier'] ?? ''); ?>
                                                </small>
                                                <?php if ($item['Telephone']): ?>
                                                    <small class="text-muted">
                                                        <i class="fas fa-phone me-1"></i> <?php echo htmlspecialchars($item['Telephone']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($item['type'] === 'reservation'): ?>
                                            <?php $statut = $statuts_labels[$item['statut']] ?? ['label' => $item['statut'], 'class' => '', 'icon' => 'fa-circle']; ?>
                                            <span class="badge-status <?php echo $statut['class']; ?>">
                                                <i class="fas <?php echo $statut['icon']; ?>"></i>
                                                <?php echo $statut['label']; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo date('d/m/Y', strtotime($item['date'])); ?></div>
                                        <small class="text-muted"><?php echo date('H:i', strtotime($item['date'])); ?></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ===== PAGINATION ===== -->
                <?php pagination_afficher($pagination, [
                    'type'   => $filtre_type,
                    'statut' => $filtre_statut,
                    'search' => $search
                ], 'éléments d\'historique'); ?>

            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <h5>Aucun historique trouvé</h5>
                    <p>
                        <?php if (!empty($search)): ?>
                            Aucun résultat pour "<strong><?php echo htmlspecialchars($search); ?></strong>"
                        <?php elseif ($filtre_type === 'reservations'): ?>
                            Vous n'avez pas encore effectué de réservation.
                        <?php elseif ($filtre_type === 'recherches'): ?>
                            Vous n'avez pas encore effectué de recherche.
                        <?php else: ?>
                            Vous n'avez pas encore d'activité sur la plateforme.
                        <?php endif; ?>
                    </p>
                    <?php if ($filtre_type !== 'recherches'): ?>
                        <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="btn btn-primary-custom mt-3" style="display: inline-block; padding: 10px 28px; border-radius: 50px; background: linear-gradient(135deg, #16A34A, #166534); color: white; text-decoration: none; font-weight: 600; transition: var(--transition);">
                            <i class="fas fa-search me-1"></i> Commencer une recherche
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

    </main>

    </div>

    <!-- ===== SCRIPTS ===== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {

            // ===== SIDEBAR MOBILE TOGGLE (Ctrl + B) =====
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.key.toLowerCase() === 'b') {
                    document.getElementById('sidebar').classList.toggle('open');
                }
            });

            // ===== KEYBOARD SHORTCUTS =====
            document.addEventListener('keydown', function(e) {
                // Ctrl + R pour rediriger vers la recherche
                if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                    e.preventDefault();
                    window.location.href = '<?php echo BASE_URL; ?>pages/patient/recherche.php';
                }
                // Ctrl + F pour focus recherche
                if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                    e.preventDefault();
                    const searchInput = document.querySelector('.search-box .form-control');
                    if (searchInput) {
                        searchInput.focus();
                        searchInput.select();
                    }
                }
            });

            // ===== ANIMATION DES STATS =====
            function animateStats() {
                const statNumbers = document.querySelectorAll('.stat-number');
                statNumbers.forEach(stat => {
                    const target = parseInt(stat.textContent);
                    if (target > 0) {
                        const duration = 1000;
                        const start = 0;
                        const startTime = performance.now();
                        
                        function updateCounter(currentTime) {
                            const elapsed = currentTime - startTime;
                            const progress = Math.min(elapsed / duration, 1);
                            const eased = 1 - Math.pow(1 - progress, 3);
                            const current = Math.round(eased * target);
                            
                            stat.textContent = current.toLocaleString();
                            
                            if (progress < 1) {
                                requestAnimationFrame(updateCounter);
                            } else {
                                stat.textContent = target.toLocaleString();
                            }
                        }
                        
                        const observer = new IntersectionObserver((entries) => {
                            entries.forEach(entry => {
                                if (entry.isIntersecting) {
                                    requestAnimationFrame(updateCounter);
                                    observer.unobserve(entry.target);
                                }
                            });
                        }, { threshold: 0.3 });
                        
                        observer.observe(stat);
                    }
                });
            }

            animateStats();

            // ===== CONSOLE LOG =====
            console.log('%c PharmaGeo - Historique ', 'background: linear-gradient(135deg, #16A34A, #166534); color: white; font-size: 16px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');
            console.log('%c Consultez toutes vos recherches et réservations.', 'color: #666; font-size: 14px;');

        });
    </script>

</body>
    <script src="../../assets/js/responsive.js"></script>
</html>