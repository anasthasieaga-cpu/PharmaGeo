<?php
// ============================================================
// PharmaGeo — Espace patient : Carte des pharmacies
// Affiche la carte Leaflet avec les pharmacies, filtres et géolocalisation.
// ============================================================

// ===== GÉOLOCALISATION DE LA PERSONNE =====
// La carte est centrée sur l'endroit où la personne se trouve réellement
// et les distances de la liste latérale en sont calculées (voir includes/geo.php).
$geo_reload = true;

require_once '../../includes/config.php';
require_once '../../includes/session.php';
require_once '../../includes/geo.php';

$position = getPositionUtilisateur($pdo);

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
        WHERE m.Statut = 'actif' AND s.Quantite > 0 AND s.Statut = 'disponible'
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

// ===== POINT DE CENTRAGE DE LA CARTE =====
// Point de vue initial : la position connue de la personne si elle existe,
// sinon le centre de Porto-Novo.
$default_lat = $position['lat'];
$default_lng = $position['lng'];

// Une distance n'est publiée que si la position est réellement exploitable
// (GPS frais et assez précis). Sinon la carte reste centrée sur Porto-Novo
// et aucune distance n'est affichée.
$geo_precise = geoPositionEstPrecise($position);

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
        .nav-item.block { display: block; color: rgba(255,255,255,0.75); padding: 11px 0; text-align: center; }
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
            box-shadow: 0 12px 40px rgba(22,163,74,0.35);
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
            border: 2px solid #D9E9DF;
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
            border: 2px solid #D9E9DF;
            transition: var(--transition);
            flex: 1;
            min-width: 200px;
            max-width: 400px;
        }

        .search-box-map { position: relative; }
        .search-suggest {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            background: var(--white);
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            border: 1px solid #D9E9DF;
            max-height: 320px;
            overflow-y: auto;
            z-index: 1500;
        }
        .search-suggest.open { display: block; }
        .search-suggest .sg-item {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            padding: 10px 14px;
            cursor: pointer;
            font-size: 0.85rem;
        }
        .search-suggest .sg-item:hover,
        .search-suggest .sg-item.active { background: #F0FDF4; }
        .search-suggest .sg-item i { color: #16A34A; margin-top: 3px; }
        .search-suggest .sg-item small { display: block; color: #6B7280; }
        .search-suggest .sg-vide { padding: 12px 14px; color: #6B7280; font-size: 0.85rem; }

        .search-box-map:focus-within {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 4px rgba(22,163,74,0.1);
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
            border-bottom: 1px solid #F0FDF4;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .pharmacy-list-item:hover {
            background: rgba(22,163,74,0.04);
        }

        .pharmacy-list-item:last-child {
            border-bottom: none;
        }

        .pharmacy-list-item .pharmacy-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: rgba(22,163,74,0.1);
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
            background: rgba(22,163,74,0.08);
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
            background: rgba(22,163,74,0.2);
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
            border: 3px solid #D9E9DF;
            border-top-color: var(--primary-green);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
    <link rel="stylesheet" href="../../assets/css/responsive.css">
    <?php require_once '../../includes/geo_style.php'; ?>    <!-- Palette verte PharmaGeo -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/palette.css">
</head>
<body>

    <div class="layout">

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar-admin" id="sidebar">
        <a href="<?php echo BASE_URL; ?>index.php" class="brand-admin" style="text-decoration: none;">
            <div class="brand-icon"><i class="fas fa-hospital"></i></div>
            <div><h4>PharmaGeo</h4><small>Patient</small></div>
        </a>
        <nav class="nav-menu">
            <div class="nav-label">Navigation</div>
            <a href="<?php echo BASE_URL; ?>index.php" class="nav-item"><i class="fas fa-home"></i><span>Accueil</span></a>
            <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="nav-item"><i class="fas fa-search"></i><span>Rechercher</span></a>
            <a href="<?php echo BASE_URL; ?>pages/patient/carte.php" class="nav-item active"><i class="fas fa-map-marker-alt"></i><span>Carte</span></a>
            <?php if ($est_connecte): ?>
                <a href="<?php echo BASE_URL; ?>pages/patient/dashboard.php" class="nav-item"><i class="fas fa-tachometer-alt"></i><span>Tableau de bord</span></a>
                <a href="<?php echo BASE_URL; ?>pages/patient/historique.php" class="nav-item"><i class="fas fa-history"></i><span>Historique</span></a>
                <a href="<?php echo BASE_URL; ?>pages/patient/profil.php" class="nav-item"><i class="fas fa-user"></i><span>Profil</span></a>
                <div class="nav-label" style="margin-top: 20px;">Système</div>
                <a href="<?php echo BASE_URL; ?>deconnexion.php" class="nav-item" style="color: #FEF2F2; margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.15); margin-bottom: 12px;"><i class="fas fa-sign-out-alt"></i><span>Déconnexion</span></a>
            <?php else: ?>
                <div class="nav-label" style="margin-top: 20px;">Système</div>
                <a href="<?php echo BASE_URL; ?>inscription.php" class="nav-item"><i class="fas fa-user-plus"></i><span>S'inscrire</span></a>
                <a href="<?php echo BASE_URL; ?>connexion.php" class="nav-item" style="color: rgba(255,255,255,0.4);"><i class="fas fa-sign-in-alt"></i><span>Se connecter</span></a>
            <?php endif; ?>
        </nav>
<?php if ($est_connecte): ?>
            <a class="user-profile" href="<?php echo BASE_URL . lienProfil(); ?>" title="Voir mon profil">
                <div class="avatar"><?php echo strtoupper(substr(($_SESSION['prenom'] ?? 'A'), 0, 1) . substr(($_SESSION['nom'] ?? 'D'), 0, 1)); ?></div>
                <div>
                    <div class="name"><?php echo htmlspecialchars(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')); ?></div>
                    <div class="role"><i class="fas fa-user me-1" style="color:#DC2626;"></i> Patient</div>
                </div>
                <i class="fas fa-chevron-right profile-chevron"></i>
            </a>
        <?php else: ?>
            <div class="user-profile">
                <div class="avatar"><?php echo strtoupper(substr(($_SESSION['prenom'] ?? 'A'), 0, 1) . substr(($_SESSION['nom'] ?? 'D'), 0, 1)); ?></div>
                <div>
                    <div class="name"><?php echo htmlspecialchars(($est_connecte ? (($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')) : 'Visiteur')); ?></div>
                    <div class="role"><i class="fas fa-user me-1" style="color:#DC2626;"></i> <?php echo $est_connecte ? 'Patient' : 'Invité'; ?></div>
                </div>
            </div>
        <?php endif; ?>
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
                <a href="<?php echo BASE_URL; ?>index.php" class="btn-action btn-outline-custom">
                    <i class="fas fa-home"></i> Accueil
                </a>
                <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="btn-action btn-primary-custom">
                    <i class="fas fa-search"></i> Rechercher
                </a>
            </div>
        </div>

        <?php echo geoAfficherPosition($position); ?>
        <?php echo geoBandeauPosition($position); ?>

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
                    <form class="search-box-map" id="searchForm" autocomplete="off">
                        <input type="text" class="form-control" id="searchInput" placeholder="Rechercher une pharmacie, un quartier, une rue..." aria-label="Rechercher une pharmacie ou un lieu" role="combobox" aria-expanded="false" aria-controls="searchSuggest">
                        <button type="submit" class="btn-search">
                            <i class="fas fa-search"></i>
                        </button>
                        <div class="search-suggest" id="searchSuggest" role="listbox"></div>
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
                        <?php
                        // Distance de chaque pharmacie depuis la position connue
                        // de la personne, calculée côté serveur. Elle est
                        // réinjectée dans data-distance pour que le tri et
                        // l'affichage soient corrects même sans JavaScript.
                        // Sans position GPS exploitable, la distance vaut null :
                        // les pharmacies sont alors classées par nom et aucune
                        // distance n'est publiée.
                        $pharmacies_triees = [];
                        foreach ($pharmacies as $p) {
                            $p['distance_metres'] = geoDistanceAffichee(
                                $position,
                                $p['Latitude'],
                                $p['Longitude']
                            );
                            $pharmacies_triees[] = $p;
                        }
                        if ($geo_precise) {
                            usort($pharmacies_triees, function ($a, $b) {
                                return $a['distance_metres'] <=> $b['distance_metres'];
                            });
                        } else {
                            usort($pharmacies_triees, function ($a, $b) {
                                return strcmp($a['Nom'], $b['Nom']);
                            });
                        }
                        ?>
                        <?php foreach ($pharmacies_triees as $index => $p): ?>
                            <?php $distance_affichee = geoFormaterDistance($p['distance_metres']); ?>
                            <div class="pharmacy-list-item <?php echo $index === 0 ? 'active' : ''; ?>" 
                                 data-id="<?php echo $p['Id_pharmacie']; ?>"
                                 data-lat="<?php echo $p['Latitude']; ?>"
                                 data-lng="<?php echo $p['Longitude']; ?>"
                                 data-distance="<?php echo $p['distance_metres'] !== null ? round($p['distance_metres'], 1) : ''; ?>">
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
                                    <i class="fas <?php echo $distance_affichee !== null ? 'fa-route' : 'fa-location-arrow'; ?>"></i>
                                    <?php if ($distance_affichee !== null): ?>
                                        <span class="distance-value" title="Distance à vol d'oiseau (ligne droite, non routière)"><?php echo $distance_affichee; ?></span>
                                    <?php else: ?>
                                        <span class="distance-value" title="Activez la localisation pour connaître la distance">
                                            —&nbsp;act.?
                                        </span>
                                    <?php endif; ?>
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
            // Point de vue initial : position connue de la personne, ou
            // centre de Porto-Novo en dernier ressort.
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
                html: '<div style="background: linear-gradient(135deg, #16A34A, #166534); width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 16px; box-shadow: 0 4px 20px rgba(22,163,74,0.3); border: 2px solid white;"><i class="fas fa-store"></i></div>',
                iconSize: [36, 36],
                iconAnchor: [18, 18],
                popupAnchor: [0, -18]
            });

            const pharmacyIconSelected = L.divIcon({
                className: 'pharmacy-marker-selected',
                html: '<div style="background: linear-gradient(135deg, #166534, #16A34A); width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; box-shadow: 0 4px 30px rgba(21,128,61,0.4); border: 3px solid white; animation: pulse 2s infinite;"><i class="fas fa-store"></i></div>',
                iconSize: [44, 44],
                iconAnchor: [22, 22],
                popupAnchor: [0, -22]
            });

            // Icône utilisateur
            const userIcon = L.divIcon({
                className: 'user-marker',
                html: '<div style="background: #166534; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 16px; box-shadow: 0 4px 20px rgba(22,83,52,0.4); border: 2px solid white;"><i class="fas fa-user"></i></div>',
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
                            <div class="name"><i class="fas fa-store" style="color: #16A34A;"></i> ${pharmacy.Nom}</div>
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
                                <a href="${BASE_URL}pages/patient/pharmacie.php?id=${pharmacy.Id_pharmacie}" class="btn-popup">
                                    <i class="fas fa-search"></i> Voir les médicaments
                                </a>
                                <a href="https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}" target="_blank" class="btn-popup" style="background: #166534;">
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

                    marker._pharmacy = pharmacy;
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
            function afficherPositionUtilisateur(lat, lng, precision) {
                // Supprimer l'ancienne position
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
                    radius: (typeof precision === 'number' && precision > 0) ? precision : 100,
                    color: '#166534',
                    fillColor: '#166534',
                    fillOpacity: 0.1,
                    weight: 2
                }).addTo(map);
            }

            // La position réellement connue (session précédente) ne sert au
            // marqueur QUE si des coordonnées réelles ont été obtenues. On ne
            // place jamais le marqueur « Votre position » sur le centre par
            // défaut : cela ferait croire à une position GPS inventée.
            function afficherPositionConnue() {
                const geo = window.PHARMA_GEO;
                if (!geo || geo.precise !== true) { return; }

                const lat = parseFloat(geo.lat);
                const lng = parseFloat(geo.lng);
                if (isNaN(lat) || isNaN(lng)) { return; }

                afficherPositionUtilisateur(lat, lng, geo.precision);
            }

            // Une position fraîchement fournie par le navigateur alimente
            // toujours le calcul : une mesure peu précise (Wi-Fi) reste bien
            // plus utile qu'un « distance inconnue ».
            function appliquerPosition(lat, lng, accuracy) {
                afficherPositionUtilisateur(lat, lng, accuracy);
                calculateDistances(lat, lng);
                map.setView([lat, lng], 14);
            }

            function getUserLocation() {
                if (!navigator.geolocation) {
                    // Pas de géolocalisation navigateur : on se contente de
                    // la position déjà connue, sans rien inventer.
                    afficherPositionConnue();
                    return;
                }

                // Haute précision d'abord (GPS matériel) ; en cas d'échec ou
                // d'expiration (fréquent sur ordinateur) on retente en
                // précision normale (position réseau), largement suffisante.
                const demander = function (options) {
                    navigator.geolocation.getCurrentPosition(
                        function(position) {
                            const lat = position.coords.latitude;
                            const lng = position.coords.longitude;
                            const accuracy = position.coords && position.coords.accuracy;
                            appliquerPosition(lat, lng, accuracy);
                        },
                        function(error) {
                            if (options.enableHighAccuracy) {
                                demander({ enableHighAccuracy: false, timeout: 15000, maximumAge: 0 });
                                return;
                            }
                            // Permission refusée ou GPS indisponible : on ne
                            // fabrique aucune distance depuis un point par
                            // défaut.
                            console.warn('Géolocalisation non disponible:', error);
                            afficherPositionConnue();
                        },
                        options
                    );
                };

                demander({ enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
            }

            // ===== AFFICHAGE DES DISTANCES =====
            // Mêmes règles que geoFormaterDistance() côté PHP, pour que la
            // valeur affichée ne change pas après un rechargement.
            function formaterDistance(metres) {
                if (metres == null || isNaN(metres)) return null;
                if (metres < 1000) {
                    if (metres < 10) return Math.round(metres) + ' m';
                    return (Math.round(metres / 10) * 10) + ' m';
                }
                return (Math.round(metres / 10) / 100).toFixed(2).replace('.', ',') + ' km';
            }

            function calculateDistances(userLat, userLng) {
                const listItems = document.querySelectorAll('.pharmacy-list-item');

                listItems.forEach(function(item) {
                    const lat = parseFloat(item.dataset.lat);
                    const lng = parseFloat(item.dataset.lng);
                    if (isNaN(lat) || isNaN(lng)) return;

                    const metres = getDistanceMetres(userLat, userLng, lat, lng);
                    item.dataset.distance = metres.toFixed(1);

                    const distanceEl = item.querySelector('.distance-value');
                    if (distanceEl) {
                        const texte = formaterDistance(metres);
                        distanceEl.textContent = texte;
                        distanceEl.title = "Distance à vol d'oiseau (ligne droite, non routière)";
                        const icone = distanceEl.previousElementSibling;
                        if (icone) icone.className = 'fas fa-route';
                    }
                });

                const list = document.getElementById('pharmacyList');
                const items = Array.from(list.querySelectorAll('.pharmacy-list-item'));
                items.sort((a, b) => {
                    const distA = parseFloat(a.dataset.distance);
                    const distB = parseFloat(b.dataset.distance);
                    if (isNaN(distA)) return 1;
                    if (isNaN(distB)) return -1;
                    return distA - distB;
                });
                items.forEach(item => list.appendChild(item));
            }

            // ===== MESURE DE DISTANCE =====
            // Haversine, en mètres. La version PHP (geoDistanceMetres) est la
            // référence : même formule, même rayon terrestre.
            function getDistanceMetres(lat1, lon1, lat2, lon2) {
                const R = 6371008.8;
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

            // ===== RECHERCHE (suggestions comme sur Google Maps) =====
            // Tape-et-trouve : les pharmacies correspondantes sont proposées
            // pendant la saisie ; un choix centre la carte et ouvre sa fiche.
            // Si aucune pharmacie ne correspond, la recherche porte sur un
            // lieu (quartier, rue) via OpenStreetMap, limité au Bénin.
            const searchInput = document.getElementById('searchInput');
            const searchSuggest = document.getElementById('searchSuggest');
            let suggestions = [];
            let suggestIndex = -1;
            let lieuMarker = null;

            function normaliser(t) {
                return (t || '').toString().toLowerCase()
                    .normalize('NFD').replace(/[̀-ͯ]/g, '').trim();
            }
            function echapper(t) {
                return (t || '').toString().replace(/[&<>"']/g, c => (
                    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
            }

            function pharmaciesCorrespondantes(terme) {
                const t = normaliser(terme);
                if (t === '') return [];
                return pharmaciesData.filter(p => {
                    const texte = normaliser([p.Nom, p.Quartier, p.Commune, p.Adresse].join(' '));
                    return t.split(/\s+/).every(mot => texte.includes(mot));
                });
            }

            // La liste latérale suit la saisie
            function filtrerListe(terme) {
                const ids = new Set(pharmaciesCorrespondantes(terme).map(p => String(p.Id_pharmacie)));
                const tout = normaliser(terme) === '';
                document.querySelectorAll('.pharmacy-list-item').forEach(item => {
                    item.style.display = (tout || ids.has(String(item.dataset.id))) ? 'flex' : 'none';
                });
            }

            function fermerSuggestions() {
                searchSuggest.classList.remove('open');
                searchSuggest.innerHTML = '';
                searchInput.setAttribute('aria-expanded', 'false');
                suggestIndex = -1;
            }

            function ouvrirPharmacie(pharmacy) {
                const lat = parseFloat(pharmacy.Latitude);
                const lng = parseFloat(pharmacy.Longitude);
                if (isNaN(lat) || isNaN(lng)) return;
                searchInput.value = pharmacy.Nom;
                filtrerListe(pharmacy.Nom);
                fermerSuggestions();
                map.setView([lat, lng], 17);
                const marker = markers.find(m => m._pharmacy && m._pharmacy.Id_pharmacie === pharmacy.Id_pharmacie);
                if (marker) marker.openPopup();
            }

            function allerAuLieu(lieu) {
                fermerSuggestions();
                if (lieuMarker) map.removeLayer(lieuMarker);
                map.setView(lieu.center, 16);
                lieuMarker = L.marker(lieu.center).addTo(map)
                    .bindPopup(echapper(lieu.name)).openPopup();
            }

            function afficherSuggestions(terme) {
                const trouvees = pharmaciesCorrespondantes(terme).slice(0, 6);
                suggestions = trouvees.map(p => ({ type: 'pharmacie', pharmacy: p }));
                if (normaliser(terme).length >= 2) suggestions.push({ type: 'lieu', terme: terme.trim() });
                suggestIndex = -1;

                if (suggestions.length === 0) { fermerSuggestions(); return; }

                searchSuggest.innerHTML = suggestions.map((sg, i) => {
                    if (sg.type === 'pharmacie') {
                        const p = sg.pharmacy;
                        return '<div class="sg-item" role="option" data-i="' + i + '"><i class="fas fa-store"></i>' +
                            '<span>' + echapper(p.Nom) + '<small>' + echapper([p.Quartier, p.Commune].filter(Boolean).join(', ')) + '</small></span></div>';
                    }
                    return '<div class="sg-item" role="option" data-i="' + i + '"><i class="fas fa-location-dot"></i>' +
                        '<span>Chercher le lieu « ' + echapper(sg.terme) + ' »<small>Quartier, rue, repère (Bénin)</small></span></div>';
                }).join('');
                searchSuggest.classList.add('open');
                searchInput.setAttribute('aria-expanded', 'true');
            }

            function choisir(sg) {
                if (!sg) return;
                if (sg.type === 'pharmacie') { ouvrirPharmacie(sg.pharmacy); return; }

                searchSuggest.innerHTML = '<div class="sg-vide">Recherche du lieu...</div>';
                L.Control.Geocoder.nominatim({
                    geocodingQueryParams: { countrycodes: 'bj', limit: 1 }
                }).geocode(sg.terme, function (resultats) {
                    if (resultats && resultats.length > 0) {
                        allerAuLieu(resultats[0]);
                    } else {
                        searchSuggest.innerHTML = '<div class="sg-vide">Aucun lieu trouvé pour « ' + echapper(sg.terme) + ' ».</div>';
                    }
                });
            }

            searchInput.addEventListener('input', function() {
                filtrerListe(this.value);
                afficherSuggestions(this.value);
            });

            searchInput.addEventListener('keydown', function(e) {
                const items = searchSuggest.querySelectorAll('.sg-item');
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    if (items.length === 0) return;
                    e.preventDefault();
                    suggestIndex = (suggestIndex + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
                    items.forEach((el, i) => el.classList.toggle('active', i === suggestIndex));
                } else if (e.key === 'Escape') {
                    fermerSuggestions();
                }
            });

            searchSuggest.addEventListener('mousedown', function(e) {
                const item = e.target.closest('.sg-item');
                if (!item) return;
                e.preventDefault();
                choisir(suggestions[parseInt(item.dataset.i)]);
            });

            document.addEventListener('click', function(e) {
                if (!e.target.closest('#searchForm')) fermerSuggestions();
            });

            // Entrée : choix en surbrillance, sinon première pharmacie, sinon le lieu
            document.getElementById('searchForm').addEventListener('submit', function(e) {
                e.preventDefault();
                if (normaliser(searchInput.value) === '') { filtrerListe(''); fermerSuggestions(); return; }
                afficherSuggestions(searchInput.value);
                choisir(suggestions[suggestIndex >= 0 ? suggestIndex : 0]);
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
            console.log('%c PharmaGeo - Carte des pharmacies ', 'background: linear-gradient(135deg, #16A34A, #166534); color: white; font-size: 16px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');
            console.log('%c Localisez les pharmacies à Porto-Novo.', 'color: #666; font-size: 14px;');
            console.log('%c Raccourcis : Ctrl+R (Recentrer) | Ctrl+F (Recherche)', 'color: #888; font-size: 12px;');

        });
    </script>

    <!-- ===== GÉOLOCALISATION DE LA PERSONNE ===== -->
    <script>
        window.BASE_URL = <?php echo json_encode(BASE_URL); ?>;
        window.PHARMA_GEO = <?php echo geoConfigJS($position); ?>;
        window.PHARMA_GEO.endpoint = <?php echo json_encode(BASE_URL . 'api/geoloc_api.php'); ?>;
        window.PHARMA_GEO.reload = true;
    </script>
    <script src="<?php echo BASE_URL; ?>assets/js/position.js"></script>

</body>
    <script src="../../assets/js/responsive.js"></script>
</html>