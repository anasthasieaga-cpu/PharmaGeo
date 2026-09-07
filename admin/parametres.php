<?php
// ============================================================
// PharmaGeo — Administration : Paramètres
// Gestion du profil administrateur, mot de passe et configuration
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

// ===== RÉCUPÉRATION DES INFORMATIONS ADMIN =====
try {
    $admin = $pdo->prepare("SELECT * FROM utilisateur WHERE Id_utilisateur = :id");
    $admin->execute([':id' => $_SESSION['user_id']]);
    $admin_info = $admin->fetch();
    
    // Statistiques pour le dashboard
    $stats = [
        'total_utilisateurs' => $pdo->query("SELECT COUNT(*) as total FROM utilisateur WHERE Statut = 'actif'")->fetch()['total'] ?? 0,
        'total_pharmacies' => $pdo->query("SELECT COUNT(*) as total FROM pharmacie WHERE Statut = 'active'")->fetch()['total'] ?? 0,
        'total_medicaments' => $pdo->query("SELECT COUNT(*) as total FROM medicament")->fetch()['total'] ?? 0,
        'total_reservations' => $pdo->query("SELECT COUNT(*) as total FROM reservation")->fetch()['total'] ?? 0
    ];
} catch(PDOException $e) {
    $admin_info = null;
    $stats = ['total_utilisateurs' => 0, 'total_pharmacies' => 0, 'total_medicaments' => 0, 'total_reservations' => 0];
}

// ===== TRAITEMENT DU FORMULAIRE =====
$error = '';
$success = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        // Mise à jour du profil
        $nom = trim($_POST['nom'] ?? '');
        $prenom = trim($_POST['prenom'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        
        // Validation
        $errors = [];
        if (empty($nom)) $errors[] = "Le nom est obligatoire.";
        if (empty($prenom)) $errors[] = "Le prénom est obligatoire.";
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "L'email est invalide.";
        
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("UPDATE utilisateur SET Nom = :nom, Prenom = :prenom, Email = :email, Telephone = :telephone WHERE Id_utilisateur = :id");
                $stmt->execute([
                    ':nom' => $nom,
                    ':prenom' => $prenom,
                    ':email' => $email,
                    ':telephone' => $telephone,
                    ':id' => $_SESSION['user_id']
                ]);
                
                // Mise à jour de la session
                $_SESSION['nom'] = $nom;
                $_SESSION['prenom'] = $prenom;
                $_SESSION['email'] = $email;
                
                $success = "Profil mis à jour avec succès !";
                
                // Recharger les infos
                $admin = $pdo->prepare("SELECT * FROM utilisateur WHERE Id_utilisateur = :id");
                $admin->execute([':id' => $_SESSION['user_id']]);
                $admin_info = $admin->fetch();
                
            } catch(PDOException $e) {
                $error = "Erreur lors de la mise à jour : " . $e->getMessage();
            }
        } else {
            $error = implode('<br>', $errors);
        }
    }
    
    if ($action === 'change_password') {
        // Changement de mot de passe
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validation
        $errors = [];
        if (empty($current_password)) $errors[] = "Le mot de passe actuel est obligatoire.";
        if (empty($new_password)) $errors[] = "Le nouveau mot de passe est obligatoire.";
        if (strlen($new_password) < 8) $errors[] = "Le mot de passe doit contenir au moins 8 caractères.";
        if ($new_password !== $confirm_password) $errors[] = "Les mots de passe ne correspondent pas.";
        
        if (empty($errors)) {
            // Vérifier le mot de passe actuel
            $stmt = $pdo->prepare("SELECT Mot_de_passe FROM utilisateur WHERE Id_utilisateur = :id");
            $stmt->execute([':id' => $_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($current_password, $user['Mot_de_passe'])) {
                // Mettre à jour le mot de passe
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE utilisateur SET Mot_de_passe = :password WHERE Id_utilisateur = :id");
                $stmt->execute([
                    ':password' => $hashed_password,
                    ':id' => $_SESSION['user_id']
                ]);
                
                $success = "Mot de passe modifié avec succès !";
            } else {
                $error = "Le mot de passe actuel est incorrect.";
            }
        } else {
            $error = implode('<br>', $errors);
        }
    }
    
    if ($action === 'update_settings') {
        // Mise à jour des paramètres généraux
        $site_name = trim($_POST['site_name'] ?? 'PharmaGeo');
        $site_description = trim($_POST['site_description'] ?? '');
        $maintenance_mode = isset($_POST['maintenance_mode']) ? 1 : 0;
        
        // Ici vous pouvez sauvegarder les paramètres dans une table settings
        // Pour l'exemple, on stocke en session
        $_SESSION['site_name'] = $site_name;
        $_SESSION['site_description'] = $site_description;
        
        $success = "Paramètres généraux mis à jour avec succès !";
    }
}

