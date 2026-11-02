<?php
// ============================================================
// PharmaGeo — Mot de passe oublié
// Permet à un utilisateur de demander la réinitialisation de
// son mot de passe. Un jeton sécurisé (valable 1 heure) est
// généré : un email est envoyé via SMTP (PHPMailer). Si l'envoi
// est désactivé (MAIL_ENABLE = false), le lien de
// réinitialisation est affiché à l'écran (mode démo / local).
// ============================================================
$page_title = 'Mot de passe oublié - PharmaGeo';
require_once 'includes/config.php';
require_once 'includes/session.php';
require_once 'includes/mailer.php';

// ===== VARIABLES =====
$error = '';
$info = '';
$success = '';
$success_link = '';
$emailValue = '';

// ===== TRAITEMENT DU FORMULAIRE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailValue = cleanInput($_POST['email'] ?? '');

    if (!filter_var($emailValue, FILTER_VALIDATE_EMAIL)) {
        $error = 'Veuillez saisir une adresse email valide.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT Id_utilisateur, Nom, Prenom, Email FROM utilisateur WHERE Email = :email AND Statut = 'actif'");
            $stmt->execute([':email' => $emailValue]);
            $user = $stmt->fetch();

            if (!$user) {
                // Message générique : ne pas révéler l'existence du compte
                $info = 'Si un compte existe avec cette adresse email, un lien de réinitialisation a été envoyé.';
            } else {
                // Génération d'un jeton sécurisé et valable 1 heure
                $token = bin2hex(random_bytes(32));
                $expiration = date('Y-m-d H:i:s', time() + 3600);
                $tokenHash = hash('sha256', $token);

                $pdo->prepare("
                    INSERT INTO password_reset (Token, Expiration, Utilise, Id_utilisateur)
                    VALUES (:token, :expiration, 0, :user)
                    ON DUPLICATE KEY UPDATE
                        Token = VALUES(Token),
                        Expiration = VALUES(Expiration),
                        Utilise = 0
                ")->execute([':token' => $tokenHash, ':expiration' => $expiration, ':user' => $user['Id_utilisateur']]);

                $link = BASE_URL . 'reinitialiser-mot-de-passe.php?token=' . $token;

                // Envoi de l'email de réinitialisation via SMTP (PHPMailer)
                $subject = 'Réinitialisation de votre mot de passe - PharmaGeo';
                $message = "Bonjour " . $user['Prenom'] . " " . $user['Nom'] . ",\n\n"
                         . "Vous avez demandé la réinitialisation de votre mot de passe.\n\n"
                         . "Cliquez sur le lien ci-dessous pour définir un nouveau mot de passe (valable 1 heure) :\n"
                         . $link . "\n\n"
                         . "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message.\n\n"
                         . "— L'équipe PharmaGeo";

                $sent = sendMail($user['Email'], $subject, $message);

                if ($sent) {
                    $success = 'Un email de réinitialisation vient d\'être envoyé à <strong>' . htmlspecialchars($user['Email']) . '</strong>. Consultez votre boîte mail.';
                } else {
                    // SMTP désactivé (mode démo) ou échec d'envoi : afficher le lien à l'écran
                    $success = 'Environnement de démonstration (SMTP désactivé ou erreur d\'envoi) : voici directement votre lien de réinitialisation <strong>(valable 1 heure)</strong> :';
                    $success_link = $link;
                }
            }
        } catch (PDOException $e) {
            $error = 'Erreur lors du traitement de la demande. Veuillez réessayer.';
        }
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
    <meta name="theme-color" content="#16A34A">

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
            --primary-green: #16A34A;
            --primary-green-dark: #166534;
            --primary-orange: #B91C1C;
            --primary-orange-dark: #B91C1C;
            --dark-text: #0F2E19;
            --gray-text: #64748b;
            --radius: 20px;
            --radius-sm: 12px;
            --shadow-lg: 0 20px 60px rgba(10,22,40,0.12);
            --shadow-glow: 0 10px 34px rgba(22,163,74,0.28);
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
            background: #F0FDF4;
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
            background: linear-gradient(160deg, rgba(6,14,26,0.92) 0%, rgba(10,30,20,0.80) 45%, rgba(22,163,74,0.55) 100%);
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
         color: #fff;}
        .auth-brand span {
            font-size: 1.4rem;
            font-weight: 800;
            letter-spacing: -0.5px;
        
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            color: var(--primary-green);
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
            background: #F0FDF4;
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
        
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            color: var(--primary-green);
        }
        

        .auth-card-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .auth-card-header i.fa-key {
            font-size: 2.4rem;
            background: linear-gradient(135deg, var(--primary-orange), var(--primary-green));
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
            border-left: 4px solid #DC2626;
            border-radius: var(--radius-sm);
            font-size: 0.92rem;
        }
        .alert-reset-success {
            border: none;
            border-left: 4px solid #16A34A;
            border-radius: var(--radius-sm);
            font-size: 0.92rem;
        }
        .alert-reset-info {
            border: none;
            border-left: 4px solid #16A34A;
            border-radius: var(--radius-sm);
            font-size: 0.92rem;
        }
        .reset-link-box {
            background: #F0FDF4;
            border: 1.5px dashed #D9E9DF;
            border-radius: var(--radius-sm);
            padding: 12px 14px;
            font-size: 0.82rem;
            word-break: break-all;
            margin-top: 10px;
            user-select: all;
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
            box-shadow: 0 0 0 4px rgba(22,163,74,0.15);
            transform: translateY(-1px);
        }
        .form-modern .input-group-text {
            background: #F0FDF4;
            border: 1.5px solid #D9E9DF;
            border-right: none;
            color: var(--gray-text);
            width: 46px;
            justify-content: center;
        }
        .form-modern .form-control {
            border: 1.5px solid #D9E9DF;
            border-left: none;
            padding: 12px 14px;
            font-size: 0.96rem;
        }
        .form-modern .toggle-password-group .form-control { border-right: none; }
        .form-modern .toggle-password {
            background: #fff;
            border: 1.5px solid #D9E9DF;
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

        .btn-login-outline {
            background: #fff;
            border: 1.5px solid #D9E9DF;
            color: var(--dark-text);
            font-weight: 600;
            border-radius: var(--radius-sm);
            transition: border-color 0.3s var(--ease), color 0.3s var(--ease);
        }
        .btn-login-outline:hover {
            border-color: var(--primary-green);
            color: var(--primary-green-dark);
        }

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
    <link rel="stylesheet" href="assets/css/responsive.css">    <!-- Palette verte PharmaGeo -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/palette.css">
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

            <a href="<?php echo BASE_URL; ?>index.php" class="auth-brand" style="text-decoration: none;">
                <div class="logo-badge"><i class="fas fa-hospital"></i></div>
                <span>PharmaGeo</span>
            </a>

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
                <a href="<?php echo BASE_URL; ?>index.php" class="auth-card-mobile-brand" style="text-decoration: none;">
                    <div class="logo-badge"><i class="fas fa-hospital"></i></div>
                    <span>PharmaGeo</span>
                </a>

                <div class="auth-card-header">
                    <i class="fas fa-key d-none d-lg-inline-block"></i>
                    <h2>Mot de passe oublié</h2>
                    <p>Saisissez votre email pour recevoir un lien de réinitialisation</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-shake" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <?php if ($info): ?>
                    <div class="alert alert-primary alert-reset-info" role="alert">
                        <i class="fas fa-info-circle me-2"></i><?php echo $info; ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-reset-success" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                        <?php if ($success_link): ?>
                            <div class="reset-link-box"><?php echo htmlspecialchars($success_link); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($success && !$error): ?>
                    <div class="text-center mt-3 auth-links">
                        <a href="connexion.php" class="btn-login-outline btn w-100 py-2">
                            <i class="fas fa-sign-in-alt me-2"></i>Aller à la connexion
                        </a>
                    </div>
                <?php else: ?>
                    <form method="POST" class="form-modern" id="forgotForm" novalidate>
                        <div class="mb-3">
                            <label for="email" class="form-label">Adresse email</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                <input type="email" class="form-control" id="email" name="email"
                                       placeholder="exemple@email.com" value="<?php echo htmlspecialchars($emailValue); ?>"
                                       autocomplete="email" required>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-login w-100 py-2" id="forgotBtn">
                            <span class="btn-label"><i class="fas fa-paper-plane me-2"></i>Envoyer le lien de réinitialisation</span>
                        </button>
                    </form>

                    <div class="text-center mt-4 auth-links">
                        <p class="text-muted small mb-0">
                            <a href="connexion.php"><i class="fas fa-arrow-left me-1"></i>Retour à la connexion</a>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Etat de chargement fluide au submit
        const forgotForm = document.getElementById('forgotForm');
        const forgotBtn = document.getElementById('forgotBtn');
        if (forgotForm && forgotBtn) {
            forgotForm.addEventListener('submit', () => {
                forgotBtn.disabled = true;
                forgotBtn.querySelector('.btn-label').innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Envoi en cours...';
            });
        }
    </script>
    <script>window.BASE_URL = <?php echo json_encode(BASE_URL); ?>;</script>
    <script src="assets/js/app.js"></script>
</body>
</html>