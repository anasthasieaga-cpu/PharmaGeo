<?php
// ============================================================
// PharmaGeo — Administration : Statistiques
// Tableau de bord analytique : indicateurs, graphiques et classements
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

// ===== PÉRIODE DE FILTRAGE =====
$periode = $_GET['periode'] ?? 'mois';
$date_debut = $_GET['date_debut'] ?? date('Y-m-01');
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');

// ===== RÉCUPÉRATION DES STATISTIQUES =====
try {
    // ===== STATISTIQUES GÉNÉRALES =====
    $stats_generales = [
        'total_utilisateurs' => $pdo->query("SELECT COUNT(*) as total FROM utilisateur")->fetch()['total'] ?? 0,
        'total_patients' => $pdo->query("SELECT COUNT(*) as total FROM utilisateur WHERE Role = 'patient'")->fetch()['total'] ?? 0,
        'total_pharmaciens' => $pdo->query("SELECT COUNT(*) as total FROM utilisateur WHERE Role = 'pharmacien'")->fetch()['total'] ?? 0,
        'total_administrateurs' => $pdo->query("SELECT COUNT(*) as total FROM utilisateur WHERE Role = 'administrateur'")->fetch()['total'] ?? 0,
        'total_pharmacies' => $pdo->query("SELECT COUNT(*) as total FROM pharmacie")->fetch()['total'] ?? 0,
        'pharmacies_actives' => $pdo->query("SELECT COUNT(*) as total FROM pharmacie WHERE Statut = 'active'")->fetch()['total'] ?? 0,
        'pharmacies_attente' => $pdo->query("SELECT COUNT(*) as total FROM pharmacie WHERE Statut = 'en_attente'")->fetch()['total'] ?? 0,
        'pharmacies_suspendues' => $pdo->query("SELECT COUNT(*) as total FROM pharmacie WHERE Statut = 'suspendue'")->fetch()['total'] ?? 0,
        'total_medicaments' => $pdo->query("SELECT COUNT(*) as total FROM medicament")->fetch()['total'] ?? 0,
        'total_categories' => $pdo->query("SELECT COUNT(*) as total FROM categorie")->fetch()['total'] ?? 0,
        'total_reservations' => $pdo->query("SELECT COUNT(*) as total FROM reservation")->fetch()['total'] ?? 0,
        'total_recherches' => $pdo->query("SELECT COUNT(*) as total FROM recherche")->fetch()['total'] ?? 0
    ];

    // ===== STATUTS DES RÉSERVATIONS =====
    $stats_reservations = [
        'en_attente' => $pdo->query("SELECT COUNT(*) as total FROM reservation WHERE Statut = 'en_attente'")->fetch()['total'] ?? 0,
        'confirmees' => $pdo->query("SELECT COUNT(*) as total FROM reservation WHERE Statut = 'confirmee'")->fetch()['total'] ?? 0,
        'refusees' => $pdo->query("SELECT COUNT(*) as total FROM reservation WHERE Statut = 'refusee'")->fetch()['total'] ?? 0,
        'retirees' => $pdo->query("SELECT COUNT(*) as total FROM reservation WHERE Statut = 'retiree'")->fetch()['total'] ?? 0,
        'annulees' => $pdo->query("SELECT COUNT(*) as total FROM reservation WHERE Statut = 'annulee'")->fetch()['total'] ?? 0,
        'expirees' => $pdo->query("SELECT COUNT(*) as total FROM reservation WHERE Statut = 'expiree'")->fetch()['total'] ?? 0
    ];

    // ===== STATUTS DES STOCKS =====
    $stats_stocks = [
        'disponible' => $pdo->query("SELECT COUNT(*) as total FROM stock WHERE Statut = 'disponible' AND Quantite > 0")->fetch()['total'] ?? 0,
        'stock_faible' => $pdo->query("SELECT COUNT(*) as total FROM stock WHERE Statut = 'stock_faible' AND Quantite > 0")->fetch()['total'] ?? 0,
        'rupture' => $pdo->query("SELECT COUNT(*) as total FROM stock WHERE Statut = 'rupture' OR Quantite = 0")->fetch()['total'] ?? 0,
        'total' => $pdo->query("SELECT COUNT(*) as total FROM stock")->fetch()['total'] ?? 0
    ];

    // ===== ÉVOLUTION MENSUELLE =====
    $evolution_mensuelle = $pdo->query("
        SELECT 
            DATE_FORMAT(Date_creation, '%Y-%m') as mois,
            COUNT(*) as total_inscriptions,
            SUM(CASE WHEN Role = 'patient' THEN 1 ELSE 0 END) as patients,
            SUM(CASE WHEN Role = 'pharmacien' THEN 1 ELSE 0 END) as pharmaciens,
            SUM(CASE WHEN Role = 'administrateur' THEN 1 ELSE 0 END) as admins
        FROM utilisateur 
        WHERE Date_creation >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(Date_creation, '%Y-%m')
        ORDER BY mois ASC
    ")->fetchAll();

    // ===== RÉSERVATIONS PAR MOIS =====
    $reservations_par_mois = $pdo->query("
        SELECT 
            DATE_FORMAT(Date_reservation, '%Y-%m') as mois,
            COUNT(*) as total,
            SUM(CASE WHEN Statut = 'confirmee' THEN 1 ELSE 0 END) as confirmees,
            SUM(CASE WHEN Statut = 'refusee' THEN 1 ELSE 0 END) as refusees
        FROM reservation 
        WHERE Date_reservation >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(Date_reservation, '%Y-%m')
        ORDER BY mois ASC
    ")->fetchAll();

    // ===== MÉDICAMENTS LES PLUS RECHERCHÉS =====
    $medicaments_populaires = $pdo->query("
        SELECT m.Nom_commercial, m.DCI, COUNT(r.Id_recherche) as total_recherches
        FROM medicament m
        LEFT JOIN recherche r ON r.Mot_recherche LIKE CONCAT('%', m.Nom_commercial, '%')
        GROUP BY m.Id_medicament
        ORDER BY total_recherches DESC
        LIMIT 10
    ")->fetchAll();

    // ===== MÉDICAMENTS LES PLUS RÉSERVÉS =====
    $medicaments_reserves = $pdo->query("
        SELECT m.Nom_commercial, m.DCI, COUNT(r.Id_reservation) as total_reservations
        FROM medicament m
        JOIN stock s ON m.Id_medicament = s.Id_medicament
        JOIN reservation r ON s.Id_stock = r.Id_stock
        WHERE r.Statut = 'confirmee'
        GROUP BY m.Id_medicament
        ORDER BY total_reservations DESC
        LIMIT 10
    ")->fetchAll();

    // ===== PHARMACIES LES PLUS ACTIVES =====
    $pharmacies_actives = $pdo->query("
        SELECT p.Nom, p.Quartier, p.Commune,
               COUNT(r.Id_reservation) as total_reservations,
               COUNT(DISTINCT r.Id_utilisateur) as total_patients
        FROM pharmacie p
        JOIN stock s ON p.Id_pharmacie = s.Id_pharmacie
        JOIN reservation r ON s.Id_stock = r.Id_stock
        WHERE r.Statut = 'confirmee'
        GROUP BY p.Id_pharmacie
        ORDER BY total_reservations DESC
        LIMIT 10
    ")->fetchAll();

    // ===== DERNIÈRES ACTIVITÉS =====
    $dernieres_activites = $pdo->query("
        SELECT l.*, u.Nom, u.Prenom, u.Email
        FROM log l
        JOIN utilisateur u ON l.Id_utilisateur = u.Id_utilisateur
        ORDER BY l.Date_action DESC
        LIMIT 10
    ")->fetchAll();

    // ===== TAUX DE CONVERSION =====
    $taux_conversion = [
        'recherches_vers_reservations' => 0,
        'reservations_vers_confirmations' => 0
    ];
    
    if ($stats_generales['total_recherches'] > 0) {
        $taux_conversion['recherches_vers_reservations'] = round(($stats_generales['total_reservations'] / $stats_generales['total_recherches']) * 100, 2);
    }
    if ($stats_generales['total_reservations'] > 0) {
        $taux_conversion['reservations_vers_confirmations'] = round(($stats_reservations['confirmees'] / $stats_generales['total_reservations']) * 100, 2);
    }

    // ===== RÉPARTITION PAR COMMUNE =====
    $repartition_communes = $pdo->query("
        SELECT Commune, COUNT(*) as total
        FROM pharmacie
        WHERE Statut = 'active'
        GROUP BY Commune
        ORDER BY total DESC
    ")->fetchAll();

    // ===== ACTIVITÉ PAR JOUR DE SEMAINE =====
    $activite_semaine = $pdo->query("
        SELECT 
            DAYNAME(Date_reservation) as jour,
            COUNT(*) as total
        FROM reservation
        GROUP BY DAYNAME(Date_reservation)
        ORDER BY FIELD(DAYNAME(Date_reservation), 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')
    ")->fetchAll();

    // ===== SANTÉ DE LA PLATEFORME =====
    $sante_plateforme = [
        'taux_disponibilite' => 0,
        'taux_validation_pharmaciens' => 0,
        'taux_actif_utilisateurs' => 0
    ];
    
    if ($stats_generales['total_pharmaciens'] > 0) {
        $sante_plateforme['taux_validation_pharmaciens'] = round(($stats_generales['pharmacies_actives'] / $stats_generales['total_pharmaciens']) * 100, 2);
    }
    if ($stats_stocks['total'] > 0) {
        $sante_plateforme['taux_disponibilite'] = round(($stats_stocks['disponible'] / $stats_stocks['total']) * 100, 2);
    }

} catch(PDOException $e) {
    $stats_generales = [];
    $stats_reservations = [];
    $stats_stocks = [];
    $evolution_mensuelle = [];
    $reservations_par_mois = [];
    $medicaments_populaires = [];
    $medicaments_reserves = [];
    $pharmacies_actives = [];
    $dernieres_activites = [];
    $taux_conversion = ['recherches_vers_reservations' => 0, 'reservations_vers_confirmations' => 0];
    $repartition_communes = [];
    $activite_semaine = [];
    $sante_plateforme = ['taux_disponibilite' => 0, 'taux_validation_pharmaciens' => 0, 'taux_actif_utilisateurs' => 0];
}

$page_title = 'Statistiques - Administration PharmaGeo';
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
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
            transform: translateY(-2px);
            box-shadow: 0 12px 40px rgba(42,195,42,0.3);
        }

        .top-bar .page-actions .btn-action.btn-outline-custom {
            border: 2px solid var(--primary-green);
            color: var(--primary-green);
            background: transparent;
        }

        .top-bar .page-actions .btn-action.btn-outline-custom:hover {
            background: var(--primary-green);
            color: white;
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
            .top-bar .page-actions .btn-action { font-size: 0.8rem; padding: 8px 16px; }
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

        .stat-card .stat-icon.green { background: rgba(42,195,42,0.1); color: var(--primary-green); }
        .stat-card .stat-icon.orange { background: rgba(200,120,30,0.1); color: var(--primary-orange); }
        .stat-card .stat-icon.blue { background: rgba(52,152,219,0.1); color: #3498db; }
        .stat-card .stat-icon.purple { background: rgba(155,89,182,0.1); color: #9b59b6; }
        .stat-card .stat-icon.red { background: rgba(231,76,60,0.1); color: #e74c3c; }
        .stat-card .stat-icon.teal { background: rgba(26,188,156,0.1); color: #1abc9c; }

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

        /* ===== CHART CONTAINER ===== */
        .section-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 24px;
            border: 1px solid rgba(0,0,0,0.02);
            transition: var(--transition);
        }

        .section-card:hover {
            box-shadow: var(--shadow-md);
        }

        .section-card .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .section-card .section-header h5 {
            font-weight: 700;
            margin: 0;
            font-size: 1.1rem;
        }

        .section-card .section-header h5 i {
            margin-right: 8px;
            color: var(--primary-green);
        }

        .chart-container {
            position: relative;
            height: 280px;
        }

        /* ===== TABLE ===== */
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
            padding: 12px 10px;
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

        .badge-status {
            padding: 4px 14px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-status.success { background: rgba(42,195,42,0.1); color: var(--primary-green); }
        .badge-status.warning { background: rgba(200,120,30,0.1); color: var(--primary-orange); }
        .badge-status.danger { background: rgba(231,76,60,0.1); color: #e74c3c; }
        .badge-status.info { background: rgba(52,152,219,0.1); color: #3498db; }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 30px 20px;
        }

        .empty-state i {
            font-size: 3rem;
            color: #ddd;
            margin-bottom: 12px;
        }

        .empty-state h6 {
            font-weight: 600;
            color: var(--gray-text);
        }

        .empty-state p {
            color: #bbb;
            font-size: 0.9rem;
            margin: 0;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
            .chart-container { height: 200px; }
            .section-card { padding: 16px; }
        }

        @media (max-width: 576px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card { padding: 12px; }
            .stat-card .stat-number { font-size: 1.3rem; }
            .stat-card .stat-label { font-size: 0.7rem; }
            .stat-card .stat-icon { width: 32px; height: 32px; font-size: 0.9rem; }
            .table-custom { font-size: 0.8rem; min-width: 500px; }
            .table-custom thead th,
            .table-custom tbody td { padding: 8px 6px; }
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
            <a href="<?php echo BASE_URL; ?>admin/reservations.php" class="nav-item">
                <i class="fas fa-calendar-check"></i>
                <span>Réservations</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/statistiques.php" class="nav-item active">
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
                        <h1><span class="highlight">Statistiques</span></h1>
                        <p><i class="fas fa-chart-bar me-1" style="color: var(--primary-green);"></i> Analysez les performances de la plateforme</p>
                    </div>
                </div>
            </div>
            <div class="page-actions">
                <a href="<?php echo BASE_URL; ?>admin/statistiques.php?export=pdf" class="btn-action btn-primary-custom">
                    <i class="fas fa-file-pdf"></i> Exporter PDF
                </a>
                <a href="<?php echo BASE_URL; ?>admin/statistiques.php?export=excel" class="btn-action btn-outline-custom">
                    <i class="fas fa-file-excel"></i> Exporter Excel
                </a>
            </div>
        </div>

        <!-- ===== STATS GÉNÉRALES ===== -->
        <div class="stats-grid animate-fade-up delay-1">
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo number_format($stats_generales['total_utilisateurs'] ?? 0); ?></div>
                        <div class="stat-label">Utilisateurs</div>
                    </div>
                    <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo number_format($stats_generales['total_pharmacies'] ?? 0); ?></div>
                        <div class="stat-label">Pharmacies</div>
                    </div>
                    <div class="stat-icon green"><i class="fas fa-store"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo number_format($stats_generales['total_medicaments'] ?? 0); ?></div>
                        <div class="stat-label">Médicaments</div>
                    </div>
                    <div class="stat-icon purple"><i class="fas fa-capsules"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo number_format($stats_generales['total_reservations'] ?? 0); ?></div>
                        <div class="stat-label">Réservations</div>
                    </div>
                    <div class="stat-icon orange"><i class="fas fa-calendar-check"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo number_format($stats_generales['total_recherches'] ?? 0); ?></div>
                        <div class="stat-label">Recherches</div>
                    </div>
                    <div class="stat-icon teal"><i class="fas fa-search"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo number_format($stats_generales['total_categories'] ?? 0); ?></div>
                        <div class="stat-label">Catégories</div>
                    </div>
                    <div class="stat-icon purple"><i class="fas fa-tags"></i></div>
                </div>
            </div>
        </div>

        <!-- ===== GRAPHIQUES ===== -->
        <div class="row g-4 animate-fade-up delay-2">
            <!-- Évolution des inscriptions -->
            <div class="col-lg-8">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-chart-line"></i> Évolution des inscriptions</h5>
                        <span class="text-muted small">12 derniers mois</span>
                    </div>
                    <div class="chart-container">
                        <canvas id="evolutionChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Taux de disponibilité -->
            <div class="col-lg-4">
                <div class="section-card h-100">
                    <div class="section-header">
                        <h5><i class="fas fa-chart-pie"></i> Disponibilité des stocks</h5>
                    </div>
                    <div class="chart-container" style="height: 250px;">
                        <canvas id="donutChart"></canvas>
                    </div>
                    <div class="row mt-3 text-center">
                        <div class="col-4">
                            <div class="small text-muted">Disponible</div>
                            <div class="fw-bold" style="color: var(--primary-green);"><?php echo $stats_stocks['disponible'] ?? 0; ?></div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted">Stock faible</div>
                            <div class="fw-bold" style="color: var(--primary-orange);"><?php echo $stats_stocks['stock_faible'] ?? 0; ?></div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted">Rupture</div>
                            <div class="fw-bold" style="color: #e74c3c;"><?php echo $stats_stocks['rupture'] ?? 0; ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== RÉSERVATIONS PAR MOIS ===== -->
        <div class="row g-4 animate-fade-up delay-3">
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-calendar-check" style="color: var(--primary-orange);"></i> Réservations par mois</h5>
                    </div>
                    <div class="chart-container">
                        <canvas id="reservationsChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Répartition des réservations -->
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-chart-pie"></i> Répartition des statuts</h5>
                    </div>
                    <div class="chart-container">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== TAUX DE CONVERSION ET SANTÉ ===== -->
        <div class="row g-4 animate-fade-up delay-4">
            <!-- Taux de conversion -->
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-funnel-dollar"></i> Taux de conversion</h5>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="p-3 bg-light rounded-3 text-center">
                                <div class="text-muted small">Recherches → Réservations</div>
                                <div class="h2 fw-bold" style="color: var(--primary-green);"><?php echo $taux_conversion['recherches_vers_reservations']; ?>%</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 bg-light rounded-3 text-center">
                                <div class="text-muted small">Réservations → Confirmées</div>
                                <div class="h2 fw-bold" style="color: var(--primary-orange);"><?php echo $taux_conversion['reservations_vers_confirmations']; ?>%</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Santé de la plateforme -->
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-heartbeat" style="color: #e74c3c;"></i> Santé de la plateforme</h5>
                    </div>
                    <div class="row g-3">
                        <div class="col-4">
                            <div class="p-3 bg-light rounded-3 text-center">
                                <div class="text-muted small">Disponibilité</div>
                                <div class="h3 fw-bold" style="color: var(--primary-green);"><?php echo $sante_plateforme['taux_disponibilite']; ?>%</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-3 bg-light rounded-3 text-center">
                                <div class="text-muted small">Pharmaciens validés</div>
                                <div class="h3 fw-bold" style="color: var(--primary-orange);"><?php echo $sante_plateforme['taux_validation_pharmaciens']; ?>%</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-3 bg-light rounded-3 text-center">
                                <div class="text-muted small">Taux global</div>
                                <div class="h3 fw-bold" style="color: #3498db;"><?php echo round(($sante_plateforme['taux_disponibilite'] + $sante_plateforme['taux_validation_pharmaciens']) / 2, 2); ?>%</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== MÉDICAMENTS POPULAIRES & PHARMACIES ACTIVES ===== -->
        <div class="row g-4 animate-fade-up delay-5">
            <!-- Médicaments populaires -->
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-fire" style="color: var(--primary-orange);"></i> Médicaments les plus recherchés</h5>
                    </div>
                    <?php if (!empty($medicaments_populaires)): ?>
                        <div class="table-responsive">
                            <table class="table table-custom">
                                <thead>
                                    <tr>
                                        <th>Médicament</th>
                                        <th>DCI</th>
                                        <th class="text-end">Recherches</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($medicaments_populaires as $med): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($med['Nom_commercial']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($med['DCI'] ?? '-'); ?></td>
                                            <td class="text-end"><span class="badge-status success"><?php echo $med['total_recherches']; ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-search"></i>
                            <h6>Aucune donnée</h6>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pharmacies actives -->
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-store" style="color: var(--primary-green);"></i> Pharmacies les plus actives</h5>
                    </div>
                    <?php if (!empty($pharmacies_actives)): ?>
                        <div class="table-responsive">
                            <table class="table table-custom">
                                <thead>
                                    <tr>
                                        <th>Pharmacie</th>
                                        <th>Réservations</th>
                                        <th>Patients</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pharmacies_actives as $p): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($p['Nom']); ?></strong></td>
                                            <td><span class="badge-status info"><?php echo $p['total_reservations']; ?></span></td>
                                            <td><?php echo $p['total_patients']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-store"></i>
                            <h6>Aucune donnée</h6>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===== RÉPARTITION PAR COMMUNE ===== -->
        <?php if (!empty($repartition_communes)): ?>
        <div class="row g-4 animate-fade-up delay-6">
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-map-marker-alt"></i> Répartition par commune</h5>
                    </div>
                    <div class="chart-container">
                        <canvas id="communesChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-calendar-week"></i> Activité par jour de semaine</h5>
                    </div>
                    <div class="chart-container">
                        <canvas id="semaineChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ===== DERNIÈRES ACTIVITÉS ===== -->
        <div class="section-card animate-fade-up delay-7">
            <div class="section-header">
                <h5><i class="fas fa-history"></i> Dernières activités</h5>
                <a href="<?php echo BASE_URL; ?>admin/logs.php" class="btn-link-custom" style="color: var(--primary-green); text-decoration: none; font-weight: 600; font-size: 0.85rem; transition: var(--transition);">Voir tout <i class="fas fa-arrow-right"></i></a>
            </div>
            <?php if (!empty($dernieres_activites)): ?>
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead>
                            <tr>
                                <th>Utilisateur</th>
                                <th>Action</th>
                                <th>Résultat</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dernieres_activites as $log): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($log['Prenom'] . ' ' . $log['Nom']); ?></td>
                                    <td><?php echo htmlspecialchars($log['Action']); ?></td>
                                    <td>
                                        <span class="badge-status <?php echo $log['Resultat'] === 'succes' ? 'success' : 'danger'; ?>">
                                            <?php echo ucfirst($log['Resultat']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($log['Date_action'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <h6>Aucune activité</h6>
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

            // ===== CHART: ÉVOLUTION DES INSCRIPTIONS =====
            <?php if (!empty($evolution_mensuelle)): ?>
            const evolutionData = <?php echo json_encode($evolution_mensuelle); ?>;
            const ctx1 = document.getElementById('evolutionChart').getContext('2d');

            const labels = evolutionData.map(item => {
                const [year, month] = item.mois.split('-');
                const months = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
                return months[parseInt(month) - 1] + ' ' + year;
            });

            new Chart(ctx1, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Patients',
                            data: evolutionData.map(item => parseInt(item.patients || 0)),
                            backgroundColor: 'rgba(52,152,219,0.6)',
                            borderColor: '#3498db',
                            borderWidth: 2,
                            borderRadius: 4
                        },
                        {
                            label: 'Pharmaciens',
                            data: evolutionData.map(item => parseInt(item.pharmaciens || 0)),
                            backgroundColor: 'rgba(42,195,42,0.6)',
                            borderColor: 'rgb(42, 195, 42)',
                            borderWidth: 2,
                            borderRadius: 4
                        },
                        {
                            label: 'Administrateurs',
                            data: evolutionData.map(item => parseInt(item.admins || 0)),
                            backgroundColor: 'rgba(200,120,30,0.6)',
                            borderColor: 'hsl(25, 75%, 47%)',
                            borderWidth: 2,
                            borderRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                padding: 20,
                                font: { size: 11, family: 'Inter' }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { stepSize: 1, font: { size: 10 } },
                            grid: { display: true, color: 'rgba(0,0,0,0.04)' }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { font: { size: 10 } }
                        }
                    }
                }
            });
            <?php endif; ?>

            // ===== CHART: DONUT DISPONIBILITÉ =====
            const disponibles = <?php echo $stats_stocks['disponible'] ?? 0; ?>;
            const stockFaible = <?php echo $stats_stocks['stock_faible'] ?? 0; ?>;
            const rupture = <?php echo $stats_stocks['rupture'] ?? 0; ?>;

            new Chart(document.getElementById('donutChart'), {
                type: 'doughnut',
                data: {
                    labels: ['Disponible', 'Stock faible', 'Rupture'],
                    datasets: [{
                        data: [disponibles, stockFaible, rupture],
                        backgroundColor: ['rgb(42, 195, 42)', 'hsl(25, 75%, 47%)', '#e74c3c'],
                        borderWidth: 3,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '70%',
                    plugins: { legend: { display: false } }
                }
            });

            // ===== CHART: RÉSERVATIONS PAR MOIS =====
            <?php if (!empty($reservations_par_mois)): ?>
            const resData = <?php echo json_encode($reservations_par_mois); ?>;
            const ctx3 = document.getElementById('reservationsChart').getContext('2d');

            const resLabels = resData.map(item => {
                const [year, month] = item.mois.split('-');
                const months = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
                return months[parseInt(month) - 1] + ' ' + year;
            });

            new Chart(ctx3, {
                type: 'line',
                data: {
                    labels: resLabels,
                    datasets: [
                        {
                            label: 'Total',
                            data: resData.map(item => parseInt(item.total || 0)),
                            borderColor: '#3498db',
                            backgroundColor: 'rgba(52,152,219,0.1)',
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Confirmées',
                            data: resData.map(item => parseInt(item.confirmees || 0)),
                            borderColor: 'rgb(42, 195, 42)',
                            backgroundColor: 'rgba(42,195,42,0.1)',
                            fill: true,
                            tension: 0.4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                padding: 20,
                                font: { size: 11, family: 'Inter' }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { stepSize: 1, font: { size: 10 } },
                            grid: { display: true, color: 'rgba(0,0,0,0.04)' }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { font: { size: 10 } }
                        }
                    }
                }
            });
            <?php endif; ?>

            // ===== CHART: RÉPARTITION DES STATUTS =====
            const statusData = <?php echo json_encode($stats_reservations); ?>;
            
            new Chart(document.getElementById('statusChart'), {
                type: 'pie',
                data: {
                    labels: ['En attente', 'Confirmées', 'Refusées', 'Retirées', 'Annulées', 'Expirées'],
                    datasets: [{
                        data: [
                            statusData.en_attente || 0,
                            statusData.confirmees || 0,
                            statusData.refusees || 0,
                            statusData.retirees || 0,
                            statusData.annulees || 0,
                            statusData.expirees || 0
                        ],
                        backgroundColor: [
                            '#f39c12', '#2ecc71', '#e74c3c', 
                            '#3498db', '#7f8c8d', '#9b59b6'
                        ],
                        borderWidth: 3,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                usePointStyle: true,
                                padding: 15,
                                font: { size: 11, family: 'Inter' }
                            }
                        }
                    }
                }
            });

            // ===== CHART: RÉPARTITION PAR COMMUNE =====
            <?php if (!empty($repartition_communes)): ?>
            const communesData = <?php echo json_encode($repartition_communes); ?>;
            new Chart(document.getElementById('communesChart'), {
                type: 'bar',
                data: {
                    labels: communesData.map(item => item.Commune || 'Non renseigné'),
                    datasets: [{
                        label: 'Pharmacies',
                        data: communesData.map(item => parseInt(item.total || 0)),
                        backgroundColor: 'rgba(42,195,42,0.6)',
                        borderColor: 'rgb(42, 195, 42)',
                        borderWidth: 2,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { stepSize: 1, font: { size: 10 } },
                            grid: { display: true, color: 'rgba(0,0,0,0.04)' }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { font: { size: 10 } }
                        }
                    }
                }
            });
            <?php endif; ?>

            // ===== CHART: ACTIVITÉ PAR JOUR DE SEMAINE =====
            <?php if (!empty($activite_semaine)): ?>
            const semaineData = <?php echo json_encode($activite_semaine); ?>;
            const jours = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            const joursFr = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
            
            const sortedData = jours.map(jour => {
                const found = semaineData.find(item => item.jour === jour);
                return found ? parseInt(found.total || 0) : 0;
            });

            new Chart(document.getElementById('semaineChart'), {
                type: 'bar',
                data: {
                    labels: joursFr,
                    datasets: [{
                        label: 'Réservations',
                        data: sortedData,
                        backgroundColor: 'rgba(52,152,219,0.6)',
                        borderColor: '#3498db',
                        borderWidth: 2,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { stepSize: 1, font: { size: 10 } },
                            grid: { display: true, color: 'rgba(0,0,0,0.04)' }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { font: { size: 10 } }
                        }
                    }
                }
            });
            <?php endif; ?>

            // ===== KEYBOARD SHORTCUTS =====
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && sidebar.classList.contains('open')) {
                    toggleSidebar();
                }
            });

            // ===== CONSOLE LOG =====
            console.log('%c PharmaGeo - Statistiques ', 'background: linear-gradient(135deg, rgb(42, 195, 42), hsl(25, 75%, 47%)); color: white; font-size: 16px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');

        });
    </script>

</body>
</html>