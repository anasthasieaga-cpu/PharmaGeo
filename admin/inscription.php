<?php
// ============================================================
// PharmaGeo — Administration : Inscription administrateur
// Formulaire de création du compte administrateur principal
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

// ===== VÉRIFICATION DE L'ADMINISTRATEUR EXISTANT =====
// Vérifier si un administrateur existe déjà
$admin_exists = false;
try {
    $check = $pdo->query("SELECT COUNT(*) as total FROM utilisateur WHERE Role = 'administrateur' AND Statut = 'actif'")->fetch();
    $admin_exists = ($check['total'] > 0);
} catch(PDOException $e) {
    // Ignorer l'erreur
}

// ===== TRAITEMENT DU FORMULAIRE =====
$error = '';
$success = '';
$form_data = [
    'nom' => '',
    'prenom' => '',
    'email' => '',
    'telephone' => '',
    'password' => '',
    'confirm_password' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Nettoyer les données
    $form_data['nom'] = trim($_POST['nom'] ?? '');
    $form_data['prenom'] = trim($_POST['prenom'] ?? '');
    $form_data['email'] = trim($_POST['email'] ?? '');
    $form_data['telephone'] = trim($_POST['telephone'] ?? '');
    $form_data['password'] = $_POST['password'] ?? '';
    $form_data['confirm_password'] = $_POST['confirm_password'] ?? '';
    
    // ===== VALIDATION =====
    $errors = [];
    
    // Vérifier si un administrateur existe déjà
    if ($admin_exists) {
        $errors[] = "Un administrateur existe déjà. Vous ne pouvez pas créer un autre compte administrateur.";
    }
    
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
    
    // ===== INSCRIPTION =====
    if (empty($errors)) {
        try {
            // Hacher le mot de passe
            $hashed_password = password_hash($form_data['password'], PASSWORD_DEFAULT);
            
            // Insérer l'utilisateur
            $stmt = $pdo->prepare("
                INSERT INTO utilisateur (Nom, Prenom, Email, Mot_de_passe, Telephone, Role, Statut)
                VALUES (:nom, :prenom, :email, :password, :telephone, 'administrateur', 'actif')
            ");
            
            $stmt->execute([
                ':nom' => $form_data['nom'],
                ':prenom' => $form_data['prenom'],
                ':email' => $form_data['email'],
                ':password' => $hashed_password,
                ':telephone' => $form_data['telephone']
            ]);
            
            $user_id = $pdo->lastInsertId();
            
            // Journaliser l'inscription
            $log_stmt = $pdo->prepare("
                INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur)
                VALUES ('Inscription administrateur', :ip, :user_agent, 'succes', :user_id)
            ");
            $log_stmt->execute([
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':user_id' => $user_id
            ]);
            
            // Connecter automatiquement l'administrateur
            $_SESSION['user_id'] = $user_id;
            $_SESSION['nom'] = $form_data['nom'];
            $_SESSION['prenom'] = $form_data['prenom'];
            $_SESSION['role'] = 'administrateur';
            $_SESSION['email'] = $form_data['email'];
            
            $success = "Compte administrateur créé avec succès ! Vous allez être redirigé vers le tableau de bord.";
            
            // Redirection après 2 secondes
            echo '<meta http-equiv="refresh" content="2;url=' . BASE_URL . 'admin/dashboard.php">';
            
        } catch(PDOException $e) {
            $error = "Erreur lors de l'inscription : " . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

$page_title = 'Inscription Administrateur - PharmaGeo';
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
            --primary-green: rgb(42, 195, 42);
            --primary-orange: hsl(25, 75%, 47%);
            --white: #ffffff;
            --dark-text: #1a1a2e;
            --gray-text: #6c757d;
            --shadow-md: 0 8px 40px rgba(0,0,0,0.10);
            --shadow-lg: 0 20px 60px rgba(0,0,0,0.15);
            --shadow-xl: 0 30px 80px rgba(0,0,0,0.20);
            --shadow-glow-green: 0 8px 40px rgba(42, 195, 42, 0.25);
            --radius: 20px;
            --radius-lg: 28px;
            --transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        /* ===== BASE ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
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

        /* ===== BACKGROUND ===== */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                url('data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 900"%3E%3Cdefs%3E%3ClinearGradient id="a" x1="0%25" y1="0%25" x2="100%25" y2="100%25"%3E%3Cstop offset="0%25" style="stop-color:%2342c942;stop-opacity:0.3"/%3E%3Cstop offset="100%25" style="stop-color:%23c8781e;stop-opacity:0.3"/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect fill="%23f0f2f5" width="1440" height="900"/%3E%3Ccircle cx="200" cy="200" r="300" fill="rgba(42,195,42,0.08)"/%3E%3Ccircle cx="1200" cy="150" r="400" fill="rgba(200,120,30,0.06)"/%3E%3Ccircle cx="700" cy="700" r="350" fill="rgba(42,195,42,0.05)"/%3E%3Ccircle cx="100" cy="600" r="200" fill="rgba(200,120,30,0.04)"/%3E%3C/svg%3E');
            background-size: cover;
            background-position: center;
            opacity: 0.5;
            z-index: 0;
        }

        body::after {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(42, 195, 42, 0.85), rgba(200, 120, 30, 0.80));
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

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }

        .animate-fade-up {
            animation: fadeInUp 0.8s ease-out forwards;
        }

        .animate-float {
            animation: float 3s ease-in-out infinite;
        }

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
            max-width: 520px;
            width: 100%;
            padding: 40px 35px;
            transition: var(--transition);
        }

        .register-card:hover {
            box-shadow: 0 40px 100px rgba(0,0,0,0.25);
        }

        /* ===== HEADER ===== */
        .register-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .register-header .logo {
            display: inline-block;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            padding: 15px 18px;
            border-radius: 16px;
            color: white;
            font-size: 2rem;
            margin-bottom: 16px;
            box-shadow: var(--shadow-glow-green);
            transition: var(--transition);
        }

        .register-header .logo:hover {
            transform: scale(1.05);
        }

        .register-header h1 {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--dark-text);
            margin-bottom: 6px;
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

        .register-header .admin-badge {
            display: inline-block;
            background: rgba(42, 195, 42, 0.1);
            color: var(--primary-green);
            padding: 6px 18px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-top: 10px;
            border: 1px solid rgba(42, 195, 42, 0.15);
        }

        .register-header .admin-badge i {
            margin-right: 6px;
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

        .alert-custom i {
            font-size: 1.2rem;
            margin-top: 2px;
        }

        .alert-custom-success {
            background: rgba(42, 195, 42, 0.08);
            color: var(--primary-green);
            border-left: 4px solid var(--primary-green);
        }

        .alert-custom-danger {
            background: rgba(231, 76, 60, 0.08);
            color: #e74c3c;
            border-left: 4px solid #e74c3c;
        }

        .alert-custom-info {
            background: rgba(52, 152, 219, 0.08);
            color: #3498db;
            border-left: 4px solid #3498db;
        }

        /* ===== FORMULAIRE ===== */
        .form-modern .form-group {
            margin-bottom: 18px;
        }

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
            box-shadow: 0 0 0 4px rgba(42, 195, 42, 0.1);
            background: var(--white);
        }

        .form-modern .input-group-custom .form-control.is-invalid {
            border-color: #e74c3c;
            box-shadow: 0 0 0 4px rgba(231, 76, 60, 0.1);
        }

        .form-modern .input-group-custom .form-control.is-valid {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 4px rgba(42, 195, 42, 0.1);
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
            background: rgba(42, 195, 42, 0.1);
            color: var(--primary-green);
        }

        .form-modern .form-text .requirements .req-item.invalid {
            background: rgba(231, 76, 60, 0.1);
            color: #e74c3c;
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
            box-shadow: 0 12px 40px rgba(42, 195, 42, 0.4);
            color: white;
        }

        .btn-primary-custom:active {
            transform: scale(0.98);
        }

        .btn-primary-custom:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
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

        /* ===== RESPONSIVE ===== */
        @media (max-width: 576px) {
            .register-card {
                padding: 28px 20px;
            }

            .register-header h1 {
                font-size: 1.4rem;
            }

            .register-header .logo {
                padding: 12px 15px;
                font-size: 1.6rem;
            }

            .form-modern .input-group-custom .form-control {
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

            body {
                padding: 10px;
            }
        }

        @media (max-width: 400px) {
            .register-card {
                padding: 20px 16px;
            }

            .register-header h1 {
                font-size: 1.2rem;
            }

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

        .btn-primary-custom.loading .btn-text {
            display: none;
        }

        .btn-primary-custom.loading .spinner {
            display: inline-block;
        }
    </style>
    <link rel="stylesheet" href="../assets/css/responsive.css">
</head>
<body>

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
            <p>Création du compte administrateur</p>
            <div class="admin-badge">
                <i class="fas fa-crown"></i> Accès réservé à l'administrateur
            </div>
        </div>

        <!-- ===== MESSAGES ===== -->
        <?php if ($admin_exists && empty($_POST)): ?>
            <div class="alert-custom alert-custom-info">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Information :</strong> Un administrateur existe déjà sur la plateforme.<br>
                    <small>Si vous avez perdu vos identifiants, veuillez contacter l'administrateur actuel.</small>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert-custom alert-custom-danger">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <strong>Erreur :</strong><br>
                    <?php echo $error; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-custom alert-custom-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Succès !</strong><br>
                    <?php echo $success; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($admin_exists && empty($_POST) && empty($success)): ?>
            <div class="text-center mb-4">
                <a href="<?php echo BASE_URL; ?>connexion.php" class="btn-primary-custom" style="text-decoration: none;">
                    <i class="fas fa-sign-in-alt"></i> Se connecter
                </a>
            </div>
            <div class="register-footer">
                <p><a href="<?php echo BASE_URL; ?>index.php"><i class="fas fa-arrow-left"></i> Retour à l'accueil</a></p>
            </div>
        <?php elseif (!$admin_exists || !empty($error)): ?>

        <!-- ===== FORMULAIRE ===== -->
        <form method="POST" class="form-modern" id="registerForm" novalidate>
            
            <!-- Nom et Prénom -->
            <div class="row g-3">
                <div class="col-md-6 form-group">
                    <label for="nom" class="form-label">
                        Nom <span class="required">*</span>
                    </label>
                    <div class="input-group-custom">
                        <span class="input-icon"><i class="fas fa-user"></i></span>
                        <input type="text" 
                               class="form-control <?php echo (!empty($error) && empty($form_data['nom'])) ? 'is-invalid' : ''; ?>" 
                               id="nom" 
                               name="nom" 
                               placeholder="Votre nom" 
                               value="<?php echo htmlspecialchars($form_data['nom']); ?>" 
                               required
                               autofocus>
                    </div>
                </div>
                <div class="col-md-6 form-group">
                    <label for="prenom" class="form-label">
                        Prénom <span class="required">*</span>
                    </label>
                    <div class="input-group-custom">
                        <span class="input-icon"><i class="fas fa-user-tie"></i></span>
                        <input type="text" 
                               class="form-control <?php echo (!empty($error) && empty($form_data['prenom'])) ? 'is-invalid' : ''; ?>" 
                               id="prenom" 
                               name="prenom" 
                               placeholder="Votre prénom" 
                               value="<?php echo htmlspecialchars($form_data['prenom']); ?>" 
                               required>
                    </div>
                </div>
            </div>

            <!-- Email -->
            <div class="form-group">
                <label for="email" class="form-label">
                    Adresse email <span class="required">*</span>
                </label>
                <div class="input-group-custom">
                    <span class="input-icon"><i class="fas fa-envelope"></i></span>
                    <input type="email" 
                           class="form-control <?php echo (!empty($error) && empty($form_data['email'])) ? 'is-invalid' : ''; ?>" 
                           id="email" 
                           name="email" 
                           placeholder="exemple@email.com" 
                           value="<?php echo htmlspecialchars($form_data['email']); ?>" 
                           required>
                </div>
                <div class="form-text">
                    <i class="fas fa-info-circle"></i> L'email sera utilisé pour la connexion
                </div>
            </div>

            <!-- Téléphone -->
            <div class="form-group">
                <label for="telephone" class="form-label">
                    Téléphone <span style="color: #6c757d; font-weight: 400;">(optionnel)</span>
                </label>
                <div class="input-group-custom">
                    <span class="input-icon"><i class="fas fa-phone"></i></span>
                    <input type="tel" 
                           class="form-control" 
                           id="telephone" 
                           name="telephone" 
                           placeholder="+229 XX XX XX XX" 
                           value="<?php echo htmlspecialchars($form_data['telephone']); ?>">
                </div>
            </div>

            <!-- Mot de passe -->
            <div class="form-group">
                <label for="password" class="form-label">
                    Mot de passe <span class="required">*</span>
                </label>
                <div class="input-group-custom">
                    <span class="input-icon"><i class="fas fa-lock"></i></span>
                    <input type="password" 
                           class="form-control <?php echo (!empty($error) && empty($form_data['password'])) ? 'is-invalid' : ''; ?>" 
                           id="password" 
                           name="password" 
                           placeholder="Min. 8 caractères" 
                           required>
                    <button type="button" class="toggle-password" id="togglePassword" aria-label="Afficher le mot de passe">
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
                <label for="confirm_password" class="form-label">
                    Confirmer le mot de passe <span class="required">*</span>
                </label>
                <div class="input-group-custom">
                    <span class="input-icon"><i class="fas fa-check-double"></i></span>
                    <input type="password" 
                           class="form-control <?php echo (!empty($error) && $form_data['password'] !== $form_data['confirm_password']) ? 'is-invalid' : ''; ?>" 
                           id="confirm_password" 
                           name="confirm_password" 
                           placeholder="Confirmez votre mot de passe" 
                           required>
                </div>
                <div class="form-text" id="passwordMatch"></div>
            </div>

            <!-- Bouton d'inscription -->
            <button type="submit" class="btn-primary-custom" id="submitBtn">
                <span class="btn-text">
                    <i class="fas fa-user-plus"></i> Créer le compte administrateur
                </span>
                <span class="spinner"></span>
            </button>

        </form>

        <!-- ===== FOOTER ===== -->
        <div class="register-footer">
            <p>
                <i class="fas fa-arrow-left me-1"></i>
                <a href="<?php echo BASE_URL; ?>index.php">Retour à l'accueil</a>
            </p>
            <p class="mt-2" style="font-size: 0.8rem; color: #adb5bd;">
                <i class="fas fa-shield-alt me-1"></i>
                Toutes les données sont sécurisées et chiffrées
            </p>
        </div>

        <?php endif; ?>

    </div>

    <!-- ===== SCRIPTS ===== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            
            // ===== TOGGLE PASSWORD =====
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');
            const confirmPasswordInput = document.getElementById('confirm_password');
            
            if (togglePassword) {
                togglePassword.addEventListener('click', function() {
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    confirmPasswordInput.setAttribute('type', type);
                    this.querySelector('i').classList.toggle('fa-eye');
                    this.querySelector('i').classList.toggle('fa-eye-slash');
                });
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

            function checkPasswordStrength(pwd) {
                let score = 0;
                let checks = {
                    length: pwd.length >= 8,
                    upper: /[A-Z]/.test(pwd),
                    lower: /[a-z]/.test(pwd),
                    number: /[0-9]/.test(pwd)
                };

                // Mettre à jour les indicateurs
                updateRequirement(reqLength, checks.length, '8 caractères');
                updateRequirement(reqUpper, checks.upper, 'Majuscule');
                updateRequirement(reqLower, checks.lower, 'Minuscule');
                updateRequirement(reqNumber, checks.number, 'Chiffre');

                // Calculer le score
                if (checks.length) score++;
                if (checks.upper) score++;
                if (checks.lower) score++;
                if (checks.number) score++;

                // Déterminer la force
                let strength = '';
                let color = '';
                if (pwd.length === 0) {
                    strength = '';
                    color = '';
                    strengthBar.style.width = '0%';
                    strengthLabel.textContent = '';
                } else if (score <= 1) {
                    strength = 'Faible';
                    color = '#e74c3c';
                    strengthBar.className = 'bar weak';
                    strengthLabel.textContent = '⚠️ Mot de passe faible';
                    strengthLabel.style.color = '#e74c3c';
                } else if (score === 2) {
                    strength = 'Moyen';
                    color = '#f39c12';
                    strengthBar.className = 'bar medium';
                    strengthLabel.textContent = '⚠️ Mot de passe moyen';
                    strengthLabel.style.color = '#f39c12';
                } else if (score === 3) {
                    strength = 'Bon';
                    color = '#2ecc71';
                    strengthBar.className = 'bar good';
                    strengthLabel.textContent = '✅ Mot de passe bon';
                    strengthLabel.style.color = '#2ecc71';
                } else if (score === 4) {
                    strength = 'Fort';
                    color = '#27ae60';
                    strengthBar.className = 'bar strong';
                    strengthLabel.textContent = '✅ Mot de passe fort';
                    strengthLabel.style.color = '#27ae60';
                }

                return checks;
            }

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
                    checkPasswordStrength(this.value);
                    checkPasswordMatch();
                });
            }

            // ===== PASSWORD MATCH =====
            function checkPasswordMatch() {
                const pwd = password.value;
                const confirm = confirmPasswordInput.value;
                
                if (confirm.length === 0) {
                    passwordMatch.textContent = '';
                    confirmPasswordInput.classList.remove('is-valid', 'is-invalid');
                    return;
                }
                
                if (pwd === confirm) {
                    passwordMatch.innerHTML = '<i class="fas fa-check-circle" style="color: var(--primary-green);"></i> Les mots de passe correspondent';
                    passwordMatch.style.color = 'var(--primary-green)';
                    confirmPasswordInput.classList.remove('is-invalid');
                    confirmPasswordInput.classList.add('is-valid');
                } else {
                    passwordMatch.innerHTML = '<i class="fas fa-exclamation-circle" style="color: #e74c3c;"></i> Les mots de passe ne correspondent pas';
                    passwordMatch.style.color = '#e74c3c';
                    confirmPasswordInput.classList.remove('is-valid');
                    confirmPasswordInput.classList.add('is-invalid');
                }
            }

            if (confirmPasswordInput) {
                confirmPasswordInput.addEventListener('input', checkPasswordMatch);
            }

            // ===== FORM SUBMISSION =====
            const form = document.getElementById('registerForm');
            const submitBtn = document.getElementById('submitBtn');

            if (form) {
                form.addEventListener('submit', function(e) {
                    // Vérifier que le mot de passe est fort
                    const pwd = password.value;
                    if (pwd.length > 0) {
                        const checks = checkPasswordStrength(pwd);
                        const score = Object.values(checks).filter(v => v === true).length;
                        if (score < 2) {
                            e.preventDefault();
                            alert('Veuillez choisir un mot de passe plus fort (au moins 8 caractères, avec majuscule, minuscule et chiffre).');
                            password.focus();
                            return;
                        }
                    }
                    
                    // Vérifier que les mots de passe correspondent
                    if (password.value !== confirmPasswordInput.value) {
                        e.preventDefault();
                        alert('Les mots de passe ne correspondent pas.');
                        confirmPasswordInput.focus();
                        return;
                    }

                    // Activer le loader
                    submitBtn.classList.add('loading');
                    submitBtn.disabled = true;
                });
            }

            // ===== KEYBOARD SHORTCUTS =====
            document.addEventListener('keydown', function(e) {
                // Alt + R pour réinitialiser
                if (e.altKey && e.key === 'r') {
                    e.preventDefault();
                    if (confirm('Voulez-vous réinitialiser le formulaire ?')) {
                        form.reset();
                        window.location.reload();
                    }
                }
            });

            // ===== CONSOLE LOG =====
            console.log('%c PharmaGeo - Inscription Administrateur ', 'background: linear-gradient(135deg, rgb(42, 195, 42), hsl(25, 75%, 47%)); color: white; font-size: 16px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');
            console.log('%c Cette page est réservée à la création du compte administrateur principal.', 'color: #666; font-size: 13px;');

        });
    </script>
</body>
</html>