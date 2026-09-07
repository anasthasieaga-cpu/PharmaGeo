<?php
// ============================================================
// PharmaGeo — Inscription
// Crée un compte patient ou pharmacien et, pour le pharmacien,
// son profil avec les informations de la pharmacie.
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

// ===== SI DÉJÀ CONNECTÉ, REDIRECTION =====
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'administrateur') {
        header('Location: ' . BASE_URL . 'admin/dashboard.php');
    } elseif ($_SESSION['role'] === 'pharmacien') {
        header('Location: ' . BASE_URL . 'pharmacien/dashboard.php');
    } else {
        header('Location: ' . BASE_URL . 'index.php');
    }
    exit();
}

// ===== VARIABLES =====
$error = '';
$success_message = '';
$form_data = [
    'nom' => '',
    'prenom' => '',
    'email' => '',
    'telephone' => '',
    'password' => '',
    'confirm_password' => '',
    'role' => 'patient'
];

// Données spécifiques au pharmacien
$pharmacie_data = [
    'nom_pharmacie' => '',
    'adresse' => '',
    'quartier' => '',
    'commune' => 'Porto-Novo',
    'latitude' => '',
    'longitude' => '',
    'telephone_pharmacie' => '',
    'email_pharmacie' => '',
    'horaire' => '',
    'numero_ordre' => ''
];

