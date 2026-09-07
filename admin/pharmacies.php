<?php
// ============================================================
// PharmaGeo — Administration : Pharmacies
// Gestion des pharmacies : validation, rejet, suppression,
// filtres, recherche et consultation des détails.
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

// ===== VALIDATION D'UNE PHARMACIE =====
if (isset($_GET['action']) && $_GET['action'] === 'valider' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("UPDATE pharmacie SET Statut = 'active' WHERE Id_pharmacie = :id");
        $stmt->execute([':id' => $id]);
        
        $log = $pdo->prepare("
            INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur)
            VALUES (:action, :ip, :ua, 'succes', :user)
        ");
        $log->execute([
            ':action' => 'Validation de pharmacie ID: ' . $id,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ':user' => $_SESSION['user_id']
        ]);
        
        $message = "Pharmacie validée avec succès !";
        $message_type = "success";
        
    } catch(PDOException $e) {
        $message = "Erreur lors de la validation : " . $e->getMessage();
        $message_type = "danger";
    }
}

// ===== REJET D'UNE PHARMACIE =====
if (isset($_GET['action']) && $_GET['action'] === 'rejeter' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("UPDATE pharmacie SET Statut = 'suspendue' WHERE Id_pharmacie = :id");
        $stmt->execute([':id' => $id]);
        
        $log = $pdo->prepare("
            INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur)
            VALUES (:action, :ip, :ua, 'succes', :user)
        ");
        $log->execute([
            ':action' => 'Rejet de pharmacie ID: ' . $id,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ':user' => $_SESSION['user_id']
        ]);
        
        $message = "Pharmacie rejetée avec succès !";
        $message_type = "warning";
        
    } catch(PDOException $e) {
        $message = "Erreur lors du rejet : " . $e->getMessage();
        $message_type = "danger";
    }
}

// ===== SUPPRESSION D'UNE PHARMACIE =====
if (isset($_GET['action']) && $_GET['action'] === 'supprimer' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM pharmacie WHERE Id_pharmacie = :id");
        $stmt->execute([':id' => $id]);
        
        $log = $pdo->prepare("
            INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur)
            VALUES (:action, :ip, :ua, 'succes', :user)
        ");
        $log->execute([
            ':action' => 'Suppression de pharmacie ID: ' . $id,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ':user' => $_SESSION['user_id']
        ]);
        
        $message = "Pharmacie supprimée avec succès !";
        $message_type = "danger";
        
    } catch(PDOException $e) {
        $message = "Erreur lors de la suppression : " . $e->getMessage();
        $message_type = "danger";
    }
}

// ===== RÉCUPÉRATION DES STATISTIQUES =====
try {
    $stats = [
        'total' => $pdo->query("SELECT COUNT(*) as total FROM pharmacie")->fetch()['total'] ?? 0,
        'actives' => $pdo->query("SELECT COUNT(*) as total FROM pharmacie WHERE Statut = 'active'")->fetch()['total'] ?? 0,
        'attente' => $pdo->query("SELECT COUNT(*) as total FROM pharmacie WHERE Statut = 'en_attente'")->fetch()['total'] ?? 0,
        'suspendues' => $pdo->query("SELECT COUNT(*) as total FROM pharmacie WHERE Statut = 'suspendue'")->fetch()['total'] ?? 0
    ];
} catch(PDOException $e) {
    $stats = ['total' => 0, 'actives' => 0, 'attente' => 0, 'suspendues' => 0];
}

// ===== RÉCUPÉRATION DE LA LISTE DES PHARMACIES =====
$filtre = $_GET['filtre'] ?? 'toutes';
$search = $_GET['search'] ?? '';
$action = $_GET['action'] ?? '';

