<?php
// ============================================================
// PharmaGeo — Administration : Utilisateurs
// Gestion des comptes : activation, suspension, suppression et
// changement de rôle des utilisateurs de la plateforme.
// ============================================================
// ===== ACTIVATION DES ERREURS =====
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ===== DÉFINITION DES CONSTANTES =====
define('BASE_URL', 'http://localhost/pharmageo/');

// ===== GESTION DES IMAGES (photos de profil) =====
require_once __DIR__ . '/../includes/images.php';

// ===== PAGINATION (10 éléments par page) =====
require_once __DIR__ . '/../includes/pagination.php';

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

// Activation d'un utilisateur
if (isset($_GET['action']) && $_GET['action'] === 'activer' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("UPDATE utilisateur SET Statut = 'actif' WHERE Id_utilisateur = :id");
        $stmt->execute([':id' => $id]);
        
        $log = $pdo->prepare("INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur) 
                              VALUES (CONCAT('Activation utilisateur ID: ', :id), :ip, :ua, 'succes', :user)");
        $log->execute([
            ':id' => $id,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ':user' => $_SESSION['user_id']
        ]);
        
        $message = "Utilisateur activé avec succès !";
        $message_type = "success";
    } catch(PDOException $e) {
        $message = "Erreur lors de l'activation : " . $e->getMessage();
        $message_type = "danger";
    }
}

// Suspension d'un utilisateur
if (isset($_GET['action']) && $_GET['action'] === 'suspendre' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("UPDATE utilisateur SET Statut = 'suspendu' WHERE Id_utilisateur = :id");
        $stmt->execute([':id' => $id]);
        
        $log = $pdo->prepare("INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur) 
                              VALUES (CONCAT('Suspension utilisateur ID: ', :id), :ip, :ua, 'succes', :user)");
        $log->execute([
            ':id' => $id,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ':user' => $_SESSION['user_id']
        ]);
        
        $message = "Utilisateur suspendu avec succès !";
        $message_type = "warning";
    } catch(PDOException $e) {
        $message = "Erreur lors de la suspension : " . $e->getMessage();
        $message_type = "danger";
    }
}

