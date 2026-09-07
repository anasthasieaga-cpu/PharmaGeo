<?php
// ============================================================
// PharmaGeo — Espace pharmacien : Tableau de bord
// Affiche une synthèse de l'activité de la pharmacie (stocks,
// réservations, médicaments populaires et dernières mises à jour).
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

// ===== RÉCUPÉRATION DES STATISTIQUES =====
try {
    if ($has_pharmacy) {
        $pharmacie_id = $user['Id_pharmacie'];

        // Nombre total de médicaments
        $total_medicaments = $pdo->prepare("
            SELECT COUNT(*) as total FROM stock WHERE Id_pharmacie = :id
        ");
        $total_medicaments->execute([':id' => $pharmacie_id]);
        $total_medicaments = $total_medicaments->fetch()['total'] ?? 0;

        // Médicaments disponibles
        $medicaments_disponibles = $pdo->prepare("
            SELECT COUNT(*) as total FROM stock WHERE Id_pharmacie = :id AND Quantite > 0 AND Statut = 'disponible'
        ");
        $medicaments_disponibles->execute([':id' => $pharmacie_id]);
        $medicaments_disponibles = $medicaments_disponibles->fetch()['total'] ?? 0;

        // Médicaments en rupture
        $medicaments_rupture = $pdo->prepare("
            SELECT COUNT(*) as total FROM stock WHERE Id_pharmacie = :id AND (Quantite = 0 OR Statut = 'rupture')
        ");
        $medicaments_rupture->execute([':id' => $pharmacie_id]);
        $medicaments_rupture = $medicaments_rupture->fetch()['total'] ?? 0;

        // Stock faible
        $stock_faible = $pdo->prepare("
            SELECT COUNT(*) as total FROM stock WHERE Id_pharmacie = :id AND Statut = 'stock_faible' AND Quantite > 0
        ");
        $stock_faible->execute([':id' => $pharmacie_id]);
        $stock_faible = $stock_faible->fetch()['total'] ?? 0;

        // Total des réservations reçues
        $total_reservations = $pdo->prepare("
            SELECT COUNT(*) as total FROM reservation r
            JOIN stock s ON r.Id_stock = s.Id_stock
            WHERE s.Id_pharmacie = :id
        ");
        $total_reservations->execute([':id' => $pharmacie_id]);
        $total_reservations = $total_reservations->fetch()['total'] ?? 0;

        // Réservations en attente
        $reservations_attente = $pdo->prepare("
            SELECT COUNT(*) as total FROM reservation r
            JOIN stock s ON r.Id_stock = s.Id_stock
            WHERE s.Id_pharmacie = :id AND r.Statut = 'en_attente'
        ");
        $reservations_attente->execute([':id' => $pharmacie_id]);
        $reservations_attente = $reservations_attente->fetch()['total'] ?? 0;

        // Réservations confirmées
        $reservations_confirmees = $pdo->prepare("
            SELECT COUNT(*) as total FROM reservation r
            JOIN stock s ON r.Id_stock = s.Id_stock
            WHERE s.Id_pharmacie = :id AND r.Statut = 'confirmee'
        ");
        $reservations_confirmees->execute([':id' => $pharmacie_id]);
        $reservations_confirmees = $reservations_confirmees->fetch()['total'] ?? 0;

        // Dernières réservations
        $dernieres_reservations = $pdo->prepare("
            SELECT r.*, u.Nom, u.Prenom, u.Email, u.Telephone,
                   m.Nom_commercial, m.DCI, m.Dosage
            FROM reservation r
            JOIN stock s ON r.Id_stock = s.Id_stock
            JOIN utilisateur u ON r.Id_utilisateur = u.Id_utilisateur
            JOIN medicament m ON s.Id_medicament = m.Id_medicament
            WHERE s.Id_pharmacie = :id
            ORDER BY r.Date_reservation DESC
            LIMIT 5
        ");
        $dernieres_reservations->execute([':id' => $pharmacie_id]);
        $dernieres_reservations = $dernieres_reservations->fetchAll();

        // Médicaments les plus réservés
        $medicaments_populaires = $pdo->prepare("
            SELECT m.Nom_commercial, m.DCI, COUNT(r.Id_reservation) as total
            FROM medicament m
            JOIN stock s ON m.Id_medicament = s.Id_medicament
            JOIN reservation r ON s.Id_stock = r.Id_stock
            WHERE s.Id_pharmacie = :id AND r.Statut = 'confirmee'
            GROUP BY m.Id_medicament
            ORDER BY total DESC
            LIMIT 5
        ");
        $medicaments_populaires->execute([':id' => $pharmacie_id]);
        $medicaments_populaires = $medicaments_populaires->fetchAll();

        // Statistiques des stocks par statut
        $stats_stocks = [
            'disponible' => $medicaments_disponibles,
            'stock_faible' => $stock_faible,
            'rupture' => $medicaments_rupture
        ];

        // Taux de disponibilité
        $taux_disponibilite = 0;
        if ($total_medicaments > 0) {
            $taux_disponibilite = round(($medicaments_disponibles / $total_medicaments) * 100);
        }

        // Dernières mises à jour de stock
        $dernieres_mises_a_jour = $pdo->prepare("
            SELECT hs.*, m.Nom_commercial, u.Nom, u.Prenom
            FROM historique_stock hs
            JOIN stock s ON hs.Id_stock = s.Id_stock
            JOIN medicament m ON s.Id_medicament = m.Id_medicament
            JOIN utilisateur u ON hs.Id_utilisateur = u.Id_utilisateur
            WHERE s.Id_pharmacie = :id
            ORDER BY hs.Date_operation DESC
            LIMIT 5
        ");
        $dernieres_mises_a_jour->execute([':id' => $pharmacie_id]);
        $dernieres_mises_a_jour = $dernieres_mises_a_jour->fetchAll();

    } else {
        // Le pharmacien n'a pas encore de pharmacie
        $total_medicaments = 0;
        $medicaments_disponibles = 0;
        $medicaments_rupture = 0;
        $stock_faible = 0;
        $total_reservations = 0;
        $reservations_attente = 0;
        $reservations_confirmees = 0;
        $dernieres_reservations = [];
        $medicaments_populaires = [];
        $stats_stocks = ['disponible' => 0, 'stock_faible' => 0, 'rupture' => 0];
        $taux_disponibilite = 0;
        $dernieres_mises_a_jour = [];
    }

} catch(PDOException $e) {
    die("Erreur : " . $e->getMessage());
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

$page_title = 'Tableau de bord - Pharmacie';
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

        .badge-pharmacy {
            background: rgba(42,195,42,0.08);
            color: var(--primary-green);
            padding: 2px 10px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* ===== BUTTONS ===== */
        .btn-action-sm {
            padding: 5px 12px;
            border-radius: 8px;
            border: none;
            font-size: 0.75rem;
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

        /* ===== CHART CONTAINER ===== */
        .chart-container {
            position: relative;
            height: 250px;
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

        /* ===== PHARMACY NOT FOUND ===== */
        .pharmacy-not-found {
            text-align: center;
            padding: 60px 20px;
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            border: 1px solid rgba(0,0,0,0.02);
        }

        .pharmacy-not-found i {
            font-size: 4rem;
            color: var(--primary-orange);
            margin-bottom: 16px;
        }

        .pharmacy-not-found h3 {
            font-weight: 800;
            color: var(--dark-text);
        }

        .pharmacy-not-found p {
            color: var(--gray-text);
            max-width: 500px;
            margin: 0 auto 20px;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .main-content { padding: 15px; }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
            .stat-card { padding: 16px; }
            .stat-card .stat-number { font-size: 1.6rem; }
            .stat-card .stat-icon { width: 40px; height: 40px; font-size: 1.1rem; }
            .top-bar { flex-direction: column; align-items: stretch; }
            .top-bar .page-actions { flex-wrap: wrap; }
            .top-bar .page-actions .btn-action { font-size: 0.8rem; padding: 8px 16px; }
            .section-card { padding: 16px; }
            .table-custom { font-size: 0.8rem; min-width: 600px; }
            .table-custom thead th,
            .table-custom tbody td { padding: 8px 6px; }
            .chart-container { height: 180px; }
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
        }

        /* ===== DONUT CHART ===== */
        .donut-container {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .donut-container .chart-wrapper {
            flex: 1;
            min-width: 150px;
            max-width: 250px;
        }

        .donut-container .legend {
            flex: 1;
            min-width: 120px;
        }

        .donut-container .legend .legend-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 6px;
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

    <div class="layout">

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar-admin" id="sidebar">
        <div class="brand-admin">
            <div class="brand-icon"><i class="fas fa-hospital"></i></div>
            <div><h4>PharmaGeo</h4><small>Pharmacien</small></div>
        </div>
        <nav class="nav-menu">
            <div class="nav-label">Navigation</div>
            <a href="<?php echo BASE_URL; ?>pharmacien/dashboard.php" class="nav-item active"><i class="fas fa-tachometer-alt"></i><span>Tableau de bord</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/pharmacie.php" class="nav-item"><i class="fas fa-store"></i><span>Ma pharmacie</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/medicaments.php" class="nav-item"><i class="fas fa-capsules"></i><span>Médicaments</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/stocks.php" class="nav-item"><i class="fas fa-boxes"></i><span>Stocks</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/historique_stocks.php" class="nav-item"><i class="fas fa-history"></i><span>Historique</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/reservations.php" class="nav-item"><i class="fas fa-calendar-check"></i><span>Réservations<?php if ($has_pharmacy && $reservations_attente > 0): ?> <span class="badge bg-danger ms-1" style="font-size:0.6rem;"><?php echo $reservations_attente; ?></span><?php endif; ?></span></a>
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

        <?php if (!$has_pharmacy): ?>
            <!-- ===== PHARMACY NOT FOUND ===== -->
            <div class="pharmacy-not-found animate-fade-up">
                <i class="fas fa-store"></i>
                <h3>Aucune pharmacie associée</h3>
                <p>
                    Vous n'avez pas encore de pharmacie associée à votre compte.
                    Veuillez contacter l'administrateur pour créer votre officine.
                </p>
                <a href="<?php echo BASE_URL; ?>pharmacien/profil.php" class="btn-primary-custom" style="display: inline-block; padding: 10px 28px; border-radius: 50px; text-decoration: none;">
                    <i class="fas fa-user-edit me-1"></i> Modifier mon profil
                </a>
            </div>
        <?php else: ?>

            <!-- ===== TOP BAR ===== -->
            <div class="top-bar animate-fade-up">
                <div class="page-title">
                    <h1>Tableau de <span class="highlight">bord</span></h1>
                    <p>
                        <i class="fas fa-store me-1" style="color: var(--primary-green);"></i>
                        <?php echo htmlspecialchars($user['Pharmacie_Nom']); ?> - 
                        <span class="text-muted"><?php echo htmlspecialchars($user['Quartier'] ?? ''); ?>, <?php echo htmlspecialchars($user['Commune'] ?? 'Porto-Novo'); ?></span>
                    </p>
                </div>
                <div class="page-actions">
                    <a href="<?php echo BASE_URL; ?>pharmacien/medicaments.php" class="btn-action btn-primary-custom">
                        <i class="fas fa-plus"></i> Ajouter un médicament
                    </a>
                    <a href="<?php echo BASE_URL; ?>pharmacien/reservations.php" class="btn-action btn-outline-custom">
                        <i class="fas fa-calendar-check"></i> Voir les réservations
                        <?php if ($reservations_attente > 0): ?>
                            <span class="badge bg-danger ms-1"><?php echo $reservations_attente; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
            </div>

            <!-- ===== STATS GRID ===== -->
            <div class="stats-grid animate-fade-up delay-1">
                <div class="stat-card delay-1">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $total_medicaments; ?></div>
                            <div class="stat-label">Total médicaments</div>
                        </div>
                        <div class="stat-icon purple"><i class="fas fa-capsules"></i></div>
                    </div>
                    <div class="stat-sub">
                        <i class="fas fa-<?php echo $total_medicaments > 0 ? 'check-circle' : 'info-circle'; ?>" 
                           style="color: <?php echo $total_medicaments > 0 ? 'var(--primary-green)' : 'var(--gray-text)'; ?>;"></i>
                        <?php echo $total_medicaments > 0 ? $total_medicaments . ' médicament(s)' : 'Aucun médicament'; ?>
                    </div>
                </div>

                <div class="stat-card delay-2">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $medicaments_disponibles; ?></div>
                            <div class="stat-label">Disponibles</div>
                        </div>
                        <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                    </div>
                    <div class="stat-sub">
                        <i class="fas fa-arrow-up" style="color: var(--primary-green);"></i>
                        Taux: <?php echo $taux_disponibilite; ?>%
                    </div>
                </div>

                <div class="stat-card delay-3">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $medicaments_rupture; ?></div>
                            <div class="stat-label">En rupture</div>
                        </div>
                        <div class="stat-icon red"><i class="fas fa-exclamation-circle"></i></div>
                    </div>
                    <div class="stat-sub">
                        <i class="fas fa-<?php echo $medicaments_rupture > 0 ? 'exclamation-triangle' : 'check-circle'; ?>" 
                           style="color: <?php echo $medicaments_rupture > 0 ? '#e74c3c' : 'var(--primary-green)'; ?>;"></i>
                        <?php echo $medicaments_rupture > 0 ? $medicaments_rupture . ' à réapprovisionner' : 'Tout est disponible'; ?>
                    </div>
                </div>

                <div class="stat-card delay-4">
                    <div class="stat-header">
                        <div>
                            <div class="stat-number"><?php echo $reservations_attente; ?></div>
                            <div class="stat-label">Réservations en attente</div>
                        </div>
                        <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
                    </div>
                    <div class="stat-sub">
                        <i class="fas fa-<?php echo $reservations_attente > 0 ? 'spinner fa-spin' : 'check-circle'; ?>" 
                           style="color: <?php echo $reservations_attente > 0 ? 'var(--primary-orange)' : 'var(--primary-green)'; ?>;"></i>
                        <?php echo $reservations_attente > 0 ? 'À traiter' : 'Aucune en attente'; ?>
                    </div>
                </div>
            </div>

            <!-- ===== CHARTS ROW ===== -->
            <div class="row g-4 animate-fade-up delay-2">
                <!-- Disponibilité des stocks -->
                <div class="col-lg-6">
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
                                    <span class="value"><?php echo $stats_stocks['disponible']; ?></span>
                                </div>
                                <div class="legend-item">
                                    <span class="color-dot" style="background: var(--primary-orange);"></span>
                                    Stock faible
                                    <span class="value"><?php echo $stats_stocks['stock_faible']; ?></span>
                                </div>
                                <div class="legend-item">
                                    <span class="color-dot" style="background: #e74c3c;"></span>
                                    Rupture
                                    <span class="value"><?php echo $stats_stocks['rupture']; ?></span>
                                </div>
                                <div class="legend-item" style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #f0f0f0;">
                                    <span class="color-dot" style="background: #3498db;"></span>
                                    Taux global
                                    <span class="value" style="font-size: 1.1rem;"><?php echo $taux_disponibilite; ?>%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Réservations récentes -->
                <div class="col-lg-6">
                    <div class="section-card h-100">
                        <div class="section-header">
                            <h5><i class="fas fa-calendar-check" style="color: var(--primary-orange);"></i> Dernières réservations</h5>
                            <a href="<?php echo BASE_URL; ?>pharmacien/reservations.php" class="btn-link-custom">
                                Voir tout <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                        <?php if (!empty($dernieres_reservations)): ?>
                            <div class="table-responsive">
                                <table class="table table-custom">
                                    <thead>
                                        <tr>
                                            <th>Patient</th>
                                            <th>Médicament</th>
                                            <th>Qté</th>
                                            <th>Statut</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dernieres_reservations as $r): 
                                            $statut = $statuts_labels[$r['Statut']] ?? ['label' => $r['Statut'], 'class' => '', 'icon' => 'fa-circle'];
                                        ?>
                                            <tr>
                                                <td>
                                                    <div><?php echo htmlspecialchars($r['Prenom'] . ' ' . $r['Nom']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($r['Email']); ?></small>
                                                </td>
                                                <td>
                                                    <div><strong><?php echo htmlspecialchars($r['Nom_commercial']); ?></strong></div>
                                                    <?php if ($r['DCI']): ?>
                                                        <small class="text-muted"><?php echo htmlspecialchars($r['DCI']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo $r['Quantite']; ?></td>
                                                <td>
                                                    <span class="badge-status <?php echo $statut['class']; ?>">
                                                        <i class="fas <?php echo $statut['icon']; ?>"></i>
                                                        <?php echo $statut['label']; ?>
                                                    </span>
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
                                <p>Vous n'avez pas encore reçu de réservation.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ===== MÉDICAMENTS POPULAIRES & MISES À JOUR ===== -->
            <div class="row g-4 animate-fade-up delay-3">
                <!-- Médicaments populaires -->
                <div class="col-lg-6">
                    <div class="section-card h-100">
                        <div class="section-header">
                            <h5><i class="fas fa-fire" style="color: var(--primary-orange);"></i> Médicaments les plus réservés</h5>
                        </div>
                        <?php if (!empty($medicaments_populaires)): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($medicaments_populaires as $med): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center" style="border-left: 3px solid var(--primary-green); border-radius: 8px; margin-bottom: 4px;">
                                        <div>
                                            <strong><?php echo htmlspecialchars($med['Nom_commercial']); ?></strong>
                                            <?php if ($med['DCI']): ?>
                                                <small class="text-muted d-block"><?php echo htmlspecialchars($med['DCI']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <span class="badge" style="background: rgba(42,195,42,0.1); color: var(--primary-green); padding: 4px 14px; border-radius: 50px;">
                                            <i class="fas fa-shopping-cart me-1"></i> <?php echo $med['total']; ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
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

                <!-- Dernières mises à jour de stock -->
                <div class="col-lg-6">
                    <div class="section-card h-100">
                        <div class="section-header">
                            <h5><i class="fas fa-history"></i> Dernières mises à jour de stock</h5>
                            <a href="<?php echo BASE_URL; ?>pharmacien/historique_stocks.php" class="btn-link-custom">
                                Voir tout <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                        <?php if (!empty($dernieres_mises_a_jour)): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($dernieres_mises_a_jour as $maj): ?>
                                    <div class="list-group-item" style="border-left: 3px solid <?php echo $maj['Ancienne_quantite'] < $maj['Nouvelle_quantite'] ? 'var(--primary-green)' : '#e74c3c'; ?>; border-radius: 8px; margin-bottom: 4px;">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <strong><?php echo htmlspecialchars($maj['Nom_commercial']); ?></strong>
                                                <small class="text-muted d-block">
                                                    <?php echo $maj['Ancienne_quantite']; ?> → 
                                                    <strong><?php echo $maj['Nouvelle_quantite']; ?></strong>
                                                </small>
                                            </div>
                                            <div class="text-end">
                                                <small class="text-muted">
                                                    <?php echo date('d/m/Y H:i', strtotime($maj['Date_operation'])); ?>
                                                </small>
                                                <br>
                                                <small class="text-muted">
                                                    <i class="fas fa-user me-1"></i>
                                                    <?php echo htmlspecialchars($maj['Prenom'] . ' ' . $maj['Nom']); ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-history"></i>
                                <h6>Aucune mise à jour</h6>
                                <p>Les mises à jour de stock apparaîtront ici.</p>
                            </div>
                        <?php endif; ?>
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

            <?php if ($has_pharmacy): ?>
            // ===== CHART: DONUT DISPONIBILITÉ =====
            const disponibles = <?php echo $stats_stocks['disponible']; ?>;
            const stockFaible = <?php echo $stats_stocks['stock_faible']; ?>;
            const rupture = <?php echo $stats_stocks['rupture']; ?>;

            new Chart(document.getElementById('donutChart'), {
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
                    cutout: '65%',
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
            <?php endif; ?>

            // ===== ANIMATION DES STATS =====
            function animateStats() {
                const statNumbers = document.querySelectorAll('.stat-number');
                statNumbers.forEach(stat => {
                    const target = parseInt(stat.textContent);
                    if (target > 0) {
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
                // Ctrl + R pour rediriger vers les réservations
                if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                    e.preventDefault();
                    window.location.href = '<?php echo BASE_URL; ?>pharmacien/reservations.php';
                }
                // Ctrl + M pour rediriger vers les médicaments
                if ((e.ctrlKey || e.metaKey) && e.key === 'm') {
                    e.preventDefault();
                    window.location.href = '<?php echo BASE_URL; ?>pharmacien/medicaments.php';
                }
                // Ctrl + H pour rediriger vers l'historique des stocks
                if ((e.ctrlKey || e.metaKey) && e.key === 'h') {
                    e.preventDefault();
                    window.location.href = '<?php echo BASE_URL; ?>pharmacien/historique_stocks.php';
                }
            });

            // ===== CONSOLE LOG =====
            console.log('%c PharmaGeo - Pharmacien Dashboard ', 'background: linear-gradient(135deg, rgb(42, 195, 42), hsl(25, 75%, 47%)); color: white; font-size: 16px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');
            console.log('%c Gérez votre officine en toute simplicité.', 'color: #666; font-size: 14px;');
            console.log('%c Raccourcis : Ctrl+R (Réservations) | Ctrl+M (Médicaments) | Ctrl+H (Historique stocks)', 'color: #888; font-size: 12px;');

        });
    </script>

</body>
    <script src="../assets/js/responsive.js"></script>
</html>