try {
    // Requête principale : jointures sur pharmacien/utilisateur pour lier
    // chaque pharmacie à son pharmacien responsable, avec sous-requêtes
    // comptant médicaments en stock et réservations associées.
    $sql = "SELECT p.*, u.Nom, u.Prenom, u.Email, u.Telephone as UserTelephone,
                   (SELECT COUNT(*) FROM stock WHERE Id_pharmacie = p.Id_pharmacie) as total_medicaments,
                   (SELECT COUNT(*) FROM stock WHERE Id_pharmacie = p.Id_pharmacie AND Quantite > 0) as total_disponibles,
                   (SELECT COUNT(*) FROM reservation r JOIN stock s ON r.Id_stock = s.Id_stock WHERE s.Id_pharmacie = p.Id_pharmacie) as total_reservations
            FROM pharmacie p
            JOIN pharmacien ph ON p.Id_pharmacien = ph.Id_pharmacien
            JOIN utilisateur u ON ph.Id_utilisateur = u.Id_utilisateur
            WHERE 1=1";
    
    $params = [];
    
    if ($action === 'validation' || $action === 'valider') {
        $sql .= " AND p.Statut = 'en_attente'";
    } elseif ($filtre !== 'toutes') {
        $sql .= " AND p.Statut = :filtre";
        $params[':filtre'] = $filtre;
    }
    
    if (!empty($search)) {
        $sql .= " AND (p.Nom LIKE :search OR u.Nom LIKE :search OR u.Prenom LIKE :search OR p.Quartier LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    $sql .= " ORDER BY p.Id_pharmacie DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $pharmacies = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $pharmacies = [];
    $message = "Erreur lors du chargement des pharmacies : " . $e->getMessage();
    $message_type = "danger";
}

// ===== DÉTAILS D'UNE PHARMACIE (action=view) =====
$view_pharmacie = null;
$view_permanences = [];
if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['id'])) {
    $view_id = (int)$_GET['id'];
    try {
        $stmtV = $pdo->prepare("
            SELECT p.*, p.Nom as Pharmacie_Nom, u.Nom, u.Prenom, u.Email, u.Telephone as UserTelephone, u.Statut as UserStatut,
                   ph.Numero_ordre, ph.Statut_validation,
                   (SELECT COUNT(*) FROM stock WHERE Id_pharmacie = p.Id_pharmacie) as total_medicaments,
                   (SELECT COUNT(*) FROM stock WHERE Id_pharmacie = p.Id_pharmacie AND Quantite > 0) as total_disponibles,
                   (SELECT COUNT(*) FROM reservation r JOIN stock s ON r.Id_stock = s.Id_stock
                    WHERE s.Id_pharmacie = p.Id_pharmacie) as total_reservations
            FROM pharmacie p
            JOIN pharmacien ph ON p.Id_pharmacien = ph.Id_pharmacien
            JOIN utilisateur u ON ph.Id_utilisateur = u.Id_utilisateur
            WHERE p.Id_pharmacie = :id
        ");
        $stmtV->execute([':id' => $view_id]);
        $view_pharmacie = $stmtV->fetch();

        if ($view_pharmacie) {
            // FIELD() ordonne les jours dans l'ordre chronologique de la semaine
            $joursOrdre = "FIELD(Jour_semaine, 'lundi','mardi','mercredi','jeudi','vendredi','samedi','dimanche')";
            $stmtP = $pdo->prepare("SELECT * FROM jour_permanence WHERE Id_pharmacie = :id ORDER BY " . $joursOrdre);
            $stmtP->execute([':id' => $view_id]);
            $view_permanences = $stmtP->fetchAll();
        }
    } catch(PDOException $e) {
        $message = "Erreur lors du chargement du détail : " . $e->getMessage();
        $message_type = "danger";
    }
}

$page_title = 'Gestion des pharmacies - Administration PharmaGeo';
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
        .sidebar .nav-menu .nav-item .badge-notif {
            margin-left: auto;
            background: #e74c3c;
            color: white;
            padding: 2px 10px;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 700;
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
            .sidebar { transform: translateX(-100%); width: 280px; }
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
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
        .stat-card .stat-icon.red { background: rgba(231,76,60,0.1); color: #e74c3c; }

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
        }

        .filters-bar .filter-btn {
            padding: 8px 20px;
            border-radius: 50px;
            border: 2px solid #e0e0e0;
            background: transparent;
            font-weight: 600;
            font-size: 0.85rem;
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

        .filters-bar .filter-btn .badge-count {
            background: rgba(255,255,255,0.2);
            padding: 0 8px;
            border-radius: 50px;
            font-size: 0.7rem;
            margin-left: 4px;
        }

        .filters-bar .filter-btn.active .badge-count {
            background: rgba(255,255,255,0.3);
        }

        .search-box {
            display: flex;
            gap: 8px;
            align-items: center;
            background: var(--white);
            border-radius: 50px;
            padding: 4px 4px 4px 16px;
            box-shadow: var(--shadow-sm);
            border: 2px solid transparent;
            transition: var(--transition);
            flex: 1;
            min-width: 200px;
            max-width: 400px;
            margin-left: auto;
        }

        .search-box:focus-within {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 4px rgba(42,195,42,0.1);
        }

        .search-box .form-control {
            border: none;
            padding: 8px 0;
            font-size: 0.9rem;
            background: transparent;
            flex: 1;
        }

        .search-box .form-control:focus { box-shadow: none; }

        .search-box .btn-search {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white;
            border: none;
            padding: 8px 18px;
            border-radius: 50px;
            font-weight: 600;
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

        .pharmacy-avatar {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .pharmacy-avatar.green { background: rgba(42,195,42,0.1); color: var(--primary-green); }
        .pharmacy-avatar.orange { background: rgba(200,120,30,0.1); color: var(--primary-orange); }
        .pharmacy-avatar.blue { background: rgba(52,152,219,0.1); color: #3498db; }

        .pharmacy-name { font-weight: 700; font-size: 0.95rem; }
        .pharmacy-address { font-size: 0.8rem; color: var(--gray-text); }

        .badge-status {
            padding: 4px 14px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-status.active { background: rgba(42,195,42,0.1); color: var(--primary-green); }
        .badge-status.en_attente { background: rgba(200,120,30,0.1); color: var(--primary-orange); }
        .badge-status.suspendue { background: rgba(231,76,60,0.1); color: #e74c3c; }

        .badge-pharmacy {
            background: rgba(42,195,42,0.08);
            color: var(--primary-green);
            padding: 2px 10px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .btn-action-sm {
            padding: 6px 12px;
            border-radius: 8px;
            border: none;
            font-size: 0.8rem;
            font-weight: 600;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-action-sm.btn-approve { background: rgba(42,195,42,0.1); color: var(--primary-green); }
        .btn-action-sm.btn-approve:hover { background: var(--primary-green); color: white; }
        .btn-action-sm.btn-reject { background: rgba(231,76,60,0.1); color: #e74c3c; }
        .btn-action-sm.btn-reject:hover { background: #e74c3c; color: white; }
        .btn-action-sm.btn-view { background: rgba(52,152,219,0.1); color: #3498db; }
        .btn-action-sm.btn-view:hover { background: #3498db; color: white; }
        .btn-action-sm.btn-delete { background: rgba(231,76,60,0.1); color: #e74c3c; }
        .btn-action-sm.btn-delete:hover { background: #e74c3c; color: white; }
        .btn-action-sm.btn-edit { background: rgba(200,120,30,0.1); color: var(--primary-orange); }
        .btn-action-sm.btn-edit:hover { background: var(--primary-orange); color: white; }

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

        .empty-state i { font-size: 4rem; color: #ddd; margin-bottom: 16px; }
        .empty-state h5 { font-weight: 700; color: var(--dark-text); }
        .empty-state p { color: var(--gray-text); max-width: 400px; margin: 0 auto; }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .filters-bar { flex-direction: column; align-items: stretch; }
            .search-box { max-width: 100%; margin-left: 0; }
            .table-container { padding: 12px; overflow-x: auto; }
            .table-custom { font-size: 0.8rem; min-width: 700px; }
            .table-custom thead th,
            .table-custom tbody td { padding: 8px 6px; }
        }

        @media (max-width: 400px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card { padding: 12px; }
            .stat-card .stat-number { font-size: 1.2rem; }
            .stat-card .stat-label { font-size: 0.7rem; }
            .stat-card .stat-icon { width: 32px; height: 32px; font-size: 0.9rem; }
        }

        /* ===== BANNIÈRE VALIDATION ===== */
        .validation-banner {
            background: linear-gradient(135deg, rgba(200,120,30,0.1), rgba(200,120,30,0.05));
            border-left: 4px solid var(--primary-orange);
            border-radius: var(--radius);
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .validation-banner .banner-text {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .validation-banner .banner-text i {
            font-size: 1.5rem;
            color: var(--primary-orange);
        }

        .validation-banner .banner-text h6 {
            margin: 0;
            font-weight: 700;
        }

        .validation-banner .banner-text p {
            margin: 0;
            color: var(--gray-text);
            font-size: 0.85rem;
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
            <a href="<?php echo BASE_URL; ?>admin/pharmacies.php" class="nav-item active">
                <i class="fas fa-store"></i>
                <span>Pharmacies</span>
                <?php if ($stats['attente'] > 0): ?>
                    <span class="badge-notif"><?php echo $stats['attente']; ?></span>
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
        <div class="top-bar">
            <div>
                <div class="d-flex align-items-center gap-3">
                    <button class="toggle-sidebar" id="toggleSidebar">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="page-title">
                        <h1>Gestion des <span class="highlight">pharmacies</span></h1>
                        <p><i class="fas fa-store me-1" style="color: var(--primary-green);"></i> Gérez toutes les pharmacies de la plateforme</p>
                    </div>
                </div>
            </div>
            <div class="page-actions">
                <a href="<?php echo BASE_URL; ?>admin/pharmacies.php?action=validation" class="btn-action btn-primary-custom">
                    <i class="fas fa-check-double"></i> Valider les pharmacies
                    <?php if ($stats['attente'] > 0): ?>
                        <span class="badge bg-white text-dark ms-1" style="font-size: 0.65rem; padding: 0 8px;"><?php echo $stats['attente']; ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </div>

        <!-- ===== STATISTIQUES ===== -->
        <div class="stats-grid animate-fade-up">
            <div class="stat-card delay-1">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">Total pharmacies</div>
                    </div>
                    <div class="stat-icon blue"><i class="fas fa-store"></i></div>
                </div>
            </div>
            <div class="stat-card delay-2">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['actives']; ?></div>
                        <div class="stat-label">Pharmacies actives</div>
                    </div>
                    <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                </div>
            </div>
            <div class="stat-card delay-3">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['attente']; ?></div>
                        <div class="stat-label">En attente</div>
                    </div>
                    <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
                </div>
            </div>
            <div class="stat-card delay-4">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['suspendues']; ?></div>
                        <div class="stat-label">Suspendues</div>
                    </div>
                    <div class="stat-icon red"><i class="fas fa-ban"></i></div>
                </div>
            </div>
        </div>

        <!-- ===== BANNIÈRE VALIDATION ===== -->
        <?php if ($action === 'validation' || $action === 'valider'): ?>
            <div class="validation-banner animate-fade-up delay-1">
                <div class="banner-text">
                    <i class="fas fa-clipboard-check"></i>
                    <div>
                        <h6>Mode validation</h6>
                        <p>Vous consultez uniquement les pharmacies en attente de validation.</p>
                    </div>
                </div>
                <a href="<?php echo BASE_URL; ?>admin/pharmacies.php" class="btn btn-sm btn-outline-secondary" style="border-radius: 50px; padding: 6px 20px; text-decoration: none;">
                    <i class="fas fa-times"></i> Quitter le mode validation
                </a>
            </div>
        <?php endif; ?>

        <!-- ===== MESSAGES ===== -->
        <?php if (!empty($message)): ?>
            <div class="alert-custom alert-custom-<?php echo $message_type; ?> animate-fade-up">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'exclamation-triangle' : 'exclamation-circle'); ?>"></i>
                <div><?php echo $message; ?></div>
            </div>
        <?php endif; ?>

        <!-- ===== FILTRES ===== -->
        <div class="filters-bar animate-fade-up delay-1">
            <a href="<?php echo BASE_URL; ?>admin/pharmacies.php?filtre=toutes" 
               class="filter-btn <?php echo $filtre === 'toutes' && $action !== 'validation' && $action !== 'valider' ? 'active' : ''; ?>">
                Toutes <span class="badge-count"><?php echo $stats['total']; ?></span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/pharmacies.php?filtre=active" 
               class="filter-btn <?php echo $filtre === 'active' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle" style="color: var(--primary-green);"></i> Actives
                <span class="badge-count"><?php echo $stats['actives']; ?></span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/pharmacies.php?filtre=en_attente" 
               class="filter-btn <?php echo $filtre === 'en_attente' || $action === 'validation' || $action === 'valider' ? 'active' : ''; ?>">
                <i class="fas fa-clock" style="color: var(--primary-orange);"></i> En attente
                <span class="badge-count"><?php echo $stats['attente']; ?></span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/pharmacies.php?filtre=suspendue" 
               class="filter-btn <?php echo $filtre === 'suspendue' ? 'active' : ''; ?>">
                <i class="fas fa-ban" style="color: #e74c3c;"></i> Suspendues
                <span class="badge-count"><?php echo $stats['suspendues']; ?></span>
            </a>

            <form method="GET" class="search-box">
                <input type="hidden" name="filtre" value="<?php echo $filtre; ?>">
                <input type="hidden" name="action" value="<?php echo $action; ?>">
                <input type="text" name="search" class="form-control" placeholder="Rechercher..." 
                       value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i>
                </button>
                <?php if (!empty($search)): ?>
                    <a href="<?php echo BASE_URL; ?>admin/pharmacies.php?filtre=<?php echo $filtre; ?><?php echo $action === 'validation' || $action === 'valider' ? '&action=validation' : ''; ?>" class="btn-search" style="background: #e74c3c;">
                        <i class="fas fa-times"></i>
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- ===== TABLEAU ===== -->
        <div class="table-container animate-fade-up delay-2">
            <?php if (!empty($pharmacies)): ?>
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead>
                            <tr>
                                <th>Pharmacie</th>
                                <th>Pharmacien</th>
                                <th>Contact</th>
                                <th>Statut</th>
                                <th>Médicaments</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pharmacies as $pharm): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="pharmacy-avatar <?php 
                                                echo $pharm['Statut'] === 'active' ? 'green' : ($pharm['Statut'] === 'en_attente' ? 'orange' : 'blue'); 
                                            ?>">
                                                <i class="fas fa-store"></i>
                                            </div>
                                            <div>
                                                <div class="pharmacy-name"><?php echo htmlspecialchars($pharm['Nom']); ?></div>
                                                <div class="pharmacy-address">
                                                    <i class="fas fa-map-marker-alt"></i>
                                                    <?php echo htmlspecialchars($pharm['Quartier'] ?? 'Non renseigné'); ?>,
                                                    <?php echo htmlspecialchars($pharm['Commune'] ?? 'Porto-Novo'); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div>
                                            <div><?php echo htmlspecialchars($pharm['Prenom'] . ' ' . $pharm['Nom']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($pharm['Email'] ?? ''); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <div>
                                            <div><i class="fas fa-phone me-1"></i> <?php echo htmlspecialchars($pharm['Telephone'] ?? 'Non disponible'); ?></div>
                                            <small class="text-muted">
                                                <i class="fas fa-user me-1"></i> 
                                                <?php echo htmlspecialchars($pharm['UserTelephone'] ?? 'Non disponible'); ?>
                                            </small>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge-status <?php echo $pharm['Statut']; ?>">
                                            <?php 
                                                $statuts = [
                                                    'active' => 'Active',
                                                    'en_attente' => 'En attente',
                                                    'suspendue' => 'Suspendue'
                                                ];
                                                echo $statuts[$pharm['Statut']] ?? $pharm['Statut'];
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge-pharmacy">
                                            <i class="fas fa-capsules me-1"></i>
                                            <?php echo $pharm['total_medicaments'] ?? 0; ?>
                                        </span>
                                        <br>
                                        <small class="text-muted"><?php echo $pharm['total_disponibles'] ?? 0; ?> disponibles</small>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex gap-1 justify-content-end flex-wrap">
                                            <?php if ($pharm['Statut'] === 'en_attente'): ?>
                                                <a href="<?php echo BASE_URL; ?>admin/pharmacies.php?action=valider&id=<?php echo $pharm['Id_pharmacie']; ?>&filtre=<?php echo $filtre; ?><?php echo $action === 'validation' || $action === 'valider' ? '&action=validation' : ''; ?>&search=<?php echo urlencode($search); ?>" 
                                                   class="btn-action-sm btn-approve" 
                                                   onclick="return confirm('Valider cette pharmacie ?')">
                                                    <i class="fas fa-check"></i> Valider
                                                </a>
                                                <a href="<?php echo BASE_URL; ?>admin/pharmacies.php?action=rejeter&id=<?php echo $pharm['Id_pharmacie']; ?>&filtre=<?php echo $filtre; ?><?php echo $action === 'validation' || $action === 'valider' ? '&action=validation' : ''; ?>&search=<?php echo urlencode($search); ?>" 
                                                   class="btn-action-sm btn-reject"
                                                   onclick="return confirm('Rejeter cette pharmacie ?')">
                                                    <i class="fas fa-times"></i> Rejeter
                                                </a>
                                            <?php endif; ?>
                                            <a href="<?php echo BASE_URL; ?>admin/pharmacies.php?action=view&id=<?php echo $pharm['Id_pharmacie']; ?>&filtre=<?php echo $filtre; ?><?php echo $action === 'validation' || $action === 'valider' ? '&action=validation' : ''; ?>&search=<?php echo urlencode($search); ?>" 
                                               class="btn-action-sm btn-view">
                                                <i class="fas fa-eye"></i> Voir
                                            </a>
                                            <a href="<?php echo BASE_URL; ?>admin/pharmacies.php?action=edit&id=<?php echo $pharm['Id_pharmacie']; ?>" 
                                               class="btn-action-sm btn-edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="<?php echo BASE_URL; ?>admin/pharmacies.php?action=supprimer&id=<?php echo $pharm['Id_pharmacie']; ?>&filtre=<?php echo $filtre; ?><?php echo $action === 'validation' || $action === 'valider' ? '&action=validation' : ''; ?>&search=<?php echo urlencode($search); ?>" 
                                               class="btn-action-sm btn-delete"
                                               onclick="return confirmDelete(<?php echo $pharm['Id_pharmacie']; ?>, '<?php echo addslashes($pharm['Nom']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-store"></i>
                    <h5>Aucune pharmacie trouvée</h5>
                    <p>
                        <?php if (!empty($search)): ?>
                            Aucun résultat pour "<strong><?php echo htmlspecialchars($search); ?></strong>"
                        <?php elseif ($action === 'validation' || $action === 'valider'): ?>
                            Aucune pharmacie en attente de validation. 🎉
                        <?php elseif ($filtre !== 'toutes'): ?>
                            Aucune pharmacie avec le statut "<strong><?php echo $filtre; ?></strong>"
                        <?php else: ?>
                            Aucune pharmacie n'est encore enregistrée sur la plateforme.
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
            window.confirmDelete = function(id, nom) {
                event.preventDefault();
                Swal.fire({
                    title: '⚠️ Supprimer la pharmacie',
                    text: `Voulez-vous vraiment supprimer la pharmacie "${nom}" ? Cette action est irréversible.`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#e74c3c',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Oui, supprimer',
                    cancelButtonText: 'Annuler'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = `<?php echo BASE_URL; ?>admin/pharmacies.php?action=supprimer&id=${id}&filtre=<?php echo $filtre; ?><?php echo $action === 'validation' || $action === 'valider' ? '&action=validation' : ''; ?>&search=<?php echo urlencode($search); ?>`;
                    }
                });
                return false;
            };

            // ===== KEYBOARD SHORTCUTS =====
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && sidebar.classList.contains('open')) {
                    toggleSidebar();
                }
                if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                    e.preventDefault();
                    const searchInput = document.querySelector('.search-box .form-control');
                    if (searchInput) {
                        searchInput.focus();
                        searchInput.select();
                    }
                }
                if (e.altKey && e.key === 'v') {
                    e.preventDefault();
                    window.location.href = '<?php echo BASE_URL; ?>admin/pharmacies.php?action=validation';
                }
            });

            <?php if (!empty($view_pharmacie)): ?>
            // ===== OUVERTURE AUTO DU MODAL DÉTAIL =====
            window.addEventListener('load', function() {
                var viewModalEl = document.getElementById('viewModal');
                if (viewModalEl) {
                    var modal = new bootstrap.Modal(viewModalEl);
                    modal.show();
                }
            });
            <?php endif; ?>

            console.log('%c PharmaGeo - Gestion des pharmacies ', 'background: linear-gradient(135deg, rgb(42, 195, 42), hsl(25, 75%, 47%)); color: white; font-size: 16px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');

        });
    </script>

    <?php if (!empty($view_pharmacie)): ?>
    <!-- ===== MODAL DÉTAIL PHARMACIE ===== -->
    <div class="modal fade" id="viewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius: 16px; overflow: hidden;">
                <div class="modal-header" style="background: linear-gradient(135deg, rgb(42,195,42), hsl(25,75%,47%)); color: white; border: none;">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-store me-2"></i>Détails de la pharmacie
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-4">
                        <div class="col-md-4 text-center">
                            <?php if (!empty($view_pharmacie['Logo'])): ?>
                                <img src="<?php echo htmlspecialchars($view_pharmacie['Logo']); ?>" alt="Logo" 
                                     class="rounded-circle mb-3" style="width: 120px; height: 120px; object-fit: cover;">
                            <?php else: ?>
                                <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3" 
                                     style="width: 120px; height: 120px; background: linear-gradient(135deg, rgba(42,195,42,.15), rgba(200,120,30,.15)); color: var(--primary-green);">
                                    <i class="fas fa-store" style="font-size: 40px;"></i>
                                </div>
                            <?php endif; ?>
                            <h5 class="mb-1"><?php echo htmlspecialchars($view_pharmacie['Pharmacie_Nom']); ?></h5>
                            <span class="badge-status <?php echo $view_pharmacie['Statut']; ?>">
                                <?php 
                                    $statutMap = ['active'=>'Active','en_attente'=>'En attente','suspendue'=>'Suspendue'];
                                    echo $statutMap[$view_pharmacie['Statut']] ?? $view_pharmacie['Statut'];
                                ?>
                            </span>
                        </div>
                        <div class="col-md-8">
                            <h6 class="text-muted text-uppercase mb-3" style="font-size: .8rem; letter-spacing: .5px;">
                                <i class="fas fa-circle-info me-1"></i> Informations générales
                            </h6>
                            <div class="row g-2 small">
                                <div class="col-6"><strong>Adresse :</strong><br><?php echo htmlspecialchars($view_pharmacie['Adresse'] ?: 'Non renseignée'); ?></div>
                                <div class="col-6"><strong>Quartier :</strong><br><?php echo htmlspecialchars($view_pharmacie['Quartier'] ?: 'Non renseigné'); ?></div>
                                <div class="col-6"><strong>Commune :</strong><br><?php echo htmlspecialchars($view_pharmacie['Commune'] ?: 'Non renseignée'); ?></div>
                                <div class="col-6"><strong>Téléphone :</strong><br>
                                    <span class="text-nowrap"><?php echo htmlspecialchars($view_pharmacie['Telephone'] ?: 'Non disponible'); ?></span>
                                </div>
                                <div class="col-6"><strong>Email :</strong><br><?php echo htmlspecialchars($view_pharmacie['Email'] ?: 'Non renseigné'); ?></div>
                                <div class="col-6"><strong>Horaire :</strong><br><?php echo htmlspecialchars($view_pharmacie['Horaire'] ?: 'Non renseigné'); ?></div>
                            </div>
                            <hr>
                            <h6 class="text-muted text-uppercase mb-2" style="font-size: .8rem; letter-spacing: .5px;">
                                <i class="fas fa-user-tie me-1"></i> Pharmacien responsable
                            </h6>
                            <div class="row g-2 small">
                                <div class="col-6"><strong>Nom :</strong><br><?php echo htmlspecialchars($view_pharmacie['Prenom'] . ' ' . $view_pharmacie['Nom']); ?></div>
                                <div class="col-6"><strong>Numéro d'ordre :</strong><br><?php echo htmlspecialchars($view_pharmacie['Numero_ordre'] ?: 'Non renseigné'); ?></div>
                                <div class="col-6"><strong>Email :</strong><br><?php echo htmlspecialchars($view_pharmacie['Email']); ?></div>
                                <div class="col-6"><strong>Téléphone :</strong><br><?php echo htmlspecialchars($view_pharmacie['UserTelephone'] ?: 'Non disponible'); ?></div>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <div class="row g-3 text-center">
                        <div class="col-3">
                            <div class="border rounded p-3 bg-light">
                                <div class="fs-4 fw-bold" style="color: var(--primary-green);"><?php echo (int)$view_pharmacie['total_medicaments']; ?></div>
                                <div class="text-muted small">Médicaments</div>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="border rounded p-3 bg-light">
                                <div class="fs-4 fw-bold" style="color: var(--primary-orange);"><?php echo (int)$view_pharmacie['total_disponibles']; ?></div>
                                <div class="text-muted small">Disponibles</div>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="border rounded p-3 bg-light">
                                <div class="fs-4 fw-bold" style="color: #3498db;"><?php echo (int)$view_pharmacie['total_reservations']; ?></div>
                                <div class="text-muted small">Réservations</div>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="border rounded p-3 bg-light">
                                <div class="fs-4 fw-bold"><?php echo count($view_permanences); ?></div>
                                <div class="text-muted small">Jours ouverts</div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <h6 class="text-muted text-uppercase mb-2" style="font-size: .8rem; letter-spacing: .5px;">
                            <i class="fas fa-calendar-week me-1"></i> Jours de permanence
                        </h6>
                        <?php if (!empty($view_permanences)): ?>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($view_permanences as $jp): ?>
                                    <span class="badge rounded-pill" style="background: rgba(42,195,42,.15); color: #1b7a1b; padding: 6px 12px;">
                                        <i class="fas fa-calendar-day me-1"></i><?php echo ucfirst(htmlspecialchars($jp['Jour_semaine'])); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span class="text-muted"><i class="fas fa-info-circle me-1"></i>Aucun jour de permanence enregistré.</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <?php if ($view_pharmacie['Statut'] === 'en_attente'): ?>
                        <a href="<?php echo BASE_URL; ?>admin/pharmacies.php?action=valider&id=<?php echo $view_pharmacie['Id_pharmacie']; ?>&filtre=<?php echo $filtre; ?>&search=<?php echo urlencode($search); ?>" 
                           class="btn btn-success" style="border-radius: 50px; padding: 8px 24px;"
                           onclick="return confirm('Valider cette pharmacie ?')">
                            <i class="fas fa-check"></i> Valider
                        </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 50px; padding: 8px 24px;">
                        Fermer
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</body>
</html>