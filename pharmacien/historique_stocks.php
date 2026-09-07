<?php
// ============================================================
// PharmaGeo — Espace pharmacien : Historique des stocks
// Affiche les mouvements de stock de la pharmacie avec filtres
// (type d'opération, médicament, période) et pagination.
// ============================================================

// ===== ACTIVATION DES ERREURS =====
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ===== DÉFINITION DES CONSTANTES =====
define('BASE_URL', 'http://localhost/pharmageo/');

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

// ===== VÉRIFICATION PHARMACIEN =====
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'pharmacien') {
    header('Location: ' . BASE_URL . 'connexion.php');
    exit();
}

// ===== RÉCUPÉRATION DES INFORMATIONS =====
try {
    // Récupérer les informations du pharmacien et de sa pharmacie
    $stmt = $pdo->prepare("
        SELECT u.*, ph.Id_pharmacien, ph.Numero_ordre, ph.Statut_validation,
               p.Id_pharmacie, p.Nom as Pharmacie_Nom, p.Statut as Pharmacie_Statut
        FROM utilisateur u
        JOIN pharmacien ph ON u.Id_utilisateur = ph.Id_utilisateur
        LEFT JOIN pharmacie p ON ph.Id_pharmacien = p.Id_pharmacien
        WHERE u.Id_utilisateur = :id
    ");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        header('Location: ' . BASE_URL . 'connexion.php');
        exit();
    }

    // Vérifier si le pharmacien a une pharmacie
    $has_pharmacy = !empty($user['Id_pharmacie']);
    $pharmacie_id = $user['Id_pharmacie'] ?? null;

    if (!$has_pharmacy) {
        header('Location: ' . BASE_URL . 'pharmacien/dashboard.php?error=no_pharmacy');
        exit();
    }

} catch(PDOException $e) {
    die("Erreur : " . $e->getMessage());
}

// ===== PARAMÈTRES DE FILTRAGE ET PAGINATION =====
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$per_page = 20;
$offset = ($page - 1) * $per_page;

$filtre_type = $_GET['type'] ?? 'tous';
$filtre_medicament = $_GET['medicament'] ?? '';
$filtre_date_debut = $_GET['date_debut'] ?? '';
$filtre_date_fin = $_GET['date_fin'] ?? '';
$search = $_GET['search'] ?? '';

