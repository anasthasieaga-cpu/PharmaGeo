<?php
// ============================================================
// PharmaGeo — Administration : Réservations
// Gestion des réservations de médicaments : changement de
// statut, suppression, filtres, recherche et statistiques.
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

// ===== VÉRIFICATION ADMIN =====
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'administrateur') {
    header('Location: ' . BASE_URL . 'connexion.php');
    exit();
}

// ===== TRAITEMENT DES ACTIONS =====
$message = '';
$message_type = '';

// ===== CHANGEMENT DE STATUT D'UNE RÉSERVATION =====
if (isset($_GET['action']) && isset($_GET['id']) && isset($_GET['statut'])) {
    $id = (int)$_GET['id'];
    $statut = $_GET['statut'];
    
    $statuts_valides = ['confirmee', 'refusee', 'annulee', 'retiree'];
    
    if (!in_array($statut, $statuts_valides)) {
        $message = "Statut invalide.";
        $message_type = "danger";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE reservation SET Statut = :statut WHERE Id_reservation = :id");
            $stmt->execute([':statut' => $statut, ':id' => $id]);
            
            // Journalisation
            $log = $pdo->prepare("INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur) 
                                  VALUES ('Changement statut réservation ID: ' . :id . ' vers ' . :statut, :ip, :ua, 'succes', :user)");
            $log->execute([
                ':id' => $id,
                ':statut' => $statut,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':user' => $_SESSION['user_id']
            ]);
            
            $statuts_labels = [
                'confirmee' => 'Confirmée',
                'refusee' => 'Refusée',
                'annulee' => 'Annulée',
                'retiree' => 'Retirée'
            ];
            
            $message = "Réservation " . $statuts_labels[$statut] . " avec succès !";
            $message_type = "success";
            
        } catch(PDOException $e) {
            $message = "Erreur lors du changement de statut : " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// ===== SUPPRESSION D'UNE RÉSERVATION =====
if (isset($_GET['action']) && $_GET['action'] === 'supprimer' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM reservation WHERE Id_reservation = :id");
        $stmt->execute([':id' => $id]);
        
        $log = $pdo->prepare("INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur) 
                              VALUES ('Suppression réservation ID: ' . :id, :ip, :ua, 'succes', :user)");
        $log->execute([
            ':id' => $id,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ':user' => $_SESSION['user_id']
        ]);
        
        $message = "Réservation supprimée avec succès !";
        $message_type = "danger";
        
    } catch(PDOException $e) {
        $message = "Erreur lors de la suppression : " . $e->getMessage();
        $message_type = "danger";
    }
}

// ===== RÉCUPÉRATION DES DONNÉES =====
$filtre_statut = $_GET['statut'] ?? 'tous';
$filtre_date = $_GET['date'] ?? '';
$search = $_GET['search'] ?? '';

try {
    // Construire la requête
    // Chaîne de jointures : réservation → utilisateur (patient), stock →
    // médicament et pharmacie fournissent le produit et le lieu de retrait.
    $sql = "
        SELECT r.*, 
               u.Nom, u.Prenom, u.Email, u.Telephone,
               m.Nom_commercial, m.DCI,
               p.Nom as Pharmacie, p.Adresse, p.Quartier, p.Telephone as Pharmacie_Tel,
               s.Quantite as Stock_Quantite
        FROM reservation r
        JOIN utilisateur u ON r.Id_utilisateur = u.Id_utilisateur
        JOIN stock s ON r.Id_stock = s.Id_stock
        JOIN medicament m ON s.Id_medicament = m.Id_medicament
        JOIN pharmacie p ON s.Id_pharmacie = p.Id_pharmacie
        WHERE 1=1
    ";
    
    $params = [];
    
    if ($filtre_statut !== 'tous') {
        $sql .= " AND r.Statut = :statut";
        $params[':statut'] = $filtre_statut;
    }
    
    if (!empty($filtre_date)) {
        $sql .= " AND DATE(r.Date_reservation) = :date";
        $params[':date'] = $filtre_date;
    }
    
    if (!empty($search)) {
        $sql .= " AND (u.Nom LIKE :search OR u.Prenom LIKE :search OR m.Nom_commercial LIKE :search OR p.Nom LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    $sql .= " ORDER BY r.Date_reservation DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reservations = $stmt->fetchAll();
    
    // Statistiques
    $stats = [
        'total' => $pdo->query("SELECT COUNT(*) as total FROM reservation")->fetch()['total'] ?? 0,
        'en_attente' => $pdo->query("SELECT COUNT(*) as total FROM reservation WHERE Statut = 'en_attente'")->fetch()['total'] ?? 0,
        'confirmees' => $pdo->query("SELECT COUNT(*) as total FROM reservation WHERE Statut = 'confirmee'")->fetch()['total'] ?? 0,
        'refusees' => $pdo->query("SELECT COUNT(*) as total FROM reservation WHERE Statut = 'refusee'")->fetch()['total'] ?? 0,
        'retirees' => $pdo->query("SELECT COUNT(*) as total FROM reservation WHERE Statut = 'retiree'")->fetch()['total'] ?? 0,
        'annulees' => $pdo->query("SELECT COUNT(*) as total FROM reservation WHERE Statut = 'annulee'")->fetch()['total'] ?? 0,
        'expirees' => $pdo->query("SELECT COUNT(*) as total FROM reservation WHERE Statut = 'expiree'")->fetch()['total'] ?? 0
    ];
    
} catch(PDOException $e) {
    $reservations = [];
    $stats = [
        'total' => 0, 'en_attente' => 0, 'confirmees' => 0, 
        'refusees' => 0, 'retirees' => 0, 'annulees' => 0, 'expirees' => 0
    ];
    $message = "Erreur lors du chargement des données : " . $e->getMessage();
    $message_type = "danger";
}

// ===== STATUTS POUR AFFICHAGE =====
$statuts_labels = [
    'en_attente' => ['label' => 'En attente', 'class' => 'en_attente', 'icon' => 'fa-clock', 'color' => '#f39c12'],
    'confirmee' => ['label' => 'Confirmée', 'class' => 'confirmee', 'icon' => 'fa-check-circle', 'color' => '#2ecc71'],
    'refusee' => ['label' => 'Refusée', 'class' => 'refusee', 'icon' => 'fa-times-circle', 'color' => '#e74c3c'],
    'expiree' => ['label' => 'Expirée', 'class' => 'expiree', 'icon' => 'fa-hourglass-end', 'color' => '#9b59b6'],
    'retiree' => ['label' => 'Retirée', 'class' => 'retiree', 'icon' => 'fa-shopping-bag', 'color' => '#3498db'],
    'annulee' => ['label' => 'Annulée', 'class' => 'annulee', 'icon' => 'fa-ban', 'color' => '#7f8c8d']
];

$page_title = 'Gestion des réservations - Administration PharmaGeo';
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
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        /* ===== VARIABLES ===== */
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
            --shadow-xl: 0 30px 80px rgba(0,0,0,0.16);
            --shadow-glow-green: 0 8px 40px rgba(42, 195, 42, 0.20);
            --shadow-glow-orange: 0 8px 40px rgba(200, 120, 30, 0.20);
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

        /* ===== SIDEBAR ===== */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: var(--dark-text);
            color: white;
            padding: 20px 0;
            z-index: 1000;
            overflow-y: auto;
            transition: var(--transition);
            box-shadow: 4px 0 30px rgba(0,0,0,0.1);
        }

        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-thumb { background: var(--primary-green); border-radius: 4px; }

        .sidebar .brand {
            text-align: center;
            padding: 20px 15px 30px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        .sidebar .brand .brand-icon {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            padding: 12px 15px;
            border-radius: 14px;
            font-size: 2rem;
            display: inline-block;
            margin-bottom: 10px;
        }

        .sidebar .brand h4 {
            font-weight: 800;
            margin: 0;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .sidebar .brand small {
            color: rgba(255,255,255,0.4);
            font-size: 0.75rem;
            display: block;
        }

        .sidebar .nav-menu { padding: 20px 15px; }

        .sidebar .nav-menu .nav-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255,255,255,0.3);
            padding: 10px 15px 5px;
            font-weight: 600;
        }

        .sidebar .nav-menu .nav-item {
            display: flex;
            align-items: center;
            padding: 12px 18px;
            color: rgba(255,255,255,0.6);
            text-decoration: none;
            border-radius: 12px;
            transition: var(--transition);
            margin-bottom: 2px;
            cursor: pointer;
            border: none;
            background: none;
            width: 100%;
            font-size: 0.9rem;
            font-weight: 500;
            gap: 12px;
        }

        .sidebar .nav-menu .nav-item:hover {
            background: rgba(255,255,255,0.05);
            color: white;
        }

        .sidebar .nav-menu .nav-item.active {
            background: linear-gradient(135deg, rgba(42,195,42,0.2), rgba(200,120,30,0.2));
            color: var(--primary-green);
            border-left: 3px solid var(--primary-green);
        }

        .sidebar .nav-menu .nav-item i { width: 20px; text-align: center; font-size: 1rem; }

        .sidebar .user-profile {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 20px;
            border-top: 1px solid rgba(255,255,255,0.05);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar .user-profile .avatar {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            color: white;
        }

        .sidebar .user-profile .user-info { flex: 1; }
        .sidebar .user-profile .user-info .name { font-weight: 600; font-size: 0.9rem; color: white; }
        .sidebar .user-profile .user-info .role { font-size: 0.7rem; color: rgba(255,255,255,0.4); }

        /* ===== MAIN CONTENT ===== */
        .main-content {
            margin-left: 260px;
            padding: 20px 30px 30px;
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

        /* ===== TOGGLE SIDEBAR ===== */
        .toggle-sidebar {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--dark-text);
            padding: 5px 10px;
            border-radius: 10px;
            transition: var(--transition);
        }

        .toggle-sidebar:hover { background: rgba(0,0,0,0.05); }

        @media (max-width: 991px) {
            .toggle-sidebar { display: block; }
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 15px; }
            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 999;
            }
            .sidebar-overlay.active { display: block; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 10px; }
            .top-bar .page-title h1 { font-size: 1.3rem; }
            .top-bar { flex-direction: column; align-items: stretch; }
        }

        /* ===== STATS CARDS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 16px 18px;
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
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .stat-card .stat-number {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--dark-text);
            line-height: 1.2;
        }

        .stat-card .stat-label {
            color: var(--gray-text);
            font-size: 0.75rem;
            font-weight: 500;
        }

        .stat-card .stat-icon.green { background: rgba(42,195,42,0.1); color: var(--primary-green); }
        .stat-card .stat-icon.orange { background: rgba(200,120,30,0.1); color: var(--primary-orange); }
        .stat-card .stat-icon.blue { background: rgba(52,152,219,0.1); color: #3498db; }
        .stat-card .stat-icon.red { background: rgba(231,76,60,0.1); color: #e74c3c; }
        .stat-card .stat-icon.purple { background: rgba(155,89,182,0.1); color: #9b59b6; }
        .stat-card .stat-icon.gray { background: rgba(108,117,125,0.1); color: #6c757d; }

        /* ===== FILTERS ===== */
        .filters-bar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            align-items: center;
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 14px 18px;
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
            background: rgba(200,120,30,0.12);
            color: var(--primary-orange);
        }

        .badge-status.confirmee {
            background: rgba(42,195,42,0.12);
            color: var(--primary-green);
        }

        .badge-status.refusee {
            background: rgba(231,76,60,0.12);
            color: #e74c3c;
        }

        .badge-status.expiree {
            background: rgba(155,89,182,0.12);
            color: #9b59b6;
        }

        .badge-status.retiree {
            background: rgba(52,152,219,0.12);
            color: #3498db;
        }

        .badge-status.annulee {
            background: rgba(108,117,125,0.12);
            color: #6c757d;
        }

        /* ===== BTN ACTION ===== */
        .btn-action-sm {
            padding: 5px 10px;
            border-radius: 8px;
            border: none;
            font-size: 0.7rem;
            font-weight: 600;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-action-sm.btn-confirm {
            background: rgba(42,195,42,0.1);
            color: var(--primary-green);
        }

        .btn-action-sm.btn-confirm:hover {
            background: var(--primary-green);
            color: white;
        }

        .btn-action-sm.btn-refuse {
            background: rgba(231,76,60,0.1);
            color: #e74c3c;
        }

        .btn-action-sm.btn-refuse:hover {
            background: #e74c3c;
            color: white;
        }

        .btn-action-sm.btn-retire {
            background: rgba(52,152,219,0.1);
            color: #3498db;
        }

        .btn-action-sm.btn-retire:hover {
            background: #3498db;
            color: white;
        }

        .btn-action-sm.btn-delete {
            background: rgba(108,117,125,0.1);
            color: #6c757d;
        }

        .btn-action-sm.btn-delete:hover {
            background: #6c757d;
            color: white;
        }

        .btn-action-sm.btn-view {
            background: rgba(200,120,30,0.1);
            color: var(--primary-orange);
        }

        .btn-action-sm.btn-view:hover {
            background: var(--primary-orange);
            color: white;
        }

        /* ===== ALERT ===== */
        .alert-custom {
            border-radius: 14px;
            padding: 14px 18px;
            border: none;
            font-weight: 500;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 20px;
        }

        .alert-custom i { font-size: 1.2rem; margin-top: 2px; }

        .alert-custom-success {
            background: rgba(42,195,42,0.08);
            color: var(--primary-green);
            border-left: 4px solid var(--primary-green);
        }

        .alert-custom-danger {
            background: rgba(231,76,60,0.08);
            color: #e74c3c;
            border-left: 4px solid #e74c3c;
        }

        .alert-custom-warning {
            background: rgba(200,120,30,0.08);
            color: var(--primary-orange);
            border-left: 4px solid var(--primary-orange);
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
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
            .filters-bar { flex-direction: column; align-items: stretch; }
            .search-box { max-width: 100%; margin-left: 0; }
            .table-container { padding: 12px; overflow-x: auto; }
            .table-custom { font-size: 0.8rem; min-width: 800px; }
            .table-custom thead th,
            .table-custom tbody td { padding: 8px 6px; }
            .filters-bar .filter-btn { font-size: 0.7rem; padding: 4px 12px; }
        }

        @media (max-width: 576px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 8px; }
            .stat-card { padding: 12px; }
            .stat-card .stat-number { font-size: 1.2rem; }
            .stat-card .stat-label { font-size: 0.65rem; }
            .stat-card .stat-icon { width: 28px; height: 28px; font-size: 0.8rem; }
            .top-bar .page-title h1 { font-size: 1.2rem; }
            .navbar-brand-custom { font-size: 1.3rem; }
        }

        @media (max-width: 400px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 6px; }
            .stat-card { padding: 10px; }
            .stat-card .stat-number { font-size: 1rem; }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/responsive.css">
</head>
<body>

    <!-- ===== SIDEBAR OVERLAY ===== -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <div class="brand-icon"><i class="fas fa-hospital"></i></div>
            <h4>PharmaGeo</h4>
            <small>Administration</small>
        </div>

        <nav class="nav-menu">
            <div class="nav-label">Navigation</div>
            <a href="<?php echo BASE_URL; ?>admin/dashboard.php" class="nav-item">
                <i class="fas fa-tachometer-alt"></i>
                <span>Tableau de bord</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/pharmacies.php" class="nav-item">
                <i class="fas fa-store"></i>
                <span>Pharmacies</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/utilisateurs.php" class="nav-item">
                <i class="fas fa-users"></i>
                <span>Utilisateurs</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/medicaments.php" class="nav-item">
                <i class="fas fa-capsules"></i>
                <span>Médicaments</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/categories.php" class="nav-item">
                <i class="fas fa-tags"></i>
                <span>Catégories</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/reservations.php" class="nav-item active">
                <i class="fas fa-calendar-check"></i>
                <span>Réservations</span>
                <?php if ($stats['en_attente'] > 0): ?>
                    <span class="badge-notif"><?php echo $stats['en_attente']; ?></span>
                <?php endif; ?>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/statistiques.php" class="nav-item">
                <i class="fas fa-chart-bar"></i>
                <span>Statistiques</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/logs.php" class="nav-item">
                <i class="fas fa-history"></i>
                <span>Journaux d'activité</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/parametres.php" class="nav-item">
                <i class="fas fa-cog"></i>
                <span>Paramètres</span>
            </a>

            <div class="nav-label" style="margin-top: 20px;">Mon compte</div>
            <a href="<?php echo BASE_URL; ?>admin/profil.php" class="nav-item">
                <i class="fas fa-user-edit"></i>
                <span>Mon profil</span>
            </a>
            <a href="<?php echo BASE_URL; ?>deconnexion.php" class="nav-item" style="color: rgba(255,255,255,0.4);">
                <i class="fas fa-sign-out-alt"></i>
                <span>Déconnexion</span>
            </a>
        </nav>

        <div class="user-profile">
            <div class="avatar">
                <?php echo strtoupper(substr($_SESSION['prenom'] ?? 'A', 0, 1) . substr($_SESSION['nom'] ?? 'D', 0, 1)); ?>
            </div>
            <div class="user-info">
                <div class="name"><?php echo htmlspecialchars(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')); ?></div>
                <div class="role"><i class="fas fa-crown me-1" style="color: #f1c40f;"></i> Administrateur</div>
            </div>
        </div>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">

        <!-- ===== TOP BAR ===== -->
        <div class="top-bar animate-fade-up">
            <div>
                <div class="d-flex align-items-center gap-3">
                    <button class="toggle-sidebar" id="toggleSidebar">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="page-title">
                        <h1>Gestion des <span class="highlight">réservations</span></h1>
                        <p><i class="fas fa-calendar-check me-1" style="color: var(--primary-green);"></i> Gérez toutes les réservations de la plateforme</p>
                    </div>
                </div>
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
                        <div class="stat-number"><?php echo $stats['en_attente']; ?></div>
                        <div class="stat-label">En attente</div>
                    </div>
                    <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['confirmees']; ?></div>
                        <div class="stat-label">Confirmées</div>
                    </div>
                    <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['refusees']; ?></div>
                        <div class="stat-label">Refusées</div>
                    </div>
                    <div class="stat-icon red"><i class="fas fa-times-circle"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['retirees']; ?></div>
                        <div class="stat-label">Retirées</div>
                    </div>
                    <div class="stat-icon blue"><i class="fas fa-shopping-bag"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['annulees']; ?></div>
                        <div class="stat-label">Annulées</div>
                    </div>
                    <div class="stat-icon gray"><i class="fas fa-ban"></i></div>
                </div>
            </div>
        </div>

        <!-- ===== MESSAGES ===== -->
        <?php if (!empty($message)): ?>
            <div class="alert-custom alert-custom-<?php echo $message_type; ?> animate-fade-up">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'exclamation-triangle' : 'exclamation-circle'); ?>"></i>
                <div><?php echo $message; ?></div>
            </div>
        <?php endif; ?>

        <!-- ===== FILTRES ===== -->
        <div class="filters-bar animate-fade-up delay-2">
            <!-- Filtre par statut -->
            <a href="<?php echo BASE_URL; ?>admin/reservations.php?statut=tous&date=<?php echo $filtre_date; ?>&search=<?php echo urlencode($search); ?>" 
               class="filter-btn <?php echo $filtre_statut === 'tous' ? 'active' : ''; ?>">
                Tous <span class="count"><?php echo $stats['total']; ?></span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/reservations.php?statut=en_attente&date=<?php echo $filtre_date; ?>&search=<?php echo urlencode($search); ?>" 
               class="filter-btn <?php echo $filtre_statut === 'en_attente' ? 'active' : ''; ?>">
                <i class="fas fa-clock" style="color: var(--primary-orange);"></i> En attente
                <span class="count"><?php echo $stats['en_attente']; ?></span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/reservations.php?statut=confirmee&date=<?php echo $filtre_date; ?>&search=<?php echo urlencode($search); ?>" 
               class="filter-btn <?php echo $filtre_statut === 'confirmee' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle" style="color: var(--primary-green);"></i> Confirmées
                <span class="count"><?php echo $stats['confirmees']; ?></span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/reservations.php?statut=refusee&date=<?php echo $filtre_date; ?>&search=<?php echo urlencode($search); ?>" 
               class="filter-btn <?php echo $filtre_statut === 'refusee' ? 'active' : ''; ?>">
                <i class="fas fa-times-circle" style="color: #e74c3c;"></i> Refusées
                <span class="count"><?php echo $stats['refusees']; ?></span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/reservations.php?statut=retiree&date=<?php echo $filtre_date; ?>&search=<?php echo urlencode($search); ?>" 
               class="filter-btn <?php echo $filtre_statut === 'retiree' ? 'active' : ''; ?>">
                <i class="fas fa-shopping-bag" style: color: #3498db;></i> Retirées
                <span class="count"><?php echo $stats['retirees']; ?></span>
            </a>

            <!-- Recherche -->
            <form method="GET" class="search-box">
                <input type="hidden" name="statut" value="<?php echo $filtre_statut; ?>">
                <input type="hidden" name="date" value="<?php echo $filtre_date; ?>">
                <input type="text" name="search" class="form-control" placeholder="Rechercher..." 
                       value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i>
                </button>
                <?php if (!empty($search)): ?>
                    <a href="<?php echo BASE_URL; ?>admin/reservations.php?statut=<?php echo $filtre_statut; ?>&date=<?php echo $filtre_date; ?>" class="btn-search" style="background: #e74c3c; padding: 6px 12px;">
                        <i class="fas fa-times"></i>
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- ===== TABLEAU ===== -->
        <div class="table-container animate-fade-up delay-3">
            <?php if (!empty($reservations)): ?>
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Patient</th>
                                <th>Médicament</th>
                                <th>Pharmacie</th>
                                <th>Qté</th>
                                <th>Statut</th>
                                <th>Date</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reservations as $r): 
                                $statut = $statuts_labels[$r['Statut']] ?? ['label' => $r['Statut'], 'class' => '', 'icon' => 'fa-circle'];
                            ?>
                                <tr>
                                    <td><strong>#<?php echo $r['Id_reservation']; ?></strong></td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($r['Prenom'] . ' ' . $r['Nom']); ?></strong>
                                            <small class="text-muted d-block">
                                                <i class="fas fa-envelope me-1" style="font-size: 0.6rem;"></i>
                                                <?php echo htmlspecialchars($r['Email']); ?>
                                            </small>
                                            <?php if ($r['Telephone']): ?>
                                                <small class="text-muted">
                                                    <i class="fas fa-phone me-1" style="font-size: 0.6rem;"></i>
                                                    <?php echo htmlspecialchars($r['Telephone']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($r['Nom_commercial']); ?></strong>
                                            <?php if ($r['DCI']): ?>
                                                <small class="text-muted d-block"><?php echo htmlspecialchars($r['DCI']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($r['Pharmacie']); ?></strong>
                                            <small class="text-muted d-block">
                                                <i class="fas fa-map-marker-alt me-1" style="font-size: 0.6rem;"></i>
                                                <?php echo htmlspecialchars($r['Quartier'] ?? ''); ?>
                                            </small>
                                            <?php if ($r['Pharmacie_Tel']): ?>
                                                <small class="text-muted">
                                                    <i class="fas fa-phone me-1" style="font-size: 0.6rem;"></i>
                                                    <?php echo htmlspecialchars($r['Pharmacie_Tel']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo $r['Quantite']; ?></td>
                                    <td>
                                        <span class="badge-status <?php echo $statut['class']; ?>">
                                            <i class="fas <?php echo $statut['icon']; ?>"></i>
                                            <?php echo $statut['label']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div><?php echo date('d/m/Y', strtotime($r['Date_reservation'])); ?></div>
                                        <small class="text-muted"><?php echo date('H:i', strtotime($r['Date_reservation'])); ?></small>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex gap-1 justify-content-end flex-wrap">
                                            <?php if ($r['Statut'] === 'en_attente'): ?>
                                                <a href="<?php echo BASE_URL; ?>admin/reservations.php?action=changer&id=<?php echo $r['Id_reservation']; ?>&statut=confirmee&statut_filtre=<?php echo $filtre_statut; ?>&date=<?php echo $filtre_date; ?>&search=<?php echo urlencode($search); ?>" 
                                                   class="btn-action-sm btn-confirm"
                                                   onclick="return confirm('Confirmer cette réservation ?')">
                                                    <i class="fas fa-check"></i> Confirmer
                                                </a>
                                                <a href="<?php echo BASE_URL; ?>admin/reservations.php?action=changer&id=<?php echo $r['Id_reservation']; ?>&statut=refusee&statut_filtre=<?php echo $filtre_statut; ?>&date=<?php echo $filtre_date; ?>&search=<?php echo urlencode($search); ?>" 
                                                   class="btn-action-sm btn-refuse"
                                                   onclick="return confirm('Refuser cette réservation ?')">
                                                    <i class="fas fa-times"></i> Refuser
                                                </a>
                                            <?php elseif ($r['Statut'] === 'confirmee'): ?>
                                                <a href="<?php echo BASE_URL; ?>admin/reservations.php?action=changer&id=<?php echo $r['Id_reservation']; ?>&statut=retiree&statut_filtre=<?php echo $filtre_statut; ?>&date=<?php echo $filtre_date; ?>&search=<?php echo urlencode($search); ?>" 
                                                   class="btn-action-sm btn-retire"
                                                   onclick="return confirm('Marquer cette réservation comme retirée ?')">
                                                    <i class="fas fa-shopping-bag"></i> Retirée
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($r['Statut'] !== 'en_attente'): ?>
                                                <a href="<?php echo BASE_URL; ?>admin/reservations.php?action=supprimer&id=<?php echo $r['Id_reservation']; ?>&statut_filtre=<?php echo $filtre_statut; ?>&date=<?php echo $filtre_date; ?>&search=<?php echo urlencode($search); ?>" 
                                                   class="btn-action-sm btn-delete"
                                                   onclick="return confirm('Supprimer définitivement cette réservation ?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-check"></i>
                    <h5>Aucune réservation trouvée</h5>
                    <p>
                        <?php if (!empty($search)): ?>
                            Aucun résultat pour "<strong><?php echo htmlspecialchars($search); ?></strong>"
                        <?php elseif ($filtre_statut !== 'tous'): ?>
                            Aucune réservation avec le statut "<strong><?php echo $filtre_statut; ?></strong>"
                        <?php else: ?>
                            Aucune réservation n'a encore été effectuée sur la plateforme.
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- ===== SCRIPTS ===== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {

            // ===== SIDEBAR TOGGLE =====
            const toggleBtn = document.getElementById('toggleSidebar');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');

            function toggleSidebar() {
                sidebar.classList.toggle('open');
                overlay.classList.toggle('active');
                document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
            }

            if (toggleBtn) {
                toggleBtn.addEventListener('click', toggleSidebar);
            }

            if (overlay) {
                overlay.addEventListener('click', toggleSidebar);
            }

            // Fermer la sidebar sur les grands écrans
            const mediaQuery = window.matchMedia('(min-width: 992px)');
            mediaQuery.addEventListener('change', function(e) {
                if (e.matches) {
                    sidebar.classList.remove('open');
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });

            // ===== CONFIRMATION SUPPRESSION =====
            document.querySelectorAll('.btn-delete').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const href = this.getAttribute('href');
                    Swal.fire({
                        title: '⚠️ Supprimer la réservation',
                        text: 'Cette action est irréversible. Voulez-vous vraiment supprimer cette réservation ?',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#6c757d',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: 'Oui, supprimer',
                        cancelButtonText: 'Annuler'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.href = href;
                        }
                    });
                });
            });

            // ===== KEYBOARD SHORTCUTS =====
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && sidebar.classList.contains('open')) {
                    toggleSidebar();
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

            // ===== CONSOLE LOG =====
            console.log('%c PharmaGeo - Gestion des réservations ', 'background: linear-gradient(135deg, rgb(42, 195, 42), hsl(25, 75%, 47%)); color: white; font-size: 16px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');

        });
    </script>

</body>
</html>