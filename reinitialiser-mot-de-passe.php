<?php
// ============================================================
// PharmaGeo — Réinitialisation du mot de passe
// Vérifie le jeton reçu par email (valable 1 heure, à usage
// unique) puis permet de définir un nouveau mot de passe.
// ============================================================
$page_title = 'Réinitialiser le mot de passe - PharmaGeo';
require_once 'includes/config.php';
require_once 'includes/session.php';

// ===== VARIABLES =====
$error = '';
$success = '';
$token = cleanInput($_GET['token'] ?? ($_POST['token'] ?? ''));

// ===== TRAITEMENT DE LA SAISIE DU NOUVEAU MOT DE PASSE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token !== '') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if (strlen($password) < 8) {
        $error = 'Le mot de passe doit contenir au moins 8 caractères.';
    } elseif ($password !== $confirm) {
        $error = 'Les deux mots de passe ne correspondent pas.';
    } else {
        try {
            $tokenHash = hash('sha256', $token);
            $stmt = $pdo->prepare("
                SELECT pr.Id_utilisateur, pr.Expiration, u.Statut
                FROM password_reset pr
                JOIN utilisateur u ON pr.Id_utilisateur = u.Id_utilisateur
                WHERE pr.Token = :token AND pr.Utilise = 0 AND pr.Expiration > NOW()
            ");
            $stmt->execute([':token' => $tokenHash]);
            $reset = $stmt->fetch();

            if (!$reset || $reset['Statut'] !== 'actif') {
                $error = 'Ce lien de réinitialisation est invalide ou a expiré. Veuillez refaire une demande.';
                $token = '';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE utilisateur SET Mot_de_passe = :hp WHERE Id_utilisateur = :id")
                    ->execute([':hp' => $hash, ':id' => $reset['Id_utilisateur']]);
                // Le jeton est consommé (usage unique)
                $pdo->prepare("UPDATE password_reset SET Utilise = 1 WHERE Token = :token")
                    ->execute([':token' => $tokenHash]);

                $success = 'Votre mot de passe a été réinitialisé avec succès.';
                $token = '';
            }
        } catch (PDOException $e) {
            $error = 'Erreur lors de la réinitialisation. Veuillez réessayer.';
        }
    }
}

// ===== VALIDATION INITIALE DU JETON (affichage du formulaire) =====
$tokenValid = false;
if ($token !== '' && $success === '' && $error === '') {
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM password_reset pr
            JOIN utilisateur u ON pr.Id_utilisateur = u.Id_utilisateur
            WHERE pr.Token = :token AND pr.Utilise = 0 AND pr.Expiration > NOW() AND u.Statut = 'actif'
        ");
        $stmt->execute([':token' => hash('sha256', $token)]);
        $tokenValid = (bool) $stmt->fetch();
    } catch (PDOException $e) {
        $tokenValid = false;
    }
    if (!$tokenValid) {
        $token = '';
        $error = 'Ce lien de réinitialisation est invalide ou a expiré. Veuillez refaire une demande.';
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
        .auth-card-header i.fa-lock {
            font-size: 2.4rem;
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
        .alert-reset-success {
            border: none;
            border-left: 4px solid #2ac32a;
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

        .btn-login-outline {
            background: #fff;
            border: 1.5px solid #e6ebe9;
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
                    <i class="fas fa-lock d-none d-lg-inline-block"></i>
                    <h2>Réinitialisation</h2>
                    <p>Définissez un nouveau mot de passe</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-shake" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-reset-success" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                    </div>
                    <div class="text-center mt-3 auth-links">
                        <a href="connexion.php" class="btn-login-outline btn w-100 py-2">
                            <i class="fas fa-sign-in-alt me-2"></i>Aller à la connexion
                        </a>
                    </div>
                <?php else: ?>
                    <?php if ($token !== ''): ?>
                        <form method="POST" class="form-modern" id="resetForm" novalidate>
                            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                            <div class="mb-3">
                                <label for="password" class="form-label">Nouveau mot de passe</label>
                                <div class="input-group toggle-password-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" class="form-control" id="password" name="password"
                                           placeholder="8 caractères minimum" minlength="8" autocomplete="new-password" required>
                                    <button class="input-group-text toggle-password" type="button" data-for="password" aria-label="Afficher le mot de passe">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text"><i class="fas fa-circle-info me-1"></i>Minimum 8 caractères.</div>
                            </div>

                            <div class="mb-3">
                                <label for="confirm" class="form-label">Confirmer le mot de passe</label>
                                <div class="input-group toggle-password-group">
                                    <span class="input-group-text"><i class="fas fa-check"></i></span>
                                    <input type="password" class="form-control" id="confirm" name="confirm"
                                           placeholder="Répétez le mot de passe" minlength="8" autocomplete="new-password" required>
                                    <button class="input-group-text toggle-password" type="button" data-for="confirm" aria-label="Afficher le mot de passe">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-login w-100 py-2" id="resetBtn">
                                <span class="btn-label"><i class="fas fa-check-double me-2"></i>Réinitialiser le mot de passe</span>
                            </button>
                        </form>

                        <div class="text-center mt-4 auth-links">
                            <p class="text-muted small mb-0">
                                <a href="connexion.php"><i class="fas fa-arrow-left me-1"></i>Retour à la connexion</a>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="text-center mt-3 auth-links">
                            <a href="motdepasse-oublie.php" class="btn-login-outline btn w-100 py-2">
                                <i class="fas fa-redo me-2"></i>Refaire une demande de lien
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Afficher / masquer les mots de passe
        document.querySelectorAll('.toggle-password').forEach((btn) => {
            btn.addEventListener('click', () => {
                const input = document.getElementById(btn.dataset.for);
                if (!input) return;
                const isHidden = input.type === 'password';
                input.type = isHidden ? 'text' : 'password';
                btn.querySelector('i').className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
                btn.setAttribute('aria-label', isHidden ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
            });
        });

        // Etat de chargement fluide au submit
        const resetForm = document.getElementById('resetForm');
        const resetBtn = document.getElementById('resetBtn');
        if (resetForm && resetBtn) {
            resetForm.addEventListener('submit', () => {
                resetBtn.disabled = true;
                resetBtn.querySelector('.btn-label').innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Réinitialisation...';
            });
        }
    </script>
    <script>window.BASE_URL = <?php echo json_encode(BASE_URL); ?>;</script>
    <script src="assets/js/app.js"></script>
</body>
</html>