// ===== RÉCUPÉRATION DE L'HISTORIQUE =====
try {
    // Construction de la requête
    $sql = "
        SELECT hs.*, m.Nom_commercial, m.DCI, m.Dosage,
               u.Nom, u.Prenom, u.Email
        FROM historique_stock hs
        JOIN stock s ON hs.Id_stock = s.Id_stock
        JOIN medicament m ON s.Id_medicament = m.Id_medicament
        JOIN utilisateur u ON hs.Id_utilisateur = u.Id_utilisateur
        WHERE s.Id_pharmacie = :pharmacie
    ";
    
    $params = [':pharmacie' => $pharmacie_id];
    
    // Filtre par type d'opération
    if ($filtre_type !== 'tous') {
        $sql .= " AND hs.Type_operation = :type";
        $params[':type'] = $filtre_type;
    }
    
    // Filtre par médicament
    if (!empty($filtre_medicament)) {
        $sql .= " AND m.Nom_commercial LIKE :medicament";
        $params[':medicament'] = '%' . $filtre_medicament . '%';
    }
    
    // Filtre par date
    if (!empty($filtre_date_debut)) {
        $sql .= " AND DATE(hs.Date_operation) >= :date_debut";
        $params[':date_debut'] = $filtre_date_debut;
    }
    if (!empty($filtre_date_fin)) {
        $sql .= " AND DATE(hs.Date_operation) <= :date_fin";
        $params[':date_fin'] = $filtre_date_fin;
    }
    
    // Recherche
    if (!empty($search)) {
        $sql .= " AND (m.Nom_commercial LIKE :search OR u.Nom LIKE :search OR u.Prenom LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    // Compter le total
    $sql_count = str_replace(
        "SELECT hs.*, m.Nom_commercial, m.DCI, m.Dosage, u.Nom, u.Prenom, u.Email",
        "SELECT COUNT(*) as total",
        $sql
    );
    
    $stmt_count = $pdo->prepare($sql_count);
    $stmt_count->execute($params);
    $total_historique = $stmt_count->fetch()['total'] ?? 0;
    
    // Récupérer les données avec pagination
    $sql .= " ORDER BY hs.Date_operation DESC LIMIT :offset, :per_page";
    
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        if ($key !== ':offset' && $key !== ':per_page') {
            $stmt->bindValue($key, $value);
        }
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
    $stmt->execute();
    $historique = $stmt->fetchAll();

    // Récupérer la liste des médicaments pour le filtre
    $medicaments_liste = $pdo->prepare("
        SELECT DISTINCT m.Nom_commercial, m.Id_medicament
        FROM stock s
        JOIN medicament m ON s.Id_medicament = m.Id_medicament
        WHERE s.Id_pharmacie = :pharmacie
        ORDER BY m.Nom_commercial ASC
    ");
    $medicaments_liste->execute([':pharmacie' => $pharmacie_id]);
    $medicaments_liste = $medicaments_liste->fetchAll();

    // ===== STATISTIQUES - CORRECTION =====
    // Total des ajouts
    $stmt_ajouts = $pdo->prepare("
        SELECT COUNT(*) as total FROM historique_stock hs 
        JOIN stock s ON hs.Id_stock = s.Id_stock 
        WHERE s.Id_pharmacie = :pharmacie AND hs.Type_operation = 'ajout'
    ");
    $stmt_ajouts->execute([':pharmacie' => $pharmacie_id]);
    $total_ajouts = $stmt_ajouts->fetch()['total'] ?? 0;

    // Total des mises à jour
    $stmt_mises_a_jour = $pdo->prepare("
        SELECT COUNT(*) as total FROM historique_stock hs 
        JOIN stock s ON hs.Id_stock = s.Id_stock 
        WHERE s.Id_pharmacie = :pharmacie AND hs.Type_operation = 'mise_a_jour'
    ");
    $stmt_mises_a_jour->execute([':pharmacie' => $pharmacie_id]);
    $total_mises_a_jour = $stmt_mises_a_jour->fetch()['total'] ?? 0;

    // Total des approvisionnements
    $stmt_approvisionnements = $pdo->prepare("
        SELECT COUNT(*) as total FROM historique_stock hs 
        JOIN stock s ON hs.Id_stock = s.Id_stock 
        WHERE s.Id_pharmacie = :pharmacie AND hs.Type_operation = 'approvisionnement'
    ");
    $stmt_approvisionnements->execute([':pharmacie' => $pharmacie_id]);
    $total_approvisionnements = $stmt_approvisionnements->fetch()['total'] ?? 0;

    // Regrouper les statistiques
    $stats = [
        'total' => $total_historique,
        'ajouts' => $total_ajouts,
        'mises_a_jour' => $total_mises_a_jour,
        'approvisionnements' => $total_approvisionnements
    ];

} catch(PDOException $e) {
    $historique = [];
    $total_historique = 0;
    $medicaments_liste = [];
    $stats = ['total' => 0, 'ajouts' => 0, 'mises_a_jour' => 0, 'approvisionnements' => 0];
    $message = "Erreur lors du chargement des données : " . $e->getMessage();
    $message_type = "danger";
}

// ===== PAGINATION =====
$total_pages = ceil($total_historique / $per_page);

// ===== TYPES D'OPÉRATIONS =====
$types_operation = [
    'ajout' => ['label' => 'Ajout', 'class' => 'ajout', 'icon' => 'fa-plus-circle'],
    'mise_a_jour' => ['label' => 'Mise à jour', 'class' => 'mise_a_jour', 'icon' => 'fa-edit'],
    'approvisionnement' => ['label' => 'Approvisionnement', 'class' => 'approvisionnement', 'icon' => 'fa-truck']
];

$page_title = 'Historique des stocks - PharmaGeo';
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
        :root {
            --primary-green: rgb(42, 195, 42);
            --primary-orange: hsl(25, 75%, 47%);
            --white: #ffffff;
            --light-bg: #f0f2f5;
            --dark-text: #1a1a2e;
            --gray-text: #6c757d;
            --shadow-sm: 0 2px 20px rgba(0,0,0,0.04);
            --shadow-md: 0 8px 40px rgba(0,0,0,0.08);
            --shadow-lg: 0 20px 60px rgba(0,0,0,0.12);
            --shadow-glow-green: 0 8px 40px rgba(42, 195, 42, 0.20);
            --shadow-glow-orange: 0 8px 40px rgba(200, 120, 30, 0.20);
            --radius: 16px;
            --radius-lg: 24px;
            --transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--light-bg);
            color: var(--dark-text);
            overflow-x: hidden;
        }

        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: var(--light-bg); }
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            border-radius: 10px;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .animate-fade-up { animation: fadeInUp 0.6s ease-out forwards; }
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
            background: rgba(42,195,42,0.08);
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
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white !important;
            padding: 8px 24px !important;
            border-radius: 50px !important;
            font-weight: 600;
            transition: var(--transition);
            border: none;
            box-shadow: 0 4px 15px rgba(231,76,60,0.2);
        }

        .btn-nav-logout:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(231,76,60,0.35);
            color: white !important;
        }

        /* ===== LAYOUT / SIDEBAR ===== */
        .layout { display: flex; min-height: 100vh; }
        .sidebar-admin {
            width: 250px; background: linear-gradient(180deg, #0c1f14, #143a1c);
            color: #fff; flex-shrink: 0; display: flex; flex-direction: column;
            position: fixed; top: 0; bottom: 0; left: 0; overflow-y: auto; z-index: 100;
        }
        .brand-admin { padding: 22px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .brand-admin .brand-icon { width: 40px; height: 40px; border-radius: 12px; background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); display: flex; align-items: center; justify-content: center; }
        .brand-admin h4 { margin: 0; font-weight: 800; font-size: 1.1rem; color: #fff; }
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

        .top-bar .page-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .top-bar .page-actions .btn-action {
            padding: 10px 20px;
            border-radius: 12px;
            border: none;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            text-decoration: none;
        }

        .top-bar .page-actions .btn-action.btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white;
            box-shadow: var(--shadow-glow-green);
        }

        .top-bar .page-actions .btn-action.btn-primary-custom:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 40px rgba(42,195,42,0.35);
        }

        .top-bar .page-actions .btn-action.btn-outline-custom {
            border: 2px solid var(--primary-green);
            color: var(--primary-green);
            background: transparent;
        }

        .top-bar .page-actions .btn-action.btn-outline-custom:hover {
            background: var(--primary-green);
            color: white;
            transform: translateY(-3px);
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
            background: rgba(42,195,42,0.1);
            color: var(--primary-green);
        }

        .stat-card .stat-icon.orange {
            background: rgba(200,120,30,0.1);
            color: var(--primary-orange);
        }

        .stat-card .stat-icon.blue {
            background: rgba(52,152,219,0.1);
            color: #3498db;
        }

        .stat-card .stat-icon.purple {
            background: rgba(155,89,182,0.1);
            color: #9b59b6;
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
            gap: 10px;
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
            padding: 6px 16px;
            border-radius: 50px;
            border: 2px solid #e0e0e0;
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
            box-shadow: 0 0 0 4px rgba(42,195,42,0.1);
        }

        .search-box .form-control {
            border: none;
            padding: 6px 0;
            font-size: 0.85rem;
            background: transparent;
            flex: 1;
        }

        .search-box .form-control:focus { box-shadow: none; }

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

        .search-box .btn-search:hover { transform: scale(1.05); }

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
            border-bottom: 2px solid #f0f0f0;
            padding: 12px 10px;
        }

        .table-custom tbody td {
            padding: 14px 10px;
            vertical-align: middle;
            border-bottom: 1px solid #f5f5f5;
            font-size: 0.9rem;
        }

        .table-custom tbody tr {
            transition: var(--transition);
        }

        .table-custom tbody tr:hover {
            background: rgba(42,195,42,0.02);
        }

        .table-custom tbody tr:last-child td {
            border-bottom: none;
        }

        /* ===== BADGES ===== */
        .badge-type {
            padding: 4px 14px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .badge-type.ajout {
            background: rgba(42,195,42,0.12);
            color: var(--primary-green);
        }

        .badge-type.mise_a_jour {
            background: rgba(200,120,30,0.12);
            color: var(--primary-orange);
        }

        .badge-type.approvisionnement {
            background: rgba(52,152,219,0.12);
            color: #3498db;
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
            border: 2px solid #e0e0e0;
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

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .main-content { padding: 15px; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .filters-bar { flex-direction: column; align-items: stretch; }
            .search-box { max-width: 100%; margin-left: 0; }
            .top-bar { flex-direction: column; align-items: stretch; }
            .top-bar .page-actions { flex-wrap: wrap; }
            .top-bar .page-actions .btn-action { font-size: 0.8rem; padding: 8px 16px; }
            .table-container { padding: 12px; overflow-x: auto; }
            .table-custom { font-size: 0.8rem; min-width: 700px; }
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

        /* ===== DATE FILTERS ===== */
        .date-filters {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .date-filters .form-control {
            padding: 6px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 0.85rem;
            transition: var(--transition);
            background: var(--light-bg);
        }

        .date-filters .form-control:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 4px rgba(42,195,42,0.1);
            background: var(--white);
        }

        .date-filters .form-label {
            font-weight: 600;
            font-size: 0.8rem;
            color: var(--gray-text);
            margin: 0;
        }
    </style>
    <link rel="stylesheet" href="../assets/css/responsive.css">
</head>
<body>

    <div class="layout">

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar-admin" id="sidebar">
        <div class="brand-admin">
            <div class="brand-icon"><i class="fas fa-hospital"></i></div>
            <div><h4>PharmaGeo</h4><small>Pharmacien</small></div>
        </div>
        <nav class="nav-menu">
            <div class="nav-label">Navigation</div>
            <a href="<?php echo BASE_URL; ?>pharmacien/dashboard.php" class="nav-item"><i class="fas fa-tachometer-alt"></i><span>Tableau de bord</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/pharmacie.php" class="nav-item"><i class="fas fa-store"></i><span>Ma pharmacie</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/medicaments.php" class="nav-item"><i class="fas fa-capsules"></i><span>Médicaments</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/stocks.php" class="nav-item"><i class="fas fa-boxes"></i><span>Stocks</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/historique_stocks.php" class="nav-item active"><i class="fas fa-history"></i><span>Historique</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/reservations.php" class="nav-item"><i class="fas fa-calendar-check"></i><span>Réservations</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/statistiques.php" class="nav-item"><i class="fas fa-chart-bar"></i><span>Statistiques</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/profil.php" class="nav-item"><i class="fas fa-user"></i><span>Profil</span></a>
            <div class="nav-label" style="margin-top: 20px;">Système</div>
            <a href="<?php echo BASE_URL; ?>deconnexion.php" class="nav-item" style="color: rgba(255,255,255,0.4);"><i class="fas fa-sign-out-alt"></i><span>Déconnexion</span></a>
        </nav>
        <div class="user-profile">
            <div class="avatar"><?php echo strtoupper(substr($_SESSION['prenom'] ?? 'A', 0, 1) . substr($_SESSION['nom'] ?? 'D', 0, 1)); ?></div>
            <div>
                <div class="name"><?php echo htmlspecialchars(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')); ?></div>
                <div class="role"><i class="fas fa-user-md me-1" style="color:#f1c40f;"></i> Pharmacien</div>
            </div>
        </div>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">

        <!-- ===== TOP BAR ===== -->
        <div class="top-bar animate-fade-up">
            <div class="page-title">
                <h1>Historique des <span class="highlight">stocks</span></h1>
                <p>
                    <i class="fas fa-history me-1" style="color: var(--primary-green);"></i>
                    Consultez tous les mouvements de stock de votre pharmacie
                </p>
            </div>
            <div class="page-actions">
                <a href="<?php echo BASE_URL; ?>pharmacien/stocks.php" class="btn-action btn-outline-custom">
                    <i class="fas fa-arrow-left"></i> Retour aux stocks
                </a>
            </div>
        </div>

        <!-- ===== STATS ===== -->
        <div class="stats-grid animate-fade-up delay-1">
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">Total mouvements</div>
                    </div>
                    <div class="stat-icon blue"><i class="fas fa-list"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['ajouts']; ?></div>
                        <div class="stat-label">Ajouts</div>
                    </div>
                    <div class="stat-icon green"><i class="fas fa-plus-circle"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['mises_a_jour']; ?></div>
                        <div class="stat-label">Mises à jour</div>
                    </div>
                    <div class="stat-icon orange"><i class="fas fa-edit"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['approvisionnements']; ?></div>
                        <div class="stat-label">Approvisionnements</div>
                    </div>
                    <div class="stat-icon purple"><i class="fas fa-truck"></i></div>
                </div>
            </div>
        </div>

        <!-- ===== FILTRES ===== -->
        <div class="filters-bar animate-fade-up delay-2">
            <!-- Filtre par type -->
            <a href="<?php echo BASE_URL; ?>pharmacien/historique_stocks.php?type=tous&medicament=<?php echo urlencode($filtre_medicament); ?>&date_debut=<?php echo $filtre_date_debut; ?>&date_fin=<?php echo $filtre_date_fin; ?>&search=<?php echo urlencode($search); ?>" 
               class="filter-btn <?php echo $filtre_type === 'tous' ? 'active' : ''; ?>">
                Tous <span class="count"><?php echo $stats['total']; ?></span>
            </a>
            <a href="<?php echo BASE_URL; ?>pharmacien/historique_stocks.php?type=ajout&medicament=<?php echo urlencode($filtre_medicament); ?>&date_debut=<?php echo $filtre_date_debut; ?>&date_fin=<?php echo $filtre_date_fin; ?>&search=<?php echo urlencode($search); ?>" 
               class="filter-btn <?php echo $filtre_type === 'ajout' ? 'active' : ''; ?>">
                <i class="fas fa-plus-circle" style="color: var(--primary-green);"></i> Ajouts
                <span class="count"><?php echo $stats['ajouts']; ?></span>
            </a>
            <a href="<?php echo BASE_URL; ?>pharmacien/historique_stocks.php?type=mise_a_jour&medicament=<?php echo urlencode($filtre_medicament); ?>&date_debut=<?php echo $filtre_date_debut; ?>&date_fin=<?php echo $filtre_date_fin; ?>&search=<?php echo urlencode($search); ?>" 
               class="filter-btn <?php echo $filtre_type === 'mise_a_jour' ? 'active' : ''; ?>">
                <i class="fas fa-edit" style="color: var(--primary-orange);"></i> Mises à jour
                <span class="count"><?php echo $stats['mises_a_jour']; ?></span>
            </a>
            <a href="<?php echo BASE_URL; ?>pharmacien/historique_stocks.php?type=approvisionnement&medicament=<?php echo urlencode($filtre_medicament); ?>&date_debut=<?php echo $filtre_date_debut; ?>&date_fin=<?php echo $filtre_date_fin; ?>&search=<?php echo urlencode($search); ?>" 
               class="filter-btn <?php echo $filtre_type === 'approvisionnement' ? 'active' : ''; ?>">
                <i class="fas fa-truck" style="color: #3498db;"></i> Approvisionnements
                <span class="count"><?php echo $stats['approvisionnements']; ?></span>
            </a>

            <!-- Filtre par médicament -->
            <select class="form-control" style="border-radius: 50px; padding: 6px 16px; border: 2px solid #e0e0e0; font-size: 0.85rem; background: var(--light-bg);" 
                    onchange="window.location.href='<?php echo BASE_URL; ?>pharmacien/historique_stocks.php?type=<?php echo $filtre_type; ?>&date_debut=<?php echo $filtre_date_debut; ?>&date_fin=<?php echo $filtre_date_fin; ?>&search=<?php echo urlencode($search); ?>&medicament='+this.value">
                <option value="">Tous les médicaments</option>
                <?php foreach ($medicaments_liste as $med): ?>
                    <option value="<?php echo htmlspecialchars($med['Nom_commercial']); ?>" <?php echo $filtre_medicament == $med['Nom_commercial'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($med['Nom_commercial']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- Filtre par date -->
            <div class="date-filters">
                <span class="form-label">Du</span>
                <input type="date" class="form-control" value="<?php echo $filtre_date_debut; ?>" 
                       onchange="window.location.href='<?php echo BASE_URL; ?>pharmacien/historique_stocks.php?type=<?php echo $filtre_type; ?>&medicament=<?php echo urlencode($filtre_medicament); ?>&date_fin=<?php echo $filtre_date_fin; ?>&search=<?php echo urlencode($search); ?>&date_debut='+this.value">
                <span class="form-label">Au</span>
                <input type="date" class="form-control" value="<?php echo $filtre_date_fin; ?>" 
                       onchange="window.location.href='<?php echo BASE_URL; ?>pharmacien/historique_stocks.php?type=<?php echo $filtre_type; ?>&medicament=<?php echo urlencode($filtre_medicament); ?>&date_debut=<?php echo $filtre_date_debut; ?>&search=<?php echo urlencode($search); ?>&date_fin='+this.value">
            </div>

            <!-- Recherche -->
            <form method="GET" class="search-box">
                <input type="hidden" name="type" value="<?php echo $filtre_type; ?>">
                <input type="hidden" name="medicament" value="<?php echo $filtre_medicament; ?>">
                <input type="hidden" name="date_debut" value="<?php echo $filtre_date_debut; ?>">
                <input type="hidden" name="date_fin" value="<?php echo $filtre_date_fin; ?>">
                <input type="text" name="search" class="form-control" placeholder="Rechercher..." 
                       value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i>
                </button>
                <?php if (!empty($search)): ?>
                    <a href="<?php echo BASE_URL; ?>pharmacien/historique_stocks.php?type=<?php echo $filtre_type; ?>&medicament=<?php echo urlencode($filtre_medicament); ?>&date_debut=<?php echo $filtre_date_debut; ?>&date_fin=<?php echo $filtre_date_fin; ?>" class="btn-search" style="background: #e74c3c; padding: 6px 12px;">
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
                                <th>Médicament</th>
                                <th>Type</th>
                                <th>Quantité</th>
                                <th>Utilisateur</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historique as $h): 
                                $type = $types_operation[$h['Type_operation']] ?? ['label' => $h['Type_operation'], 'class' => '', 'icon' => 'fa-circle'];
                                $diff = $h['Nouvelle_quantite'] - $h['Ancienne_quantite'];
                            ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($h['Nom_commercial']); ?></strong>
                                            <?php if ($h['Dosage']): ?>
                                                <small class="text-muted d-block"><?php echo htmlspecialchars($h['Dosage']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge-type <?php echo $type['class']; ?>">
                                            <i class="fas <?php echo $type['icon']; ?>"></i>
                                            <?php echo $type['label']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div>
                                            <span class="fw-bold <?php echo $diff > 0 ? 'text-success' : ($diff < 0 ? 'text-danger' : ''); ?>">
                                                <?php echo $h['Ancienne_quantite']; ?> → <?php echo $h['Nouvelle_quantite']; ?>
                                            </span>
                                            <br>
                                            <small class="text-muted">
                                                <?php if ($diff != 0): ?>
                                                    (<?php echo $diff > 0 ? '+' : ''; ?><?php echo $diff; ?>)
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </td>
                                    <td>
                                        <div>
                                            <?php echo htmlspecialchars($h['Prenom'] . ' ' . $h['Nom']); ?>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($h['Email']); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <div><?php echo date('d/m/Y', strtotime($h['Date_operation'])); ?></div>
                                        <small class="text-muted"><?php echo date('H:i', strtotime($h['Date_operation'])); ?></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ===== PAGINATION ===== -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination-custom">
                        <?php if ($page > 1): ?>
                            <a href="<?php echo BASE_URL; ?>pharmacien/historique_stocks.php?page=<?php echo $page - 1; ?>&type=<?php echo $filtre_type; ?>&medicament=<?php echo urlencode($filtre_medicament); ?>&date_debut=<?php echo $filtre_date_debut; ?>&date_fin=<?php echo $filtre_date_fin; ?>&search=<?php echo urlencode($search); ?>" 
                               class="page-link-custom">
                                <i class="fas fa-chevron-left"></i> Précédent
                            </a>
                        <?php else: ?>
                            <span class="page-link-custom disabled">
                                <i class="fas fa-chevron-left"></i> Précédent
                            </span>
                        <?php endif; ?>

                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="<?php echo BASE_URL; ?>pharmacien/historique_stocks.php?page=<?php echo $i; ?>&type=<?php echo $filtre_type; ?>&medicament=<?php echo urlencode($filtre_medicament); ?>&date_debut=<?php echo $filtre_date_debut; ?>&date_fin=<?php echo $filtre_date_fin; ?>&search=<?php echo urlencode($search); ?>" 
                               class="page-link-custom <?php echo $i === $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="<?php echo BASE_URL; ?>pharmacien/historique_stocks.php?page=<?php echo $page + 1; ?>&type=<?php echo $filtre_type; ?>&medicament=<?php echo urlencode($filtre_medicament); ?>&date_debut=<?php echo $filtre_date_debut; ?>&date_fin=<?php echo $filtre_date_fin; ?>&search=<?php echo urlencode($search); ?>" 
                               class="page-link-custom">
                                Suivant <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="page-link-custom disabled">
                                Suivant <i class="fas fa-chevron-right"></i>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <h5>Aucun historique trouvé</h5>
                    <p>
                        <?php if (!empty($search) || $filtre_type !== 'tous' || !empty($filtre_medicament) || !empty($filtre_date_debut) || !empty($filtre_date_fin)): ?>
                            Aucun résultat avec les filtres appliqués.
                        <?php else: ?>
                            Aucun mouvement de stock n'a encore été enregistré.
                        <?php endif; ?>
                    </p>
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

            // ===== KEYBOARD SHORTCUTS =====
            document.addEventListener('keydown', function(e) {
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

            // ===== CONSOLE LOG =====
            console.log('%c PharmaGeo - Historique des stocks ', 'background: linear-gradient(135deg, rgb(42, 195, 42), hsl(25, 75%, 47%)); color: white; font-size: 16px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');

        });
    </script>

</body>
    <script src="../assets/js/responsive.js"></script>
</html>