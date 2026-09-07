<?php
// ============================================================
// PharmaGeo — Espace patient : Carte des pharmacies
// Affiche la carte Leaflet avec les pharmacies, filtres et géolocalisation.
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

// ===== ACCÈS PUBLIC =====
// La carte des pharmacies est accessible sans compte : un visiteur peut
// consulter les pharmacies à proximité sans être connecté.
$est_connecte = isset($_SESSION['user_id']);

// ===== RÉCUPÉRATION DES PHARMACIES =====
try {
    // Récupérer toutes les pharmacies actives
    $pharmacies = $pdo->query("
        SELECT p.*, u.Nom as Pharmacien_Nom, u.Prenom as Pharmacien_Prenom,
               (SELECT COUNT(*) FROM stock WHERE Id_pharmacie = p.Id_pharmacie AND Quantite > 0) as total_medicaments
        FROM pharmacie p
        JOIN pharmacien ph ON p.Id_pharmacien = ph.Id_pharmacien
        JOIN utilisateur u ON ph.Id_utilisateur = u.Id_utilisateur
        WHERE p.Statut = 'active'
        ORDER BY p.Nom ASC
    ")->fetchAll();

    // Récupérer les médicaments pour les filtres
    $medicaments = $pdo->query("
        SELECT DISTINCT m.Id_medicament, m.Nom_commercial, m.DCI
        FROM medicament m
        JOIN stock s ON m.Id_medicament = s.Id_medicament
        WHERE s.Quantite > 0 AND s.Statut = 'disponible'
        ORDER BY m.Nom_commercial ASC
        LIMIT 50
    ")->fetchAll();

    // Récupérer les catégories
    $categories = $pdo->query("
        SELECT * FROM categorie 
        ORDER BY Libelle ASC
    ")->fetchAll();

} catch(PDOException $e) {
    $pharmacies = [];
    $medicaments = [];
    $categories = [];
}

// ===== COORDONNÉES PAR DÉFAUT (Porto-Novo) =====
$default_lat = 6.4970;
$default_lng = 2.6050;

// ===== STATISTIQUES =====
$total_pharmacies = count($pharmacies);
$pharmacies_avec_stock = 0;
foreach ($pharmacies as $p) {
    if ($p['total_medicaments'] > 0) {
        $pharmacies_avec_stock++;
    }
}

$page_title = 'Carte des pharmacies - PharmaGeo';
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
    
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder@1.13.0/dist/Control.Geocoder.css" />
    
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

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
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
        .nav-item.block { display: block; color: rgba(255,255,255,0.75); padding: 11px 0; text-align: center; }
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

        /* ===== STATS ===== */
        .stats-mini {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }

        .stats-mini .stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            color: var(--gray-text);
        }

        .stats-mini .stat-item .number {
            font-weight: 700;
            color: var(--dark-text);
            font-size: 1.1rem;
        }

        .stats-mini .stat-item .icon {
            color: var(--primary-green);
        }

        /* ===== MAP CONTAINER ===== */
        #map {
            height: 550px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            border: 1px solid rgba(0,0,0,0.02);
            z-index: 1;
        }

        #map:hover {
            box-shadow: var(--shadow-lg);
        }

        /* ===== FILTERS ===== */
        .filters-section {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 20px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 24px;
            border: 1px solid rgba(0,0,0,0.02);
        }

        .filters-section .filter-group {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filters-section .filter-group .filter-label {
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--gray-text);
        }

        .filters-section .filter-group .filter-btn {
            padding: 6px 16px;
            border-radius: 50px;
            border: 2px solid #e0e0e0;
            background: transparent;
            font-weight: 500;
            font-size: 0.8rem;
            transition: var(--transition);
            color: var(--gray-text);
            cursor: pointer;
        }

        .filters-section .filter-group .filter-btn:hover {
            border-color: var(--primary-green);
            color: var(--primary-green);
        }

        .filters-section .filter-group .filter-btn.active {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white;
            border-color: transparent;
            box-shadow: var(--shadow-glow-green);
        }

        .filters-section .filter-group .filter-btn .count {
            background: rgba(255,255,255,0.2);
            padding: 0 8px;
            border-radius: 50px;
            font-size: 0.65rem;
            margin-left: 4px;
        }

        .filters-section .filter-group .filter-btn.active .count {
            background: rgba(255,255,255,0.3);
        }

        /* ===== SEARCH ===== */
        .search-box-map {
            display: flex;
            gap: 8px;
            align-items: center;
            background: var(--white);
            border-radius: 50px;
            padding: 4px 4px 4px 16px;
            box-shadow: var(--shadow-sm);
            border: 2px solid #e0e0e0;
            transition: var(--transition);
            flex: 1;
            min-width: 200px;
            max-width: 400px;
        }

        .search-box-map:focus-within {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 4px rgba(42,195,42,0.1);
        }

        .search-box-map .form-control {
            border: none;
            padding: 8px 0;
            font-size: 0.9rem;
            background: transparent;
            flex: 1;
        }

        .search-box-map .form-control:focus {
            box-shadow: none;
        }

        .search-box-map .btn-search {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white;
            border: none;
            padding: 8px 18px;
            border-radius: 50px;
            font-weight: 600;
            transition: var(--transition);
        }

        .search-box-map .btn-search:hover {
            transform: scale(1.05);
        }

        /* ===== SIDEBAR LIST ===== */
        .pharmacy-list-sidebar {
            max-height: 500px;
            overflow-y: auto;
            border-radius: var(--radius);
            background: var(--white);
            box-shadow: var(--shadow-sm);
            border: 1px solid rgba(0,0,0,0.02);
        }

        .pharmacy-list-sidebar::-webkit-scrollbar {
            width: 4px;
        }
        .pharmacy-list-sidebar::-webkit-scrollbar-thumb {
            background: var(--primary-green);
            border-radius: 4px;
        }

        .pharmacy-list-item {
            padding: 12px 16px;
            border-bottom: 1px solid #f5f5f5;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .pharmacy-list-item:hover {
            background: rgba(42,195,42,0.04);
        }

        .pharmacy-list-item:last-child {
            border-bottom: none;
        }

        .pharmacy-list-item .pharmacy-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: rgba(42,195,42,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-green);
            flex-shrink: 0;
        }

        .pharmacy-list-item .pharmacy-info {
            flex: 1;
        }

        .pharmacy-list-item .pharmacy-info .name {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .pharmacy-list-item .pharmacy-info .address {
            font-size: 0.8rem;
            color: var(--gray-text);
        }

        .pharmacy-list-item .pharmacy-info .med-count {
            font-size: 0.7rem;
            color: var(--primary-green);
        }

        .pharmacy-list-item .pharmacy-distance {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--primary-orange);
            white-space: nowrap;
        }

        .pharmacy-list-item.active {
            background: rgba(42,195,42,0.08);
            border-left: 3px solid var(--primary-green);
        }

        /* ===== MAP CONTROLS ===== */
        .map-controls {
            position: absolute;
            bottom: 30px;
            right: 30px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .map-controls .btn-map-control {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            border: none;
            background: var(--white);
            box-shadow: var(--shadow-md);
            color: var(--dark-text);
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .map-controls .btn-map-control:hover {
            background: var(--primary-green);
            color: white;
            transform: scale(1.05);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 991px) {
            .main-content { padding: 15px; }
            .top-bar .page-title h1 { font-size: 1.4rem; }
            #map { height: 400px; }
            .search-box-map { max-width: 100%; }
            .filters-section .filter-group { gap: 8px; }
            .filters-section .filter-group .filter-btn { font-size: 0.7rem; padding: 4px 12px; }
        }

        @media (max-width: 768px) {
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
            #map { height: 350px; }
            .stats-mini { gap: 15px; }
            .stats-mini .stat-item { font-size: 0.8rem; }
            .pharmacy-list-sidebar { max-height: 300px; }
            .map-controls { bottom: 15px; right: 15px; }
            .map-controls .btn-map-control { width: 38px; height: 38px; font-size: 0.9rem; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 10px; }
            .top-bar .page-title h1 { font-size: 1.2rem; }
            #map { height: 300px; }
            .filters-section { padding: 14px; }
            .filters-section .filter-group .filter-btn { font-size: 0.65rem; padding: 3px 10px; }
            .navbar-brand-custom { font-size: 1.3rem; }
            .pharmacy-list-item { padding: 10px 12px; }
            .pharmacy-list-item .pharmacy-icon { width: 32px; height: 32px; font-size: 0.9rem; }
            .search-box-map { padding: 4px 4px 4px 12px; }
            .search-box-map .btn-search { padding: 6px 14px; font-size: 0.8rem; }
        }

        /* ===== LEAFLET OVERRIDES ===== */
        .leaflet-popup-content {
            min-width: 250px;
            padding: 10px 0;
        }

        .leaflet-popup-content .pharmacy-popup .name {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--dark-text);
        }

        .leaflet-popup-content .pharmacy-popup .address {
            font-size: 0.85rem;
            color: var(--gray-text);
        }

        .leaflet-popup-content .pharmacy-popup .details {
            font-size: 0.8rem;
            color: var(--gray-text);
            margin-top: 6px;
        }

        .leaflet-popup-content .pharmacy-popup .details i {
            width: 16px;
            color: var(--primary-green);
        }

        .leaflet-popup-content .pharmacy-popup .btn-popup {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            margin-top: 8px;
            transition: var(--transition);
        }

        .leaflet-popup-content .pharmacy-popup .btn-popup:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-glow-green);
        }

        /* ===== MARKER CLUSTER ===== */
        .marker-cluster {
            background: rgba(42,195,42,0.2);
            border: 2px solid var(--primary-green);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: var(--primary-green);
            font-size: 0.9rem;
            animation: pulse 2s infinite;
        }

        /* ===== LOADING ===== */
        .map-loading {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1000;
            background: rgba(255,255,255,0.9);
            padding: 20px 30px;
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .map-loading .spinner {
            width: 24px;
            height: 24px;
            border: 3px solid #e0e0e0;
            border-top-color: var(--primary-green);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
    <link rel="stylesheet" href="../../assets/css/responsive.css">
</head>
<body>

    <div class="layout">

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar-admin" id="sidebar">
        <div class="brand-admin">
            <div class="brand-icon"><i class="fas fa-hospital"></i></div>
            <div><h4>PharmaGeo</h4><small>Patient</small></div>
        </div>
        <nav class="nav-menu">
            <div class="nav-label">Navigation</div>
            <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="nav-item"><i class="fas fa-search"></i><span>Rechercher</span></a>
            <a href="<?php echo BASE_URL; ?>pages/patient/carte.php" class="nav-item active"><i class="fas fa-map-marker-alt"></i><span>Carte</span></a>
            <?php if ($est_connecte): ?>
                <a href="<?php echo BASE_URL; ?>pages/patient/dashboard.php" class="nav-item"><i class="fas fa-tachometer-alt"></i><span>Tableau de bord</span></a>
                <a href="<?php echo BASE_URL; ?>pages/patient/historique.php" class="nav-item"><i class="fas fa-history"></i><span>Historique</span></a>
                <a href="<?php echo BASE_URL; ?>pages/patient/profil.php" class="nav-item"><i class="fas fa-user"></i><span>Profil</span></a>
                <div class="nav-label" style="margin-top: 20px;">Système</div>
                <a href="<?php echo BASE_URL; ?>deconnexion.php" class="nav-item" style="color: rgba(255,255,255,0.4);"><i class="fas fa-sign-out-alt"></i><span>Déconnexion</span></a>
            <?php else: ?>
                <div class="nav-label" style="margin-top: 20px;">Système</div>
                <a href="<?php echo BASE_URL; ?>inscription.php" class="nav-item"><i class="fas fa-user-plus"></i><span>S'inscrire</span></a>
                <a href="<?php echo BASE_URL; ?>connexion.php" class="nav-item" style="color: rgba(255,255,255,0.4);"><i class="fas fa-sign-in-alt"></i><span>Se connecter</span></a>
            <?php endif; ?>
        </nav>
        <div class="user-profile">
            <div class="avatar"><?php echo strtoupper(substr(($_SESSION['prenom'] ?? 'A'), 0, 1) . substr(($_SESSION['nom'] ?? 'D'), 0, 1)); ?></div>
            <div>
                <div class="name"><?php echo htmlspecialchars(($est_connecte ? (($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')) : 'Visiteur')); ?></div>
                <div class="role"><i class="fas fa-user me-1" style="color:#f1c40f;"></i> <?php echo $est_connecte ? 'Patient' : 'Invité'; ?></div>
            </div>
        </div>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">

        <!-- ===== TOP BAR ===== -->
        <div class="top-bar animate-fade-up">
            <div class="page-title">
                <h1>Carte des <span class="highlight">pharmacies</span></h1>
                <p>
                    <i class="fas fa-map-marker-alt me-1" style="color: var(--primary-green);"></i>
                    Localisez les pharmacies à Porto-Novo
                </p>
            </div>
            <div class="page-actions">
                <div class="stats-mini">
                    <div class="stat-item">
                        <span class="icon"><i class="fas fa-store"></i></span>
                        <span><span class="number"><?php echo $total_pharmacies; ?></span> pharmacies</span>
                    </div>
                    <div class="stat-item">
                        <span class="icon"><i class="fas fa-capsules"></i></span>
                        <span><span class="number"><?php echo $pharmacies_avec_stock; ?></span> avec stock</span>
                    </div>
                </div>
                <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="btn-action btn-primary-custom">
                    <i class="fas fa-search"></i> Rechercher
                </a>
            </div>
        </div>

        <!-- ===== FILTRES ===== -->
        <div class="filters-section animate-fade-up delay-1">
            <div class="row g-3 align-items-center">
                <div class="col-md-6">
                    <div class="filter-group">
                        <span class="filter-label"><i class="fas fa-filter me-1"></i> Filtres :</span>
                        <button class="filter-btn active" data-filter="all">
                            Toutes <span class="count"><?php echo $total_pharmacies; ?></span>
                        </button>
                        <button class="filter-btn" data-filter="stock">
                            Avec stock <span class="count"><?php echo $pharmacies_avec_stock; ?></span>
                        </button>
                    </div>
                </div>
                <div class="col-md-6">
                    <form class="search-box-map" id="searchForm">
                        <input type="text" class="form-control" id="searchInput" placeholder="Rechercher une pharmacie..." aria-label="Rechercher une pharmacie">
                        <button type="submit" class="btn-search">
                            <i class="fas fa-search"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ===== MAP & SIDEBAR ===== -->
        <div class="row g-4 animate-fade-up delay-2">
            <!-- Carte -->
            <div class="col-lg-8">
                <div style="position: relative;">
                    <div id="map"></div>
                    <div class="map-loading" id="mapLoading">
                        <div class="spinner"></div>
                        <span>Chargement de la carte...</span>
                    </div>
                    <div class="map-controls">
                        <button class="btn-map-control" id="recenterBtn" title="Recentrer sur ma position">
                            <i class="fas fa-crosshairs"></i>
                        </button>
                        <button class="btn-map-control" id="zoomInBtn" title="Zoom avant">
                            <i class="fas fa-plus"></i>
                        </button>
                        <button class="btn-map-control" id="zoomOutBtn" title="Zoom arrière">
                            <i class="fas fa-minus"></i>
                        </button>
                        <button class="btn-map-control" id="fullscreenBtn" title="Plein écran">
                            <i class="fas fa-expand"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Sidebar liste -->
            <div class="col-lg-4">
                <div class="pharmacy-list-sidebar" id="pharmacyList">
                    <?php if (!empty($pharmacies)): ?>
                        <?php foreach ($pharmacies as $index => $p): ?>
                            <div class="pharmacy-list-item <?php echo $index === 0 ? 'active' : ''; ?>" 
                                 data-id="<?php echo $p['Id_pharmacie']; ?>"
                                 data-lat="<?php echo $p['Latitude']; ?>"
                                 data-lng="<?php echo $p['Longitude']; ?>">
                                <div class="pharmacy-icon">
                                    <i class="fas fa-store"></i>
                                </div>
                                <div class="pharmacy-info">
                                    <div class="name"><?php echo htmlspecialchars($p['Nom']); ?></div>
                                    <div class="address">
                                        <i class="fas fa-map-marker-alt me-1" style="font-size: 0.6rem;"></i>
                                        <?php echo htmlspecialchars($p['Quartier'] ?? ''); ?>, 
                                        <?php echo htmlspecialchars($p['Commune'] ?? 'Porto-Novo'); ?>
                                    </div>
                                    <div class="med-count">
                                        <i class="fas fa-capsules me-1"></i>
                                        <?php echo $p['total_medicaments']; ?> médicaments
                                    </div>
                                </div>
                                <div class="pharmacy-distance">
                                    <i class="fas fa-route"></i>
                                    <span class="distance-value">---</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center p-4">
                            <i class="fas fa-store" style="font-size: 2rem; color: #ddd;"></i>
                            <p class="mt-2 text-muted">Aucune pharmacie disponible</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </main>

    </div>

    <!-- ===== SCRIPTS ===== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.js"></script>
    <script src="https://unpkg.com/leaflet-control-geocoder@1.13.0/dist/Control.Geocoder.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {

            // ===== SIDEBAR MOBILE TOGGLE (Ctrl + B) =====
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.key.toLowerCase() === 'b') {
                    document.getElementById('sidebar').classList.toggle('open');
                }
            });

            // ===== DONNÉES DES PHARMACIES =====
            const pharmaciesData = <?php echo json_encode($pharmacies); ?>;
            const defaultLat = <?php echo $default_lat; ?>;
            const defaultLng = <?php echo $default_lng; ?>;
            const BASE_URL = <?php echo json_encode(BASE_URL); ?>;

            // ===== INITIALISATION DE LA CARTE =====
            const map = L.map('map', {
                center: [defaultLat, defaultLng],
                zoom: 13,
                zoomControl: false
            });

            // ===== COUCHE DE TUILES =====
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                maxZoom: 19,
                minZoom: 10
            }).addTo(map);

            // ===== CACHER LE LOADING =====
            document.getElementById('mapLoading').style.display = 'none';

            // ===== MARQUEURS =====
            let markers = [];
            let userMarker = null;
            let userCircle = null;
            let routingControl = null;

            // Icône personnalisée pour les pharmacies
            const pharmacyIcon = L.divIcon({
                className: 'pharmacy-marker',
                html: '<div style="background: linear-gradient(135deg, rgb(42, 195, 42), hsl(25, 75%, 47%)); width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 16px; box-shadow: 0 4px 20px rgba(42,195,42,0.3); border: 2px solid white;"><i class="fas fa-store"></i></div>',
                iconSize: [36, 36],
                iconAnchor: [18, 18],
                popupAnchor: [0, -18]
            });

            const pharmacyIconSelected = L.divIcon({
                className: 'pharmacy-marker-selected',
                html: '<div style="background: linear-gradient(135deg, hsl(25, 75%, 47%), rgb(42, 195, 42)); width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; box-shadow: 0 4px 30px rgba(200,120,30,0.4); border: 3px solid white; animation: pulse 2s infinite;"><i class="fas fa-store"></i></div>',
                iconSize: [44, 44],
                iconAnchor: [22, 22],
                popupAnchor: [0, -22]
            });

            // Icône utilisateur
            const userIcon = L.divIcon({
                className: 'user-marker',
                html: '<div style="background: #3498db; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 16px; box-shadow: 0 4px 20px rgba(52,152,219,0.4); border: 2px solid white;"><i class="fas fa-user"></i></div>',
                iconSize: [32, 32],
                iconAnchor: [16, 16]
            });

            // ===== AJOUT DES MARQUEURS =====
            function addPharmaciesToMap(pharmacies) {
                // Supprimer les anciens marqueurs
                markers.forEach(m => map.removeLayer(m));
                markers = [];

                pharmacies.forEach((pharmacy, index) => {
                    const lat = parseFloat(pharmacy.Latitude);
                    const lng = parseFloat(pharmacy.Longitude);
                    
                    if (isNaN(lat) || isNaN(lng)) return;

                    // Sélectionner la pharmacie demandée via ?id= sinon la première
                    const urlParams = new URLSearchParams(window.location.search);
                    const requestedId = urlParams.get('id');
                    const isSelected = requestedId
                        ? String(pharmacy.Id_pharmacie) === requestedId
                        : index === 0;
                    const icon = isSelected ? pharmacyIconSelected : pharmacyIcon;

                    const popupContent = `
                        <div class="pharmacy-popup">
                            <div class="name"><i class="fas fa-store" style="color: rgb(42, 195, 42);"></i> ${pharmacy.Nom}</div>
                            <div class="address">
                                <i class="fas fa-map-marker-alt"></i> ${pharmacy.Quartier || ''}, ${pharmacy.Commune || 'Porto-Novo'}
                            </div>
                            <div class="details">
                                <div><i class="fas fa-phone"></i> ${pharmacy.Telephone || 'Non disponible'}</div>
                                <div><i class="fas fa-clock"></i> ${pharmacy.Horaire || 'Horaires non renseignés'}</div>
                                <div><i class="fas fa-capsules"></i> ${pharmacy.total_medicaments || 0} médicaments disponibles</div>
                                <div><i class="fas fa-user"></i> ${pharmacy.Pharmacien_Prenom || ''} ${pharmacy.Pharmacien_Nom || ''}</div>
                            </div>
                            <div class="d-flex gap-2 mt-2 flex-wrap">
                                <a href="${BASE_URL}pages/patient/recherche.php?pharmacie=${pharmacy.Id_pharmacie}" class="btn-popup">
                                    <i class="fas fa-search"></i> Voir les médicaments
                                </a>
                                <a href="https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}" target="_blank" class="btn-popup" style="background: #3498db;">
                                    <i class="fas fa-directions"></i> Itinéraire
                                </a>
                            </div>
                        </div>
                    `;

                    const marker = L.marker([lat, lng], { icon: icon })
                        .bindPopup(popupContent, { maxWidth: 320, className: 'custom-popup' })
                        .addTo(map);

                    // Ajouter l'événement de survol
                    marker.on('mouseover', function() {
                        this.openPopup();
                    });

                    markers.push(marker);

                    // Si c'est le premier, ouvrir le popup
                    if (isSelected) {
                        setTimeout(() => marker.openPopup(), 500);
                    }
                });

                // Ajuster la vue pour afficher tous les marqueurs
                if (markers.length > 0) {
                    const group = L.featureGroup(markers);
                    map.fitBounds(group.getBounds(), { padding: [50, 50] });
                }
            }

            // ===== AJOUT DES MARQUEURS =====
            addPharmaciesToMap(pharmaciesData);

            // ===== GÉOLOCALISATION =====
            function getUserLocation() {
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(
                        function(position) {
                            const lat = position.coords.latitude;
                            const lng = position.coords.longitude;

                            // Supprimer l'ancien marqueur
                            if (userMarker) {
                                map.removeLayer(userMarker);
                            }
                            if (userCircle) {
                                map.removeLayer(userCircle);
                            }

                            // Ajouter le marqueur utilisateur
                            userMarker = L.marker([lat, lng], { icon: userIcon })
                                .bindPopup('📍 Votre position')
                                .addTo(map);

                            // Cercle de précision
                            userCircle = L.circle([lat, lng], {
                                radius: 100,
                                color: '#3498db',
                                fillColor: '#3498db',
                                fillOpacity: 0.1,
                                weight: 2
                            }).addTo(map);

                            // Calculer les distances
                            calculateDistances(lat, lng);

                            // Recentrer
                            map.setView([lat, lng], 14);
                        },
                        function(error) {
                            console.warn('Géolocalisation non disponible:', error);
                            // Utiliser la position par défaut
                            calculateDistances(defaultLat, defaultLng);
                        }
                    );
                } else {
                    calculateDistances(defaultLat, defaultLng);
                }
            }

            // ===== CALCUL DES DISTANCES =====
            function calculateDistances(userLat, userLng) {
                const distanceElements = document.querySelectorAll('.distance-value');
                const listItems = document.querySelectorAll('.pharmacy-list-item');

                listItems.forEach((item, index) => {
                    const lat = parseFloat(item.dataset.lat);
                    const lng = parseFloat(item.dataset.lng);
                    
                    if (!isNaN(lat) && !isNaN(lng)) {
                        const distance = getDistance(userLat, userLng, lat, lng);
                        const distanceEl = distanceElements[index];
                        if (distanceEl) {
                            if (distance < 1) {
                                distanceEl.textContent = Math.round(distance * 1000) + ' m';
                            } else {
                                distanceEl.textContent = distance.toFixed(2) + ' km';
                            }
                        }
                    }
                });

                // Trier la liste par distance
                const list = document.getElementById('pharmacyList');
                const items = Array.from(list.querySelectorAll('.pharmacy-list-item'));
                items.sort((a, b) => {
                    const distA = parseFloat(a.querySelector('.distance-value')?.textContent || '999');
                    const distB = parseFloat(b.querySelector('.distance-value')?.textContent || '999');
                    return distA - distB;
                });
                items.forEach(item => list.appendChild(item));
            }

            // ===== FORMULE DE DISTANCE =====
            function getDistance(lat1, lon1, lat2, lon2) {
                const R = 6371; // Rayon de la Terre en km
                const dLat = deg2rad(lat2 - lat1);
                const dLon = deg2rad(lon2 - lon1);
                const a = 
                    Math.sin(dLat/2) * Math.sin(dLat/2) +
                    Math.cos(deg2rad(lat1)) * Math.cos(deg2rad(lat2)) * 
                    Math.sin(dLon/2) * Math.sin(dLon/2);
                const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
                return R * c;
            }

            function deg2rad(deg) {
                return deg * (Math.PI/180);
            }

            // ===== INTERACTION AVEC LA LISTE =====
            document.querySelectorAll('.pharmacy-list-item').forEach(item => {
                item.addEventListener('click', function() {
                    // Retirer la classe active de tous
                    document.querySelectorAll('.pharmacy-list-item').forEach(el => {
                        el.classList.remove('active');
                    });
                    this.classList.add('active');

                    const lat = parseFloat(this.dataset.lat);
                    const lng = parseFloat(this.dataset.lng);
                    
                    if (!isNaN(lat) && !isNaN(lng)) {
                        map.setView([lat, lng], 16);
                        
                        // Trouver le marqueur correspondant
                        markers.forEach(marker => {
                            const pos = marker.getLatLng();
                            if (Math.abs(pos.lat - lat) < 0.0001 && Math.abs(pos.lng - lng) < 0.0001) {
                                marker.openPopup();
                            }
                        });
                    }
                });
            });

            // ===== FILTRES =====
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');

                    const filter = this.dataset.filter;
                    const items = document.querySelectorAll('.pharmacy-list-item');
                    
                    items.forEach(item => {
                        const id = parseInt(item.dataset.id);
                        const pharmacy = pharmaciesData.find(p => p.Id_pharmacie === id);
                        
                        if (filter === 'all') {
                            item.style.display = 'flex';
                        } else if (filter === 'stock') {
                            item.style.display = (pharmacy && pharmacy.total_medicaments > 0) ? 'flex' : 'none';
                        }
                    });
                });
            });

            // ===== RECHERCHE =====
            document.getElementById('searchForm').addEventListener('submit', function(e) {
                e.preventDefault();
                const searchTerm = document.getElementById('searchInput').value.toLowerCase().trim();
                
                if (searchTerm === '') {
                    document.querySelectorAll('.pharmacy-list-item').forEach(item => {
                        item.style.display = 'flex';
                    });
                    return;
                }

                document.querySelectorAll('.pharmacy-list-item').forEach(item => {
                    const name = item.querySelector('.name')?.textContent?.toLowerCase() || '';
                    const address = item.querySelector('.address')?.textContent?.toLowerCase() || '';
                    
                    if (name.includes(searchTerm) || address.includes(searchTerm)) {
                        item.style.display = 'flex';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });

            // ===== CONTROLES DE LA CARTE =====
            // Recentrer
            document.getElementById('recenterBtn').addEventListener('click', getUserLocation);

            // Zoom
            document.getElementById('zoomInBtn').addEventListener('click', () => map.zoomIn());
            document.getElementById('zoomOutBtn').addEventListener('click', () => map.zoomOut());

            // Plein écran
            document.getElementById('fullscreenBtn').addEventListener('click', function() {
                const container = document.getElementById('map').parentElement;
                if (!document.fullscreenElement) {
                    container.requestFullscreen().catch(err => {});
                } else {
                    document.exitFullscreen();
                }
            });

            // ===== RACCOURCIS CLAVIER =====
            document.addEventListener('keydown', function(e) {
                // Ctrl + R pour recentrer
                if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                    e.preventDefault();
                    getUserLocation();
                }
                // Ctrl + F pour focus recherche
                if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                    e.preventDefault();
                    document.getElementById('searchInput').focus();
                }
                // Escape pour fermer le popup
                if (e.key === 'Escape') {
                    map.closePopup();
                }
            });

            // ===== LANCER LA GÉOLOCALISATION =====
            getUserLocation();

            // ===== CONSOLE LOG =====
            console.log('%c PharmaGeo - Carte des pharmacies ', 'background: linear-gradient(135deg, rgb(42, 195, 42), hsl(25, 75%, 47%)); color: white; font-size: 16px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');
            console.log('%c Localisez les pharmacies à Porto-Novo.', 'color: #666; font-size: 14px;');
            console.log('%c Raccourcis : Ctrl+R (Recentrer) | Ctrl+F (Recherche)', 'color: #888; font-size: 12px;');

        });
    </script>

</body>
    <script src="../../assets/js/responsive.js"></script>
</html>