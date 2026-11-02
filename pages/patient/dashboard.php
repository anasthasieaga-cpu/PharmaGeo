<?php
// ============================================================
// PharmaGeo — Espace patient : Tableau de bord
// Affiche les statistiques, dernières réservations et pharmacies à proximité.
// ============================================================

// ===== GÉOLOCALISATION DE LA PERSONNE =====
// Les distances sont calculées depuis l'endroit où la personne se trouve
// réellement (voir includes/geo.php).
$geo_reload = true;

require_once '../../includes/config.php';
require_once '../../includes/session.php';
require_once '../../includes/geo.php';

$position = getPositionUtilisateur($pdo);

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

// ===== STATISTIQUES PATIENT =====
try {
    // Nombre de recherches effectuées
    $recherches = $pdo->prepare("SELECT COUNT(*) as total FROM recherche WHERE Id_utilisateur = :id");
    $recherches->execute([':id' => $_SESSION['user_id']]);
    $total_recherches = $recherches->fetch()['total'] ?? 0;

    // Nombre de réservations
    $reservations_total = $pdo->prepare("SELECT COUNT(*) as total FROM reservation WHERE Id_utilisateur = :id");
    $reservations_total->execute([':id' => $_SESSION['user_id']]);
    $total_reservations = $reservations_total->fetch()['total'] ?? 0;

    // Réservations en attente
    $reservations_attente = $pdo->prepare("SELECT COUNT(*) as total FROM reservation WHERE Id_utilisateur = :id AND Statut = 'en_attente'");
    $reservations_attente->execute([':id' => $_SESSION['user_id']]);
    $total_attente = $reservations_attente->fetch()['total'] ?? 0;

    // Réservations confirmées
    $reservations_confirmees = $pdo->prepare("SELECT COUNT(*) as total FROM reservation WHERE Id_utilisateur = :id AND Statut = 'confirmee'");
    $reservations_confirmees->execute([':id' => $_SESSION['user_id']]);
    $total_confirmees = $reservations_confirmees->fetch()['total'] ?? 0;

    // Dernières réservations
    $dernieres_reservations = $pdo->prepare("
        SELECT r.*, m.Nom_commercial, m.DCI, p.Nom as Pharmacie, p.Adresse, p.Telephone, p.Quartier, s.Prix as prix_unitaire
        FROM reservation r
        JOIN stock s ON r.Id_stock = s.Id_stock
        JOIN medicament m ON s.Id_medicament = m.Id_medicament
        JOIN pharmacie p ON s.Id_pharmacie = p.Id_pharmacie
        WHERE r.Id_utilisateur = :id
        ORDER BY r.Date_reservation DESC
        LIMIT 5
    ");
    $dernieres_reservations->execute([':id' => $_SESSION['user_id']]);
    $reservations = $dernieres_reservations->fetchAll();

    // Dernières recherches
    $dernieres_recherches = $pdo->prepare("
        SELECT * FROM recherche 
        WHERE Id_utilisateur = :id 
        ORDER BY Date_recherche DESC 
        LIMIT 5
    ");
    $dernieres_recherches->execute([':id' => $_SESSION['user_id']]);
    $recherches_liste = $dernieres_recherches->fetchAll();

    // Médicaments populaires (pour suggestions)
    $medicaments_populaires = $pdo->query("
        SELECT m.Nom_commercial, m.DCI, COUNT(r.Id_recherche) as total
        FROM medicament m
        JOIN recherche r ON r.Mot_recherche LIKE CONCAT('%', m.Nom_commercial, '%')
        WHERE m.Statut = 'actif'
        GROUP BY m.Id_medicament
        ORDER BY total DESC
        LIMIT 6
    ")->fetchAll();

    // Pharmacies à proximité : triées par distance depuis la position de la
// personne (et non par ordre d'insertion). Sans position GPS exploitable,
// on ne trie pas : proposer un « plus proche » serait faux.
$geo_precise = geoPositionEstPrecise($position);

$stmt_pharmacies_proches = $pdo->prepare("
        SELECT Id_pharmacie, Nom, Quartier, Commune, Telephone, Latitude, Longitude,
               " . ($geo_precise
                   ? "(6371 * ACOS(LEAST(1, GREATEST(-1,
                      COS(RADIANS(:lat)) * COS(RADIANS(Latitude)) *
                      COS(RADIANS(Longitude) - RADIANS(:lng)) +
                      SIN(RADIANS(:lat)) * SIN(RADIANS(Latitude))
                      )))) as Distance"
                   : 'NULL as Distance') . "
        FROM pharmacie
        WHERE Statut = 'active'
        ORDER BY " . ($geo_precise ? 'Distance ASC' : 'Nom ASC') . "
        LIMIT 4
    ");
    $params_pharm = [];
    if ($geo_precise) {
        $params_pharm[':lat'] = $position['lat'];
        $params_pharm[':lng'] = $position['lng'];
    }
    $stmt_pharmacies_proches->execute($params_pharm);
    $pharmacies_proches = $stmt_pharmacies_proches->fetchAll();

    foreach ($pharmacies_proches as $k => $ph) {
        $pharmacies_proches[$k]['distance_metres'] = $geo_precise
            ? geoDistanceMetres($position['lat'], $position['lng'], $ph['Latitude'], $ph['Longitude'])
            : null;
    }

} catch(PDOException $e) {
    $total_recherches = 0;
    $total_reservations = 0;
    $total_attente = 0;
    $total_confirmees = 0;
    $reservations = [];
    $recherches_liste = [];
    $medicaments_populaires = [];
    $pharmacies_proches = [];
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

$page_title = 'Tableau de bord - PharmaGeo';
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

        .top-bar .page-title p .user-name {
            font-weight: 600;
            color: var(--dark-text);
        }

        .top-bar .page-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .top-bar .page-actions .btn-action {
            padding: 10px 24px;
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

        /* ===== STATS CARDS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 20px 22px;
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
            height: 4px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            opacity: 0;
            transition: var(--transition);
        }

        .stat-card:hover::before { opacity: 1; }
        .stat-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-md);
        }

        .stat-card .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .stat-card .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
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
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--dark-text);
            line-height: 1.2;
            margin-top: 6px;
        }

        .stat-card .stat-label {
            color: var(--gray-text);
            font-size: 0.85rem;
            font-weight: 500;
        }

        .stat-card .stat-sub {
            font-size: 0.75rem;
            color: var(--gray-text);
            margin-top: 4px;
        }

        /* ===== SECTION CARDS ===== */
        .section-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 24px;
            transition: var(--transition);
            border: 1px solid rgba(0,0,0,0.02);
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
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-text);
            border-bottom: 2px solid #F0FDF4;
            padding: 12px 10px;
        }

        .table-custom tbody td {
            padding: 12px 10px;
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

        /* ===== PHARMACY CARD ===== */
        .pharmacy-mini-card {
            background: var(--light-bg);
            border-radius: var(--radius);
            padding: 16px 18px;
            transition: var(--transition);
            border: 1px solid rgba(0,0,0,0.02);
            height: 100%;
        }

        .pharmacy-mini-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
            background: var(--white);
        }

        .pharmacy-mini-card .pharmacy-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: rgba(22,163,74,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-green);
            font-size: 1.2rem;
        }

        .pharmacy-mini-card .pharmacy-name {
            font-weight: 700;
            font-size: 0.95rem;
        }

        .pharmacy-mini-card .pharmacy-address {
            font-size: 0.8rem;
            color: var(--gray-text);
        }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
        }

        .empty-state i {
            font-size: 3.5rem;
            color: #ddd;
            margin-bottom: 12px;
        }

        .empty-state h6 {
            font-weight: 600;
            color: var(--dark-text);
        }

        .empty-state p {
            color: #bbb;
            font-size: 0.9rem;
            margin: 0;
        }

        /* ===== LIST GROUP ===== */
        .list-group-item-custom {
            border-left: 3px solid var(--primary-green);
            border-radius: 12px !important;
            margin-bottom: 4px;
            transition: var(--transition);
        }

        .list-group-item-custom:hover {
            background: rgba(22,163,74,0.03);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 991px) {
            .main-content { padding: 15px; }
            .top-bar .page-title h1 { font-size: 1.4rem; }
        }

        @media (max-width: 768px) {
            .stats-grid { 
                grid-template-columns: 1fr 1fr; 
                gap: 12px; 
            }
            .stat-card { padding: 16px; }
            .stat-card .stat-number { font-size: 1.6rem; }
            .stat-card .stat-icon { 
                width: 40px; 
                height: 40px; 
                font-size: 1.1rem; 
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
            .section-card { padding: 16px; }
            .table-custom { 
                font-size: 0.8rem; 
                min-width: 500px; 
            }
            .table-custom thead th,
            .table-custom tbody td {
                padding: 8px 6px;
            }
        }

        @media (max-width: 576px) {
            .main-content { padding: 10px; }
            .stats-grid { 
                grid-template-columns: 1fr 1fr; 
                gap: 8px; 
            }
            .stat-card { padding: 12px; }
            .stat-card .stat-number { font-size: 1.3rem; }
            .stat-card .stat-label { font-size: 0.7rem; }
            .stat-card .stat-icon { 
                width: 32px; 
                height: 32px; 
                font-size: 0.9rem; 
            }
            .top-bar .page-title h1 { font-size: 1.2rem; }
            .navbar-brand-custom { font-size: 1.3rem; }
        }

        @media (max-width: 400px) {
            .stats-grid { 
                grid-template-columns: 1fr 1fr; 
                gap: 6px; 
            }
            .stat-card { padding: 10px; }
            .stat-card .stat-number { font-size: 1.1rem; }
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
            <a href="<?php echo BASE_URL; ?>pages/patient/dashboard.php" class="nav-item active"><i class="fas fa-tachometer-alt"></i><span>Tableau de bord</span></a>
            <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="nav-item"><i class="fas fa-search"></i><span>Rechercher</span></a>
            <a href="<?php echo BASE_URL; ?>pages/patient/carte.php" class="nav-item"><i class="fas fa-map-marker-alt"></i><span>Carte</span></a>
            <a href="<?php echo BASE_URL; ?>pages/patient/historique.php" class="nav-item"><i class="fas fa-history"></i><span>Historique</span></a>
            <a href="<?php echo BASE_URL; ?>pages/patient/profil.php" class="nav-item"><i class="fas fa-user"></i><span>Profil</span></a>
            <div class="nav-label" style="margin-top: 20px;">Système</div>
            <a href="<?php echo BASE_URL; ?>deconnexion.php" class="nav-item" style="color: #FEF2F2; margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.15); margin-bottom: 12px;"><i class="fas fa-sign-out-alt"></i><span>Déconnexion</span></a>
        </nav>
        <a class="user-profile" href="<?php echo BASE_URL . lienProfil(); ?>" title="Voir mon profil">
            <div class="avatar"><?php echo strtoupper(substr(($user['Prenom'] ?? 'A'), 0, 1) . substr(($user['Nom'] ?? 'D'), 0, 1)); ?></div>
            <div>
                <div class="name"><?php echo htmlspecialchars(($user['Prenom'] ?? '') . ' ' . ($user['Nom'] ?? '')); ?></div>
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
                <h1>Tableau de <span class="highlight">bord</span></h1>
                <p>
                    <i class="fas fa-user me-1" style="color: var(--primary-green);"></i> 
                    Bonjour, <span class="user-name"><?php echo htmlspecialchars($user['Prenom'] . ' ' . $user['Nom']); ?></span> !
                    <span class="text-muted ms-2" style="font-size: 0.8rem;">
                        <i class="fas fa-calendar-alt me-1"></i>
                        <?php echo date('l d F Y'); ?>
                    </span>
                </p>
            </div>
            <div class="page-actions">
                <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="btn-action btn-primary-custom">
                    <i class="fas fa-search"></i> Rechercher un médicament
                </a>
                <a href="<?php echo BASE_URL; ?>pages/patient/carte.php" class="btn-action btn-outline-custom">
                    <i class="fas fa-map-marker-alt"></i> Voir la carte
                </a>
            </div>
        </div>

        <?php echo geoAfficherPosition($position); ?>
        <?php echo geoBandeauPosition($position); ?>

        <!-- ===== STATS GRID ===== -->
        <div class="stats-grid">
            <div class="stat-card animate-fade-up delay-1">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $total_recherches; ?></div>
                        <div class="stat-label">Recherches effectuées</div>
                    </div>
                    <div class="stat-icon blue"><i class="fas fa-search"></i></div>
                </div>
                <div class="stat-sub">
                    <i class="fas fa-arrow-up" style="color: var(--primary-green);"></i> 
                    <?php echo $total_recherches > 0 ? 'Vous êtes actif' : 'Commencez à chercher'; ?>
                </div>
            </div>

            <div class="stat-card animate-fade-up delay-2">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $total_reservations; ?></div>
                        <div class="stat-label">Total réservations</div>
                    </div>
                    <div class="stat-icon green"><i class="fas fa-calendar-check"></i></div>
                </div>
                <div class="stat-sub">
                    <i class="fas fa-<?php echo $total_reservations > 0 ? 'check-circle' : 'info-circle'; ?>" 
                       style="color: <?php echo $total_reservations > 0 ? 'var(--primary-green)' : 'var(--gray-text)'; ?>;"></i>
                    <?php echo $total_reservations > 0 ? $total_reservations . ' réservation(s)' : 'Aucune réservation'; ?>
                </div>
            </div>

            <div class="stat-card animate-fade-up delay-3">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $total_attente; ?></div>
                        <div class="stat-label">En attente</div>
                    </div>
                    <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
                </div>
                <div class="stat-sub">
                    <?php if ($total_attente > 0): ?>
                        <i class="fas fa-spinner fa-spin" style="color: var(--primary-orange);"></i> 
                        En cours de traitement
                    <?php else: ?>
                        <i class="fas fa-check-circle" style="color: var(--primary-green);"></i> 
                        Aucune en attente
                    <?php endif; ?>
                </div>
            </div>

            <div class="stat-card animate-fade-up delay-4">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $total_confirmees; ?></div>
                        <div class="stat-label">Confirmées</div>
                    </div>
                    <div class="stat-icon purple"><i class="fas fa-check-circle"></i></div>
                </div>
                <div class="stat-sub">
                    <i class="fas fa-<?php echo $total_confirmees > 0 ? 'check-double' : 'info-circle'; ?>" 
                       style="color: <?php echo $total_confirmees > 0 ? 'var(--primary-green)' : 'var(--gray-text)'; ?>;"></i>
                    <?php echo $total_confirmees > 0 ? $total_confirmees . ' prête(s)' : 'En attente de confirmation'; ?>
                </div>
            </div>
        </div>

        <!-- ===== DERNIÈRES RÉSERVATIONS ===== -->
        <div class="section-card animate-fade-up delay-2">
            <div class="section-header">
                <h5><i class="fas fa-history"></i> Mes dernières réservations</h5>
                <a href="<?php echo BASE_URL; ?>pages/patient/historique.php" class="btn-link-custom">
                    Voir tout <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            <?php if (!empty($reservations)): ?>
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead>
                            <tr>
                                <th>Médicament</th>
                                <th>Pharmacie</th>
                                <th>Qté</th>
                                <th>Statut</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reservations as $r): 
                                $statut = $statuts_labels[$r['Statut']] ?? ['label' => $r['Statut'], 'class' => '', 'icon' => 'fa-circle'];
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($r['Nom_commercial']); ?></strong>
                                        <?php if ($r['DCI']): ?>
                                            <small class="text-muted d-block"><?php echo htmlspecialchars($r['DCI']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($r['Pharmacie']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($r['Quartier'] ?? ''); ?></small>
                                    </td>
                                    <td>
                                        <?php echo $r['Quantite']; ?>
                                        <?php $montantDash = $r['Montant'] !== null && $r['Montant'] !== '' ? (float)$r['Montant'] : ((!empty($r['prix_unitaire'])) ? (float)$r['prix_unitaire'] * (int)$r['Quantite'] : null); ?>
                                        <?php if ($montantDash !== null): ?>
                                            <small class="d-block text-success fw-semibold">
                                                <?php echo number_format($montantDash, 0, ',', ' ') . ' FCFA'; ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
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
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-check"></i>
                    <h6>Aucune réservation</h6>
                    <p>Vous n'avez pas encore effectué de réservation.</p>
                    <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="btn btn-primary-custom mt-2" style="display: inline-block; padding: 10px 28px; border-radius: 50px; background: linear-gradient(135deg, #16A34A, #166534); color: white; text-decoration: none; font-weight: 600; transition: var(--transition);">
                        <i class="fas fa-search me-1"></i> Rechercher un médicament
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- ===== DERNIÈRES RECHERCHES & MÉDICAMENTS POPULAIRES ===== -->
        <div class="row g-4 animate-fade-up delay-3">
            <!-- Dernières recherches -->
            <div class="col-lg-6">
                <div class="section-card h-100">
                    <div class="section-header">
                        <h5><i class="fas fa-search" style="color: var(--primary-orange);"></i> Mes dernières recherches</h5>
                        <a href="<?php echo BASE_URL; ?>pages/patient/historique.php" class="btn-link-custom">
                            Voir tout <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                    <?php if (!empty($recherches_liste)): ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($recherches_liste as $r): ?>
                                <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php?q=<?php echo urlencode($r['Mot_recherche']); ?>" 
                                   class="list-group-item list-group-item-action list-group-item-custom d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo htmlspecialchars($r['Mot_recherche']); ?></strong>
                                        <small class="text-muted d-block">
                                            <i class="far fa-clock me-1"></i>
                                            <?php echo date('d/m/Y à H:i', strtotime($r['Date_recherche'])); ?>
                                        </small>
                                    </div>
                                    <i class="fas fa-chevron-right" style="color: var(--primary-green);"></i>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-search"></i>
                            <h6>Aucune recherche</h6>
                            <p>Commencez à rechercher des médicaments.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Médicaments populaires -->
            <div class="col-lg-6">
                <div class="section-card h-100">
                    <div class="section-header">
                        <h5><i class="fas fa-fire" style="color: var(--primary-orange);"></i> Médicaments populaires</h5>
                    </div>
                    <?php if (!empty($medicaments_populaires)): ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($medicaments_populaires as $med): ?>
                                <div class="list-group-item list-group-item-custom d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo htmlspecialchars($med['Nom_commercial']); ?></strong>
                                        <?php if ($med['DCI']): ?>
                                            <small class="text-muted d-block"><?php echo htmlspecialchars($med['DCI']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <span class="badge" style="background: rgba(22,163,74,0.1); color: var(--primary-green); padding: 4px 14px; border-radius: 50px; font-weight: 600;">
                                        <i class="fas fa-search me-1"></i> <?php echo $med['total']; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-pills"></i>
                            <h6>Aucun médicament populaire</h6>
                            <p>Les données apparaîtront ici bientôt.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===== PHARMACIES À PROXIMITÉ ===== -->
        <?php if (!empty($pharmacies_proches)): ?>
        <div class="row g-4 animate-fade-up delay-4">
            <div class="col-12">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-store" style="color: var(--primary-green);"></i>
                            <?php echo $geo_precise ? 'Pharmacies à proximité' : 'Pharmacies'; ?>
                        </h5>
                        <a href="<?php echo BASE_URL; ?>pages/patient/carte.php" class="btn-link-custom">
                            Voir sur la carte <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                    <div class="row g-3">
                        <?php foreach ($pharmacies_proches as $pharm): ?>
                            <div class="col-lg-3 col-md-6">
                                <div class="pharmacy-mini-card">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="pharmacy-icon">
                                            <i class="fas fa-store"></i>
                                        </div>
                                        <div>
                                            <div class="pharmacy-name"><?php echo htmlspecialchars($pharm['Nom']); ?></div>
                                            <div class="pharmacy-address">
                                                <i class="fas fa-map-marker-alt me-1" style="font-size: 0.6rem;"></i>
                                                <?php echo htmlspecialchars($pharm['Quartier'] ?? ''); ?>, 
                                                <?php echo htmlspecialchars($pharm['Commune'] ?? 'Porto-Novo'); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mt-2 d-flex justify-content-between align-items-center">
                                        <small class="text-muted">
                                            <i class="fas fa-phone me-1"></i> <?php echo htmlspecialchars($pharm['Telephone'] ?? 'Non disponible'); ?>
                                        </small>
                                        <?php $distance_dash = geoFormaterDistance($pharm['distance_metres'] ?? null); ?>
                                        <span class="geo-distance-chip <?php echo $distance_dash === null ? 'geo-distance-chip--vide' : ''; ?>"
                                              <?php echo $distance_dash !== null ? 'title="Distance à vol d\'oiseau (ligne droite, non routière)"' : ''; ?>>
                                            <?php if ($distance_dash !== null): ?>
                                                <i class="fas fa-route me-1"></i><?php echo $distance_dash; ?>
                                            <?php else: ?>
                                                <i class="fas fa-location-arrow me-1"></i>Distance inconnue
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <a href="<?php echo BASE_URL; ?>pages/patient/carte.php?id=<?php echo $pharm['Id_pharmacie'] ?? ''; ?>" 
                                       class="btn btn-sm btn-outline-custom mt-2" 
                                       style="border-color: var(--primary-green); color: var(--primary-green); border-radius: 8px; font-weight: 600; padding: 4px 16px; text-decoration: none; font-size: 0.8rem; transition: var(--transition);">
                                        <i class="fas fa-map-pin"></i> Localiser
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

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

            // ===== ANIMATION DES STATS (Compteur) =====
            function animateCounters() {
                const statNumbers = document.querySelectorAll('.stat-number');
                
                statNumbers.forEach(stat => {
                    const target = parseInt(stat.textContent);
                    if (target > 0) {
                        const duration = 1500;
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
                        
                        // Observer pour démarrer l'animation quand la carte est visible
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

            animateCounters();

            // ===== KEYBOARD SHORTCUTS =====
            document.addEventListener('keydown', function(e) {
                // Ctrl + R pour rediriger vers la recherche
                if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                    e.preventDefault();
                    window.location.href = '<?php echo BASE_URL; ?>pages/patient/recherche.php';
                }
                // Ctrl + M pour rediriger vers la carte
                if ((e.ctrlKey || e.metaKey) && e.key === 'm') {
                    e.preventDefault();
                    window.location.href = '<?php echo BASE_URL; ?>pages/patient/carte.php';
                }
            });

            // ===== CONSOLE LOG =====
            console.log('%c PharmaGeo - Patient Dashboard ', 'background: linear-gradient(135deg, #16A34A, #166534); color: white; font-size: 16px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');
            console.log('%c Bienvenue sur votre tableau de bord patient.', 'color: #666; font-size: 14px;');
            console.log('%c Raccourcis : Ctrl+R (Recherche) | Ctrl+M (Carte)', 'color: #888; font-size: 12px;');

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