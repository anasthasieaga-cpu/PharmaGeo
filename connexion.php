<?php
// ============================================================
// PharmaGeo — Connexion
// Permet à un utilisateur de se connecter à son compte et de
// rediriger selon son rôle (administrateur, pharmacien, patient).
// ============================================================
$page_title = 'Connexion - PharmaGeo';
require_once 'includes/config.php';
require_once 'includes/session.php';

// ===== VARIABLES =====
$error = '';

// ===== TRAITEMENT DU FORMULAIRE DE CONNEXION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = cleanInput($_POST['email']);
    $password = $_POST['password'];
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM utilisateur WHERE Email = :email AND Statut = 'actif'");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['Mot_de_passe'])) {
            // Connexion réussie
            $_SESSION['user_id'] = $user['Id_utilisateur'];
            $_SESSION['nom'] = $user['Nom'];
            $_SESSION['prenom'] = $user['Prenom'];
            $_SESSION['role'] = $user['Role'];
            $_SESSION['email'] = $user['Email'];
            
            // Journalisation
            $logStmt = $pdo->prepare("INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur) 
                                      VALUES ('Connexion', :ip, :user_agent, 'succes', :user)");
            $logStmt->execute([
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':user' => $user['Id_utilisateur']
            ]);
            
            // Redirection selon le rôle (patient : retour à la page demandée si une
            // redirection sécurisée a été transmise via ?redirect=, sinon index.php)
            switch ($user['Role']) {
                case 'administrateur':
                    header('Location: admin/dashboard.php');
                    break;
                case 'pharmacien':
                    header('Location: pharmacien/dashboard.php');
                    break;
                default:
                    if (!empty($_REQUEST['redirect']) && stripos($_REQUEST['redirect'], BASE_URL) === 0) {
                        header('Location: ' . $_REQUEST['redirect']);
                    } else {
                        header('Location: index.php');
                    }
            }
            exit();
        } else {
            $error = 'Email ou mot de passe incorrect.';
            
            // Journalisation de l'échec
            try {
                $idLog = null;
                if ($user) {
                    $idLog = $user['Id_utilisateur'];
                } elseif ($email !== '') {
                    $stmtUser = $pdo->prepare("SELECT Id_utilisateur FROM utilisateur WHERE Email = :email");
                    $stmtUser->execute([':email' => $email]);
                    $found = $stmtUser->fetch();
                    if ($found) {
                        $idLog = $found['Id_utilisateur'];
                    }
                }
                if ($idLog !== null) {
                    $logStmt = $pdo->prepare("INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur) 
                                              VALUES ('Tentative de connexion échouée', :ip, :user_agent, 'echec', :user)");
                    $logStmt->execute([
                        ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                        ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                        ':user' => $idLog
                    ]);
                }
            } catch (PDOException $e) {
                // La journalisation ne doit pas bloquer la connexion
            }
        }
    } catch(PDOException $e) {
        $error = 'Erreur lors de la connexion.';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<!-- ===== EN-TÊTE DE PAGE (META ET RESSOURCES) ===== -->
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#2ac32a">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://images.unsplash.com" crossorigin>
    <link rel="preload" as="image" href="https://images.unsplash.com/photo-1696861286643-341a8d7a79e9?q=80&amp;w=1400&amp;auto=format&amp;fit=crop">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&amp;display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        /* ===== VARIABLES & BASE ===== */
        :root {
            --primary-green: #2ac32a;
            --primary-green-dark: #1e8e1e;
            --primary-orange: #d4880f;
            --primary-orange-dark: #b3740d;
            --dark-text: #0a1628;
            --gray-text: #64748b;
            --radius: 20px;
            --radius-sm: 12px;
            --shadow-lg: 0 20px 60px rgba(10,22,40,0.12);
            --shadow-glow: 0 10px 34px rgba(42,195,42,0.28);
            --ease: cubic-bezier(0.23,1,0.32,1);
        }

        * { box-sizing: border-box; }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
                scroll-behavior: auto !important;
            }
        }

        html, body {
            height: 100%;
        }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f4f7f6;
            overflow-x: hidden;
        }

        a:focus-visible, button:focus-visible, input:focus-visible {
            outline: 3px solid var(--primary-green);
            outline-offset: 2px;
        }

        /* ============================================================
           LAYOUT SPLIT ECRAN
           ============================================================ */
        .auth-shell {
            min-height: 100vh;
            display: flex;
        }

        /* --- Panneau gauche : images en arrière-plan qui défilent --- */
        .auth-visual {
            position: relative;
            flex: 1 1 50%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
            padding: 56px 60px;
            color: #fff;
            isolation: isolate;
        }
        .auth-visual .bg-slide {
            position: absolute;
            inset: 0;
            background-size: cover;
            background-position: center;
            opacity: 0;
            transform: scale(1.12);
            animation: slideShow 21s infinite;
            z-index: -2;
        }
        .auth-visual .bg-slide:nth-child(1) {
            background-image: url('https://images.unsplash.com/photo-1696861286643-341a8d7a79e9?q=80&w=1400&auto=format&fit=crop');
            animation-delay: 0s;
        }
        .auth-visual .bg-slide:nth-child(2) {
            background-image: url('https://images.unsplash.com/photo-1577401132921-cb39bb0adcff?q=80&w=1400&auto=format&fit=crop');
            animation-delay: 7s;
        }
        .auth-visual .bg-slide:nth-child(3) {
            background-image: url('https://images.unsplash.com/photo-1576091160550-2173dba999ef?q=80&w=1400&auto=format&fit=crop');
            animation-delay: 14s;
        }
        @keyframes slideShow {
            0%      { opacity: 0; transform: scale(1.12); }
            4%      { opacity: 1; transform: scale(1.12); }
            28%     { opacity: 1; transform: scale(1); }
            33%     { opacity: 0; transform: scale(1); }
            100%    { opacity: 0; transform: scale(1); }
        }
        @media (prefers-reduced-motion: reduce) {
            .auth-visual .bg-slide { animation: none; opacity: 0; }
            .auth-visual .bg-slide:nth-child(1) { opacity: 1; transform: scale(1); }
        }
        .auth-visual::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(160deg, rgba(6,14,26,0.92) 0%, rgba(10,30,20,0.80) 45%, rgba(42,195,42,0.55) 100%);
            z-index: -1;
        }
        .auth-visual::after {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                radial-gradient(circle at 15% 20%, rgba(255,255,255,0.08) 0%, transparent 45%),
                radial-gradient(circle at 85% 85%, rgba(255,255,255,0.06) 0%, transparent 50%);
            z-index: -1;
            pointer-events: none;
        }

        .auth-brand {
            display: flex;
            align-items: center;
            gap: 14px;
            animation: fadeDown 0.9s var(--ease) both;
        }
        .auth-brand .logo-badge {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            box-shadow: var(--shadow-glow);
        }
        .auth-brand span {
            font-size: 1.4rem;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .auth-visual-content {
            max-width: 460px;
            animation: fadeUp 1s 0.15s var(--ease) both;
        }
        .auth-visual-content h1 {
            font-size: clamp(1.9rem, 2.6vw + 1rem, 2.7rem);
            font-weight: 800;
            line-height: 1.15;
            letter-spacing: -1px;
            margin-bottom: 18px;
        }
        .auth-visual-content p {
            font-size: 1.05rem;
            opacity: 0.88;
            margin-bottom: 32px;
        }
        .auth-features {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .auth-features li {
            display: flex;
            align-items: center;
            gap: 14px;
            font-size: 0.98rem;
            opacity: 0.95;
        }
        .auth-features .feat-icon {
            flex-shrink: 0;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(255,255,255,0.14);
            backdrop-filter: blur(6px);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.4s var(--ease), background 0.4s var(--ease);
        }
        .auth-features li:hover .feat-icon {
            background: rgba(255,255,255,0.24);
            transform: translateY(-2px) scale(1.05);
        }

        .auth-slide-dots {
            display: flex;
            gap: 8px;
            animation: fadeUp 1s 0.3s var(--ease) both;
        }
        .auth-slide-dots span {
            width: 26px;
            height: 4px;
            border-radius: 4px;
            background: rgba(255,255,255,0.25);
            overflow: hidden;
            position: relative;
        }
        .auth-slide-dots span::after {
            content: '';
            position: absolute;
            inset: 0;
            background: #fff;
            transform: translateX(-100%);
            animation: dotFill 21s infinite;
        }
        .auth-slide-dots span:nth-child(1)::after { animation-delay: 0s; }
        .auth-slide-dots span:nth-child(2)::after { animation-delay: 7s; }
        .auth-slide-dots span:nth-child(3)::after { animation-delay: 14s; }
        @keyframes dotFill {
            0%   { transform: translateX(-100%); }
            2%   { transform: translateX(-100%); }
            5%   { transform: translateX(0); }
            33%  { transform: translateX(0); }
            34%  { transform: translateX(0); }
            100% { transform: translateX(0); }
        }

        /* --- Panneau droit : formulaire --- */
        .auth-form-panel {
            flex: 1 1 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 24px;
            background: #f4f7f6;
            position: relative;
        }
        .auth-card {
            width: 100%;
            max-width: 420px;
            background: #ffffff;
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            padding: 44px 40px;
            animation: fadeUp 0.8s var(--ease) both;
        }
        .auth-card-mobile-brand {
            display: none;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 22px;
        }
        .auth-card-mobile-brand .logo-badge {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.1rem;
        }
        .auth-card-mobile-brand span {
            font-weight: 800;
            font-size: 1.2rem;
            color: var(--dark-text);
        }

        .auth-card-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .auth-card-header i.fa-hospital {
            font-size: 2.6rem;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .auth-card-header h2 {
            margin-top: 10px;
            font-weight: 800;
            font-size: 1.6rem;
            color: var(--dark-text);
            letter-spacing: -0.5px;
        }
        .auth-card-header p {
            color: var(--gray-text);
            margin-bottom: 0;
            font-size: 0.95rem;
        }

        .alert-shake {
            animation: shake 0.5s var(--ease);
            border: none;
            border-left: 4px solid #dc3545;
            border-radius: var(--radius-sm);
            font-size: 0.92rem;
        }

        .form-modern .form-label {
            font-weight: 600;
            font-size: 0.88rem;
            color: var(--dark-text);
            margin-bottom: 6px;
        }
        .form-modern .input-group {
            border-radius: var(--radius-sm);
            overflow: hidden;
            transition: box-shadow 0.35s var(--ease), transform 0.35s var(--ease);
        }
        .form-modern .input-group:focus-within {
            box-shadow: 0 0 0 4px rgba(42,195,42,0.15);
            transform: translateY(-1px);
        }
        .form-modern .input-group-text {
            background: #f4f7f6;
            border: 1.5px solid #e6ebe9;
            border-right: none;
            color: var(--gray-text);
            width: 46px;
            justify-content: center;
        }
        .form-modern .form-control {
            border: 1.5px solid #e6ebe9;
            border-left: none;
            padding: 12px 14px;
            font-size: 0.96rem;
        }
        .form-modern .toggle-password-group .form-control { border-right: none; }
        .form-modern .toggle-password {
            background: #fff;
            border: 1.5px solid #e6ebe9;
            border-left: none;
            color: var(--gray-text);
            cursor: pointer;
            transition: color 0.25s var(--ease);
        }
        .form-modern .toggle-password:hover { color: var(--primary-green); }
        .form-modern .form-control:focus,
        .form-modern .input-group:focus-within .input-group-text,
        .form-modern .input-group:focus-within .toggle-password {
            border-color: var(--primary-green);
            box-shadow: none;
        }

        .btn-login {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-green-dark));
            border: none;
            color: #fff;
            font-weight: 700;
            border-radius: var(--radius-sm);
            padding: 13px;
            letter-spacing: 0.2px;
            transition: transform 0.35s var(--ease), box-shadow 0.35s var(--ease), background 0.35s var(--ease);
            position: relative;
            overflow: hidden;
        }
        .btn-login:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: var(--shadow-glow);
            background: linear-gradient(135deg, var(--primary-green-dark), var(--primary-green));
        }
        .btn-login:active:not(:disabled) { transform: translateY(0); }
        .btn-login:disabled { opacity: 0.8; cursor: progress; }

        .auth-links a {
            color: var(--primary-green);
            font-weight: 600;
            text-decoration: none;
            transition: color 0.25s var(--ease);
        }
        .auth-links a:hover { color: var(--primary-green-dark); text-decoration: underline; }
        .auth-links .forgot-link { color: var(--primary-orange); }
        .auth-links .forgot-link:hover { color: var(--primary-orange-dark); }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(24px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeDown {
            from { opacity: 0; transform: translateY(-16px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes shake {
            10%, 90% { transform: translateX(-1px); }
            20%, 80% { transform: translateX(2px); }
            30%, 50%, 70% { transform: translateX(-4px); }
            40%, 60% { transform: translateX(4px); }
        }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 991px) {
            .auth-visual { display: none; }
            .auth-form-panel { flex: 1 1 100%; padding: 32px 20px; }
            .auth-card-mobile-brand { display: flex; }
        }
        @media (max-width: 480px) {
            .auth-card { padding: 32px 24px; border-radius: 16px; }
        }
    </style>
    <link rel="stylesheet" href="assets/css/responsive.css">
</head>
<body>
    <div class="auth-shell">
        <!-- ============================================================
        PANNEAU VISUEL - IMAGES EN ARRIERE-PLAN QUI DEFILENT
        ============================================================ -->
        <div class="auth-visual" aria-hidden="true">
            <div class="bg-slide"></div>
            <div class="bg-slide"></div>
            <div class="bg-slide"></div>

            <div class="auth-brand">
                <div class="logo-badge"><i class="fas fa-hospital"></i></div>
                <span>PharmaGeo</span>
            </div>

            <div class="auth-visual-content">
                <h1>Votre santé, connectée en un instant</h1>
                <p>Accédez à votre espace pour géolocaliser les pharmacies, suivre vos réservations et gérer vos médicaments en toute simplicité.</p>
                <ul class="auth-features">
                    <li><span class="feat-icon"><i class="fas fa-map-marker-alt"></i></span> Pharmacies proches en temps réel</li>
                    <li><span class="feat-icon"><i class="fas fa-pills"></i></span> Disponibilité des médicaments à jour</li>
                    <li><span class="feat-icon"><i class="fas fa-shield-alt"></i></span> Données personnelles sécurisées</li>
                </ul>
            </div>

            <div class="auth-slide-dots">
                <span></span><span></span><span></span>
            </div>
        </div>

        <!-- ============================================================
        PANNEAU FORMULAIRE
        ============================================================ -->
        <div class="auth-form-panel">
            <div class="auth-card">
                <div class="auth-card-mobile-brand">
                    <div class="logo-badge"><i class="fas fa-hospital"></i></div>
                    <span>PharmaGeo</span>
                </div>

                <div class="auth-card-header">
                    <i class="fas fa-hospital d-none d-lg-inline-block"></i>
                    <h2>Connexion</h2>
                    <p>Connectez-vous à votre compte</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-shake" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="form-modern" id="loginForm" novalidate>
                    <!-- Conserver la redirection demandée (ex: retour à la réservation après connexion) -->
                    <?php if (!empty($_GET['redirect']) && stripos($_GET['redirect'], BASE_URL) === 0): ?>
                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_GET['redirect']); ?>">
                    <?php endif; ?>
                    <div class="mb-3">
                        <label for="email" class="form-label">Adresse email</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                            <input type="email" class="form-control" id="email" name="email"
                                   placeholder="exemple@email.com" autocomplete="email" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Mot de passe</label>
                        <div class="input-group toggle-password-group">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                            <input type="password" class="form-control" id="password" name="password"
                                   placeholder="Votre mot de passe" autocomplete="current-password" required>
                            <button class="input-group-text toggle-password" type="button" id="togglePassword" aria-label="Afficher le mot de passe">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-login w-100 py-2" id="loginBtn">
                        <span class="btn-label"><i class="fas fa-sign-in-alt me-2"></i>Se connecter</span>
                    </button>
                </form>

                <div class="text-center mt-4 auth-links">
                    <p class="text-muted mb-2">
                        Pas encore de compte ?
                        <a href="inscription.php">Inscrivez-vous</a>
                    </p>
                    <p class="text-muted small mb-0">
                        <a href="motdepasse-oublie.php" class="forgot-link">Mot de passe oublié ?</a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Afficher / masquer le mot de passe
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        if (togglePassword && passwordInput) {
            togglePassword.addEventListener('click', () => {
                const isHidden = passwordInput.type === 'password';
                passwordInput.type = isHidden ? 'text' : 'password';
                togglePassword.querySelector('i').className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
                togglePassword.setAttribute('aria-label', isHidden ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
            });
        }

        // Etat de chargement fluide au submit
        const loginForm = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginBtn');
        if (loginForm && loginBtn) {
            loginForm.addEventListener('submit', () => {
                loginBtn.disabled = true;
                loginBtn.querySelector('.btn-label').innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Connexion...';
            });
        }
    </script>
    <script>window.BASE_URL = <?php echo json_encode(BASE_URL); ?>;</script>
    <script src="assets/js/app.js"></script>
</body>
</html>