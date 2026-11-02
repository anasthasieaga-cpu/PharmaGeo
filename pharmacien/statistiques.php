<?php
// ============================================================
// PharmaGeo — Espace pharmacien : Statistiques
// Affiche les indicateurs d'activité de la pharmacie (stocks,
// réservations, médicaments populaires, patients et tendances).
// ============================================================

// ===== ACTIVATION DES ERREURS =====
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ===== DÉFINITION DES CONSTANTES =====
define('BASE_URL', 'http://localhost/pharmageo/');

// ===== FONCTIONS UTILITAIRES =====
require_once __DIR__ . '/../includes/fonctions.php';

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

// ===== RÉCUPÉRATION DES STATISTIQUES =====
try {
    // ===== STATISTIQUES GÉNÉRALES =====
    // Total des médicaments en stock
    $stmt_total_medicaments = $pdo->prepare("
        SELECT COUNT(*) as total FROM stock WHERE Id_pharmacie = :pharmacie
    ");
    $stmt_total_medicaments->execute([':pharmacie' => $pharmacie_id]);
    $total_medicaments = $stmt_total_medicaments->fetch()['total'] ?? 0;

    // Médicaments disponibles
    $stmt_disponibles = $pdo->prepare("
        SELECT COUNT(*) as total FROM stock 
        WHERE Id_pharmacie = :pharmacie AND Quantite > 0 AND Statut = 'disponible'
    ");
    $stmt_disponibles->execute([':pharmacie' => $pharmacie_id]);
    $total_disponibles = $stmt_disponibles->fetch()['total'] ?? 0;

    // Stock faible
    $stmt_stock_faible = $pdo->prepare("
        SELECT COUNT(*) as total FROM stock 
        WHERE Id_pharmacie = :pharmacie AND Statut = 'stock_faible' AND Quantite > 0
    ");
    $stmt_stock_faible->execute([':pharmacie' => $pharmacie_id]);
    $total_stock_faible = $stmt_stock_faible->fetch()['total'] ?? 0;

    // En rupture
    $stmt_rupture = $pdo->prepare("
        SELECT COUNT(*) as total FROM stock 
        WHERE Id_pharmacie = :pharmacie AND (Quantite = 0 OR Statut = 'rupture')
    ");
    $stmt_rupture->execute([':pharmacie' => $pharmacie_id]);
    $total_rupture = $stmt_rupture->fetch()['total'] ?? 0;

    // ===== STATISTIQUES DES RÉSERVATIONS =====
    // Total des réservations
    $stmt_total_reservations = $pdo->prepare("
        SELECT COUNT(*) as total FROM reservation r
        JOIN stock s ON r.Id_stock = s.Id_stock
        WHERE s.Id_pharmacie = :pharmacie
    ");
    $stmt_total_reservations->execute([':pharmacie' => $pharmacie_id]);
    $total_reservations = $stmt_total_reservations->fetch()['total'] ?? 0;

    // Réservations en attente
    $stmt_attente = $pdo->prepare("
        SELECT COUNT(*) as total FROM reservation r
        JOIN stock s ON r.Id_stock = s.Id_stock
        WHERE s.Id_pharmacie = :pharmacie AND r.Statut = 'en_attente'
    ");
    $stmt_attente->execute([':pharmacie' => $pharmacie_id]);
    $total_attente = $stmt_attente->fetch()['total'] ?? 0;

    // Réservations confirmées
    $stmt_confirmees = $pdo->prepare("
        SELECT COUNT(*) as total FROM reservation r
        JOIN stock s ON r.Id_stock = s.Id_stock
        WHERE s.Id_pharmacie = :pharmacie AND r.Statut = 'confirmee'
    ");
    $stmt_confirmees->execute([':pharmacie' => $pharmacie_id]);
    $total_confirmees = $stmt_confirmees->fetch()['total'] ?? 0;

    // Réservations refusées
    $stmt_refusees = $pdo->prepare("
        SELECT COUNT(*) as total FROM reservation r
        JOIN stock s ON r.Id_stock = s.Id_stock
        WHERE s.Id_pharmacie = :pharmacie AND r.Statut = 'refusee'
    ");
    $stmt_refusees->execute([':pharmacie' => $pharmacie_id]);
    $total_refusees = $stmt_refusees->fetch()['total'] ?? 0;

    // Réservations retirées
    $stmt_retirees = $pdo->prepare("
        SELECT COUNT(*) as total FROM reservation r
        JOIN stock s ON r.Id_stock = s.Id_stock
        WHERE s.Id_pharmacie = :pharmacie AND r.Statut = 'retiree'
    ");
    $stmt_retirees->execute([':pharmacie' => $pharmacie_id]);
    $total_retirees = $stmt_retirees->fetch()['total'] ?? 0;

    // ===== STATISTIQUES PAR MOIS =====
    // Réservations par mois (12 derniers mois)
    $reservations_par_mois = $pdo->prepare("
        SELECT 
            DATE_FORMAT(r.Date_reservation, '%Y-%m') as mois,
            COUNT(*) as total,
            SUM(CASE WHEN r.Statut = 'confirmee' THEN 1 ELSE 0 END) as confirmees,
            SUM(CASE WHEN r.Statut = 'refusee' THEN 1 ELSE 0 END) as refusees
        FROM reservation r
        JOIN stock s ON r.Id_stock = s.Id_stock
        WHERE s.Id_pharmacie = :pharmacie
        AND r.Date_reservation >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(r.Date_reservation, '%Y-%m')
        ORDER BY mois ASC
    ");
    $reservations_par_mois->execute([':pharmacie' => $pharmacie_id]);
    $reservations_par_mois = $reservations_par_mois->fetchAll();

    // ===== MÉDICAMENTS LES PLUS RÉSERVÉS =====
    $medicaments_populaires = $pdo->prepare("
        SELECT m.Nom_commercial, m.DCI, COUNT(r.Id_reservation) as total,
               SUM(r.Quantite) as total_quantite
        FROM medicament m
        JOIN stock s ON m.Id_medicament = s.Id_medicament
        JOIN reservation r ON s.Id_stock = r.Id_stock
        WHERE s.Id_pharmacie = :pharmacie AND r.Statut = 'confirmee'
        GROUP BY m.Id_medicament
        ORDER BY total DESC
        LIMIT 10
    ");
    $medicaments_populaires->execute([':pharmacie' => $pharmacie_id]);
    $medicaments_populaires = $medicaments_populaires->fetchAll();

    // ===== MEILLEURS PATIENTS =====
    $meilleurs_patients = $pdo->prepare("
        SELECT u.Nom, u.Prenom, u.Email, u.Telephone,
               COUNT(r.Id_reservation) as total_reservations,
               SUM(r.Quantite) as total_quantite
        FROM utilisateur u
        JOIN reservation r ON u.Id_utilisateur = r.Id_utilisateur
        JOIN stock s ON r.Id_stock = s.Id_stock
        WHERE s.Id_pharmacie = :pharmacie AND r.Statut = 'confirmee'
        GROUP BY u.Id_utilisateur
        ORDER BY total_reservations DESC
        LIMIT 10
    ");
    $meilleurs_patients->execute([':pharmacie' => $pharmacie_id]);
    $meilleurs_patients = $meilleurs_patients->fetchAll();

    // ===== STATUT DES STOCKS =====
    $statut_stocks = [
        'disponible' => $total_disponibles,
        'stock_faible' => $total_stock_faible,
        'rupture' => $total_rupture
    ];

    // ===== TAUX DE DISPONIBILITÉ =====
    $taux_disponibilite = 0;
    if ($total_medicaments > 0) {
        $taux_disponibilite = round(($total_disponibles / $total_medicaments) * 100);
    }

    // ===== TAUX DE CONVERSION =====
    $taux_conversion = 0;
    if ($total_reservations > 0) {
        $taux_conversion = round(($total_confirmees / $total_reservations) * 100);
    }

    // ===== VALEUR DU STOCK =====
    $stmt_valeur = $pdo->prepare("
        SELECT SUM(Quantite * Prix) as total FROM stock 
        WHERE Id_pharmacie = :pharmacie AND Prix IS NOT NULL
    ");
    $stmt_valeur->execute([':pharmacie' => $pharmacie_id]);
    $valeur_stock = $stmt_valeur->fetch()['total'] ?? 0;

    // ===== DERNIÈRES ACTIVITÉS =====
    $dernieres_activites = $pdo->prepare("
        SELECT l.*, u.Nom, u.Prenom
        FROM log l
        JOIN utilisateur u ON l.Id_utilisateur = u.Id_utilisateur
        WHERE l.Id_utilisateur = :user_id
        ORDER BY l.Date_action DESC
        LIMIT 10
    ");
    $dernieres_activites->execute([':user_id' => $_SESSION['user_id']]);
    $dernieres_activites = $dernieres_activites->fetchAll();

} catch(PDOException $e) {
    $total_medicaments = 0;
    $total_disponibles = 0;
    $total_stock_faible = 0;
    $total_rupture = 0;
    $total_reservations = 0;
    $total_attente = 0;
    $total_confirmees = 0;
    $total_refusees = 0;
    $total_retirees = 0;
    $reservations_par_mois = [];
    $medicaments_populaires = [];
    $meilleurs_patients = [];
    $statut_stocks = ['disponible' => 0, 'stock_faible' => 0, 'rupture' => 0];
    $taux_disponibilite = 0;
    $taux_conversion = 0;
    $valeur_stock = 0;
    $dernieres_activites = [];
    $message = "Erreur lors du chargement des données : " . $e->getMessage();
    $message_type = "danger";
}

$page_title = 'Statistiques - PharmaGeo';
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
            --primary-green: #16A34A;
            --primary-orange: #166534;
            --white: #ffffff;
            --light-bg: #F0FDF4;
            --dark-text: #1F2937;
            --gray-text: #64748B;
            --shadow-sm: 0 2px 20px rgba(0,0,0,0.04);
            --shadow-md: 0 8px 40px rgba(0,0,0,0.08);
            --shadow-lg: 0 20px 60px rgba(0,0,0,0.12);
            --shadow-xl: 0 30px 80px rgba(0,0,0,0.16);
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

        .stat-card .stat-icon.red {
            background: rgba(220,38,38,0.1);
            color: #DC2626;
        }

        .stat-card .stat-icon.teal {
            background: rgba(26,188,156,0.1);
            color: #16A34A;
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

        .stat-card .stat-sub {
            font-size: 0.7rem;
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

        /* ===== CHART CONTAINER ===== */
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

        .badge-status {
            padding: 4px 14px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-status.success {
            background: rgba(22,163,74,0.1);
            color: var(--primary-green);
        }

        .badge-status.warning {
            background: rgba(21,128,61,0.1);
            color: var(--primary-orange);
        }

        .badge-status.danger {
            background: rgba(220,38,38,0.1);
            color: #DC2626;
        }

        .badge-status.info {
            background: rgba(22,83,52,0.1);
            color: #166534;
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

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .main-content { padding: 15px; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .chart-container { height: 200px; }
            .section-card { padding: 16px; }
            .top-bar { flex-direction: column; align-items: stretch; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 10px; }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card { padding: 12px; }
            .stat-card .stat-number { font-size: 1.3rem; }
            .stat-card .stat-label { font-size: 0.7rem; }
            .stat-card .stat-icon { width: 32px; height: 32px; font-size: 0.9rem; }
            .top-bar .page-title h1 { font-size: 1.2rem; }
            .navbar-brand-custom { font-size: 1.3rem; }
            .table-custom { font-size: 0.8rem; min-width: 500px; }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/responsive.css">    <!-- Palette verte PharmaGeo -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/palette.css">
</head>
<body>

    <div class="layout">

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar-admin" id="sidebar">
        <a href="<?php echo BASE_URL; ?>index.php" class="brand-admin" style="text-decoration: none;">
            <div class="brand-icon"><i class="fas fa-hospital"></i></div>
            <div><h4>PharmaGeo</h4><small><?php echo htmlspecialchars(pharma_libelle_role('pharmacien', $user['Prenom'] ?? '')); ?></small></div>
        </a>
        <nav class="nav-menu">
            <div class="nav-label">Navigation</div>
            <a href="<?php echo BASE_URL; ?>pharmacien/dashboard.php" class="nav-item"><i class="fas fa-tachometer-alt"></i><span>Tableau de bord</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/pharmacie.php" class="nav-item"><i class="fas fa-store"></i><span>Ma pharmacie</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/medicaments.php" class="nav-item"><i class="fas fa-capsules"></i><span>Médicaments</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/stocks.php" class="nav-item"><i class="fas fa-boxes"></i><span>Stocks</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/historique_stocks.php" class="nav-item"><i class="fas fa-history"></i><span>Historique</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/reservations.php" class="nav-item"><i class="fas fa-calendar-check"></i><span>Réservations</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/statistiques.php" class="nav-item active"><i class="fas fa-chart-bar"></i><span>Statistiques</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/profil.php" class="nav-item"><i class="fas fa-user"></i><span>Profil</span></a>
            <div class="nav-label" style="margin-top: 20px;">Système</div>
            <?php require_once __DIR__ . '/../includes/reinitialiser.php'; echo html_bloc_reinitialiser(); ?>
            <a href="<?php echo BASE_URL; ?>deconnexion.php" class="nav-item" style="color: #FEF2F2; margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.15); margin-bottom: 12px;"><i class="fas fa-sign-out-alt"></i><span>Déconnexion</span></a>
        </nav>
        <a class="user-profile" href="<?php echo BASE_URL; ?>pharmacien/profil.php" title="Voir mon profil">
            <div class="avatar"><?php if (!empty($_SESSION['photo']) && function_exists('pharma_image_disponible') && pharma_image_disponible($_SESSION['photo'])): ?><img src="<?php echo htmlspecialchars(pharma_image_url($_SESSION['photo'])); ?>" alt=""><?php else: ?><?php echo strtoupper(substr($_SESSION['prenom'] ?? 'A', 0, 1) . substr($_SESSION['nom'] ?? 'D', 0, 1)); ?><?php endif; ?></div>
            <div>
                <div class="name"><?php echo htmlspecialchars(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')); ?></div>
                <div class="role"><i class="fas fa-user-md me-1" style="color:#DC2626;"></i> <?php echo htmlspecialchars(pharma_libelle_role('pharmacien', $user['Prenom'] ?? '')); ?></div>
            </div>
            <i class="fas fa-chevron-right profile-chevron"></i>
        </a>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">

        <!-- ===== TOP BAR ===== -->
        <div class="top-bar animate-fade-up">
            <div class="page-title">
                <h1><span class="highlight">Statistiques</span></h1>
                <p>
                    <i class="fas fa-chart-bar me-1" style="color: var(--primary-green);"></i>
                    Analysez les performances de votre pharmacie
                </p>
            </div>
        </div>

        <!-- ===== STATS CARDS ===== -->
        <div class="stats-grid animate-fade-up delay-1">
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $total_medicaments; ?></div>
                        <div class="stat-label">Médicaments</div>
                    </div>
                    <div class="stat-icon purple"><i class="fas fa-capsules"></i></div>
                </div>
                <div class="stat-sub">
                    <i class="fas fa-check-circle" style="color: var(--primary-green);"></i>
                    <?php echo $total_disponibles; ?> disponibles
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo number_format($valeur_stock, 0, ',', ' '); ?> F</div>
                        <div class="stat-label">Valeur du stock</div>
                    </div>
                    <div class="stat-icon teal"><i class="fas fa-money-bill-wave"></i></div>
                </div>
                <div class="stat-sub">
                    <i class="fas fa-percent"></i>
                    Taux disponibilité: <?php echo $taux_disponibilite; ?>%
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $total_reservations; ?></div>
                        <div class="stat-label">Réservations</div>
                    </div>
                    <div class="stat-icon blue"><i class="fas fa-calendar-check"></i></div>
                </div>
                <div class="stat-sub">
                    <i class="fas fa-clock" style="color: var(--primary-orange);"></i>
                    <?php echo $total_attente; ?> en attente
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $taux_conversion; ?>%</div>
                        <div class="stat-label">Taux de conversion</div>
                    </div>
                    <div class="stat-icon green"><i class="fas fa-funnel-dollar"></i></div>
                </div>
                <div class="stat-sub">
                    <i class="fas fa-check-circle" style="color: var(--primary-green);"></i>
                    <?php echo $total_confirmees; ?> confirmées
                </div>
            </div>
        </div>

        <!-- ===== GRAPHIQUES ===== -->
        <div class="row g-4 animate-fade-up delay-2">
            <!-- Réservations par mois -->
            <div class="col-lg-8">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-chart-line"></i> Évolution des réservations</h5>
                        <span class="text-muted small">12 derniers mois</span>
                    </div>
                    <div class="chart-container">
                        <canvas id="reservationsChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Statut des stocks -->
            <div class="col-lg-4">
                <div class="section-card h-100">
                    <div class="section-header">
                        <h5><i class="fas fa-chart-pie"></i> Statut des stocks</h5>
                    </div>
                    <div class="chart-container" style="height: 250px;">
                        <canvas id="stockChart"></canvas>
                    </div>
                    <div class="row mt-3 text-center">
                        <div class="col-4">
                            <div class="small text-muted">Disponible</div>
                            <div class="fw-bold" style="color: var(--primary-green);"><?php echo $statut_stocks['disponible']; ?></div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted">Stock faible</div>
                            <div class="fw-bold" style="color: var(--primary-orange);"><?php echo $statut_stocks['stock_faible']; ?></div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted">Rupture</div>
                            <div class="fw-bold" style="color: #DC2626;"><?php echo $statut_stocks['rupture']; ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== MÉDICAMENTS POPULAIRES & MEILLEURS PATIENTS ===== -->
        <div class="row g-4 animate-fade-up delay-3">
            <!-- Médicaments populaires -->
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-fire" style="color: var(--primary-orange);"></i> Médicaments les plus réservés</h5>
                    </div>
                    <?php if (!empty($medicaments_populaires)): ?>
                        <div class="table-responsive">
                            <table class="table table-custom">
                                <thead>
                                    <tr>
                                        <th>Médicament</th>
                                        <th>Réservations</th>
                                        <th>Quantité</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($medicaments_populaires as $med): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($med['Nom_commercial']); ?></strong>
                                                <?php if ($med['DCI']): ?>
                                                    <small class="text-muted d-block"><?php echo htmlspecialchars($med['DCI']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge-status success"><?php echo $med['total']; ?></span></td>
                                            <td><?php echo $med['total_quantite']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-pills"></i>
                            <h6>Aucune donnée</h6>
                            <p>Les médicaments les plus réservés apparaîtront ici.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Meilleurs patients -->
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-users" style="color: var(--primary-green);"></i> Meilleurs patients</h5>
                    </div>
                    <?php if (!empty($meilleurs_patients)): ?>
                        <div class="table-responsive">
                            <table class="table table-custom">
                                <thead>
                                    <tr>
                                        <th>Patient</th>
                                        <th>Réservations</th>
                                        <th>Quantité</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($meilleurs_patients as $patient): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($patient['Prenom'] . ' ' . $patient['Nom']); ?></strong>
                                                <small class="text-muted d-block"><?php echo htmlspecialchars($patient['Email']); ?></small>
                                            </td>
                                            <td><span class="badge-status info"><?php echo $patient['total_reservations']; ?></span></td>
                                            <td><?php echo $patient['total_quantite']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <h6>Aucune donnée</h6>
                            <p>Les meilleurs patients apparaîtront ici.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===== DERNIÈRES ACTIVITÉS ===== -->
        <div class="section-card animate-fade-up delay-4">
            <div class="section-header">
                <h5><i class="fas fa-history"></i> Dernières activités</h5>
            </div>
            <?php if (!empty($dernieres_activites)): ?>
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead>
                            <tr>
                                <th>Action</th>
                                <th>Résultat</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dernieres_activites as $log): ?>
                                <tr>
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
                    <p>Les logs apparaîtront ici lorsque des actions seront effectuées.</p>
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

            // ===== CHART: RÉSERVATIONS PAR MOIS =====
            <?php if (!empty($reservations_par_mois)): ?>
            const resData = <?php echo json_encode($reservations_par_mois); ?>;
            
            const months = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
            const labels = resData.map(item => {
                const [year, month] = item.mois.split('-');
                return months[parseInt(month) - 1] + ' ' + year;
            });

            new Chart(document.getElementById('reservationsChart'), {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Total',
                            data: resData.map(item => parseInt(item.total || 0)),
                            backgroundColor: 'rgba(22,83,52,0.6)',
                            borderColor: '#166534',
                            borderWidth: 2,
                            borderRadius: 4
                        },
                        {
                            label: 'Confirmées',
                            data: resData.map(item => parseInt(item.confirmees || 0)),
                            backgroundColor: 'rgba(22,163,74,0.6)',
                            borderColor: '#16A34A',
                            borderWidth: 2,
                            borderRadius: 4
                        },
                        {
                            label: 'Refusées',
                            data: resData.map(item => parseInt(item.refusees || 0)),
                            backgroundColor: 'rgba(220,38,38,0.6)',
                            borderColor: '#DC2626',
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

            // ===== CHART: STATUT DES STOCKS =====
            const disponibles = <?php echo $statut_stocks['disponible']; ?>;
            const stockFaible = <?php echo $statut_stocks['stock_faible']; ?>;
            const rupture = <?php echo $statut_stocks['rupture']; ?>;

            new Chart(document.getElementById('stockChart'), {
                type: 'doughnut',
                data: {
                    labels: ['Disponible', 'Stock faible', 'Rupture'],
                    datasets: [{
                        data: [disponibles, stockFaible, rupture],
                        backgroundColor: [
                            '#16A34A',
                            '#166534',
                            '#DC2626'
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
                        legend: { display: false }
                    }
                }
            });

            // ===== ANIMATION DES STATS =====
            function animateStats() {
                const statNumbers = document.querySelectorAll('.stat-number');
                statNumbers.forEach(stat => {
                    const target = parseInt(stat.textContent.replace(/[^0-9]/g, ''));
                    if (target > 0 && !isNaN(target)) {
                        const duration = 1200;
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
                // Escape pour fermer
                if (e.key === 'Escape') {
                    const modal = document.querySelector('.modal.show');
                    if (modal) {
                        const closeBtn = modal.querySelector('.btn-close');
                        if (closeBtn) closeBtn.click();
                    }
                }
            });

            // ===== CONSOLE LOG =====
            console.log('%c PharmaGeo - Statistiques ', 'background: linear-gradient(135deg, #16A34A, #166534); color: white; font-size: 16px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');

        });
    </script>

</body>
    <script src="../assets/js/responsive.js"></script>
</html>