// Suppression d'un utilisateur
if (isset($_GET['action']) && $_GET['action'] === 'supprimer' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    // Empêcher la suppression de son propre compte
    if ($id == $_SESSION['user_id']) {
        $message = "Vous ne pouvez pas supprimer votre propre compte !";
        $message_type = "danger";
    } else {
        try {
            $stmt = $pdo->prepare("DELETE FROM utilisateur WHERE Id_utilisateur = :id");
            $stmt->execute([':id' => $id]);
            
            $log = $pdo->prepare("INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur) 
                                  VALUES (CONCAT('Suppression utilisateur ID: ', :id), :ip, :ua, 'succes', :user)");
            $log->execute([
                ':id' => $id,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':user' => $_SESSION['user_id']
            ]);
            
            $message = "Utilisateur supprimé avec succès !";
            $message_type = "danger";
        } catch(PDOException $e) {
            $message = "Erreur lors de la suppression : " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// Changement de rôle
if (isset($_GET['action']) && $_GET['action'] === 'changer_role' && isset($_GET['id']) && isset($_GET['role'])) {
    $id = (int)$_GET['id'];
    $role = $_GET['role'];
    
    // Empêcher le changement de son propre rôle
    if ($id == $_SESSION['user_id']) {
        $message = "Vous ne pouvez pas modifier votre propre rôle !";
        $message_type = "danger";
    } elseif (!in_array($role, ['patient', 'pharmacien', 'administrateur'])) {
        $message = "Rôle invalide !";
        $message_type = "danger";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE utilisateur SET Role = :role WHERE Id_utilisateur = :id");
            $stmt->execute([':role' => $role, ':id' => $id]);
            
            $log = $pdo->prepare("INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur) 
                                  VALUES (CONCAT('Changement rôle utilisateur ID: ', :id, ' vers ', :role), :ip, :ua, 'succes', :user)");
            $log->execute([
                ':id' => $id,
                ':role' => $role,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':user' => $_SESSION['user_id']
            ]);
            
            $message = "Rôle de l'utilisateur modifié avec succès !";
            $message_type = "success";
        } catch(PDOException $e) {
            $message = "Erreur lors du changement de rôle : " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// ===== DÉTAILS D'UN UTILISATEUR (action=view) =====
$vue_utilisateur = null;
if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['id'])) {
    $vue_id = (int)$_GET['id'];
    try {
        $stmtV = $pdo->prepare("SELECT * FROM utilisateur WHERE Id_utilisateur = :id");
        $stmtV->execute([':id' => $vue_id]);
        $vue_utilisateur = $stmtV->fetch() ?: null;
    } catch(PDOException $e) {
        $vue_utilisateur = null;
        $message = "Erreur lors du chargement de l'utilisateur : " . $e->getMessage();
        $message_type = "danger";
    }
}

// ===== RÉCUPÉRATION DES STATISTIQUES =====
try {
    $stats = [
        'total' => $pdo->query("SELECT COUNT(*) as total FROM utilisateur")->fetch()['total'] ?? 0,
        'actifs' => $pdo->query("SELECT COUNT(*) as total FROM utilisateur WHERE Statut = 'actif'")->fetch()['total'] ?? 0,
        'suspendus' => $pdo->query("SELECT COUNT(*) as total FROM utilisateur WHERE Statut = 'suspendu'")->fetch()['total'] ?? 0,
        'patients' => $pdo->query("SELECT COUNT(*) as total FROM utilisateur WHERE Role = 'patient'")->fetch()['total'] ?? 0,
        'pharmaciens' => $pdo->query("SELECT COUNT(*) as total FROM utilisateur WHERE Role = 'pharmacien'")->fetch()['total'] ?? 0,
        'administrateurs' => $pdo->query("SELECT COUNT(*) as total FROM utilisateur WHERE Role = 'administrateur'")->fetch()['total'] ?? 0
    ];
} catch(PDOException $e) {
    $stats = ['total' => 0, 'actifs' => 0, 'suspendus' => 0, 'patients' => 0, 'pharmaciens' => 0, 'administrateurs' => 0];
}

// ===== RÉCUPÉRATION DE LA LISTE DES UTILISATEURS =====
$filtre_role = $_GET['role_filtre'] ?? $_GET['role'] ?? 'tous';
$filtre_statut = $_GET['statut'] ?? 'tous';
$search = $_GET['search'] ?? '';

$total_utilisateurs = 0;
$pagination = pagination_calculer(0);

try {
    $where = ["1=1"];
    $params = [];

    if ($filtre_role !== 'tous') {
        $where[] = "Role = :role";
        $params[':role'] = $filtre_role;
    }

    if ($filtre_statut !== 'tous') {
        $where[] = "Statut = :statut";
        $params[':statut'] = $filtre_statut;
    }

    if (!empty($search)) {
        $where[] = "(Nom LIKE :search OR Prenom LIKE :search OR Email LIKE :search OR Telephone LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    $clauses = ' WHERE ' . implode(' AND ', $where);

    // Nombre total de résultats (filtres compris) pour la pagination
    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM utilisateur$clauses");
    $stmt_count->execute($params);
    $total_utilisateurs = (int) $stmt_count->fetchColumn();

    $pagination = pagination_calculer($total_utilisateurs);

    // Requête de la page courante uniquement (pagination côté serveur)
    $sql = "SELECT * FROM utilisateur$clauses"
         . " ORDER BY Id_utilisateur DESC" . pagination_sql_limite($pagination);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $utilisateurs = $stmt->fetchAll();
    
    // Récupérer les informations supplémentaires pour les pharmaciens
    foreach ($utilisateurs as &$user) {
        if ($user['Role'] === 'pharmacien') {
            $pharm = $pdo->prepare("SELECT * FROM pharmacien WHERE Id_utilisateur = :id");
            $pharm->execute([':id' => $user['Id_utilisateur']]);
            $user['pharmacien_info'] = $pharm->fetch();
        }
    }
    // Indispensable : sans ceci, $user reste une référence vers la dernière
    // ligne et le dernier utilisateur de la liste se retrouve écrasé.
    unset($user);
    
} catch(PDOException $e) {
    $utilisateurs = [];
    $message = "Erreur lors du chargement des utilisateurs : " . $e->getMessage();
    $message_type = "danger";
}

$page_title = 'Gestion des utilisateurs - Administration PharmaGeo';
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
            display: flex;
            flex-direction: column;
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
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 18px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

.sidebar .brand .brand-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
         color: #fff;}

.sidebar .brand h4 {
            font-size: 1.1rem;
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

        .sidebar .nav-menu { padding: 20px 15px; flex: 1; }

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
            background: linear-gradient(135deg, rgba(22,163,74,0.2), rgba(21,128,61,0.2));
            color: var(--primary-green);
            border-left: 3px solid var(--primary-green);
        }

        .sidebar .nav-menu .nav-item i { width: 20px; text-align: center; font-size: 1rem; }

        .sidebar .user-profile {
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
        a.user-profile { text-decoration: none; color: inherit; transition: background-color 0.2s ease; }
        a.user-profile:hover { background-color: rgba(255,255,255,0.07); }
        a.user-profile .profile-chevron { margin-left: auto; font-size: 0.7rem; color: rgba(255,255,255,0.45); }
        a.user-profile:hover .profile-chevron { color: #fff; }

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

        .stat-card .stat-icon.red {
            background: rgba(220,38,38,0.1);
            color: #DC2626;
        }

        .stat-card .stat-icon.purple {
            background: rgba(155,89,182,0.1);
            color: #1F2937;
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
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            align-items: center;
        }

        .filters-bar .filter-btn {
            padding: 8px 20px;
            border-radius: 50px;
            border: 2px solid #D9E9DF;
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
            box-shadow: 0 0 0 4px rgba(22,163,74,0.1);
        }

        .search-box .form-control {
            border: none;
            padding: 8px 0;
            font-size: 0.9rem;
            background: transparent;
            flex: 1;
        }

        .search-box .form-control:focus {
            box-shadow: none;
        }

        .search-box .btn-search {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white;
            border: none;
            padding: 8px 18px;
            border-radius: 50px;
            font-weight: 600;
            transition: var(--transition);
        }

        .search-box .btn-search:hover {
            transform: scale(1.05);
        }

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
            border-bottom: 2px solid #F0FDF4;
            padding: 12px 10px;
        }

        .table-custom tbody td {
            padding: 14px 10px;
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

        .user-avatar {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: 700;
            flex-shrink: 0;
        }

        .user-avatar.green {
            background: rgba(22,163,74,0.1);
            color: var(--primary-green);
        }

        .user-avatar.orange {
            background: rgba(21,128,61,0.1);
            color: var(--primary-orange);
        }

        .user-avatar.blue {
            background: rgba(22,83,52,0.1);
            color: #166534;
        }

        .user-avatar.purple {
            background: rgba(155,89,182,0.1);
            color: #1F2937;
        }

        .user-avatar.red {
            background: rgba(220,38,38,0.1);
            color: #DC2626;
        }

        /* Photo de profil : l'image occupe toute la pastille. */
        .user-avatar {
            overflow: hidden;
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 12px;
            display: block;
        }

        .user-name {
            font-weight: 700;
            font-size: 0.95rem;
        }

        .user-email {
            font-size: 0.8rem;
            color: var(--gray-text);
        }

        .user-email i {
            margin-right: 4px;
            font-size: 0.7rem;
        }

        .badge-status {
            padding: 4px 14px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-status.actif {
            background: rgba(22,163,74,0.1);
            color: var(--primary-green);
        }

        .badge-status.suspendu {
            background: rgba(21,128,61,0.1);
            color: var(--primary-orange);
        }

        .badge-role {
            padding: 4px 14px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-role.patient {
            background: rgba(22,83,52,0.1);
            color: #166534;
        }

        .badge-role.pharmacien {
            background: rgba(22,163,74,0.1);
            color: var(--primary-green);
        }

        .badge-role.administrateur {
            background: rgba(21,128,61,0.1);
            color: var(--primary-orange);
        }

        .btn-action-sm {
            padding: 6px 12px;
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

        .btn-action-sm.btn-view { background: rgba(22,83,52,0.1); color: #166534; }

        .btn-action-sm.btn-view:hover { background: #166534; color: white; }

        .btn-action-sm.btn-activate {
            background: rgba(22,163,74,0.1);
            color: var(--primary-green);
        }

        .btn-action-sm.btn-activate:hover {
            background: var(--primary-green);
            color: white;
        }

        .btn-action-sm.btn-suspend {
            background: rgba(21,128,61,0.1);
            color: var(--primary-orange);
        }

        .btn-action-sm.btn-suspend:hover {
            background: var(--primary-orange);
            color: white;
        }

        .btn-action-sm.btn-delete {
            background: rgba(220,38,38,0.1);
            color: #DC2626;
        }

        .btn-action-sm.btn-delete:hover {
            background: #DC2626;
            color: white;
        }

        .btn-action-sm.btn-role {
            background: rgba(155,89,182,0.1);
            color: #1F2937;
        }

        .btn-action-sm.btn-role:hover {
            background: #1F2937;
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
            background: rgba(22,163,74,0.08);
            color: var(--primary-green);
            border-left: 4px solid var(--primary-green);
        }

        .alert-custom-danger {
            background: rgba(220,38,38,0.08);
            color: #DC2626;
            border-left: 4px solid #DC2626;
        }

        .alert-custom-warning {
            background: rgba(21,128,61,0.08);
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
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            .filters-bar {
                flex-direction: column;
                align-items: stretch;
            }
            .search-box {
                max-width: 100%;
                margin-left: 0;
            }
            .table-container {
                padding: 12px;
                overflow-x: auto;
            }
            .table-custom {
                font-size: 0.8rem;
                min-width: 700px;
            }
            .table-custom thead th,
            .table-custom tbody td {
                padding: 8px 6px;
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
                font-size: 1.2rem;
            }
            .stat-card .stat-label {
                font-size: 0.7rem;
            }
            .stat-card .stat-icon {
                width: 32px;
                height: 32px;
                font-size: 0.9rem;
            }
        }

        /* ===== DROPDOWN ROLE ===== */
        .dropdown-role-menu {
            min-width: 180px;
            padding: 8px;
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            border: none;
        }

        .dropdown-role-menu .dropdown-item {
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .dropdown-role-menu .dropdown-item:hover {
            background: rgba(22,163,74,0.05);
            color: var(--primary-green);
        }

        .dropdown-role-menu .dropdown-item i {
            width: 20px;
            text-align: center;
        }    </style>
    <link rel="stylesheet" href="../assets/css/responsive.css">    <!-- Palette verte PharmaGeo -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/palette.css">
    <!-- Favicon & identité visuelle PharmaGéo -->
    <link rel="icon" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon.ico" sizes="any">
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo BASE_URL; ?>assets/identite/favicons/apple-touch-icon.png">
    <link rel="manifest" href="<?php echo BASE_URL; ?>assets/identite/favicons/site.webmanifest">
    <meta property="og:image" content="<?php echo BASE_URL; ?>assets/identite/reseaux-sociaux/og-image-1200x630.png">
</head>
<body>

    <!-- ===== SIDEBAR OVERLAY ===== -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar" id="sidebar">
        <a href="<?php echo BASE_URL; ?>index.php" class="brand" style="text-decoration: none;">
            <div class="brand-icon"><img class="pg-brand-logo" src="<?php echo BASE_URL; ?>assets/identite/icones/pharmageo-icone-blanc.svg" alt=""></div>
            <div><h4>PharmaGeo</h4><small>Administration</small></div>
        </a>

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
            <a href="<?php echo BASE_URL; ?>admin/utilisateurs.php" class="nav-item active">
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

        </nav>

        <a class="user-profile" href="<?php echo BASE_URL; ?>admin/profil.php" title="Voir mon profil">
            <div class="avatar">
                <?php if (!empty($_SESSION['photo']) && pharma_image_disponible($_SESSION['photo'])): ?>
                    <img src="<?php echo htmlspecialchars(pharma_image_url($_SESSION['photo'])); ?>" alt="">
                <?php else: ?>
                    <?php echo strtoupper(substr($_SESSION['prenom'] ?? 'A', 0, 1) . substr($_SESSION['nom'] ?? 'D', 0, 1)); ?>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <div class="name"><?php echo htmlspecialchars(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')); ?></div>
                <div class="role"><i class="fas fa-crown me-1" style="color: #DC2626;"></i> Administrateur</div>
            <i class="fas fa-chevron-right profile-chevron"></i>
        </a>
    
    <?php require_once __DIR__ . '/../includes/reinitialiser.php'; echo html_bloc_reinitialiser(); ?>
    <a href="<?php echo BASE_URL; ?>deconnexion.php" class="nav-item" style="color: #FEF2F2; padding: 14px 20px; border-top: 1px solid rgba(255,255,255,0.15); margin-top: auto; text-decoration: none;">
        <i class="fas fa-sign-out-alt"></i>
        <span>Déconnexion</span>
    </a>
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
                        <h1>Gestion des <span class="highlight">utilisateurs</span></h1>
                        <p><i class="fas fa-users me-1" style="color: var(--primary-green);"></i> Gérez tous les utilisateurs de la plateforme</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== STATISTIQUES ===== -->
        <div class="stats-grid animate-fade-up">
            <div class="stat-card delay-1">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">Total utilisateurs</div>
                    </div>
                    <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                </div>
            </div>
            <div class="stat-card delay-2">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['actifs']; ?></div>
                        <div class="stat-label">Actifs</div>
                    </div>
                    <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                </div>
            </div>
            <div class="stat-card delay-3">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['suspendus']; ?></div>
                        <div class="stat-label">Suspendus</div>
                    </div>
                    <div class="stat-icon orange"><i class="fas fa-pause-circle"></i></div>
                </div>
            </div>
            <div class="stat-card delay-4">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['patients']; ?></div>
                        <div class="stat-label">Patients</div>
                    </div>
                    <div class="stat-icon blue"><i class="fas fa-user"></i></div>
                </div>
            </div>
            <div class="stat-card delay-5">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['pharmaciens']; ?></div>
                        <div class="stat-label">Pharmaciens</div>
                    </div>
                    <div class="stat-icon green"><i class="fas fa-user-md"></i></div>
                </div>
            </div>
            <div class="stat-card delay-6">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['administrateurs']; ?></div>
                        <div class="stat-label">Administrateurs</div>
                    </div>
                    <div class="stat-icon purple"><i class="fas fa-crown"></i></div>
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
        <div class="filters-bar animate-fade-up delay-1">
            <!-- Filtre par rôle -->
            <a href="<?php echo BASE_URL; ?>admin/utilisateurs.php?role=tous&statut=<?php echo $filtre_statut; ?>&search=<?php echo urlencode($search); ?>" 
               class="filter-btn <?php echo $filtre_role === 'tous' ? 'active' : ''; ?>">
                Tous <span class="badge-count"><?php echo $stats['total']; ?></span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/utilisateurs.php?role=patient&statut=<?php echo $filtre_statut; ?>&search=<?php echo urlencode($search); ?>" 
               class="filter-btn <?php echo $filtre_role === 'patient' ? 'active' : ''; ?>">
                <i class="fas fa-user"></i> Patients
                <span class="badge-count"><?php echo $stats['patients']; ?></span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/utilisateurs.php?role=pharmacien&statut=<?php echo $filtre_statut; ?>&search=<?php echo urlencode($search); ?>" 
               class="filter-btn <?php echo $filtre_role === 'pharmacien' ? 'active' : ''; ?>">
                <i class="fas fa-user-md"></i> Pharmaciens
                <span class="badge-count"><?php echo $stats['pharmaciens']; ?></span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/utilisateurs.php?role=administrateur&statut=<?php echo $filtre_statut; ?>&search=<?php echo urlencode($search); ?>" 
               class="filter-btn <?php echo $filtre_role === 'administrateur' ? 'active' : ''; ?>">
                <i class="fas fa-crown"></i> Admins
                <span class="badge-count"><?php echo $stats['administrateurs']; ?></span>
            </a>

            <!-- Filtre par statut -->
            <a href="<?php echo BASE_URL; ?>admin/utilisateurs.php?role=<?php echo $filtre_role; ?>&statut=tous&search=<?php echo urlencode($search); ?>" 
               class="filter-btn <?php echo $filtre_statut === 'tous' ? 'active' : ''; ?>">
                Tous statuts
            </a>
            <a href="<?php echo BASE_URL; ?>admin/utilisateurs.php?role=<?php echo $filtre_role; ?>&statut=actif&search=<?php echo urlencode($search); ?>" 
               class="filter-btn <?php echo $filtre_statut === 'actif' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle" style="color: var(--primary-green);"></i> Actifs
            </a>
            <a href="<?php echo BASE_URL; ?>admin/utilisateurs.php?role=<?php echo $filtre_role; ?>&statut=suspendu&search=<?php echo urlencode($search); ?>" 
               class="filter-btn <?php echo $filtre_statut === 'suspendu' ? 'active' : ''; ?>">
                <i class="fas fa-pause-circle" style="color: var(--primary-orange);"></i> Suspendus
            </a>

            <!-- Recherche -->
            <form method="GET" class="search-box">
                <input type="hidden" name="role" value="<?php echo $filtre_role; ?>">
                <input type="hidden" name="statut" value="<?php echo $filtre_statut; ?>">
                <input type="text" name="search" class="form-control" placeholder="Rechercher..." 
                       value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i>
                </button>
                <?php if (!empty($search)): ?>
                    <a href="<?php echo BASE_URL; ?>admin/utilisateurs.php?role=<?php echo $filtre_role; ?>&statut=<?php echo $filtre_statut; ?>" class="btn-search" style="background: #DC2626;">
                        <i class="fas fa-times"></i>
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- ===== TABLEAU ===== -->
        <div class="table-container animate-fade-up delay-2">
            <?php if (!empty($utilisateurs)): ?>
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead>
                            <tr>
                                <th>Utilisateur</th>
                                <th>Contact</th>
                                <th>Rôle</th>
                                <th>Statut</th>
                                <th>Date d'inscription</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($utilisateurs as $user): 
                                $avatarClass = $user['Role'] === 'administrateur' ? 'purple' : ($user['Role'] === 'pharmacien' ? 'green' : 'blue');
                                $isCurrentUser = ($user['Id_utilisateur'] == $_SESSION['user_id']);
                            ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="user-avatar <?php echo $avatarClass; ?>">
                                                <?php if (pharma_image_disponible($user['Photo'] ?? null)): ?>
                                                    <img src="<?php echo htmlspecialchars(pharma_image_url($user['Photo'])); ?>"
                                                         alt="Photo de <?php echo htmlspecialchars($user['Prenom'] . ' ' . $user['Nom']); ?>"
                                                         loading="lazy"
                                                         onerror="this.onerror=null;this.src='<?php echo htmlspecialchars(pharma_image_defaut('pharmacien')); ?>';">
                                                <?php else: ?>
                                                    <img src="<?php echo htmlspecialchars(pharma_image_defaut('pharmacien')); ?>"
                                                         alt="Avatar par défaut de <?php echo htmlspecialchars($user['Prenom'] . ' ' . $user['Nom']); ?>"
                                                         loading="lazy">
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <div class="user-name">
                                                    <?php echo htmlspecialchars($user['Prenom'] . ' ' . $user['Nom']); ?>
                                                    <?php if ($isCurrentUser): ?>
                                                        <span class="badge bg-primary ms-1" style="font-size: 0.6rem;">Vous</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="user-email">
                                                    <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['Email']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div>
                                            <div><i class="fas fa-phone me-1"></i> <?php echo htmlspecialchars($user['Telephone'] ?? 'Non renseigné'); ?></div>
                                            <small class="text-muted">
                                                ID: #<?php echo $user['Id_utilisateur']; ?>
                                            </small>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge-role <?php echo $user['Role']; ?>">
                                            <i class="fas fa-<?php echo $user['Role'] === 'administrateur' ? 'crown' : ($user['Role'] === 'pharmacien' ? 'user-md' : 'user'); ?>"></i>
                                            <?php echo ucfirst($user['Role']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge-status <?php echo $user['Statut']; ?>">
                                            <i class="fas fa-<?php echo $user['Statut'] === 'actif' ? 'check-circle' : 'pause-circle'; ?>"></i>
                                            <?php echo ucfirst($user['Statut']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div>
                                            <?php echo date('d/m/Y', strtotime($user['Date_creation'])); ?>
                                            <small class="text-muted d-block">
                                                <?php echo date('H:i', strtotime($user['Date_creation'])); ?>
                                            </small>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex gap-1 justify-content-end flex-wrap">
                                            <a href="<?php echo BASE_URL; ?>admin/utilisateurs.php?action=view&id=<?php echo $user['Id_utilisateur']; ?>&role=<?php echo $filtre_role; ?>&statut=<?php echo $filtre_statut; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $pagination['page']; ?>"
                                               class="btn-action-sm btn-view"
                                               title="Voir la fiche de <?php echo htmlspecialchars($user['Prenom'] . ' ' . $user['Nom']); ?>">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($user['Statut'] === 'suspendu'): ?>
                                                <a href="<?php echo BASE_URL; ?>admin/utilisateurs.php?action=activer&id=<?php echo $user['Id_utilisateur']; ?>&role=<?php echo $filtre_role; ?>&statut=<?php echo $filtre_statut; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $pagination['page']; ?>" 
                                                   class="btn-action-sm btn-activate"
                                                   onclick="return confirm('Activer cet utilisateur ?')">
                                                    <i class="fas fa-check"></i> Activer
                                                </a>
                                            <?php elseif ($user['Statut'] === 'actif' && !$isCurrentUser): ?>
                                                <a href="<?php echo BASE_URL; ?>admin/utilisateurs.php?action=suspendre&id=<?php echo $user['Id_utilisateur']; ?>&role=<?php echo $filtre_role; ?>&statut=<?php echo $filtre_statut; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $pagination['page']; ?>" 
                                                   class="btn-action-sm btn-suspend"
                                                   onclick="return confirm('Suspendre cet utilisateur ?')">
                                                    <i class="fas fa-pause"></i> Suspendre
                                                </a>
                                            <?php endif; ?>

                                            <?php if (!$isCurrentUser): ?>
                                                <div class="dropdown d-inline-block">
                                                    <button class="btn-action-sm btn-role dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                        <i class="fas fa-exchange-alt"></i> Rôle
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-role-menu">
                                                        <li>
                                                            <a class="dropdown-item <?php echo $user['Role'] === 'patient' ? 'active' : ''; ?>" 
                                                               href="<?php echo BASE_URL; ?>admin/utilisateurs.php?action=changer_role&id=<?php echo $user['Id_utilisateur']; ?>&role=patient&role_filtre=<?php echo $filtre_role; ?>&statut=<?php echo $filtre_statut; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $pagination['page']; ?>">
                                                                <i class="fas fa-user"></i> Patient
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item <?php echo $user['Role'] === 'pharmacien' ? 'active' : ''; ?>" 
                                                               href="<?php echo BASE_URL; ?>admin/utilisateurs.php?action=changer_role&id=<?php echo $user['Id_utilisateur']; ?>&role=pharmacien&role_filtre=<?php echo $filtre_role; ?>&statut=<?php echo $filtre_statut; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $pagination['page']; ?>">
                                                                <i class="fas fa-user-md"></i> Pharmacien
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item <?php echo $user['Role'] === 'administrateur' ? 'active' : ''; ?>" 
                                                               href="<?php echo BASE_URL; ?>admin/utilisateurs.php?action=changer_role&id=<?php echo $user['Id_utilisateur']; ?>&role=administrateur&role_filtre=<?php echo $filtre_role; ?>&statut=<?php echo $filtre_statut; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $pagination['page']; ?>">
                                                                <i class="fas fa-crown"></i> Administrateur
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>

                                                <a href="<?php echo BASE_URL; ?>admin/utilisateurs.php?action=supprimer&id=<?php echo $user['Id_utilisateur']; ?>&role=<?php echo $filtre_role; ?>&statut=<?php echo $filtre_statut; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $pagination['page']; ?>" 
                                                   class="btn-action-sm btn-delete"
                                                   onclick="return confirm('Supprimer définitivement cet utilisateur ? Cette action est irréversible.')">
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

                <!-- ===== PAGINATION ===== -->
                <?php pagination_afficher($pagination, [
                    'role'   => $filtre_role,
                    'statut' => $filtre_statut,
                    'search' => $search
                ], 'utilisateurs'); ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <h5>Aucun utilisateur trouvé</h5>
                    <p>
                        <?php if (!empty($search)): ?>
                            Aucun résultat pour "<strong><?php echo htmlspecialchars($search); ?></strong>"
                        <?php elseif ($filtre_role !== 'tous' || $filtre_statut !== 'tous'): ?>
                            Aucun utilisateur avec ces critères de filtrage.
                        <?php else: ?>
                            Aucun utilisateur n'est encore enregistré sur la plateforme.
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($vue_utilisateur)): ?>
        <!-- ===== MODAL FICHE UTILISATEUR ===== -->
        <div class="modal fade" id="viewUserModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content" style="border-radius: 16px; overflow: hidden;">
                    <div class="modal-header" style="background: linear-gradient(135deg, #16A34A, #166534); color: white; border: none;">
                        <h5 class="modal-title fw-bold"><i class="fas fa-user me-2"></i>Fiche de l'utilisateur</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="row g-4">
                            <div class="col-md-4 text-center">
                                <img src="<?php echo htmlspecialchars(pharma_image_url_ou_defaut($vue_utilisateur['Photo'] ?? null, 'pharmacien')); ?>"
                                     alt="Photo de <?php echo htmlspecialchars($vue_utilisateur['Prenom'] . ' ' . $vue_utilisateur['Nom']); ?>"
                                     class="rounded-circle mb-3"
                                     style="width: 150px; height: 150px; object-fit: cover; border: 3px solid #16A34A;"
                                     onerror="this.onerror=null;this.src='<?php echo htmlspecialchars(pharma_image_defaut('pharmacien')); ?>';">
                                <?php if (!pharma_image_disponible($vue_utilisateur['Photo'] ?? null)): ?>
                                    <div class="text-muted" style="font-size: .78rem;">
                                        <i class="fas fa-image me-1"></i>Aucune photo enregistrée : avatar par défaut
                                    </div>
                                <?php endif; ?>
                                <h5 class="mb-1"><?php echo htmlspecialchars($vue_utilisateur['Prenom'] . ' ' . $vue_utilisateur['Nom']); ?></h5>
                                <span class="badge-status <?php echo htmlspecialchars($vue_utilisateur['Statut']); ?>">
                                    <?php echo $vue_utilisateur['Statut'] === 'actif' ? 'Actif' : 'Suspendu'; ?>
                                </span>
                            </div>
                            <div class="col-md-8">
                                <h6 class="text-muted text-uppercase mb-3" style="font-size: .8rem; letter-spacing: .5px;">
                                    <i class="fas fa-circle-info me-1"></i> Informations du compte
                                </h6>
                                <div class="row g-2 small">
                                    <div class="col-6"><strong>Identifiant :</strong><br>#<?php echo (int)$vue_utilisateur['Id_utilisateur']; ?></div>
                                    <div class="col-6"><strong>Rôle :</strong><br>
                                        <?php
                                            $roles_libelles = ['patient' => 'Patient', 'pharmacien' => 'Pharmacien', 'administrateur' => 'Administrateur'];
                                            echo htmlspecialchars($roles_libelles[$vue_utilisateur['Role']] ?? $vue_utilisateur['Role']);
                                        ?>
                                    </div>
                                    <div class="col-6"><strong>Email :</strong><br><?php echo htmlspecialchars($vue_utilisateur['Email'] ?: 'Non renseigné'); ?></div>
                                    <div class="col-6"><strong>Téléphone :</strong><br><?php echo htmlspecialchars($vue_utilisateur['Telephone'] ?: 'Non renseigné'); ?></div>
                                    <div class="col-6"><strong>Date d'inscription :</strong><br><?php echo date('d/m/Y', strtotime($vue_utilisateur['Date_creation'])); ?></div>
                                    <div class="col-6"><strong>Statut du compte :</strong><br><?php echo htmlspecialchars(ucfirst($vue_utilisateur['Statut'])); ?></div>
                                </div>
                                <hr>
                                <h6 class="text-muted text-uppercase mb-2" style="font-size: .8rem; letter-spacing: .5px;">
                                    <i class="fas fa-image me-1"></i> Photo de profil
                                </h6>
                                <div class="small">
                                    <?php if (pharma_image_disponible($vue_utilisateur['Photo'] ?? null)): ?>
                                        <div class="text-truncate" style="max-width: 100%;">
                                            <i class="fas fa-check-circle me-1" style="color: var(--primary-green);"></i>
                                            Enregistrée : <code><?php echo htmlspecialchars($vue_utilisateur['Photo']); ?></code>
                                        </div>
                                    <?php else: ?>
                                        <i class="fas fa-times-circle me-1" style="color: var(--gray-text);"></i>
                                        Aucune photo enregistrée pour cet utilisateur.
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer" style="border-top: 1px solid #E5E7EB;">
                        <a href="<?php echo BASE_URL; ?>admin/utilisateurs.php?role=<?php echo $filtre_role; ?>&statut=<?php echo $filtre_statut; ?>&search=<?php echo urlencode($search); ?>"
                           class="btn btn-secondary btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>Retour à la liste
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

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
                        title: '⚠️ Supprimer l\'utilisateur',
                        text: 'Cette action est irréversible. Voulez-vous vraiment supprimer cet utilisateur ?',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#DC2626',
                        cancelButtonColor: '#64748B',
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
                // Ctrl + F pour focus sur la recherche
                if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                    e.preventDefault();
                    const searchInput = document.querySelector('.search-box .form-control');
                    if (searchInput) {
                        searchInput.focus();
                        searchInput.select();
                    }
                }
            });

            <?php if (!empty($vue_utilisateur)): ?>
            // ===== OUVERTURE AUTO DU MODAL FICHE UTILISATEUR =====
            window.addEventListener('load', function() {
                var el = document.getElementById('viewUserModal');
                if (el) { new bootstrap.Modal(el).show(); }
            });
            <?php endif; ?>

            // ===== CONSOLE LOG =====
            console.log('%c PharmaGeo - Gestion des utilisateurs ', 'background: linear-gradient(135deg, #16A34A, #166534); color: white; font-size: 16px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');

        });
    </script>

</body>
</html>