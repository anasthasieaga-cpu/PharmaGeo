<?php
// ============================================================
// PharmaGeo — Administration : Tableau de bord
// Page de synthèse affichant les statistiques globales de la
// plateforme (utilisateurs, pharmacies, stocks, réservations).
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

// ===== RÉCUPÉRATION DES STATISTIQUES =====
try {
    // Statistiques générales
    $total_utilisateurs = $pdo->query("SELECT COUNT(*) as total FROM utilisateur WHERE Statut = 'actif'")->fetch();
    $total_patients = $pdo->query("SELECT COUNT(*) as total FROM utilisateur WHERE Role = 'patient' AND Statut = 'actif'")->fetch();
    $total_pharmaciens = $pdo->query("SELECT COUNT(*) as total FROM pharmacien WHERE Statut_validation = 'valide'")->fetch();
    $total_pharmacies = $pdo->query("SELECT COUNT(*) as total FROM pharmacie WHERE Statut = 'active'")->fetch();
    $total_pharmacies_attente = $pdo->query("SELECT COUNT(*) as total FROM pharmacie WHERE Statut = 'en_attente'")->fetch();
    $total_medicaments = $pdo->query("SELECT COUNT(*) as total FROM medicament")->fetch();
    $total_stocks = $pdo->query("SELECT COUNT(*) as total FROM stock WHERE Quantite > 0")->fetch();
    $total_reservations = $pdo->query("SELECT COUNT(*) as total FROM reservation")->fetch();
    $total_reservations_attente = $pdo->query("SELECT COUNT(*) as total FROM reservation WHERE Statut = 'en_attente'")->fetch();
    $total_recherches = $pdo->query("SELECT COUNT(*) as total FROM recherche")->fetch();
    $total_logs = $pdo->query("SELECT COUNT(*) as total FROM log")->fetch();

    // Regrouper les statistiques
    $stats = [
        'total_utilisateurs' => $total_utilisateurs['total'] ?? 0,
        'total_patients' => $total_patients['total'] ?? 0,
        'total_pharmaciens' => $total_pharmaciens['total'] ?? 0,
        'total_pharmacies' => $total_pharmacies['total'] ?? 0,
        'total_pharmacies_attente' => $total_pharmacies_attente['total'] ?? 0,
        'total_medicaments' => $total_medicaments['total'] ?? 0,
        'total_stocks' => $total_stocks['total'] ?? 0,
        'total_reservations' => $total_reservations['total'] ?? 0,
        'total_reservations_attente' => $total_reservations_attente['total'] ?? 0,
        'total_recherches' => $total_recherches['total'] ?? 0,
        'total_logs' => $total_logs['total'] ?? 0
    ];

    // Évolution mensuelle des inscriptions
    $evolution = $pdo->query("
        SELECT 
            DATE_FORMAT(Date_creation, '%Y-%m') as mois, 
            COUNT(*) as total,
            SUM(CASE WHEN Role = 'patient' THEN 1 ELSE 0 END) as patients,
            SUM(CASE WHEN Role = 'pharmacien' THEN 1 ELSE 0 END) as pharmaciens,
            SUM(CASE WHEN Role = 'administrateur' THEN 1 ELSE 0 END) as admins
        FROM utilisateur 
        WHERE Date_creation >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(Date_creation, '%Y-%m')
        ORDER BY mois ASC
    ")->fetchAll();

    // Médicaments les plus recherchés
    // Jointure LEFT sur la table recherche pour garder les médicaments sans recherche
    $medicaments_populaires = $pdo->query("
        SELECT m.Nom_commercial, m.DCI, COUNT(r.Id_recherche) as total_recherches
        FROM medicament m
        LEFT JOIN recherche r ON r.Mot_recherche LIKE CONCAT('%', m.Nom_commercial, '%')
        GROUP BY m.Id_medicament
        ORDER BY total_recherches DESC
        LIMIT 10
    ")->fetchAll();

    // Pharmacies les plus consultées
    $pharmacies_populaires = $pdo->query("
        SELECT p.Nom, p.Quartier, p.Commune, 
               COUNT(r.Id_recherche) as total_consultations
        FROM pharmacie p
        LEFT JOIN recherche r ON r.Latitude = p.Latitude AND r.Longitude = p.Longitude
        WHERE p.Statut = 'active'
        GROUP BY p.Id_pharmacie
        ORDER BY total_consultations DESC
        LIMIT 10
    ")->fetchAll();

    // Dernières activités
    $dernieres_activites = $pdo->query("
        SELECT l.*, u.Nom, u.Prenom, u.Email
        FROM log l
        JOIN utilisateur u ON l.Id_utilisateur = u.Id_utilisateur
        ORDER BY l.Date_action DESC
        LIMIT 10
    ")->fetchAll();

    // Pharmacies en attente de validation
    $pharmacies_attente = $pdo->query("
        SELECT p.*, u.Nom, u.Prenom, u.Email, u.Telephone
        FROM pharmacie p
        JOIN pharmacien ph ON p.Id_pharmacien = ph.Id_pharmacien
        JOIN utilisateur u ON ph.Id_utilisateur = u.Id_utilisateur
        WHERE p.Statut = 'en_attente'
        ORDER BY p.Id_pharmacie DESC
        LIMIT 5
    ")->fetchAll();

    // Taux de disponibilité des médicaments
    $taux_disponibilite = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN Statut = 'disponible' AND Quantite > 0 THEN 1 ELSE 0 END) as disponibles,
            SUM(CASE WHEN Statut = 'stock_faible' AND Quantite > 0 THEN 1 ELSE 0 END) as stock_faible,
            SUM(CASE WHEN Statut = 'rupture' OR Quantite = 0 THEN 1 ELSE 0 END) as rupture
        FROM stock
    ")->fetch();

} catch(PDOException $e) {
    // En cas d'erreur, définir des valeurs par défaut
    $stats = [
        'total_utilisateurs' => 0, 'total_patients' => 0, 'total_pharmaciens' => 0,
        'total_pharmacies' => 0, 'total_pharmacies_attente' => 0, 'total_medicaments' => 0,
        'total_stocks' => 0, 'total_reservations' => 0, 'total_reservations_attente' => 0,
        'total_recherches' => 0, 'total_logs' => 0
    ];
    $evolution = [];
    $medicaments_populaires = [];
    $pharmacies_populaires = [];
    $dernieres_activites = [];
    $pharmacies_attente = [];
    $taux_disponibilite = ['total' => 0, 'disponibles' => 0, 'stock_faible' => 0, 'rupture' => 0];
}

// Calcul du taux de disponibilité
// Pourcentage arrondi des stocks disponibles par rapport au total des stocks
$taux_dispo = 0;
if ($taux_disponibilite['total'] > 0) {
    $taux_dispo = round(($taux_disponibilite['disponibles'] / $taux_disponibilite['total']) * 100);
}

$page_title = 'Tableau de bord - Administration PharmaGeo';
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .animate-fade-up {
            animation: fadeInUp 0.6s ease-out forwards;
        }

        .animate-slide-left {
            animation: slideInLeft 0.5s ease-out forwards;
        }

        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }
        .delay-5 { animation-delay: 0.5s; }

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

        .sidebar::-webkit-scrollbar {
            width: 4px;
        }
        .sidebar::-webkit-scrollbar-thumb {
            background: var(--primary-green);
            border-radius: 4px;
        }

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

        .sidebar .nav-menu {
            padding: 20px 15px;
        }

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

        .sidebar .nav-menu .nav-item i {
            width: 20px;
            text-align: center;
            font-size: 1rem;
        }

        .sidebar .nav-menu .nav-item .badge-notif {
            margin-left: auto;
            background: #e74c3c;
            color: white;
            padding: 2px 10px;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 700;
        }

        .sidebar .nav-menu .nav-item .badge-notif.green {
            background: var(--primary-green);
        }

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

        .sidebar .user-profile .user-info {
            flex: 1;
        }

        .sidebar .user-profile .user-info .name {
            font-weight: 600;
            font-size: 0.9rem;
            color: white;
        }

        .sidebar .user-profile .user-info .role {
            font-size: 0.7rem;
            color: rgba(255,255,255,0.4);
        }

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

        .top-bar .datetime {
            color: var(--gray-text);
            font-size: 0.85rem;
        }

        .top-bar .datetime i {
            margin-right: 6px;
        }

        /* ===== STATS CARDS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 22px 20px;
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

        .stat-card:hover::before {
            opacity: 1;
        }

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
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
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

        .stat-card .stat-icon.red {
            background: rgba(231,76,60,0.1);
            color: #e74c3c;
        }

        .stat-card .stat-icon.teal {
            background: rgba(26,188,156,0.1);
            color: #1abc9c;
        }

        .stat-card .stat-number {
            font-size: 2rem;
            font-weight: 800;
            color: var(--dark-text);
            line-height: 1.2;
            margin-top: 8px;
        }

        .stat-card .stat-label {
            color: var(--gray-text);
            font-size: 0.85rem;
            font-weight: 500;
        }

        .stat-card .stat-change {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 2px 12px;
            border-radius: 50px;
            margin-top: 6px;
        }

        .stat-card .stat-change.up {
            color: var(--primary-green);
            background: rgba(42,195,42,0.08);
        }

        .stat-card .stat-change.down {
            color: #e74c3c;
            background: rgba(231,76,60,0.08);
        }

        /* ===== SECTION CARDS ===== */
        .section-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 24px;
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

        .section-card .section-header .btn-link-custom {
            color: var(--primary-green);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .section-card .section-header .btn-link-custom:hover {
            color: var(--primary-orange);
        }

        /* ===== TABLE ===== */
        .table-custom {
            margin: 0;
        }

        .table-custom thead th {
            font-weight: 600;
            font-size: 0.8rem;
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

        .badge-status.success {
            background: rgba(42,195,42,0.1);
            color: var(--primary-green);
        }

        .badge-status.warning {
            background: rgba(200,120,30,0.1);
            color: var(--primary-orange);
        }

        .badge-status.danger {
            background: rgba(231,76,60,0.1);
            color: #e74c3c;
        }

        .badge-status.info {
            background: rgba(52,152,219,0.1);
            color: #3498db;
        }

        /* ===== CHART CONTAINER ===== */
        .chart-container {
            position: relative;
            height: 280px;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 991px) {
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 15px;
            }

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

            .sidebar-overlay.active {
                display: block;
            }

            .top-bar .page-title h1 {
                font-size: 1.3rem;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 15px;
            }

            .stat-card .stat-number {
                font-size: 1.5rem;
            }
        }

        @media (max-width: 576px) {
            .main-content {
                padding: 10px;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .stat-card {
                padding: 15px;
            }

            .stat-card .stat-number {
                font-size: 1.2rem;
            }

            .stat-card .stat-label {
                font-size: 0.75rem;
            }

            .stat-card .stat-icon {
                width: 36px;
                height: 36px;
                font-size: 1rem;
            }

            .section-card {
                padding: 16px;
            }

            .top-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .top-bar .page-actions {
                flex-wrap: wrap;
            }

            .top-bar .page-actions .btn-action {
                font-size: 0.8rem;
                padding: 8px 16px;
            }

            .table-custom {
                font-size: 0.8rem;
            }

            .table-custom thead th,
            .table-custom tbody td {
                padding: 8px 6px;
            }

            .badge-status {
                font-size: 0.65rem;
                padding: 2px 10px;
            }

            .chart-container {
                height: 200px;
            }

            .sidebar .brand h4 {
                font-size: 1.2rem;
            }
        }

        @media (max-width: 400px) {
            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }

            .stat-card {
                padding: 12px;
            }

            .stat-card .stat-number {
                font-size: 1rem;
            }

            .stat-card .stat-label {
                font-size: 0.65rem;
            }

            .stat-card .stat-icon {
                width: 30px;
                height: 30px;
                font-size: 0.8rem;
            }
        }

        /* ===== TOGGLE SIDEBAR BUTTON ===== */
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

        .toggle-sidebar:hover {
            background: rgba(0,0,0,0.05);
        }

        @media (max-width: 991px) {
            .toggle-sidebar {
                display: block;
            }
        }

        /* ===== ACTIVITY ITEM ===== */
        .activity-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 10px 0;
            border-bottom: 1px solid #f5f5f5;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-item .activity-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .activity-item .activity-icon.green {
            background: rgba(42,195,42,0.1);
            color: var(--primary-green);
        }

        .activity-item .activity-icon.orange {
            background: rgba(200,120,30,0.1);
            color: var(--primary-orange);
        }

        .activity-item .activity-icon.blue {
            background: rgba(52,152,219,0.1);
            color: #3498db;
        }

        .activity-item .activity-icon.red {
            background: rgba(231,76,60,0.1);
            color: #e74c3c;
        }

        .activity-item .activity-content {
            flex: 1;
        }

        .activity-item .activity-content .text {
            font-size: 0.9rem;
            margin: 0;
        }

        .activity-item .activity-content .text strong {
            color: var(--dark-text);
        }

        .activity-item .activity-content .time {
            font-size: 0.75rem;
            color: var(--gray-text);
        }

        /* ===== PHARMACY PENDING ===== */
        .pharmacy-pending-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 0;
            border-bottom: 1px solid #f5f5f5;
        }

        .pharmacy-pending-item:last-child {
            border-bottom: none;
        }

        .pharmacy-pending-item .pharmacy-avatar {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: rgba(200,120,30,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: var(--primary-orange);
            flex-shrink: 0;
        }

        .pharmacy-pending-item .pharmacy-info {
            flex: 1;
        }

        .pharmacy-pending-item .pharmacy-info .name {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .pharmacy-pending-item .pharmacy-info .details {
            font-size: 0.8rem;
            color: var(--gray-text);
        }

        .pharmacy-pending-item .pharmacy-actions {
            display: flex;
            gap: 6px;
        }

        .pharmacy-pending-item .pharmacy-actions .btn-sm-custom {
            padding: 4px 14px;
            border-radius: 8px;
            border: none;
            font-size: 0.75rem;
            font-weight: 600;
            transition: var(--transition);
        }

        .pharmacy-pending-item .pharmacy-actions .btn-sm-custom.btn-approve {
            background: rgba(42,195,42,0.1);
            color: var(--primary-green);
        }

        .pharmacy-pending-item .pharmacy-actions .btn-sm-custom.btn-approve:hover {
            background: var(--primary-green);
            color: white;
        }

        .pharmacy-pending-item .pharmacy-actions .btn-sm-custom.btn-reject {
            background: rgba(231,76,60,0.1);
            color: #e74c3c;
        }

        .pharmacy-pending-item .pharmacy-actions .btn-sm-custom.btn-reject:hover {
            background: #e74c3c;
            color: white;
        }

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

        /* ===== DONUT CHART ===== */
        .donut-container {
            display: flex;
            align-items: center;
            gap: 30px;
            flex-wrap: wrap;
        }

        .donut-container .chart-wrapper {
            flex: 1;
            min-width: 200px;
            max-width: 300px;
        }

        .donut-container .legend {
            flex: 1;
            min-width: 150px;
        }

        .donut-container .legend .legend-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
            font-size: 0.85rem;
        }

        .donut-container .legend .legend-item .color-dot {
            width: 12px;
            height: 12px;
            border-radius: 4px;
            flex-shrink: 0;
        }

        .donut-container .legend .legend-item .value {
            font-weight: 600;
            margin-left: auto;
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
            <div class="brand-icon">
                <i class="fas fa-hospital"></i>
            </div>
            <h4>PharmaGeo</h4>
            <small>Administration</small>
        </div>

        <nav class="nav-menu">
            <div class="nav-label">Navigation</div>
            <a href="<?php echo BASE_URL; ?>admin/dashboard.php" class="nav-item active">
                <i class="fas fa-tachometer-alt"></i>
                <span>Tableau de bord</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/pharmacies.php" class="nav-item">
                <i class="fas fa-store"></i>
                <span>Pharmacies</span>
                <?php if ($stats['total_pharmacies_attente'] > 0): ?>
                    <span class="badge-notif"><?php echo $stats['total_pharmacies_attente']; ?></span>
                <?php endif; ?>
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
                <?php if ($stats['total_reservations_attente'] > 0): ?>
                    <span class="badge-notif"><?php echo $stats['total_reservations_attente']; ?></span>
                <?php endif; ?>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/statistiques.php" class="nav-item">
                <i class="fas fa-chart-bar"></i>
                <span>Statistiques</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/logs.php" class="nav-item">
                <i class="fas fa-history"></i>
                <span>Journaux d'activité</span>
                <span class="badge-notif green"><?php echo $stats['total_logs']; ?></span>
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
        <div class="top-bar">
            <div>
                <div class="d-flex align-items-center gap-3">
                    <button class="toggle-sidebar" id="toggleSidebar">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="page-title">
                        <h1>Tableau de <span class="highlight">bord</span></h1>
                        <p><i class="fas fa-user-shield me-1" style="color: var(--primary-green);"></i> Bienvenue, <?php echo htmlspecialchars($_SESSION['prenom'] ?? 'Administrateur'); ?> !</p>
                    </div>
                </div>
            </div>
            <div class="page-actions">
                <span class="datetime" id="currentDateTime">
                    <i class="fas fa-calendar-alt"></i>
                    <span id="dateTimeDisplay"></span>
                </span>
                <a href="<?php echo BASE_URL; ?>admin/pharmacies.php?action=validation" class="btn-action btn-primary-custom">
                    <i class="fas fa-check-double"></i> Valider les pharmacies
                    <?php if ($stats['total_pharmacies_attente'] > 0): ?>
                        <span class="badge bg-white text-dark ms-1" style="font-size: 0.65rem; padding: 0 8px;"><?php echo $stats['total_pharmacies_attente']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?php echo BASE_URL; ?>admin/statistiques.php" class="btn-action btn-outline-custom">
                    <i class="fas fa-file-alt"></i> Rapport
                </a>
            </div>
        </div>

        <!-- ===== STATS GRID ===== -->
        <div class="stats-grid animate-fade-up">
            <div class="stat-card delay-1">
                <div class="stat-header">
                    <div>
                        <div class="stat-label">Utilisateurs</div>
                        <div class="stat-number"><?php echo number_format($stats['total_utilisateurs']); ?></div>
                    </div>
                    <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                </div>
                <div class="stat-change up">
                    <i class="fas fa-arrow-up"></i> <?php echo $stats['total_patients']; ?> patients
                </div>
            </div>

            <div class="stat-card delay-2">
                <div class="stat-header">
                    <div>
                        <div class="stat-label">Pharmacies</div>
                        <div class="stat-number"><?php echo number_format($stats['total_pharmacies']); ?></div>
                    </div>
                    <div class="stat-icon green"><i class="fas fa-store"></i></div>
                </div>
                <div class="stat-change up">
                    <i class="fas fa-clock"></i> <?php echo $stats['total_pharmacies_attente']; ?> en attente
                </div>
            </div>

            <div class="stat-card delay-3">
                <div class="stat-header">
                    <div>
                        <div class="stat-label">Médicaments</div>
                        <div class="stat-number"><?php echo number_format($stats['total_medicaments']); ?></div>
                    </div>
                    <div class="stat-icon purple"><i class="fas fa-capsules"></i></div>
                </div>
                <div class="stat-change up">
                    <i class="fas fa-boxes"></i> <?php echo $stats['total_stocks']; ?> en stock
                </div>
            </div>

            <div class="stat-card delay-4">
                <div class="stat-header">
                    <div>
                        <div class="stat-label">Réservations</div>
                        <div class="stat-number"><?php echo number_format($stats['total_reservations']); ?></div>
                    </div>
                    <div class="stat-icon orange"><i class="fas fa-calendar-check"></i></div>
                </div>
                <div class="stat-change <?php echo $stats['total_reservations_attente'] > 0 ? 'up' : 'down'; ?>">
                    <?php if ($stats['total_reservations_attente'] > 0): ?>
                        <i class="fas fa-clock"></i> <?php echo $stats['total_reservations_attente']; ?> en attente
                    <?php else: ?>
                        <i class="fas fa-check"></i> Aucune en attente
                    <?php endif; ?>
                </div>
            </div>

            <div class="stat-card delay-5">
                <div class="stat-header">
                    <div>
                        <div class="stat-label">Recherches</div>
                        <div class="stat-number"><?php echo number_format($stats['total_recherches']); ?></div>
                    </div>
                    <div class="stat-icon teal"><i class="fas fa-search"></i></div>
                </div>
                <div class="stat-change up">
                    <i class="fas fa-arrow-up"></i> Médicaments recherchés
                </div>
            </div>

            <div class="stat-card delay-5">
                <div class="stat-header">
                    <div>
                        <div class="stat-label">Pharmaciens</div>
                        <div class="stat-number"><?php echo number_format($stats['total_pharmaciens']); ?></div>
                    </div>
                    <div class="stat-icon red"><i class="fas fa-user-md"></i></div>
                </div>
                <div class="stat-change up">
                    <i class="fas fa-check"></i> Comptes validés
                </div>
            </div>
        </div>

        <!-- ===== CHARTS ROW ===== -->
        <div class="row g-4 animate-fade-up delay-2">
            <!-- Évolution des inscriptions -->
            <div class="col-lg-8">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-chart-line"></i> Évolution des inscriptions</h5>
                        <span class="text-muted small">6 derniers mois</span>
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
                    <div class="donut-container">
                        <div class="chart-wrapper">
                            <canvas id="donutChart"></canvas>
                        </div>
                        <div class="legend">
                            <div class="legend-item">
                                <span class="color-dot" style="background: var(--primary-green);"></span>
                                Disponible
                                <span class="value"><?php echo $taux_disponibilite['disponibles']; ?></span>
                            </div>
                            <div class="legend-item">
                                <span class="color-dot" style="background: var(--primary-orange);"></span>
                                Stock faible
                                <span class="value"><?php echo $taux_disponibilite['stock_faible']; ?></span>
                            </div>
                            <div class="legend-item">
                                <span class="color-dot" style="background: #e74c3c;"></span>
                                Rupture
                                <span class="value"><?php echo $taux_disponibilite['rupture']; ?></span>
                            </div>
                            <div class="legend-item" style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #f0f0f0;">
                                <span class="color-dot" style="background: #3498db;"></span>
                                Taux global
                                <span class="value" style="font-size: 1.1rem;"><?php echo $taux_dispo; ?>%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== MEDICAMENTS POPULAIRES & PHARMACIES POPULAIRES ===== -->
        <div class="row g-4 animate-fade-up delay-3">
            <!-- Médicaments populaires -->
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-fire" style="color: var(--primary-orange);"></i> Médicaments les plus recherchés</h5>
                        <a href="<?php echo BASE_URL; ?>admin/statistiques.php" class="btn-link-custom">Voir tout <i class="fas fa-arrow-right"></i></a>
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
                                            <td class="text-end">
                                                <span class="badge-status success"><?php echo $med['total_recherches']; ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-search"></i>
                            <h6>Aucune recherche enregistrée</h6>
                            <p>Les données apparaîtront ici lorsque les utilisateurs effectueront des recherches.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pharmacies populaires -->
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-store" style="color: var(--primary-green);"></i> Pharmacies les plus consultées</h5>
                        <a href="<?php echo BASE_URL; ?>admin/pharmacies.php" class="btn-link-custom">Voir tout <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <?php if (!empty($pharmacies_populaires)): ?>
                        <div class="table-responsive">
                            <table class="table table-custom">
                                <thead>
                                    <tr>
                                        <th>Pharmacie</th>
                                        <th>Localisation</th>
                                        <th class="text-end">Consultations</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pharmacies_populaires as $pharm): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($pharm['Nom']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($pharm['Quartier'] ?? $pharm['Commune'] ?? 'Porto-Novo'); ?></td>
                                            <td class="text-end">
                                                <span class="badge-status info"><?php echo $pharm['total_consultations']; ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-store"></i>
                            <h6>Aucune consultation enregistrée</h6>
                            <p>Les données apparaîtront ici lorsque les utilisateurs consulteront des pharmacies.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===== PHARMACIES EN ATTENTE & DERNIÈRES ACTIVITÉS ===== -->
        <div class="row g-4 animate-fade-up delay-4">
            <!-- Pharmacies en attente -->
            <div class="col-lg-5">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-clock" style="color: var(--primary-orange);"></i> Pharmacies en attente de validation</h5>
                        <a href="<?php echo BASE_URL; ?>admin/pharmacies.php?action=validation" class="btn-link-custom">
                            <?php if ($stats['total_pharmacies_attente'] > 0): ?>
                                <span class="badge bg-danger me-1"><?php echo $stats['total_pharmacies_attente']; ?></span>
                            <?php endif; ?>
                            Gérer
                        </a>
                    </div>
                    <?php if (!empty($pharmacies_attente)): ?>
                        <?php foreach ($pharmacies_attente as $pharm): ?>
                            <div class="pharmacy-pending-item">
                                <div class="pharmacy-avatar">
                                    <i class="fas fa-store"></i>
                                </div>
                                <div class="pharmacy-info">
                                    <div class="name"><?php echo htmlspecialchars($pharm['Nom']); ?></div>
                                    <div class="details">
                                        <i class="fas fa-user me-1"></i> <?php echo htmlspecialchars($pharm['Prenom'] . ' ' . $pharm['Nom']); ?>
                                        <span class="mx-1">•</span>
                                        <i class="fas fa-map-marker-alt me-1"></i> <?php echo htmlspecialchars($pharm['Quartier'] ?? 'Porto-Novo'); ?>
                                    </div>
                                </div>
                                <div class="pharmacy-actions">
                                    <form method="POST" action="<?php echo BASE_URL; ?>admin/pharmacies.php?action=valider" style="display:inline;">
                                        <input type="hidden" name="id_pharmacie" value="<?php echo $pharm['Id_pharmacie']; ?>">
                                        <button type="submit" class="btn-sm-custom btn-approve" title="Valider">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </form>
                                    <form method="POST" action="<?php echo BASE_URL; ?>admin/pharmacies.php?action=rejeter" style="display:inline;">
                                        <input type="hidden" name="id_pharmacie" value="<?php echo $pharm['Id_pharmacie']; ?>">
                                        <button type="submit" class="btn-sm-custom btn-reject" title="Rejeter" onclick="return confirm('Êtes-vous sûr de vouloir rejeter cette pharmacie ?')">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle" style="color: var(--primary-green);"></i>
                            <h6>Aucune pharmacie en attente</h6>
                            <p>Toutes les pharmacies ont été validées.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Dernières activités -->
            <div class="col-lg-7">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-history"></i> Dernières activités</h5>
                        <a href="<?php echo BASE_URL; ?>admin/logs.php" class="btn-link-custom">Voir tout <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <?php if (!empty($dernieres_activites)): ?>
                        <?php foreach ($dernieres_activites as $log): ?>
                            <div class="activity-item">
                                <div class="activity-icon <?php echo $log['Resultat'] === 'succes' ? 'green' : 'red'; ?>">
                                    <i class="fas fa-<?php echo $log['Resultat'] === 'succes' ? 'check' : 'times'; ?>"></i>
                                </div>
                                <div class="activity-content">
                                    <p class="text">
                                        <strong><?php echo htmlspecialchars($log['Prenom'] . ' ' . $log['Nom']); ?></strong>
                                        <?php echo htmlspecialchars($log['Action']); ?>
                                    </p>
                                    <div class="time">
                                        <i class="far fa-clock me-1"></i>
                                        <?php echo date('d/m/Y à H:i', strtotime($log['Date_action'])); ?>
                                        <span class="mx-1">•</span>
                                        <i class="fas fa-globe me-1"></i>
                                        <?php echo htmlspecialchars($log['Adresse_ip'] ?? 'Local'); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <h6>Aucune activité récente</h6>
                            <p>Les logs apparaîtront ici lorsque des actions seront effectuées.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
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

            // ===== DATE ET HEURE EN TEMPS RÉEL =====
            function updateDateTime() {
                const now = new Date();
                const options = {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                };
                const display = document.getElementById('dateTimeDisplay');
                if (display) {
                    display.textContent = now.toLocaleDateString('fr-FR', options);
                }
            }

            updateDateTime();
            setInterval(updateDateTime, 1000);

            // ===== CHART: ÉVOLUTION DES INSCRIPTIONS =====
            <?php if (!empty($evolution)): ?>
            const evolutionData = <?php echo json_encode($evolution); ?>;
            const ctx = document.getElementById('evolutionChart').getContext('2d');

            const labels = evolutionData.map(item => {
                const [year, month] = item.mois.split('-');
                const months = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
                return months[parseInt(month) - 1] + ' ' + year;
            });

            const patientsData = evolutionData.map(item => parseInt(item.patients || 0));
            const pharmaciensData = evolutionData.map(item => parseInt(item.pharmaciens || 0));
            const adminsData = evolutionData.map(item => parseInt(item.admins || 0));

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Patients',
                            data: patientsData,
                            borderColor: '#3498db',
                            backgroundColor: 'rgba(52,152,219,0.1)',
                            fill: true,
                            tension: 0.4,
                            pointRadius: 4,
                            pointBackgroundColor: '#3498db'
                        },
                        {
                            label: 'Pharmaciens',
                            data: pharmaciensData,
                            borderColor: 'rgb(42, 195, 42)',
                            backgroundColor: 'rgba(42,195,42,0.1)',
                            fill: true,
                            tension: 0.4,
                            pointRadius: 4,
                            pointBackgroundColor: 'rgb(42, 195, 42)'
                        },
                        {
                            label: 'Administrateurs',
                            data: adminsData,
                            borderColor: 'hsl(25, 75%, 47%)',
                            backgroundColor: 'rgba(200,120,30,0.1)',
                            fill: true,
                            tension: 0.4,
                            pointRadius: 4,
                            pointBackgroundColor: 'hsl(25, 75%, 47%)',
                            borderDash: [5, 5]
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
                                font: {
                                    size: 11,
                                    family: 'Inter'
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                font: {
                                    size: 10
                                }
                            },
                            grid: {
                                display: true,
                                color: 'rgba(0,0,0,0.04)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    size: 10
                                }
                            }
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    }
                }
            });
            <?php endif; ?>

            // ===== CHART: DONUT DISPONIBILITÉ =====
            const donutCtx = document.getElementById('donutChart').getContext('2d');

            const disponibles = <?php echo $taux_disponibilite['disponibles'] ?? 0; ?>;
            const stockFaible = <?php echo $taux_disponibilite['stock_faible'] ?? 0; ?>;
            const rupture = <?php echo $taux_disponibilite['rupture'] ?? 0; ?>;

            new Chart(donutCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Disponible', 'Stock faible', 'Rupture'],
                    datasets: [{
                        data: [disponibles, stockFaible, rupture],
                        backgroundColor: [
                            'rgb(42, 195, 42)',
                            'hsl(25, 75%, 47%)',
                            '#e74c3c'
                        ],
                        borderWidth: 3,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '70%',
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });

            // ===== KEYBOARD SHORTCUTS =====
            document.addEventListener('keydown', function(e) {
                // Ctrl + R pour rafraîchir les données
                if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                    e.preventDefault();
                    window.location.reload();
                }
                // Escape pour fermer la sidebar
                if (e.key === 'Escape' && sidebar.classList.contains('open')) {
                    toggleSidebar();
                }
            });

            // ===== CONSOLE LOG =====
            console.log('%c PharmaGeo - Administration ', 'background: linear-gradient(135deg, rgb(42, 195, 42), hsl(25, 75%, 47%)); color: white; font-size: 18px; font-weight: bold; padding: 12px 24px; border-radius: 8px;');
            console.log('%c Bienvenue sur le tableau de bord d\'administration.', 'color: #666; font-size: 14px;');

        });
    </script>
</body>
</html>