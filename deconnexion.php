<?php
// ============================================================
// PharmaGeo — Déconnexion
// Déconnecte l'utilisateur, journalise l'action, détruit la
// session puis affiche une page de confirmation.
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
    $pdo = null;
}

// ===== GESTION DES SESSIONS =====
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== VÉRIFICATION SI L'UTILISATEUR EST CONNECTÉ =====
$is_logged_in = isset($_SESSION['user_id']);

// ===== TRAITEMENT DE LA DÉCONNEXION =====
if (isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
    
    // Journalisation de la déconnexion
    if ($is_logged_in && $pdo !== null) {
        try {
            $log_stmt = $pdo->prepare("
                INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur)
                VALUES ('Déconnexion', :ip, :user_agent, 'succes', :user_id)
            ");
            $log_stmt->execute([
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':user_id' => $_SESSION['user_id']
            ]);
        } catch(PDOException $e) {
            // Ignorer
        }
    }

    // Détruire la session
    $_SESSION = array();
    
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    
    session_destroy();
    
    // Redirection après 2 secondes
    header('Refresh: 2; url=' . BASE_URL . 'index.php');
    $deconnexion_reussie = true;
    
} else {
    $deconnexion_reussie = false;
}

$page_title = 'Déconnexion - PharmaGeo';
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
    
    <style>
        /* ===== VARIABLES & BASE ===== */
        :root {
            --primary-green: rgb(42, 195, 42);
            --primary-orange: hsl(25, 75%, 47%);
            --white: #ffffff;
            --dark-text: #1a1a2e;
            --gray-text: #6c757d;
            --shadow-xl: 0 30px 80px rgba(0,0,0,0.16);
            --shadow-glow-green: 0 8px 40px rgba(42, 195, 42, 0.20);
            --radius-lg: 24px;
            --transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

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

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(40px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-15px); }
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .animate-fade-up { animation: fadeInUp 0.8s ease-out forwards; }
        .animate-float { animation: float 3s ease-in-out infinite; }

        /* ===== CARTE DE DÉCONNEXION ===== */
        .logout-card {
            position: relative;
            z-index: 2;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-xl);
            border: 1px solid rgba(255, 255, 255, 0.2);
            max-width: 500px;
            width: 100%;
            padding: 40px 35px;
            text-align: center;
            transition: var(--transition);
        }

        .logout-card:hover {
            box-shadow: 0 40px 100px rgba(0,0,0,0.25);
        }

        .logout-icon {
            display: inline-block;
            background: rgba(231, 76, 60, 0.1);
            padding: 20px;
            border-radius: 50%;
            font-size: 3rem;
            color: #e74c3c;
            margin-bottom: 16px;
        }

        .logout-card h2 {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--dark-text);
            margin-bottom: 8px;
        }

        .logout-card h2 .highlight {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .logout-card p {
            color: var(--gray-text);
            font-size: 0.95rem;
            margin-bottom: 24px;
        }

        /* ===== BOUTONS D'ACTION ===== */
        .btn-group-custom {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white;
            border: none;
            padding: 12px 32px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 0.95rem;
            transition: var(--transition);
            box-shadow: var(--shadow-glow-green);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-primary-custom:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 40px rgba(42,195,42,0.4);
            color: white;
        }

        .btn-danger-custom {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 12px 32px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 0.95rem;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-danger-custom:hover {
            background: #c0392b;
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(231,76,60,0.3);
            color: white;
        }

        .btn-outline-custom {
            border: 2px solid var(--gray-text);
            color: var(--gray-text);
            background: transparent;
            padding: 10px 28px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.95rem;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-outline-custom:hover {
            border-color: var(--primary-green);
            color: var(--primary-green);
            transform: translateY(-3px);
        }

        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-right: 8px;
        }

        /* ===== FORMES FLOTTANTES EN ARRIÈRE-PLAN ===== */
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
            .logout-card { padding: 28px 20px; }
            .logout-card h2 { font-size: 1.4rem; }
            .btn-group-custom { flex-direction: column; align-items: stretch; }
            .btn-primary-custom, .btn-danger-custom, .btn-outline-custom { 
                justify-content: center; 
                padding: 12px 20px;
            }
        }
    </style>
    <link rel="stylesheet" href="assets/css/responsive.css">
</head>
<body>

    <!-- ===== FLOATING SHAPES ===== -->
    <div class="floating-shapes">
        <div class="shape"></div>
        <div class="shape"></div>
        <div class="shape"></div>
    </div>

    <!-- ===== CARD ===== -->
    <div class="logout-card animate-fade-up">
        
        <?php if ($deconnexion_reussie): ?>
            <!-- ===== DÉCONNEXION RÉUSSIE ===== -->
            <div class="logout-icon" style="background: rgba(42,195,42,0.1); color: var(--primary-green);">
                <i class="fas fa-check-circle"></i>
            </div>
            <h2>Déconnexion <span class="highlight">réussie</span></h2>
            <p>Vous avez été déconnecté avec succès. Vous allez être redirigé vers la page d'accueil.</p>
            <div class="spinner" style="margin: 0 auto 16px; display: block;"></div>
            <a href="<?php echo BASE_URL; ?>" class="btn-primary-custom">
                <i class="fas fa-home"></i> Retour à l'accueil
            </a>

        <?php elseif ($is_logged_in): ?>
            <!-- ===== CONFIRMATION DE DÉCONNEXION ===== -->
            <div class="logout-icon">
                <i class="fas fa-sign-out-alt"></i>
            </div>
            <h2>Déconnexion</h2>
            <p>Êtes-vous sûr de vouloir vous déconnecter de votre compte ?</p>
            <div class="btn-group-custom">
                <a href="<?php echo BASE_URL; ?>deconnexion.php?confirm=yes" class="btn-danger-custom">
                    <i class="fas fa-sign-out-alt"></i> Oui, me déconnecter
                </a>
                <a href="javascript:history.back()" class="btn-outline-custom">
                    <i class="fas fa-arrow-left"></i> Annuler
                </a>
            </div>

        <?php else: ?>
            <!-- ===== NON CONNECTÉ ===== -->
            <div class="logout-icon" style="background: rgba(52,152,219,0.1); color: #3498db;">
                <i class="fas fa-info-circle"></i>
            </div>
            <h2>Déjà <span class="highlight">déconnecté</span></h2>
            <p>Vous n'êtes pas connecté à votre compte.</p>
            <div class="btn-group-custom">
                <a href="<?php echo BASE_URL; ?>connexion.php" class="btn-primary-custom">
                    <i class="fas fa-sign-in-alt"></i> Se connecter
                </a>
                <a href="<?php echo BASE_URL; ?>" class="btn-outline-custom">
                    <i class="fas fa-home"></i> Accueil
                </a>
            </div>
        <?php endif; ?>

    </div>

    <!-- ===== SCRIPTS ===== -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {

            <?php if ($deconnexion_reussie): ?>
            // Compteur de redirection
            let seconds = 3;
            const counter = setInterval(function() {
                seconds--;
                if (seconds <= 0) {
                    clearInterval(counter);
                    window.location.href = '<?php echo BASE_URL; ?>';
                }
            }, 1000);
            <?php endif; ?>

            // ===== CONSOLE LOG =====
            console.log('%c PharmaGeo - Déconnexion ', 'background: linear-gradient(135deg, rgb(42, 195, 42), hsl(25, 75%, 47%)); color: white; font-size: 16px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');
            console.log('%c À bientôt sur PharmaGeo !', 'color: #666; font-size: 14px;');

        });
    </script>

</body>
</html>