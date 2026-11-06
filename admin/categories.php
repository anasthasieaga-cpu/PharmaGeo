<?php
// ============================================================
// PharmaGeo — Administration : Catégories
// Gestion des catégories de médicaments : ajout, modification,
// suppression et comptage des médicaments associés.
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

// ===== AJOUT D'UNE CATÉGORIE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajouter') {
    $libelle = trim($_POST['libelle'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    $errors = [];
    if (empty($libelle)) {
        $errors[] = "Le libellé de la catégorie est obligatoire.";
    }
    
    // Vérifier si la catégorie existe déjà
    if (empty($errors)) {
        $check = $pdo->prepare("SELECT COUNT(*) as total FROM categorie WHERE Libelle = :libelle");
        $check->execute([':libelle' => $libelle]);
        if ($check->fetch()['total'] > 0) {
            $errors[] = "Cette catégorie existe déjà.";
        }
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO categorie (Libelle, Description) VALUES (:libelle, :description)");
            $stmt->execute([
                ':libelle' => $libelle,
                ':description' => $description
            ]);
            
            // Journalisation
            $log = $pdo->prepare("INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur) 
                                  VALUES ('Ajout catégorie: ' . :libelle, :ip, :ua, 'succes', :user)");
            $log->execute([
                ':libelle' => $libelle,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':user' => $_SESSION['user_id']
            ]);
            
            $message = "Catégorie ajoutée avec succès !";
            $message_type = "success";
            
        } catch(PDOException $e) {
            $message = "Erreur lors de l'ajout : " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = implode('<br>', $errors);
        $message_type = "danger";
    }
}

// ===== MODIFICATION D'UNE CATÉGORIE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'modifier') {
    $id = (int)($_POST['id_categorie'] ?? 0);
    $libelle = trim($_POST['libelle'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    $errors = [];
    if ($id <= 0) {
        $errors[] = "ID invalide.";
    }
    if (empty($libelle)) {
        $errors[] = "Le libellé de la catégorie est obligatoire.";
    }
    
    // Vérifier si la catégorie existe déjà (sauf pour celle-ci)
    // La condition Id_categorie != :id exclut la catégorie en cours d'édition
    if (empty($errors)) {
        $check = $pdo->prepare("SELECT COUNT(*) as total FROM categorie WHERE Libelle = :libelle AND Id_categorie != :id");
        $check->execute([':libelle' => $libelle, ':id' => $id]);
        if ($check->fetch()['total'] > 0) {
            $errors[] = "Cette catégorie existe déjà.";
        }
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("UPDATE categorie SET Libelle = :libelle, Description = :description WHERE Id_categorie = :id");
            $stmt->execute([
                ':libelle' => $libelle,
                ':description' => $description,
                ':id' => $id
            ]);
            
            // Journalisation
            $log = $pdo->prepare("INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur) 
                                  VALUES ('Modification catégorie ID: ' . :id, :ip, :ua, 'succes', :user)");
            $log->execute([
                ':id' => $id,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':user' => $_SESSION['user_id']
            ]);
            
            $message = "Catégorie modifiée avec succès !";
            $message_type = "success";
            
        } catch(PDOException $e) {
            $message = "Erreur lors de la modification : " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = implode('<br>', $errors);
        $message_type = "danger";
    }
}

// ===== SUPPRESSION D'UNE CATÉGORIE =====
if (isset($_GET['action']) && $_GET['action'] === 'supprimer' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        // Vérifier si la catégorie est utilisée par des médicaments
        $check = $pdo->prepare("SELECT COUNT(*) as total FROM medicament WHERE Id_categorie = :id");
        $check->execute([':id' => $id]);
        $count = $check->fetch()['total'];
        
        if ($count > 0) {
            $message = "Cette catégorie est utilisée par $count médicament(s) et ne peut pas être supprimée.";
            $message_type = "warning";
        } else {
            $stmt = $pdo->prepare("DELETE FROM categorie WHERE Id_categorie = :id");
            $stmt->execute([':id' => $id]);
            
            $log = $pdo->prepare("INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur) 
                                  VALUES ('Suppression catégorie ID: ' . :id, :ip, :ua, 'succes', :user)");
            $log->execute([
                ':id' => $id,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':user' => $_SESSION['user_id']
            ]);
            
            $message = "Catégorie supprimée avec succès !";
            $message_type = "danger";
        }
    } catch(PDOException $e) {
        $message = "Erreur lors de la suppression : " . $e->getMessage();
        $message_type = "danger";
    }
}

// ===== RÉCUPÉRATION DES DONNÉES =====
try {
    // Récupérer les catégories de la page courante (pagination serveur)
    $total_categories = (int) $pdo->query("SELECT COUNT(*) FROM categorie")->fetchColumn();
    $pagination = pagination_calculer($total_categories);
    $categories = $pdo->query("
        SELECT c.*,
               (SELECT COUNT(*) FROM medicament WHERE Id_categorie = c.Id_categorie) as total_medicaments
        FROM categorie c
        ORDER BY c.Libelle ASC
    " . pagination_sql_limite($pagination))->fetchAll();
    
    // Statistiques
    $stats = [
        'total' => $pdo->query("SELECT COUNT(*) as total FROM categorie")->fetch()['total'] ?? 0,
        'avec_medicaments' => $pdo->query("SELECT COUNT(DISTINCT c.Id_categorie) as total FROM categorie c JOIN medicament m ON c.Id_categorie = m.Id_categorie")->fetch()['total'] ?? 0,
        'sans_medicaments' => $pdo->query("SELECT COUNT(*) as total FROM categorie c WHERE NOT EXISTS (SELECT 1 FROM medicament m WHERE m.Id_categorie = c.Id_categorie)")->fetch()['total'] ?? 0
    ];
    
} catch(PDOException $e) {
    $categories = [];
    $stats = ['total' => 0, 'avec_medicaments' => 0, 'sans_medicaments' => 0];
    $message = "Erreur lors du chargement des données : " . $e->getMessage();
    $message_type = "danger";
}

// ===== RÉCUPÉRATION D'UNE CATÉGORIE POUR MODIFICATION =====
$edit_categorie = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM categorie WHERE Id_categorie = :id");
    $stmt->execute([':id' => $id]);
    $edit_categorie = $stmt->fetch();
}

$page_title = 'Gestion des catégories - Administration PharmaGeo';
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
            box-shadow: 0 12px 40px rgba(22,163,74,0.3);
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

        /* ===== BADGES ===== */
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

        .badge-status.info {
            background: rgba(22,83,52,0.1);
            color: #166534;
        }

        .badge-status.secondary {
            background: rgba(108,117,125,0.1);
            color: #64748B;
        }

        /* ===== BTN ACTION ===== */
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

        .btn-action-sm.btn-edit {
            background: rgba(21,128,61,0.1);
            color: var(--primary-orange);
        }

        .btn-action-sm.btn-edit:hover {
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

        .btn-action-sm.btn-view {
            background: rgba(22,83,52,0.1);
            color: #166534;
        }

        .btn-action-sm.btn-view:hover {
            background: #166534;
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

        /* ===== MODAL ===== */
        .modal-content-custom {
            border-radius: var(--radius-lg);
            border: none;
            box-shadow: var(--shadow-xl);
        }

        .modal-header-custom {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            padding: 20px 24px;
        }

        .modal-header-custom .btn-close {
            filter: brightness(0) invert(1);
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

        /* ===== CATEGORY COLOR PICKER ===== */
        .color-picker-wrapper {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .color-option {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 3px solid transparent;
            cursor: pointer;
            transition: var(--transition);
        }

        .color-option:hover {
            transform: scale(1.1);
        }

        .color-option.active {
            border-color: var(--dark-text);
            transform: scale(1.1);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .table-container { padding: 12px; overflow-x: auto; }
            .table-custom { font-size: 0.8rem; min-width: 500px; }
            .table-custom thead th,
            .table-custom tbody td { padding: 8px 6px; }
        }

        @media (max-width: 576px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card { padding: 12px; }
            .stat-card .stat-number { font-size: 1.3rem; }
            .stat-card .stat-label { font-size: 0.7rem; }
            .stat-card .stat-icon { width: 32px; height: 32px; font-size: 0.9rem; }
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
            <a href="<?php echo BASE_URL; ?>admin/utilisateurs.php" class="nav-item">
                <i class="fas fa-users"></i>
                <span>Utilisateurs</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/medicaments.php" class="nav-item">
                <i class="fas fa-capsules"></i>
                <span>Médicaments</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/categories.php" class="nav-item active">
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
        <div class="top-bar animate-fade-up">
            <div>
                <div class="d-flex align-items-center gap-3">
                    <button class="toggle-sidebar" id="toggleSidebar">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="page-title">
                        <h1>Gestion des <span class="highlight">catégories</span></h1>
                        <p><i class="fas fa-tags me-1" style="color: var(--primary-green);"></i> Gérez toutes les catégories de médicaments</p>
                    </div>
                </div>
            </div>
            <div class="page-actions">
                <button class="btn-action btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="fas fa-plus"></i> Ajouter une catégorie
                </button>
            </div>
        </div>

        <!-- ===== STATS ===== -->
        <div class="stats-grid animate-fade-up delay-1">
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">Total catégories</div>
                    </div>
                    <div class="stat-icon blue"><i class="fas fa-tags"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['avec_medicaments']; ?></div>
                        <div class="stat-label">Avec médicaments</div>
                    </div>
                    <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['sans_medicaments']; ?></div>
                        <div class="stat-label">Sans médicaments</div>
                    </div>
                    <div class="stat-icon orange"><i class="fas fa-exclamation-circle"></i></div>
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

        <!-- ===== TABLEAU ===== -->
        <div class="table-container animate-fade-up delay-2">
            <?php if (!empty($categories)): ?>
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Libellé</th>
                                <th>Description</th>
                                <th>Médicaments</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $index => $cat): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge" style="background: <?php echo $cat['Couleur'] ?? '#64748B'; ?>; width: 12px; height: 12px; border-radius: 50%;"></span>
                                            <strong><?php echo htmlspecialchars($cat['Libelle']); ?></strong>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($cat['Description'] ?? '-'); ?></td>
                                    <td>
                                        <span class="badge-status <?php echo $cat['total_medicaments'] > 0 ? 'success' : 'secondary'; ?>">
                                            <?php echo $cat['total_medicaments']; ?> médicament(s)
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex gap-1 justify-content-end flex-wrap">
                                            <a href="<?php echo BASE_URL; ?>admin/categories.php?action=edit&id=<?php echo $cat['Id_categorie']; ?>" 
                                               class="btn-action-sm btn-edit" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $cat['Id_categorie']; ?>">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="<?php echo BASE_URL; ?>admin/categories.php?action=supprimer&id=<?php echo $cat['Id_categorie']; ?>" 
                                               class="btn-action-sm btn-delete"
                                               onclick="return confirmDelete(<?php echo $cat['Id_categorie']; ?>, '<?php echo addslashes($cat['Libelle']); ?>', <?php echo $cat['total_medicaments']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ===== PAGINATION ===== -->
                <?php pagination_afficher($pagination, [], 'catégories'); ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-tags"></i>
                    <h5>Aucune catégorie trouvée</h5>
                    <p>Commencez par ajouter des catégories pour organiser vos médicaments.</p>
                    <button class="btn btn-primary-custom mt-3" data-bs-toggle="modal" data-bs-target="#addModal" style="padding: 10px 28px; border-radius: 50px; background: linear-gradient(135deg, #16A34A, #166534); color: white; border: none; font-weight: 600; transition: var(--transition);">
                        <i class="fas fa-plus me-1"></i> Ajouter une catégorie
                    </button>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- ===== MODAL AJOUT ===== -->
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content modal-content-custom">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-plus me-2"></i>Ajouter une catégorie
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" class="p-4">
                    <input type="hidden" name="action" value="ajouter">
                    
                    <div class="form-group mb-3">
                        <label for="libelle" class="form-label fw-bold">Libellé <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="libelle" name="libelle" 
                               placeholder="Ex: Antalgiques, Antibiotiques..." required>
                    </div>

                    <div class="form-group mb-3">
                        <label for="description" class="form-label fw-bold">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3" 
                                  placeholder="Description de la catégorie..."></textarea>
                    </div>

                    <div class="mt-4 d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 50px; padding: 10px 28px;">Annuler</button>
                        <button type="submit" class="btn btn-primary-custom" style="border-radius: 50px; padding: 10px 28px;">
                            <i class="fas fa-save me-1"></i> Enregistrer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ===== MODALS ÉDITION ===== -->
    <?php foreach ($categories as $cat): ?>
    <div class="modal fade" id="editModal<?php echo $cat['Id_categorie']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content modal-content-custom">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-edit me-2"></i>Modifier la catégorie
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" class="p-4">
                    <input type="hidden" name="action" value="modifier">
                    <input type="hidden" name="id_categorie" value="<?php echo $cat['Id_categorie']; ?>">
                    
                    <div class="form-group mb-3">
                        <label for="libelle_<?php echo $cat['Id_categorie']; ?>" class="form-label fw-bold">Libellé <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="libelle_<?php echo $cat['Id_categorie']; ?>" 
                               name="libelle" value="<?php echo htmlspecialchars($cat['Libelle']); ?>" required>
                    </div>

                    <div class="form-group mb-3">
                        <label for="description_<?php echo $cat['Id_categorie']; ?>" class="form-label fw-bold">Description</label>
                        <textarea class="form-control" id="description_<?php echo $cat['Id_categorie']; ?>" 
                                  name="description" rows="3"><?php echo htmlspecialchars($cat['Description'] ?? ''); ?></textarea>
                    </div>

                    <div class="mt-4 d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 50px; padding: 10px 28px;">Annuler</button>
                        <button type="submit" class="btn btn-primary-custom" style="border-radius: 50px; padding: 10px 28px;">
                            <i class="fas fa-save me-1"></i> Mettre à jour
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

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
            window.confirmDelete = function(id, libelle, totalMedicaments) {
                event.preventDefault();
                
                let message = `Voulez-vous vraiment supprimer la catégorie "${libelle}" ?`;
                if (totalMedicaments > 0) {
                    message = `⚠️ Cette catégorie contient ${totalMedicaments} médicament(s). Elle ne peut pas être supprimée.`;
                    Swal.fire({
                        title: '⚠️ Suppression impossible',
                        text: message,
                        icon: 'warning',
                        confirmButtonColor: '#64748B',
                        confirmButtonText: 'OK'
                    });
                    return false;
                }
                
                Swal.fire({
                    title: '⚠️ Supprimer la catégorie',
                    text: message + ' Cette action est irréversible.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#DC2626',
                    cancelButtonColor: '#64748B',
                    confirmButtonText: 'Oui, supprimer',
                    cancelButtonText: 'Annuler'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = `<?php echo BASE_URL; ?>admin/categories.php?action=supprimer&id=${id}`;
                    }
                });
                return false;
            };

            // ===== KEYBOARD SHORTCUTS =====
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && sidebar.classList.contains('open')) {
                    toggleSidebar();
                }
                // Ctrl + N pour ouvrir le modal d'ajout
                if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                    e.preventDefault();
                    const addBtn = document.querySelector('[data-bs-target="#addModal"]');
                    if (addBtn) addBtn.click();
                }
            });

            // ===== CONSOLE LOG =====
            console.log('%c PharmaGeo - Gestion des catégories ', 'background: linear-gradient(135deg, #16A34A, #166534); color: white; font-size: 16px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');
            console.log('%c Raccourci : Ctrl+N pour ajouter une catégorie', 'color: #666; font-size: 13px;');

        });
    </script>

</body>
</html>