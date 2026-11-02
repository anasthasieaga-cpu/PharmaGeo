<?php
// ============================================================
// PharmaGeo — Espace patient : Profil
// Gestion du profil, mot de passe et statistiques personnelles.
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

// ===== TRAITEMENT DES FORMULAIRES =====
$error = '';
$success = '';
$message = '';

// ===== MISE À JOUR DU PROFIL =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    
    // Validation
    $errors = [];
    if (empty($nom)) $errors[] = "Le nom est obligatoire.";
    if (empty($prenom)) $errors[] = "Le prénom est obligatoire.";
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "L'email est invalide.";
    
    // Vérifier si l'email est déjà utilisé par un autre utilisateur
    if (empty($errors) && $email !== $user['Email']) {
        $check = $pdo->prepare("SELECT COUNT(*) as total FROM utilisateur WHERE LOWER(Email) = LOWER(:email) AND Id_utilisateur != :id");
        $check->execute([':email' => $email, ':id' => $_SESSION['user_id']]);
        if ($check->fetch()['total'] > 0) {
            $errors[] = "Cet email est déjà utilisé par un autre compte.";
        }
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE utilisateur 
                SET Nom = :nom, Prenom = :prenom, Email = :email, Telephone = :telephone 
                WHERE Id_utilisateur = :id
            ");
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
            
            // Recharger les données
            $stmt = $pdo->prepare("SELECT * FROM utilisateur WHERE Id_utilisateur = :id");
            $stmt->execute([':id' => $_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            $success = "Profil mis à jour avec succès !";
            
            // Journalisation
            $log = $pdo->prepare("INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur) 
                                  VALUES ('Mise à jour du profil', :ip, :ua, 'succes', :user)");
            $log->execute([
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':user' => $_SESSION['user_id']
            ]);
            
        } catch(PDOException $e) {
            $error = "Erreur lors de la mise à jour : " . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// ===== CHANGEMENT DE MOT DE PASSE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validation
    $errors = [];
    if (empty($current_password)) $errors[] = "Le mot de passe actuel est obligatoire.";
    if (empty($new_password)) $errors[] = "Le nouveau mot de passe est obligatoire.";
    if (strlen($new_password) < 8) $errors[] = "Le mot de passe doit contenir au moins 8 caractères.";
    if (!preg_match('/[A-Z]/', $new_password)) $errors[] = "Le mot de passe doit contenir au moins une majuscule.";
    if (!preg_match('/[a-z]/', $new_password)) $errors[] = "Le mot de passe doit contenir au moins une minuscule.";
    if (!preg_match('/[0-9]/', $new_password)) $errors[] = "Le mot de passe doit contenir au moins un chiffre.";
    if ($new_password !== $confirm_password) $errors[] = "Les mots de passe ne correspondent pas.";
    
    if (empty($errors)) {
        // Vérifier le mot de passe actuel
        $stmt = $pdo->prepare("SELECT Mot_de_passe FROM utilisateur WHERE Id_utilisateur = :id");
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user_data = $stmt->fetch();
        
        if ($user_data && password_verify($current_password, $user_data['Mot_de_passe'])) {
            // Mettre à jour le mot de passe
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE utilisateur SET Mot_de_passe = :password WHERE Id_utilisateur = :id");
            $stmt->execute([
                ':password' => $hashed_password,
                ':id' => $_SESSION['user_id']
            ]);
            
            $success = "Mot de passe modifié avec succès !";
            
            // Journalisation
            $log = $pdo->prepare("INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur) 
                                  VALUES ('Changement de mot de passe', :ip, :ua, 'succes', :user)");
            $log->execute([
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':user' => $_SESSION['user_id']
            ]);
            
        } else {
            $error = "Le mot de passe actuel est incorrect.";
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// ===== RÉCUPÉRATION DES STATISTIQUES (CORRIGÉ) =====
try {
    // Total des réservations
    $stmt_reservations = $pdo->prepare("SELECT COUNT(*) as total FROM reservation WHERE Id_utilisateur = :id");
    $stmt_reservations->execute([':id' => $_SESSION['user_id']]);
    $total_reservations = $stmt_reservations->fetch()['total'] ?? 0;

    // Total des recherches
    $stmt_recherches = $pdo->prepare("SELECT COUNT(*) as total FROM recherche WHERE Id_utilisateur = :id");
    $stmt_recherches->execute([':id' => $_SESSION['user_id']]);
    $total_recherches = $stmt_recherches->fetch()['total'] ?? 0;

    // Réservations en attente
    $stmt_attente = $pdo->prepare("SELECT COUNT(*) as total FROM reservation WHERE Id_utilisateur = :id AND Statut = 'en_attente'");
    $stmt_attente->execute([':id' => $_SESSION['user_id']]);
    $total_attente = $stmt_attente->fetch()['total'] ?? 0;

    // Réservations confirmées
    $stmt_confirmees = $pdo->prepare("SELECT COUNT(*) as total FROM reservation WHERE Id_utilisateur = :id AND Statut = 'confirmee'");
    $stmt_confirmees->execute([':id' => $_SESSION['user_id']]);
    $total_confirmees = $stmt_confirmees->fetch()['total'] ?? 0;

    // Regrouper les statistiques
    $stats = [
        'reservations' => $total_reservations,
        'recherches' => $total_recherches,
        'en_attente' => $total_attente,
        'confirmees' => $total_confirmees
    ];

} catch(PDOException $e) {
    $stats = ['reservations' => 0, 'recherches' => 0, 'en_attente' => 0, 'confirmees' => 0];
}

$page_title = 'Mon profil - PharmaGeo';
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

        /* ===== PROFILE CARD ===== */
        .profile-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-sm);
            border: 1px solid rgba(0,0,0,0.02);
            transition: var(--transition);
        }

        .profile-card:hover {
            box-shadow: var(--shadow-md);
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3.5rem;
            font-weight: 800;
            color: white;
            margin: 0 auto 16px;
            box-shadow: var(--shadow-glow-green);
            border: 4px solid var(--white);
        }

        .profile-name {
            text-align: center;
            font-size: 1.4rem;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .profile-email {
            text-align: center;
            color: var(--gray-text);
            margin-bottom: 16px;
        }

        .profile-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
            gap: 12px;
            padding-top: 16px;
            border-top: 1px solid #F0FDF4;
        }

        .profile-stats .stat-item {
            text-align: center;
        }

        .profile-stats .stat-item .number {
            font-size: 1.4rem;
            font-weight: 800;
            display: block;
        }

        .profile-stats .stat-item .label {
            font-size: 0.7rem;
            color: var(--gray-text);
        }

        /* ===== SETTINGS CARD ===== */
        .settings-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-sm);
            border: 1px solid rgba(0,0,0,0.02);
            transition: var(--transition);
            margin-bottom: 24px;
        }

        .settings-card:hover {
            box-shadow: var(--shadow-md);
        }

        .settings-card .card-header-custom {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 20px;
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
            background: rgba(22,163,74,0.1);
            color: var(--primary-green);
        }

        .settings-card .card-header-custom .header-icon.orange {
            background: rgba(21,128,61,0.1);
            color: var(--primary-orange);
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
            color: #DC2626;
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
            border: 2px solid #D9E9DF;
            border-radius: 14px;
            font-size: 0.95rem;
            transition: var(--transition);
            background: #F0FDF4;
            color: var(--dark-text);
        }

        .form-modern .input-group-custom .form-control:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 4px rgba(22,163,74,0.1);
            background: var(--white);
        }

        .form-modern .input-group-custom .form-control:disabled {
            background: #F0FDF4;
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
            box-shadow: 0 12px 40px rgba(22,163,74,0.4);
            color: white;
        }

        .btn-primary-custom:active { transform: scale(0.98); }

        .btn-danger-custom {
            background: #DC2626;
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 50px;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-danger-custom:hover {
            background: #B91C1C;
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(220,38,38,0.3);
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
            background: rgba(22,163,74,0.08);
            color: var(--primary-green);
            border-left: 4px solid var(--primary-green);
        }

        .alert-custom-danger {
            background: rgba(220,38,38,0.08);
            color: #DC2626;
            border-left: 4px solid #DC2626;
        }

        /* ===== PASSWORD STRENGTH ===== */
        .password-strength {
            height: 4px;
            border-radius: 4px;
            background: #D9E9DF;
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

        .password-strength .bar.weak { width: 25%; background: #DC2626; }
        .password-strength .bar.medium { width: 50%; background: #166534; }
        .password-strength .bar.good { width: 75%; background: #16A34A; }
        .password-strength .bar.strong { width: 100%; background: var(--primary-green); }

        .password-strength-label {
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 4px;
            text-align: right;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 991px) {
            .main-content { padding: 15px; }
            .top-bar .page-title h1 { font-size: 1.4rem; }
        }

        @media (max-width: 768px) {
            .top-bar { flex-direction: column; align-items: stretch; }
            .profile-avatar { width: 90px; height: 90px; font-size: 2.5rem; }
            .profile-name { font-size: 1.2rem; }
            .settings-card { padding: 18px; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 10px; }
            .top-bar .page-title h1 { font-size: 1.2rem; }
            .profile-card { padding: 20px; }
            .profile-avatar { width: 70px; height: 70px; font-size: 2rem; }
            .settings-card { padding: 14px; }
            .settings-card .card-header-custom .header-icon { width: 40px; height: 40px; font-size: 1.1rem; }
            .form-modern .input-group-custom .form-control { padding: 10px 14px 10px 38px; font-size: 0.9rem; }
            .btn-primary-custom { padding: 10px 20px; font-size: 0.9rem; }
            .profile-stats { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
    <link rel="stylesheet" href="../../assets/css/responsive.css">    <!-- Palette verte PharmaGeo -->
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
            <a href="<?php echo BASE_URL; ?>pages/patient/dashboard.php" class="nav-item"><i class="fas fa-tachometer-alt"></i><span>Tableau de bord</span></a>
            <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="nav-item"><i class="fas fa-search"></i><span>Rechercher</span></a>
            <a href="<?php echo BASE_URL; ?>pages/patient/carte.php" class="nav-item"><i class="fas fa-map-marker-alt"></i><span>Carte</span></a>
            <a href="<?php echo BASE_URL; ?>pages/patient/historique.php" class="nav-item"><i class="fas fa-history"></i><span>Historique</span></a>
            <a href="<?php echo BASE_URL; ?>pages/patient/profil.php" class="nav-item active"><i class="fas fa-user"></i><span>Profil</span></a>
            <div class="nav-label" style="margin-top: 20px;">Système</div>
            <a href="<?php echo BASE_URL; ?>deconnexion.php" class="nav-item" style="color: #FEF2F2; margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.15); margin-bottom: 12px;"><i class="fas fa-sign-out-alt"></i><span>Déconnexion</span></a>
        </nav>
        <a class="user-profile" href="<?php echo BASE_URL; ?>pages/patient/profil.php" title="Voir mon profil" aria-current="page">
            <div class="avatar"><?php echo strtoupper(substr(($_SESSION['prenom'] ?? 'A'), 0, 1) . substr(($_SESSION['nom'] ?? 'D'), 0, 1)); ?></div>
            <div>
                <div class="name"><?php echo htmlspecialchars(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')); ?></div>
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
                <h1>Mon <span class="highlight">profil</span></h1>
                <p>
                    <i class="fas fa-user me-1" style="color: var(--primary-green);"></i>
                    Gérez vos informations personnelles
                </p>
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

        <div class="row g-4">

            <!-- ===== COLONNE GAUCHE - PROFIL ===== -->
            <div class="col-lg-4 animate-fade-up delay-1">
                <div class="profile-card">
                    <div class="profile-avatar">
                        <?php echo strtoupper(substr($user['Prenom'] ?? 'U', 0, 1) . substr($user['Nom'] ?? 'U', 0, 1)); ?>
                    </div>
                    <div class="profile-name">
                        <?php echo htmlspecialchars($user['Prenom'] . ' ' . $user['Nom']); ?>
                    </div>
                    <div class="profile-email">
                        <i class="fas fa-envelope me-1"></i>
                        <?php echo htmlspecialchars($user['Email']); ?>
                    </div>
                    <div class="profile-email" style="font-size: 0.9rem;">
                        <i class="fas fa-phone me-1"></i>
                        <?php echo htmlspecialchars($user['Telephone'] ?? 'Non renseigné'); ?>
                    </div>
                    <div class="profile-stats">
                        <div class="stat-item">
                            <span class="number" style="color: var(--primary-green);"><?php echo $stats['reservations']; ?></span>
                            <span class="label">Réservations</span>
                        </div>
                        <div class="stat-item">
                            <span class="number" style="color: #166534;"><?php echo $stats['recherches']; ?></span>
                            <span class="label">Recherches</span>
                        </div>
                        <div class="stat-item">
                            <span class="number" style="color: var(--primary-orange);"><?php echo $stats['en_attente']; ?></span>
                            <span class="label">En attente</span>
                        </div>
                        <div class="stat-item">
                            <span class="number" style="color: var(--primary-green);"><?php echo $stats['confirmees']; ?></span>
                            <span class="label">Confirmées</span>
                        </div>
                    </div>
                    <div class="mt-3 pt-3 text-center" style="border-top: 1px solid #F0FDF4;">
                        <small class="text-muted">
                            <i class="fas fa-calendar-alt me-1"></i>
                            Membre depuis le <?php echo date('d/m/Y', strtotime($user['Date_creation'])); ?>
                        </small>
                    </div>
                </div>
            </div>

            <!-- ===== COLONNE DROITE - FORMULAIRES ===== -->
            <div class="col-lg-8">

                <!-- ===== SECTION 1: INFORMATIONS PERSONNELLES ===== -->
                <div class="settings-card animate-fade-up delay-2">
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
                                           value="<?php echo htmlspecialchars($user['Nom']); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6 form-group">
                                <label for="prenom" class="form-label">Prénom <span class="required">*</span></label>
                                <div class="input-group-custom">
                                    <span class="input-icon"><i class="fas fa-user-tie"></i></span>
                                    <input type="text" class="form-control" id="prenom" name="prenom" 
                                           value="<?php echo htmlspecialchars($user['Prenom']); ?>" required>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="email" class="form-label">Adresse email <span class="required">*</span></label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-envelope"></i></span>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($user['Email']); ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="telephone" class="form-label">Téléphone</label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-phone"></i></span>
                                <input type="tel" class="form-control" id="telephone" name="telephone" 
                                       value="<?php echo htmlspecialchars($user['Telephone'] ?? ''); ?>">
                            </div>
                        </div>

                        <button type="submit" class="btn-primary-custom">
                            <i class="fas fa-save"></i> Mettre à jour le profil
                        </button>
                    </form>
                </div>

                <!-- ===== SECTION 2: CHANGEMENT DE MOT DE PASSE ===== -->
                <div class="settings-card animate-fade-up delay-3">
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
                            <div class="form-text">
                                <small>Le mot de passe doit contenir au moins 8 caractères, une majuscule, une minuscule et un chiffre.</small>
                            </div>
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

                <!-- ===== SECTION 3: SUPPRESSION DU COMPTE ===== -->
                <div class="settings-card animate-fade-up delay-4">
                    <div class="card-header-custom">
                        <div class="header-icon" style="background: rgba(220,38,38,0.1); color: #DC2626;">
                            <i class="fas fa-trash-alt"></i>
                        </div>
                        <div class="header-title">
                            <h5 style="color: #DC2626;">Supprimer le compte</h5>
                            <p>Cette action est irréversible</p>
                        </div>
                    </div>

                    <div class="alert-custom" style="background: rgba(220,38,38,0.05); border-left-color: #DC2626; color: #DC2626;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div>
                            <strong>Attention !</strong> La suppression de votre compte est définitive. 
                            Toutes vos données (réservations, recherches, historique) seront supprimées.
                        </div>
                    </div>

                    <button class="btn-danger-custom" onclick="confirmDelete()">
                        <i class="fas fa-trash-alt"></i> Supprimer mon compte
                    </button>
                </div>

            </div>
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
                        strengthLabel.style.color = '#DC2626';
                    } else if (score === 2) {
                        strengthBar.className = 'bar medium';
                        strengthLabel.textContent = '⚠️ Mot de passe moyen';
                        strengthLabel.style.color = '#DC2626';
                    } else if (score === 3) {
                        strengthBar.className = 'bar good';
                        strengthLabel.textContent = '✅ Mot de passe bon';
                        strengthLabel.style.color = '#16A34A';
                    } else {
                        strengthBar.className = 'bar strong';
                        strengthLabel.textContent = '✅ Mot de passe fort';
                        strengthLabel.style.color = '#16A34A';
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
                        passwordMatch.innerHTML = '<i class="fas fa-exclamation-circle" style="color: #DC2626;"></i> Les mots de passe ne correspondent pas';
                        passwordMatch.style.color = '#DC2626';
                    }
                });
            }

            // ===== CONFIRMATION SUPPRESSION =====
            window.confirmDelete = function() {
                Swal.fire({
                    title: '⚠️ Supprimer votre compte',
                    text: 'Cette action est IRRÉVERSIBLE. Toutes vos données seront supprimées définitivement. Êtes-vous absolument sûr ?',
                    icon: 'error',
                    showCancelButton: true,
                    confirmButtonColor: '#DC2626',
                    cancelButtonColor: '#64748B',
                    confirmButtonText: 'Oui, supprimer mon compte',
                    cancelButtonText: 'Annuler',
                    reverseButtons: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.fire({
                            title: 'Veuillez confirmer',
                            text: 'Pour confirmer la suppression, tapez "SUPPRIMER" ci-dessous',
                            input: 'text',
                            inputPlaceholder: 'Tapez SUPPRIMER',
                            showCancelButton: true,
                            confirmButtonColor: '#DC2626',
                            cancelButtonColor: '#64748B',
                            confirmButtonText: 'Confirmer',
                            cancelButtonText: 'Annuler',
                            preConfirm: (value) => {
                                if (value !== 'SUPPRIMER') {
                                    Swal.showValidationMessage('Veuillez taper "SUPPRIMER" pour confirmer');
                                    return false;
                                }
                                return true;
                            }
                        }).then((result) => {
                            if (result.isConfirmed) {
                                // Rediriger vers la page de suppression
                                window.location.href = '<?php echo BASE_URL; ?>pages/patient/supprimer_compte.php';
                            }
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
                // Escape pour fermer les popups
                if (e.key === 'Escape') {
                    const modal = document.querySelector('.modal.show');
                    if (modal) {
                        const closeBtn = modal.querySelector('.btn-close');
                        if (closeBtn) closeBtn.click();
                    }
                }
            });

            // ===== CONSOLE LOG =====
            console.log('%c PharmaGeo - Mon profil ', 'background: linear-gradient(135deg, #16A34A, #166534); color: white; font-size: 16px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');
            console.log('%c Gérez vos informations personnelles.', 'color: #666; font-size: 14px;');

        });
    </script>

</body>
    <script src="../../assets/js/responsive.js"></script>
</html>