// ===== TRAITEMENT DU FORMULAIRE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Nettoyer les données
    $form_data['nom'] = trim($_POST['nom'] ?? '');
    $form_data['prenom'] = trim($_POST['prenom'] ?? '');
    $form_data['email'] = trim($_POST['email'] ?? '');
    $form_data['telephone'] = trim($_POST['telephone'] ?? '');
    $form_data['password'] = $_POST['password'] ?? '';
    $form_data['confirm_password'] = $_POST['confirm_password'] ?? '';
    $form_data['role'] = $_POST['role'] ?? 'patient';
    
    // Données pharmacien
    if ($form_data['role'] === 'pharmacien') {
        $pharmacie_data['nom_pharmacie'] = trim($_POST['nom_pharmacie'] ?? '');
        $pharmacie_data['adresse'] = trim($_POST['adresse'] ?? '');
        $pharmacie_data['quartier'] = trim($_POST['quartier'] ?? '');
        $pharmacie_data['commune'] = trim($_POST['commune'] ?? 'Porto-Novo');
        $pharmacie_data['latitude'] = trim($_POST['latitude'] ?? '');
        $pharmacie_data['longitude'] = trim($_POST['longitude'] ?? '');
        $pharmacie_data['telephone_pharmacie'] = trim($_POST['telephone_pharmacie'] ?? '');
        $pharmacie_data['email_pharmacie'] = trim($_POST['email_pharmacie'] ?? '');
        $pharmacie_data['horaire'] = trim($_POST['horaire'] ?? '');
        $pharmacie_data['numero_ordre'] = trim($_POST['numero_ordre'] ?? '');
    }
    
    // ===== VALIDATION =====
    $errors = [];
    
    // Nom
    if (empty($form_data['nom'])) {
        $errors[] = "Le nom est obligatoire.";
    } elseif (strlen($form_data['nom']) < 2) {
        $errors[] = "Le nom doit contenir au moins 2 caractères.";
    }
    
    // Prénom
    if (empty($form_data['prenom'])) {
        $errors[] = "Le prénom est obligatoire.";
    } elseif (strlen($form_data['prenom']) < 2) {
        $errors[] = "Le prénom doit contenir au moins 2 caractères.";
    }
    
    // Email
    if (empty($form_data['email'])) {
        $errors[] = "L'adresse email est obligatoire.";
    } elseif (!filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "L'adresse email n'est pas valide.";
    } else {
        // Vérifier si l'email existe déjà
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM utilisateur WHERE Email = :email");
            $stmt->execute([':email' => $form_data['email']]);
            if ($stmt->fetch()['total'] > 0) {
                $errors[] = "Cette adresse email est déjà utilisée.";
            }
        } catch(PDOException $e) {
            // Ignorer
        }
    }
    
    // Téléphone
    if (!empty($form_data['telephone']) && !preg_match('/^[0-9+\-\s]{8,20}$/', $form_data['telephone'])) {
        $errors[] = "Le numéro de téléphone n'est pas valide.";
    }
    
    // Mot de passe
    if (empty($form_data['password'])) {
        $errors[] = "Le mot de passe est obligatoire.";
    } elseif (strlen($form_data['password']) < 8) {
        $errors[] = "Le mot de passe doit contenir au moins 8 caractères.";
    } elseif (!preg_match('/[A-Z]/', $form_data['password'])) {
        $errors[] = "Le mot de passe doit contenir au moins une majuscule.";
    } elseif (!preg_match('/[a-z]/', $form_data['password'])) {
        $errors[] = "Le mot de passe doit contenir au moins une minuscule.";
    } elseif (!preg_match('/[0-9]/', $form_data['password'])) {
        $errors[] = "Le mot de passe doit contenir au moins un chiffre.";
    }
    
    // Confirmation du mot de passe
    if ($form_data['password'] !== $form_data['confirm_password']) {
        $errors[] = "Les mots de passe ne correspondent pas.";
    }
    
    // Validation spécifique pharmacien
    if ($form_data['role'] === 'pharmacien') {
        if (empty($pharmacie_data['nom_pharmacie'])) {
            $errors[] = "Le nom de la pharmacie est obligatoire.";
        }
        if (empty($pharmacie_data['adresse'])) {
            $errors[] = "L'adresse de la pharmacie est obligatoire.";
        }
        if (empty($pharmacie_data['quartier'])) {
            $errors[] = "Le quartier est obligatoire.";
        }
        if (empty($pharmacie_data['latitude']) || empty($pharmacie_data['longitude'])) {
            $errors[] = "Les coordonnées GPS sont obligatoires pour la géolocalisation.";
        }
        if (empty($pharmacie_data['numero_ordre'])) {
            $errors[] = "Le numéro d'ordre ou d'autorisation est obligatoire.";
        }
        
        // Vérifier si le numéro d'ordre existe déjà
        if (!empty($pharmacie_data['numero_ordre'])) {
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM pharmacien WHERE Numero_ordre = :num");
                $stmt->execute([':num' => $pharmacie_data['numero_ordre']]);
                if ($stmt->fetch()['total'] > 0) {
                    $errors[] = "Ce numéro d'ordre est déjà utilisé.";
                }
            } catch(PDOException $e) {
                // Ignorer
            }
        }
        
        // Vérifier si l'email de la pharmacie existe déjà
        if (!empty($pharmacie_data['email_pharmacie'])) {
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM pharmacie WHERE Email = :email");
                $stmt->execute([':email' => $pharmacie_data['email_pharmacie']]);
                if ($stmt->fetch()['total'] > 0) {
                    $errors[] = "Cet email de pharmacie est déjà utilisé.";
                }
            } catch(PDOException $e) {
                // Ignorer
            }
        }
    }
    
    // ===== INSCRIPTION =====
    if (empty($errors)) {
        try {
            // Démarrer la transaction
            $pdo->beginTransaction();
            
            // Hacher le mot de passe
            $hashed_password = password_hash($form_data['password'], PASSWORD_DEFAULT);
            
            // Insérer l'utilisateur
            $stmt = $pdo->prepare("
                INSERT INTO utilisateur (Nom, Prenom, Email, Mot_de_passe, Telephone, Role, Statut)
                VALUES (:nom, :prenom, :email, :password, :telephone, :role, 'actif')
            ");
            
            $stmt->execute([
                ':nom' => $form_data['nom'],
                ':prenom' => $form_data['prenom'],
                ':email' => $form_data['email'],
                ':password' => $hashed_password,
                ':telephone' => $form_data['telephone'],
                ':role' => $form_data['role']
            ]);
            
            $user_id = $pdo->lastInsertId();
            
            // Si pharmacien, créer le profil pharmacien et la pharmacie
            if ($form_data['role'] === 'pharmacien') {
                // Créer le profil pharmacien
                $stmt = $pdo->prepare("
                    INSERT INTO pharmacien (Numero_ordre, Date_validation, Statut_validation, Id_utilisateur)
                    VALUES (:num_ordre, NULL, 'en_attente', :user_id)
                ");
                $stmt->execute([
                    ':num_ordre' => $pharmacie_data['numero_ordre'],
                    ':user_id' => $user_id
                ]);
                
                $pharmacien_id = $pdo->lastInsertId();
                
                // Créer la pharmacie
                $stmt = $pdo->prepare("
                    INSERT INTO pharmacie (
                        Nom, Adresse, Quartier, Commune, Latitude, Longitude, 
                        Telephone, Email, Horaire, Statut, Id_pharmacien
                    ) VALUES (
                        :nom, :adresse, :quartier, :commune, :lat, :lng,
                        :tel, :email, :horaire, 'en_attente', :pharmacien_id
                    )
                ");
                $stmt->execute([
                    ':nom' => $pharmacie_data['nom_pharmacie'],
                    ':adresse' => $pharmacie_data['adresse'],
                    ':quartier' => $pharmacie_data['quartier'],
                    ':commune' => $pharmacie_data['commune'],
                    ':lat' => $pharmacie_data['latitude'],
                    ':lng' => $pharmacie_data['longitude'],
                    ':tel' => $pharmacie_data['telephone_pharmacie'],
                    ':email' => $pharmacie_data['email_pharmacie'],
                    ':horaire' => $pharmacie_data['horaire'],
                    ':pharmacien_id' => $pharmacien_id
                ]);
            }
            
            // ===== JOURNALISATION - CORRECTION =====
            $log_stmt = $pdo->prepare("
                INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur)
                VALUES (CONCAT('Inscription ', :role), :ip, :user_agent, 'succes', :user_id)
            ");
            $log_stmt->execute([
                ':role' => $form_data['role'],
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':user_id' => $user_id
            ]);
            
            // Valider la transaction
            $pdo->commit();
            
            // Connecter automatiquement l'utilisateur
            $_SESSION['user_id'] = $user_id;
            $_SESSION['nom'] = $form_data['nom'];
            $_SESSION['prenom'] = $form_data['prenom'];
            $_SESSION['role'] = $form_data['role'];
            $_SESSION['email'] = $form_data['email'];
            $_SESSION['welcome_shown'] = false;
            
            $success_message = "Inscription réussie ! Bienvenue sur PharmaGeo.";
            if ($form_data['role'] === 'pharmacien') {
                $success_message .= " Votre compte pharmacien est en attente de validation par l'administrateur.";
            }
            
            // Redirection après 2 secondes
            echo '<meta http-equiv="refresh" content="2;url=' . BASE_URL . 'index.php">';
            
        } catch(PDOException $e) {
            // Annuler la transaction en cas d'erreur
            $pdo->rollBack();
            $errors[] = "Erreur lors de l'inscription : " . $e->getMessage();
        }
    }
    
    if (!empty($errors)) {
        $error = implode('<br>', $errors);
    }
}

$page_title = 'Inscription - PharmaGeo';
?>
<!DOCTYPE html>
<html lang="fr">
<!-- ===== EN-TÊTE DE PAGE (META ET RESSOURCES) ===== -->
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

    <!-- Préconnexions images de fond -->
    <link rel="preconnect" href="https://images.unsplash.com" crossorigin>
    <link rel="preload" as="image" href="https://images.unsplash.com/photo-1576091160550-2173dba999ef?q=80&amp;w=1600&amp;auto=format&amp;fit=crop">
    
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

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
                scroll-behavior: auto !important;
            }
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(42, 195, 42, 0.92), rgba(200, 120, 30, 0.88));
            padding: 20px;
            position: relative;
        }

        /* ===== BACKGROUND — IMAGES RÉELLES EN FONDU ENCHAÎNÉ ===== */
        .auth-bg {
            position: fixed;
            inset: 0;
            z-index: 0;
            overflow: hidden;
        }
        .auth-bg .bg-slide {
            position: absolute;
            inset: 0;
            background-size: cover;
            background-position: center;
            opacity: 0;
            transform: scale(1.12);
            animation: bgSlideShow 24s infinite;
        }
        .auth-bg .bg-slide:nth-child(1) {
            background-image: url('https://images.unsplash.com/photo-1576091160550-2173dba999ef?q=80&w=1600&auto=format&fit=crop');
            animation-delay: 0s;
        }
        .auth-bg .bg-slide:nth-child(2) {
            background-image: url('https://images.unsplash.com/photo-1696861286643-341a8d7a79e9?q=80&w=1600&auto=format&fit=crop');
            animation-delay: 8s;
        }
        .auth-bg .bg-slide:nth-child(3) {
            background-image: url('https://images.unsplash.com/photo-1577401132921-cb39bb0adcff?q=80&w=1600&auto=format&fit=crop');
            animation-delay: 16s;
        }
        @keyframes bgSlideShow {
            0%   { opacity: 0; transform: scale(1.12); }
            4%   { opacity: 1; transform: scale(1.12); }
            30%  { opacity: 1; transform: scale(1); }
            34%  { opacity: 0; transform: scale(1); }
            100% { opacity: 0; transform: scale(1); }
        }
        @media (prefers-reduced-motion: reduce) {
            .auth-bg .bg-slide { animation: none; opacity: 0; }
            .auth-bg .bg-slide:nth-child(1) { opacity: 1; transform: scale(1); }
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image:
                radial-gradient(circle at 15% 20%, rgba(255,255,255,0.06) 0%, transparent 45%),
                radial-gradient(circle at 85% 85%, rgba(255,255,255,0.05) 0%, transparent 50%);
            z-index: 0;
        }

        body::after {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(150deg, rgba(6,14,26,0.88) 0%, rgba(10,30,20,0.72) 45%, rgba(42,195,42,0.65) 78%, rgba(200,120,30,0.65) 100%);
            z-index: 1;
        }

        /* ===== ANIMATIONS ===== */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(40px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-15px); }
        }

        .animate-fade-up { animation: fadeInUp 0.8s ease-out forwards; }
        .animate-float { animation: float 3s ease-in-out infinite; }
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }

        /* ===== CARD PRINCIPALE ===== */
        .register-card {
            position: relative;
            z-index: 2;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-xl);
            border: 1px solid rgba(255, 255, 255, 0.2);
            max-width: 600px;
            width: 100%;
            padding: 40px 35px;
            transition: var(--transition);
            max-height: 90vh;
            overflow-y: auto;
        }

        .register-card::-webkit-scrollbar { width: 6px; }
        .register-card::-webkit-scrollbar-track { background: transparent; }
        .register-card::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            border-radius: 10px;
        }

        .register-card:hover {
            box-shadow: 0 40px 100px rgba(0,0,0,0.25);
        }

        /* ===== HEADER ===== */
        .register-header {
            text-align: center;
            margin-bottom: 28px;
        }

        .register-header .logo {
            display: inline-block;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            padding: 14px 18px;
            border-radius: 16px;
            color: white;
            font-size: 2rem;
            margin-bottom: 14px;
            box-shadow: var(--shadow-glow-green);
            transition: var(--transition);
        }

        .register-header .logo:hover {
            transform: scale(1.05);
        }

        .register-header h1 {
            font-size: clamp(1.2rem, 3vw + 0.6rem, 1.8rem);
            font-weight: 800;
            color: var(--dark-text);
            margin-bottom: 4px;
        }

        .register-header h1 .highlight {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .register-header p {
            color: var(--gray-text);
            font-size: 0.95rem;
            margin-bottom: 0;
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

        .alert-custom-info {
            background: rgba(52,152,219,0.08);
            color: #3498db;
            border-left: 4px solid #3498db;
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

        .form-modern .input-group-custom .form-control,
        .form-modern .input-group-custom .form-select {
            padding: 12px 16px 12px 44px;
            border: 2px solid #e8ecf1;
            border-radius: 14px;
            font-size: 0.95rem;
            transition: var(--transition);
            background: #f8f9fa;
            color: var(--dark-text);
        }

        .form-modern .input-group-custom .form-control:focus,
        .form-modern .input-group-custom .form-select:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 4px rgba(42,195,42,0.1);
            background: var(--white);
        }

        .form-modern .input-group-custom .form-control.is-invalid {
            border-color: #e74c3c;
            box-shadow: 0 0 0 4px rgba(231,76,60,0.1);
        }

        .form-modern .input-group-custom .form-control.is-valid {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 4px rgba(42,195,42,0.1);
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

        .form-modern .form-text {
            font-size: 0.8rem;
            color: var(--gray-text);
            margin-top: 4px;
        }

        .form-modern .form-text .requirements {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 4px;
        }

        .form-modern .form-text .requirements .req-item {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 0.75rem;
            padding: 2px 10px;
            border-radius: 50px;
            background: #f0f0f0;
            transition: var(--transition);
        }

        .form-modern .form-text .requirements .req-item.valid {
            background: rgba(42,195,42,0.1);
            color: var(--primary-green);
        }

        .form-modern .form-text .requirements .req-item.invalid {
            background: rgba(231,76,60,0.1);
            color: #e74c3c;
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

        /* ===== BOUTONS ===== */
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 1rem;
            width: 100%;
            transition: var(--transition);
            box-shadow: var(--shadow-glow-green);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-primary-custom:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 40px rgba(42,195,42,0.4);
            color: white;
        }

        .btn-primary-custom:active { transform: scale(0.98); }

        /* ===== ROLE SELECTOR ===== */
        .role-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 20px;
        }

        .role-option {
            padding: 14px;
            border: 3px solid #e8ecf1;
            border-radius: 14px;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            background: var(--white);
        }

        .role-option:hover {
            border-color: var(--primary-green);
            transform: translateY(-2px);
        }

        .role-option.active {
            border-color: var(--primary-green);
            background: rgba(42,195,42,0.04);
            box-shadow: 0 0 0 4px rgba(42,195,42,0.1);
        }

        .role-option .role-icon {
            font-size: 1.8rem;
            display: block;
            margin-bottom: 6px;
        }

        .role-option .role-name {
            font-weight: 700;
            font-size: 0.95rem;
            display: block;
        }

        .role-option .role-desc {
            font-size: 0.75rem;
            color: var(--gray-text);
        }

        /* ===== FOOTER ===== */
        .register-footer {
            text-align: center;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid rgba(0,0,0,0.05);
        }

        .register-footer p {
            color: var(--gray-text);
            font-size: 0.9rem;
            margin-bottom: 0;
        }

        .register-footer a {
            color: var(--primary-green);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
        }

        .register-footer a:hover {
            color: var(--primary-orange);
            text-decoration: underline;
        }

        /* ===== FLOATING ELEMENTS ===== */
        .floating-shapes {
            position: fixed;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: 0;
        }

        .floating-shapes .shape {
            position: absolute;
            border-radius: 50%;
            background: rgba(255,255,255,0.05);
            animation: float 8s ease-in-out infinite;
        }

        .floating-shapes .shape:nth-child(1) {
            width: 400px;
            height: 400px;
            top: -150px;
            right: -100px;
            animation-delay: 0s;
            background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
        }

        .floating-shapes .shape:nth-child(2) {
            width: 250px;
            height: 250px;
            bottom: 80px;
            left: -80px;
            animation-delay: 2s;
            background: radial-gradient(circle, rgba(255,255,255,0.06) 0%, transparent 70%);
        }

        .floating-shapes .shape:nth-child(3) {
            width: 180px;
            height: 180px;
            top: 40%;
            right: 15%;
            animation-delay: 4s;
            background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%);
        }

        /* ===== PHARMACIE FIELDS ===== */
        .pharmacie-fields {
            display: none;
            padding-top: 16px;
            border-top: 2px dashed #e8ecf1;
            margin-top: 16px;
        }

        .pharmacie-fields.show {
            display: block;
            animation: fadeInUp 0.4s ease-out;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 576px) {
            .register-card {
                padding: 24px 18px;
                max-height: 95vh;
            }

            .register-header .logo {
                padding: 12px 15px;
                font-size: 1.6rem;
            }

            .form-modern .input-group-custom .form-control,
            .form-modern .input-group-custom .form-select {
                padding: 10px 14px 10px 38px;
                font-size: 0.9rem;
            }

            .form-modern .input-group-custom .input-icon {
                left: 12px;
                font-size: 0.9rem;
            }

            .btn-primary-custom {
                padding: 12px 20px;
                font-size: 0.95rem;
            }

            .role-selector { grid-template-columns: 1fr; }
            body { padding: 10px; }
        }

        @media (max-width: 400px) {
            .register-card { padding: 16px 12px; }
            .register-header .logo {
                padding: 10px 12px;
                font-size: 1.3rem;
            }
        }

        /* ===== SPINNER ===== */
        .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .btn-primary-custom.loading .btn-text { display: none; }
        .btn-primary-custom.loading .spinner { display: inline-block; }
    </style>
    <link rel="stylesheet" href="assets/css/responsive.css">
</head>
<body>

    <!-- ===== IMAGES EN ARRIÈRE-PLAN QUI DÉFILENT ===== -->
    <div class="auth-bg" aria-hidden="true">
        <div class="bg-slide"></div>
        <div class="bg-slide"></div>
        <div class="bg-slide"></div>
    </div>

    <!-- ===== FLOATING SHAPES ===== -->
    <div class="floating-shapes">
        <div class="shape"></div>
        <div class="shape"></div>
        <div class="shape"></div>
    </div>

    <!-- ===== CARD PRINCIPALE ===== -->
    <div class="register-card animate-fade-up">
        
        <!-- ===== HEADER ===== -->
        <div class="register-header">
            <div class="logo animate-float">
                <i class="fas fa-hospital"></i>
            </div>
            <h1>
                <span class="highlight">PharmaGeo</span>
            </h1>
            <p>Créez votre compte pour accéder à tous nos services</p>
        </div>

        <!-- ===== MESSAGES ===== -->
        <?php if (!empty($error)): ?>
            <div class="alert-custom alert-custom-danger">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <strong>Erreur :</strong><br>
                    <?php echo $error; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="alert-custom alert-custom-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Succès !</strong><br>
                    <?php echo $success_message; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ===== FORMULAIRE ===== -->
        <form method="POST" class="form-modern" id="registerForm" novalidate>
            
            <!-- ===== SÉLECTION DU RÔLE ===== -->
            <div class="role-selector">
                <div class="role-option active" data-role="patient" onclick="selectRole('patient')">
                    <span class="role-icon">👤</span>
                    <span class="role-name">Patient</span>
                    <span class="role-desc">Rechercher des médicaments</span>
                </div>
                <div class="role-option" data-role="pharmacien" onclick="selectRole('pharmacien')">
                    <span class="role-icon">🏥</span>
                    <span class="role-name">Pharmacien</span>
                    <span class="role-desc">Gérer votre officine</span>
                </div>
            </div>

            <input type="hidden" name="role" id="roleInput" value="patient">

            <!-- ===== INFORMATIONS PERSONNELLES ===== -->
            <div class="row g-3">
                <div class="col-md-6 form-group">
                    <label for="nom" class="form-label">Nom <span class="required">*</span></label>
                    <div class="input-group-custom">
                        <span class="input-icon"><i class="fas fa-user"></i></span>
                        <input type="text" class="form-control" id="nom" name="nom" 
                               placeholder="Votre nom" value="<?php echo htmlspecialchars($form_data['nom']); ?>" 
                               required autofocus>
                    </div>
                </div>
                <div class="col-md-6 form-group">
                    <label for="prenom" class="form-label">Prénom <span class="required">*</span></label>
                    <div class="input-group-custom">
                        <span class="input-icon"><i class="fas fa-user-tie"></i></span>
                        <input type="text" class="form-control" id="prenom" name="prenom" 
                               placeholder="Votre prénom" value="<?php echo htmlspecialchars($form_data['prenom']); ?>" 
                               required>
                    </div>
                </div>
            </div>

            <!-- Email -->
            <div class="form-group">
                <label for="email" class="form-label">Adresse email <span class="required">*</span></label>
                <div class="input-group-custom">
                    <span class="input-icon"><i class="fas fa-envelope"></i></span>
                    <input type="email" class="form-control" id="email" name="email" 
                           placeholder="exemple@email.com" value="<?php echo htmlspecialchars($form_data['email']); ?>" 
                           required>
                </div>
                <div class="form-text">
                    <i class="fas fa-info-circle"></i> L'email sera utilisé pour la connexion
                </div>
            </div>

            <!-- Téléphone -->
            <div class="form-group">
                <label for="telephone" class="form-label">Téléphone <span style="color: #6c757d; font-weight: 400;">(optionnel)</span></label>
                <div class="input-group-custom">
                    <span class="input-icon"><i class="fas fa-phone"></i></span>
                    <input type="tel" class="form-control" id="telephone" name="telephone" 
                           placeholder="+229 XX XX XX XX" value="<?php echo htmlspecialchars($form_data['telephone']); ?>">
                </div>
            </div>

            <!-- ===== CHAMPS PHARMACIEN ===== -->
            <div class="pharmacie-fields <?php echo $form_data['role'] === 'pharmacien' ? 'show' : ''; ?>" id="pharmacieFields">
                <h6 class="fw-bold mb-3" style="color: var(--primary-green);">
                    <i class="fas fa-store me-2"></i>Informations de la pharmacie
                </h6>
                
                <div class="form-group">
                    <label for="nom_pharmacie" class="form-label">Nom de la pharmacie <span class="required">*</span></label>
                    <div class="input-group-custom">
                        <span class="input-icon"><i class="fas fa-store"></i></span>
                        <input type="text" class="form-control" id="nom_pharmacie" name="nom_pharmacie" 
                               placeholder="Nom de votre officine" value="<?php echo htmlspecialchars($pharmacie_data['nom_pharmacie']); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="numero_ordre" class="form-label">Numéro d'ordre / Autorisation <span class="required">*</span></label>
                    <div class="input-group-custom">
                        <span class="input-icon"><i class="fas fa-id-card"></i></span>
                        <input type="text" class="form-control" id="numero_ordre" name="numero_ordre" 
                               placeholder="Numéro d'autorisation d'exploitation" value="<?php echo htmlspecialchars($pharmacie_data['numero_ordre']); ?>">
                    </div>
                    <div class="form-text">
                        <i class="fas fa-info-circle"></i> Numéro délivré par l'ordre des pharmaciens
                    </div>
                </div>

                <div class="form-group">
                    <label for="adresse" class="form-label">Adresse complète <span class="required">*</span></label>
                    <div class="input-group-custom">
                        <span class="input-icon"><i class="fas fa-map-pin"></i></span>
                        <input type="text" class="form-control" id="adresse" name="adresse" 
                               placeholder="Adresse complète de la pharmacie" value="<?php echo htmlspecialchars($pharmacie_data['adresse']); ?>">
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6 form-group">
                        <label for="quartier" class="form-label">Quartier <span class="required">*</span></label>
                        <div class="input-group-custom">
                            <span class="input-icon"><i class="fas fa-map-marker-alt"></i></span>
                            <input type="text" class="form-control" id="quartier" name="quartier" 
                                   placeholder="Quartier" value="<?php echo htmlspecialchars($pharmacie_data['quartier']); ?>">
                        </div>
                    </div>
                    <div class="col-md-6 form-group">
                        <label for="commune" class="form-label">Commune</label>
                        <div class="input-group-custom">
                            <span class="input-icon"><i class="fas fa-city"></i></span>
                            <input type="text" class="form-control" id="commune" name="commune" 
                                   list="communes-list-inscription"
                                   placeholder="Sélectionnez ou saisissez une commune"
                                   value="<?php echo htmlspecialchars($pharmacie_data['commune']); ?>">
                        </div>
                        <datalist id="communes-list-inscription">
                            <option value="Porto-Novo">
                            <option value="Ouèdo">
                            <option value="Kingnal">
                            <option value="Togba">
                            <option value="Adjarra">
                            <option value="Sèmè-Podji">
                            <option value="Avèkèrou">
                            <option value="Glo-Djigbé">
                            <option value="Sèmè-Kpodji">
                            <option value="Kpota">
                            <option value="Djègbadji">
                            <option value="Hêvié">
                            <option value="Missérété">
                            <option value="Zinvié">
                            <option value="Calavi">
                            <option value="Cotonou">
                            <option value="Abomey-Calavi">
                            <option value="Godomey">
                            <option value="Allada">
                            <option value="Ouidah">
                            <option value="Whydah">
                            <option value="Possotomé">
                            <option value="Ahouandon">
                            <option value="Togoudo">
                            <option value="Akpro-Missérété">
                            <option value="Agblangandan">
                            <option value="Dèkpo">
                            <option value="Akpakpa">
                            <option value="Cadjèhoun">
                            <option value="Fidjrossè">
                            <option value="Gbanline">
                            <option value="Massamé">
                            <option value="Sèhouè">
                            <option value="Togoudo-Agoogle">
                            <option value="Zè">
                        </datalist>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6 form-group">
                        <label for="latitude" class="form-label">Latitude <span class="required">*</span></label>
                        <div class="input-group-custom">
                            <span class="input-icon"><i class="fas fa-globe"></i></span>
                            <input type="text" class="form-control" id="latitude" name="latitude" 
                                   placeholder="Ex: 6.4970" value="<?php echo htmlspecialchars($pharmacie_data['latitude']); ?>">
                        </div>
                    </div>
                    <div class="col-md-6 form-group">
                        <label for="longitude" class="form-label">Longitude <span class="required">*</span></label>
                        <div class="input-group-custom">
                            <span class="input-icon"><i class="fas fa-globe"></i></span>
                            <input type="text" class="form-control" id="longitude" name="longitude" 
                                   placeholder="Ex: 2.6050" value="<?php echo htmlspecialchars($pharmacie_data['longitude']); ?>">
                        </div>
                    </div>
                </div>
                <div class="form-text mb-2">
                    <i class="fas fa-info-circle"></i> Utilisez <a href="https://www.google.com/maps" target="_blank">Google Maps</a> pour trouver les coordonnées GPS
                </div>

                <div class="row g-3">
                    <div class="col-md-6 form-group">
                        <label for="telephone_pharmacie" class="form-label">Téléphone de la pharmacie</label>
                        <div class="input-group-custom">
                            <span class="input-icon"><i class="fas fa-phone"></i></span>
                            <input type="text" class="form-control" id="telephone_pharmacie" name="telephone_pharmacie" 
                                   placeholder="Téléphone" value="<?php echo htmlspecialchars($pharmacie_data['telephone_pharmacie']); ?>">
                        </div>
                    </div>
                    <div class="col-md-6 form-group">
                        <label for="email_pharmacie" class="form-label">Email de la pharmacie</label>
                        <div class="input-group-custom">
                            <span class="input-icon"><i class="fas fa-envelope"></i></span>
                            <input type="email" class="form-control" id="email_pharmacie" name="email_pharmacie" 
                                   placeholder="Email de la pharmacie" value="<?php echo htmlspecialchars($pharmacie_data['email_pharmacie']); ?>">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="horaire" class="form-label">Horaires d'ouverture</label>
                    <div class="input-group-custom">
                        <span class="input-icon"><i class="fas fa-clock"></i></span>
                        <input type="text" class="form-control" id="horaire" name="horaire" 
                               placeholder="Ex: Lun-Ven: 8h-20h, Sam: 8h-14h" value="<?php echo htmlspecialchars($pharmacie_data['horaire']); ?>">
                    </div>
                </div>
            </div>

            <!-- ===== MOT DE PASSE ===== -->
            <div class="form-group">
                <label for="password" class="form-label">Mot de passe <span class="required">*</span></label>
                <div class="input-group-custom">
                    <span class="input-icon"><i class="fas fa-lock"></i></span>
                    <input type="password" class="form-control" id="password" name="password" 
                           placeholder="Min. 8 caractères" required>
                    <button type="button" class="toggle-password" onclick="togglePassword('password')">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                
                <!-- Force du mot de passe -->
                <div class="password-strength" id="passwordStrength">
                    <div class="bar" id="strengthBar"></div>
                </div>
                <div class="password-strength-label" id="strengthLabel"></div>
                
                <div class="form-text">
                    <div class="requirements">
                        <span class="req-item" id="req-length"><i class="fas fa-times"></i> 8 caractères</span>
                        <span class="req-item" id="req-upper"><i class="fas fa-times"></i> Majuscule</span>
                        <span class="req-item" id="req-lower"><i class="fas fa-times"></i> Minuscule</span>
                        <span class="req-item" id="req-number"><i class="fas fa-times"></i> Chiffre</span>
                    </div>
                </div>
            </div>

            <!-- Confirmation mot de passe -->
            <div class="form-group">
                <label for="confirm_password" class="form-label">Confirmer le mot de passe <span class="required">*</span></label>
                <div class="input-group-custom">
                    <span class="input-icon"><i class="fas fa-check-double"></i></span>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                           placeholder="Confirmez votre mot de passe" required>
                    <button type="button" class="toggle-password" onclick="togglePassword('confirm_password')">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <div class="form-text" id="passwordMatch"></div>
            </div>

            <!-- Bouton d'inscription -->
            <button type="submit" class="btn-primary-custom" id="submitBtn">
                <span class="btn-text">
                    <i class="fas fa-user-plus"></i> S'inscrire
                </span>
                <span class="spinner"></span>
            </button>

        </form>

        <!-- ===== FOOTER ===== -->
        <div class="register-footer">
            <p>
                Déjà un compte ? 
                <a href="<?php echo BASE_URL; ?>connexion.php">Se connecter</a>
            </p>
            <p class="mt-1" style="font-size: 0.8rem; color: #adb5bd;">
                <i class="fas fa-shield-alt me-1"></i>
                Vos données sont sécurisées et chiffrées
            </p>
            <p style="font-size: 0.75rem; color: #adb5bd;">
                <a href="<?php echo BASE_URL; ?>index.php" style="color: #adb5bd; text-decoration: none;">
                    <i class="fas fa-arrow-left me-1"></i> Retour à l'accueil
                </a>
            </p>
        </div>

    </div>

    <!-- ===== SCRIPTS ===== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // ===== SÉLECTION DU RÔLE =====
        function selectRole(role) {
            // Mettre à jour l'interface
            document.querySelectorAll('.role-option').forEach(el => {
                el.classList.remove('active');
            });
            document.querySelector(`.role-option[data-role="${role}"]`).classList.add('active');
            
            // Mettre à jour le champ caché
            document.getElementById('roleInput').value = role;
            
            // Afficher/masquer les champs pharmacien
            const pharmacieFields = document.getElementById('pharmacieFields');
            if (role === 'pharmacien') {
                pharmacieFields.classList.add('show');
                // Rendre les champs requis
                document.querySelectorAll('#pharmacieFields input').forEach(input => {
                    if (input.id !== 'telephone_pharmacie' && input.id !== 'email_pharmacie' && input.id !== 'horaire') {
                        input.required = true;
                    }
                });
            } else {
                pharmacieFields.classList.remove('show');
                document.querySelectorAll('#pharmacieFields input').forEach(input => {
                    input.required = false;
                });
            }
        }

        // ===== TOGGLE PASSWORD =====
        function togglePassword(fieldId) {
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
        }

        // ===== PASSWORD STRENGTH =====
        const password = document.getElementById('password');
        const strengthBar = document.getElementById('strengthBar');
        const strengthLabel = document.getElementById('strengthLabel');
        
        const reqLength = document.getElementById('req-length');
        const reqUpper = document.getElementById('req-upper');
        const reqLower = document.getElementById('req-lower');
        const reqNumber = document.getElementById('req-number');
        const passwordMatch = document.getElementById('passwordMatch');

        function updateRequirement(element, valid, label) {
            if (valid) {
                element.className = 'req-item valid';
                element.innerHTML = '<i class="fas fa-check"></i> ' + label;
            } else {
                element.className = 'req-item invalid';
                element.innerHTML = '<i class="fas fa-times"></i> ' + label;
            }
        }

        if (password) {
            password.addEventListener('input', function() {
                const pwd = this.value;
                let score = 0;
                let checks = {
                    length: pwd.length >= 8,
                    upper: /[A-Z]/.test(pwd),
                    lower: /[a-z]/.test(pwd),
                    number: /[0-9]/.test(pwd)
                };

                updateRequirement(reqLength, checks.length, '8 caractères');
                updateRequirement(reqUpper, checks.upper, 'Majuscule');
                updateRequirement(reqLower, checks.lower, 'Minuscule');
                updateRequirement(reqNumber, checks.number, 'Chiffre');

                if (checks.length) score++;
                if (checks.upper) score++;
                if (checks.lower) score++;
                if (checks.number) score++;

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

                checkPasswordMatch();
            });
        }

        // ===== PASSWORD MATCH =====
        const confirmPassword = document.getElementById('confirm_password');

        function checkPasswordMatch() {
            const pwd = password.value;
            const confirm = confirmPassword.value;
            
            if (confirm.length === 0) {
                passwordMatch.textContent = '';
                confirmPassword.classList.remove('is-valid', 'is-invalid');
                return;
            }
            
            if (pwd === confirm) {
                passwordMatch.innerHTML = '<i class="fas fa-check-circle" style="color: var(--primary-green);"></i> Les mots de passe correspondent';
                passwordMatch.style.color = 'var(--primary-green)';
                confirmPassword.classList.remove('is-invalid');
                confirmPassword.classList.add('is-valid');
            } else {
                passwordMatch.innerHTML = '<i class="fas fa-exclamation-circle" style="color: #e74c3c;"></i> Les mots de passe ne correspondent pas';
                passwordMatch.style.color = '#e74c3c';
                confirmPassword.classList.remove('is-valid');
                confirmPassword.classList.add('is-invalid');
            }
        }

        if (confirmPassword) {
            confirmPassword.addEventListener('input', checkPasswordMatch);
        }

        // ===== FORM SUBMISSION =====
        const form = document.getElementById('registerForm');
        const submitBtn = document.getElementById('submitBtn');

        if (form) {
            form.addEventListener('submit', function(e) {
                // Vérifier que le mot de passe est fort
                const pwd = password.value;
                if (pwd.length > 0) {
                    const checks = {
                        length: pwd.length >= 8,
                        upper: /[A-Z]/.test(pwd),
                        lower: /[a-z]/.test(pwd),
                        number: /[0-9]/.test(pwd)
                    };
                    const score = Object.values(checks).filter(v => v === true).length;
                    if (score < 3) {
                        e.preventDefault();
                        alert('Veuillez choisir un mot de passe plus fort (au moins 8 caractères, avec majuscule, minuscule et chiffre).');
                        password.focus();
                        return;
                    }
                }
                
                // Vérifier que les mots de passe correspondent
                if (password.value !== confirmPassword.value) {
                    e.preventDefault();
                    alert('Les mots de passe ne correspondent pas.');
                    confirmPassword.focus();
                    return;
                }

                // Vérifier les champs pharmacien si nécessaire
                const role = document.getElementById('roleInput').value;
                if (role === 'pharmacien') {
                    const requiredFields = ['nom_pharmacie', 'numero_ordre', 'adresse', 'quartier', 'latitude', 'longitude'];
                    let missing = false;
                    requiredFields.forEach(fieldId => {
                        const field = document.getElementById(fieldId);
                        if (!field.value.trim()) {
                            field.classList.add('is-invalid');
                            missing = true;
                        } else {
                            field.classList.remove('is-invalid');
                        }
                    });
                    if (missing) {
                        e.preventDefault();
                        alert('Veuillez remplir tous les champs obligatoires de la pharmacie.');
                        return;
                    }
                }

                // Activer le loader
                submitBtn.classList.add('loading');
                submitBtn.disabled = true;
            });
        }

        // ===== KEYBOARD SHORTCUTS =====
        document.addEventListener('keydown', function(e) {
            // Escape pour réinitialiser
            if (e.key === 'Escape') {
                if (confirm('Voulez-vous réinitialiser le formulaire ?')) {
                    form.reset();
                    window.location.reload();
                }
            }
        });

        // ===== CONSOLE LOG =====
        console.log('%c PharmaGeo - Inscription ', 'background: linear-gradient(135deg, rgb(42, 195, 42), hsl(25, 75%, 47%)); color: white; font-size: 16px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');
        console.log('%c Créez votre compte pour accéder à tous nos services.', 'color: #666; font-size: 13px;');
    </script>

</body>
</html>