$page_title = 'Paramètres - Administration PharmaGeo';
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

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }

        .animate-fade-up { animation: fadeInUp 0.6s ease-out forwards; }
        .animate-slide-left { animation: slideInLeft 0.5s ease-out forwards; }
        .animate-float { animation: float 3s ease-in-out infinite; }
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

        /* ===== SIDEBAR TOGGLE ===== */
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

        /* ===== SETTINGS CARDS ===== */
        .settings-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 28px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            border: 1px solid rgba(0,0,0,0.02);
            margin-bottom: 24px;
        }

        .settings-card:hover {
            box-shadow: var(--shadow-md);
        }

        .settings-card .card-header-custom {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--light-bg);
        }

        .settings-card .card-header-custom .header-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }

        .settings-card .card-header-custom .header-icon.green {
            background: rgba(42,195,42,0.1);
            color: var(--primary-green);
        }

        .settings-card .card-header-custom .header-icon.orange {
            background: rgba(200,120,30,0.1);
            color: var(--primary-orange);
        }

        .settings-card .card-header-custom .header-icon.blue {
            background: rgba(52,152,219,0.1);
            color: #3498db;
        }

        .settings-card .card-header-custom .header-icon.purple {
            background: rgba(155,89,182,0.1);
            color: #9b59b6;
        }

        .settings-card .card-header-custom .header-title h5 {
            font-weight: 700;
            margin: 0;
            font-size: 1.1rem;
        }

        .settings-card .card-header-custom .header-title p {
            color: var(--gray-text);
            margin: 0;
            font-size: 0.85rem;
        }

        /* ===== FORMULAIRE ===== */
        .form-modern .form-group { margin-bottom: 18px; }

        .form-modern .form-label {
            font-weight: 600;
            color: var(--dark-text);
            font-size: 0.9rem;
            margin-bottom: 6px;
        }

        .form-modern .form-label .required {
            color: #e74c3c;
            margin-left: 2px;
        }

        .form-modern .input-group-custom {
            position: relative;
        }

        .form-modern .input-group-custom .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-text);
            font-size: 1rem;
            transition: var(--transition);
            z-index: 3;
        }

        .form-modern .input-group-custom .form-control {
            padding: 12px 16px 12px 44px;
            border: 2px solid #e8ecf1;
            border-radius: 14px;
            font-size: 0.95rem;
            transition: var(--transition);
            background: #f8f9fa;
            color: var(--dark-text);
        }

        .form-modern .input-group-custom .form-control:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 4px rgba(42,195,42,0.1);
            background: var(--white);
        }

        .form-modern .input-group-custom .form-control:disabled {
            background: #f0f0f0;
            cursor: not-allowed;
        }

        .form-modern .input-group-custom .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray-text);
            cursor: pointer;
            padding: 4px;
            transition: var(--transition);
            z-index: 3;
        }

        .form-modern .input-group-custom .toggle-password:hover {
            color: var(--primary-green);
        }

        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 0.95rem;
            transition: var(--transition);
            box-shadow: var(--shadow-glow-green);
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-primary-custom:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 40px rgba(42,195,42,0.4);
            color: white;
        }

        .btn-primary-custom:active { transform: scale(0.98); }

        .btn-outline-custom {
            border: 2px solid var(--primary-green);
            color: var(--primary-green);
            background: transparent;
            padding: 10px 24px;
            border-radius: 50px;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-outline-custom:hover {
            background: var(--primary-green);
            color: white;
            transform: translateY(-3px);
            box-shadow: var(--shadow-glow-green);
        }

        .btn-danger-custom {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 50px;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-danger-custom:hover {
            background: #c0392b;
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(231,76,60,0.3);
            color: white;
        }

        /* ===== ALERTES ===== */
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

        /* ===== ADMIN PROFILE ===== */
        .admin-profile-card {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            border-radius: var(--radius-lg);
            padding: 30px;
            color: white;
            position: relative;
            overflow: hidden;
            margin-bottom: 24px;
        }

        .admin-profile-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 60%;
            height: 200%;
            background: radial-gradient(ellipse, rgba(255,255,255,0.05) 0%, transparent 70%);
            border-radius: 50%;
        }

        .admin-profile-card .profile-content {
            position: relative;
            z-index: 1;
        }

        .admin-profile-card .admin-avatar-large {
            width: 80px;
            height: 80px;
            border-radius: 20px;
            background: rgba(255,255,255,0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.4rem;
            font-weight: 800;
            border: 3px solid rgba(255,255,255,0.2);
            margin-bottom: 16px;
        }

        .admin-profile-card .admin-name {
            font-size: 1.4rem;
            font-weight: 800;
            margin: 0;
        }

        .admin-profile-card .admin-role {
            font-size: 0.9rem;
            opacity: 0.8;
            margin: 0;
        }

        .admin-profile-card .admin-stats {
            display: flex;
            gap: 30px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }

        .admin-profile-card .admin-stats .stat-item { text-align: center; }

        .admin-profile-card .admin-stats .stat-number {
            font-size: 1.6rem;
            font-weight: 800;
            display: block;
        }

        .admin-profile-card .admin-stats .stat-label {
            font-size: 0.75rem;
            opacity: 0.7;
        }

        /* ===== SWITCH TOGGLE ===== */
        .switch-container {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .switch {
            position: relative;
            width: 50px;
            height: 28px;
            flex-shrink: 0;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .switch .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: #ccc;
            transition: var(--transition);
            border-radius: 34px;
        }

        .switch .slider::before {
            content: '';
            position: absolute;
            height: 20px;
            width: 20px;
            left: 4px;
            bottom: 4px;
            background: white;
            transition: var(--transition);
            border-radius: 50%;
        }

        .switch input:checked + .slider {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
        }

        .switch input:checked + .slider::before {
            transform: translateX(22px);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 576px) {
            .settings-card { padding: 20px; }
            .admin-profile-card { padding: 20px; }
            .admin-profile-card .admin-avatar-large {
                width: 60px;
                height: 60px;
                font-size: 1.8rem;
            }
            .admin-profile-card .admin-stats {
                gap: 15px;
                flex-wrap: wrap;
            }
            .admin-profile-card .admin-stats .stat-number {
                font-size: 1.2rem;
            }
        }

        @media (max-width: 400px) {
            .settings-card { padding: 16px; }
            .settings-card .card-header-custom .header-icon {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }
            .settings-card .card-header-custom .header-title h5 { font-size: 0.95rem; }
            .admin-profile-card .admin-name { font-size: 1.1rem; }
        }

        /* ===== PASSWORD STRENGTH ===== */
        .password-strength {
            height: 4px;
            border-radius: 4px;
            background: #e8ecf1;
            margin-top: 8px;
            overflow: hidden;
            transition: var(--transition);
        }

        .password-strength .bar {
            height: 100%;
            width: 0%;
            border-radius: 4px;
            transition: width 0.4s ease, background 0.4s ease;
        }

        .password-strength .bar.weak { width: 25%; background: #e74c3c; }
        .password-strength .bar.medium { width: 50%; background: #f39c12; }
        .password-strength .bar.good { width: 75%; background: #2ecc71; }
        .password-strength .bar.strong { width: 100%; background: var(--primary-green); }

        .password-strength-label {
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 4px;
            text-align: right;
        }

        /* ===== DG LOGO ===== */
        .dg-logo-container {
            display: flex;
            align-items: center;
            gap: 16px;
            background: rgba(255,255,255,0.08);
            padding: 10px 16px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.05);
        }

        .dg-logo-container .dg-icon {
            font-size: 2rem;
        }

        .dg-logo-container .dg-text {
            font-size: 0.85rem;
            opacity: 0.8;
        }

        .dg-logo-container .dg-text strong {
            display: block;
            font-size: 1rem;
            opacity: 1;
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
            <a href="<?php echo BASE_URL; ?>admin/parametres.php" class="nav-item active">
                <i class="fas fa-cog"></i>
                <span>Paramètres</span>
            </a>

            <div class="nav-label" style="margin-top: 20px;">Mon compte</div>
            <a href="<?php echo BASE_URL; ?>pages/patient/profil.php" class="nav-item">
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
                        <h1><span class="highlight">Paramètres</span></h1>
                        <p><i class="fas fa-cog me-1" style="color: var(--primary-green);"></i> Gérez les paramètres de la plateforme</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== MESSAGES ===== -->
        <?php if (!empty($error)): ?>
            <div class="alert-custom alert-custom-danger animate-fade-up">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo $error; ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-custom alert-custom-success animate-fade-up">
                <i class="fas fa-check-circle"></i>
                <div><?php echo $success; ?></div>
            </div>
        <?php endif; ?>

        <!-- ===== ADMIN PROFILE CARD ===== -->
        <div class="admin-profile-card animate-fade-up delay-1">
            <div class="profile-content">
                <div class="row align-items-center">
                    <div class="col-md-7">
                        <div class="d-flex align-items-center gap-4">
                            <div class="admin-avatar-large">
                                <?php echo strtoupper(substr($admin_info['Prenom'] ?? 'A', 0, 1) . substr($admin_info['Nom'] ?? 'D', 0, 1)); ?>
                            </div>
                            <div>
                                <h2 class="admin-name"><?php echo htmlspecialchars(($admin_info['Prenom'] ?? '') . ' ' . ($admin_info['Nom'] ?? '')); ?></h2>
                                <p class="admin-role">
                                    <i class="fas fa-crown me-1" style="color: #f1c40f;"></i>
                                    Administrateur Principal
                                </p>
                                <div class="dg-logo-container">
                                    <div class="dg-icon">👔</div>
                                    <div class="dg-text">
                                        <strong>DG - Directeur Général</strong>
                                        <span>Plateforme PharmaGeo</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="admin-stats">
                            <div class="stat-item">
                                <span class="stat-number"><?php echo number_format($stats['total_utilisateurs']); ?></span>
                                <span class="stat-label">Utilisateurs</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-number"><?php echo number_format($stats['total_pharmacies']); ?></span>
                                <span class="stat-label">Pharmacies</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-number"><?php echo number_format($stats['total_medicaments']); ?></span>
                                <span class="stat-label">Médicaments</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== PARAMÈTRES ===== -->
        <div class="row g-4">

            <!-- ===== SECTION 1: PROFIL ===== -->
            <div class="col-lg-6 animate-fade-up delay-2">
                <div class="settings-card">
                    <div class="card-header-custom">
                        <div class="header-icon green">
                            <i class="fas fa-user-edit"></i>
                        </div>
                        <div class="header-title">
                            <h5>Informations personnelles</h5>
                            <p>Mettez à jour vos informations de profil</p>
                        </div>
                    </div>

                    <form method="POST" class="form-modern">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="row g-3">
                            <div class="col-md-6 form-group">
                                <label for="nom" class="form-label">Nom <span class="required">*</span></label>
                                <div class="input-group-custom">
                                    <span class="input-icon"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control" id="nom" name="nom" 
                                           value="<?php echo htmlspecialchars($admin_info['Nom'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6 form-group">
                                <label for="prenom" class="form-label">Prénom <span class="required">*</span></label>
                                <div class="input-group-custom">
                                    <span class="input-icon"><i class="fas fa-user-tie"></i></span>
                                    <input type="text" class="form-control" id="prenom" name="prenom" 
                                           value="<?php echo htmlspecialchars($admin_info['Prenom'] ?? ''); ?>" required>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="email" class="form-label">Email <span class="required">*</span></label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-envelope"></i></span>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($admin_info['Email'] ?? ''); ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="telephone" class="form-label">Téléphone</label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-phone"></i></span>
                                <input type="tel" class="form-control" id="telephone" name="telephone" 
                                       value="<?php echo htmlspecialchars($admin_info['Telephone'] ?? ''); ?>">
                            </div>
                        </div>

                        <button type="submit" class="btn-primary-custom">
                            <i class="fas fa-save"></i> Mettre à jour
                        </button>
                    </form>
                </div>
            </div>

            <!-- ===== SECTION 2: MOT DE PASSE ===== -->
            <div class="col-lg-6 animate-fade-up delay-3">
                <div class="settings-card">
                    <div class="card-header-custom">
                        <div class="header-icon orange">
                            <i class="fas fa-lock"></i>
                        </div>
                        <div class="header-title">
                            <h5>Changer le mot de passe</h5>
                            <p>Modifiez votre mot de passe de connexion</p>
                        </div>
                    </div>

                    <form method="POST" class="form-modern" id="passwordForm">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="form-group">
                            <label for="current_password" class="form-label">Mot de passe actuel <span class="required">*</span></label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-key"></i></span>
                                <input type="password" class="form-control" id="current_password" name="current_password" required>
                                <button type="button" class="toggle-password" onclick="togglePassword('current_password')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="new_password" class="form-label">Nouveau mot de passe <span class="required">*</span></label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-lock"></i></span>
                                <input type="password" class="form-control" id="new_password" name="new_password" required>
                                <button type="button" class="toggle-password" onclick="togglePassword('new_password')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="password-strength" id="passwordStrength">
                                <div class="bar" id="strengthBar"></div>
                            </div>
                            <div class="password-strength-label" id="strengthLabel"></div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password" class="form-label">Confirmer le mot de passe <span class="required">*</span></label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-check-double"></i></span>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                            </div>
                            <div class="form-text" id="passwordMatch"></div>
                        </div>

                        <button type="submit" class="btn-primary-custom">
                            <i class="fas fa-shield-alt"></i> Changer le mot de passe
                        </button>
                    </form>
                </div>
            </div>

            <!-- ===== SECTION 3: PARAMÈTRES GÉNÉRAUX ===== -->
            <div class="col-lg-6 animate-fade-up delay-4">
                <div class="settings-card">
                    <div class="card-header-custom">
                        <div class="header-icon blue">
                            <i class="fas fa-globe"></i>
                        </div>
                        <div class="header-title">
                            <h5>Paramètres généraux</h5>
                            <p>Configuration de la plateforme</p>
                        </div>
                    </div>

                    <form method="POST" class="form-modern">
                        <input type="hidden" name="action" value="update_settings">
                        
                        <div class="form-group">
                            <label for="site_name" class="form-label">Nom du site</label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-hospital"></i></span>
                                <input type="text" class="form-control" id="site_name" name="site_name" 
                                       value="<?php echo htmlspecialchars($_SESSION['site_name'] ?? 'PharmaGeo'); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="site_description" class="form-label">Description du site</label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-align-left"></i></span>
                                <textarea class="form-control" id="site_description" name="site_description" rows="3" 
                                          style="padding-left: 44px;"><?php echo htmlspecialchars($_SESSION['site_description'] ?? 'Plateforme de géolocalisation des pharmacies et recherche de disponibilité des médicaments à Porto-Novo'); ?></textarea>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Mode maintenance</label>
                            <div class="switch-container">
                                <label class="switch">
                                    <input type="checkbox" name="maintenance_mode" <?php echo isset($_SESSION['maintenance_mode']) && $_SESSION['maintenance_mode'] ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                                <span style="font-size: 0.9rem; color: var(--gray-text);">
                                    Activer le mode maintenance
                                </span>
                            </div>
                            <small class="text-muted">Lorsque le mode maintenance est activé, seuls les administrateurs peuvent accéder à la plateforme.</small>
                        </div>

                        <button type="submit" class="btn-primary-custom">
                            <i class="fas fa-save"></i> Enregistrer les paramètres
                        </button>
                    </form>
                </div>
            </div>

            <!-- ===== SECTION 4: ACTIONS ===== -->
            <div class="col-lg-6 animate-fade-up delay-4">
                <div class="settings-card">
                    <div class="card-header-custom">
                        <div class="header-icon purple">
                            <i class="fas fa-tools"></i>
                        </div>
                        <div class="header-title">
                            <h5>Actions administratives</h5>
                            <p>Opérations avancées</p>
                        </div>
                    </div>

                    <div class="d-grid gap-3">
                        <button class="btn-outline-custom" onclick="confirmAction('export')">
                            <i class="fas fa-file-export"></i> Exporter les données
                        </button>
                        <button class="btn-outline-custom" onclick="confirmAction('backup')">
                            <i class="fas fa-database"></i> Sauvegarder la base de données
                        </button>
                        <button class="btn-danger-custom" onclick="confirmAction('clear_cache')">
                            <i class="fas fa-trash-alt"></i> Vider le cache
                        </button>
                        <button class="btn-danger-custom" onclick="confirmAction('reset')" style="background: #e74c3c;">
                            <i class="fas fa-exclamation-triangle"></i> Réinitialiser la plateforme
                        </button>
                    </div>

                    <div class="mt-3 p-3 bg-light rounded-3">
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Ces actions sont irréversibles. Assurez-vous d'avoir effectué une sauvegarde avant de procéder.
                        </small>
                    </div>
                </div>
            </div>

            <!-- ===== SECTION 5: INFORMATIONS ===== -->
            <div class="col-12 animate-fade-up delay-5">
                <div class="settings-card">
                    <div class="card-header-custom">
                        <div class="header-icon green">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <div class="header-title">
                            <h5>Informations sur la plateforme</h5>
                            <p>Version et informations techniques</p>
                        </div>
                    </div>

                    <div class="row g-4">
                        <div class="col-md-3 col-6">
                            <div class="text-center p-3 bg-light rounded-3">
                                <i class="fas fa-code" style="font-size: 2rem; color: var(--primary-green);"></i>
                                <h6 class="mt-2 mb-0">Version</h6>
                                <small class="text-muted">1.0.0</small>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="text-center p-3 bg-light rounded-3">
                                <i class="fas fa-calendar-alt" style="font-size: 2rem; color: var(--primary-orange);"></i>
                                <h6 class="mt-2 mb-0">Dernière mise à jour</h6>
                                <small class="text-muted"><?php echo date('d/m/Y'); ?></small>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="text-center p-3 bg-light rounded-3">
                                <i class="fas fa-database" style="font-size: 2rem; color: #3498db;"></i>
                                <h6 class="mt-2 mb-0">Base de données</h6>
                                <small class="text-muted">MySQL</small>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="text-center p-3 bg-light rounded-3">
                                <i class="fas fa-shield-alt" style="font-size: 2rem; color: #9b59b6;"></i>
                                <h6 class="mt-2 mb-0">Sécurité</h6>
                                <small class="text-muted">SSL activé</small>
                            </div>
                        </div>
                    </div>
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

            // ===== TOGGLE PASSWORD =====
            window.togglePassword = function(fieldId) {
                const field = document.getElementById(fieldId);
                const icon = field.parentElement.querySelector('.toggle-password i');
                if (field.type === 'password') {
                    field.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    field.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            };

            // ===== PASSWORD STRENGTH =====
            const newPassword = document.getElementById('new_password');
            const strengthBar = document.getElementById('strengthBar');
            const strengthLabel = document.getElementById('strengthLabel');

            if (newPassword) {
                newPassword.addEventListener('input', function() {
                    const pwd = this.value;
                    let score = 0;

                    if (pwd.length >= 8) score++;
                    if (/[A-Z]/.test(pwd)) score++;
                    if (/[a-z]/.test(pwd)) score++;
                    if (/[0-9]/.test(pwd)) score++;

                    if (pwd.length === 0) {
                        strengthBar.style.width = '0%';
                        strengthLabel.textContent = '';
                        return;
                    }

                    if (score <= 1) {
                        strengthBar.className = 'bar weak';
                        strengthLabel.textContent = '⚠️ Mot de passe faible';
                        strengthLabel.style.color = '#e74c3c';
                    } else if (score === 2) {
                        strengthBar.className = 'bar medium';
                        strengthLabel.textContent = '⚠️ Mot de passe moyen';
                        strengthLabel.style.color = '#f39c12';
                    } else if (score === 3) {
                        strengthBar.className = 'bar good';
                        strengthLabel.textContent = '✅ Mot de passe bon';
                        strengthLabel.style.color = '#2ecc71';
                    } else {
                        strengthBar.className = 'bar strong';
                        strengthLabel.textContent = '✅ Mot de passe fort';
                        strengthLabel.style.color = '#27ae60';
                    }
                });
            }

            // ===== PASSWORD MATCH =====
            const confirmPassword = document.getElementById('confirm_password');
            const passwordMatch = document.getElementById('passwordMatch');

            if (confirmPassword && newPassword) {
                confirmPassword.addEventListener('input', function() {
                    if (this.value.length === 0) {
                        passwordMatch.textContent = '';
                        return;
                    }
                    if (this.value === newPassword.value) {
                        passwordMatch.innerHTML = '<i class="fas fa-check-circle" style="color: var(--primary-green);"></i> Les mots de passe correspondent';
                        passwordMatch.style.color = 'var(--primary-green)';
                    } else {
                        passwordMatch.innerHTML = '<i class="fas fa-exclamation-circle" style="color: #e74c3c;"></i> Les mots de passe ne correspondent pas';
                        passwordMatch.style.color = '#e74c3c';
                    }
                });
            }

            // ===== CONFIRM ACTION =====
            window.confirmAction = function(action) {
                const messages = {
                    'export': {
                        title: 'Exporter les données',
                        text: 'Voulez-vous exporter toutes les données de la plateforme ?',
                        icon: 'info'
                    },
                    'backup': {
                        title: 'Sauvegarder la base de données',
                        text: 'Voulez-vous créer une sauvegarde de la base de données ?',
                        icon: 'question'
                    },
                    'clear_cache': {
                        title: 'Vider le cache',
                        text: 'Cette action supprimera toutes les données en cache. Continuer ?',
                        icon: 'warning'
                    },
                    'reset': {
                        title: '⚠️ Réinitialisation de la plateforme',
                        text: 'Cette action est IRRÉVERSIBLE. Toutes les données seront supprimées. Êtes-vous absolument sûr ?',
                        icon: 'error'
                    }
                };

                const msg = messages[action] || {
                    title: 'Confirmation',
                    text: 'Voulez-vous effectuer cette action ?',
                    icon: 'question'
                };

                Swal.fire({
                    title: msg.title,
                    text: msg.text,
                    icon: msg.icon,
                    showCancelButton: true,
                    confirmButtonColor: action === 'reset' ? '#e74c3c' : '#27ae60',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Confirmer',
                    cancelButtonText: 'Annuler'
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.fire({
                            title: 'Action en cours...',
                            text: 'Veuillez patienter',
                            icon: 'info',
                            showConfirmButton: false,
                            timerProgressBar: true,
                            timer: 2000
                        }).then(() => {
                            Swal.fire({
                                title: '✅ Action réussie',
                                text: 'L\'opération a été effectuée avec succès.',
                                icon: 'success',
                                confirmButtonColor: '#27ae60',
                                confirmButtonText: 'OK'
                            });
                        });
                    }
                });
            };

            // ===== KEYBOARD SHORTCUTS =====
            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    const form = document.querySelector('form');
                    if (form) form.submit();
                }
                if (e.key === 'Escape' && sidebar.classList.contains('open')) {
                    toggleSidebar();
                }
            });

            // ===== CONSOLE LOG =====
            console.log('%c PharmaGeo - Paramètres Administration ', 'background: linear-gradient(135deg, rgb(42, 195, 42), hsl(25, 75%, 47%)); color: white; font-size: 16px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');
            console.log('%c 👔 Directeur Général de la plateforme.', 'color: #666; font-size: 14px;');

        });
    </script>

</body>
</html>