<?php
// ============================================================
// PharmaGeo — Page de test SMTP (destinée au développement)
// Permet de vérifier que l'envoi d'e-mail fonctionne avec la
// configuration de includes/config.php. A supprimer en production.
// Accessible UNIQUEMENT depuis localhost / 127.0.0.1.
// ============================================================
$page_title = 'Test SMTP - PharmaGeo';
require_once 'includes/config.php';
require_once 'includes/mailer.php';

// ===== SÉCURITÉ : accès réservé à la machine locale =====
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocal = in_array($ip, ['127.0.0.1', '::1', 'localhost'], true);
if (!$isLocal) {
    http_response_code(403);
    $blocked = true;
} else {
    $blocked = false;
}

// ===== VARIABLES =====
$error = '';
$success = '';
$mailDetails = '';
$recipient = $_POST['recipient'] ?? '';
$subject   = $_POST['subject'] ?? '';
$message   = $_POST['message'] ?? '';

// ===== PRÉ-TESTS DE L'ENVIRONNEMENT =====
$hasOpenssl = extension_loaded('openssl');
$configured = !(strpos(MAIL_USERNAME, 'votre.') !== false || MAIL_USERNAME === '');

// ===== TRAITEMENT =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$blocked) {
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        $error = 'Veuillez saisir une adresse email de destinataire valide.';
    } elseif (trim($subject) === '' || trim($message) === '') {
        $error = 'Le sujet et le message doivent être renseignés.';
    } else {
        $errorInfo = null;
        $sent = sendMail($recipient, $subject, $message, [], $errorInfo);
        if ($sent) {
            $success = 'E-mail envoyé avec succès à <strong>' . htmlspecialchars($recipient) . '</strong>.';
        } else {
            $error = 'Échec de l\'envoi de l\'e-mail.';
            if ($errorInfo !== null) {
                $mailDetails = $errorInfo;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-green: #16A34A;
            --primary-green-dark: #166534;
            --primary-orange: #B91C1C;
            --dark-text: #0F2E19;
            --gray-text: #64748b;
        }
        body { font-family: 'Inter', sans-serif; background: #F0FDF4; color: var(--dark-text); }
        .card-shell {
            max-width: 720px;
            margin: 40px auto;
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(10,22,40,0.12);
            padding: 36px 40px;
        }
        .badge-green { background: rgba(22,163,74,0.14); color: var(--primary-green-dark); font-weight: 700; }
        .badge-orange { background: rgba(212,136,15,0.14); color: var(--primary-orange); font-weight: 700; }
        .badge-gray   { background: #F0FDF4; color: var(--gray-text); font-weight: 600; }
        .kv { display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; border-bottom: 1px dashed #D9E9DF; font-size: 0.9rem; }
        .kv dt { color: var(--gray-text); margin: 0; font-weight: 500; }
        .kv dd { margin: 0; font-weight: 600; text-align: right; }
        .btn-send {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-green-dark));
            border: none; font-weight: 700; border-radius: 12px; padding: 12px; color: #fff; width: 100%;
        }
        .btn-send:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(22,163,74,0.28); }
        .form-control, .form-select { border: 1.5px solid #D9E9DF; padding: 11px 14px; font-size: 0.95rem; }
        .form-control:focus, .form-select:focus { border-color: var(--primary-green); box-shadow: 0 0 0 4px rgba(22,163,74,0.15); }
        .mono { font-family: Consolas, monospace; font-size: 0.82rem; background: #F0FDF4; border: 1.5px solid #FEF2F2; border-left: 4px solid var(--primary-orange); border-radius: 10px; padding: 10px 12px; word-break: break-word; }
    </style>    <!-- Palette verte PharmaGeo -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/palette.css">
</head>
<body>
    <div class="card-shell">
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="logo-badge d-inline-flex align-items-center justify-content-center" style="width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,var(--primary-green),var(--primary-orange));color:#fff;"><i class="fas fa-envelope"></i></span>
            <h1 class="fs-4 fw-bold mb-0">Test d'envoi SMTP</h1>
        </div>
        <p class="text-muted mb-4" style="font-size:0.92rem;">Vérifie la configuration de <code>includes/config.php</code> avant de dépendre de l'envoi de la réinitialisation de mot de passe.</p>

        <?php if ($blocked): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fas fa-shield-alt me-2"></i>Accès refusé : cette page est réservée à la machine locale (localhost). Supprimez ce fichier en production.
            </div>
        <?php else: ?>

            <?php if ($success): ?>
                <div class="alert alert-success" role="alert"><i class="fas fa-check-circle me-2"></i><?php echo $success; ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                    <?php if ($mailDetails): ?>
                        <div class="mono mt-2"><?php echo htmlspecialchars($mailDetails); ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <h2 class="fs-6 fw-bold text-uppercase text-muted mt-2">Configuration actuelle</h2>
            <dl class="mt-2">
                <div class="kv"><dt>SMTP activé</dt><dd><?php echo MAIL_ENABLE ? '<span class="badge-green badge">Actif</span>' : '<span class="badge-orange badge">Désactivé (mode démo)</span>'; ?></dd></div>
                <div class="kv"><dt>Serveur</dt><dd><?php echo htmlspecialchars(MAIL_HOST . ':' . MAIL_PORT); ?></dd></div>
                <div class="kv"><dt>Sécurité</dt><dd><?php echo htmlspecialchars(MAIL_ENCRYPTION ?: 'Aucune'); ?></dd></div>
                <div class="kv"><dt>Identifiants renseignés</dt><dd><?php echo $configured ? '<span class="badge-green badge">Oui</span>' : '<span class="badge-orange badge">Non (valeurs par défaut)</span>'; ?></dd></div>
                <div class="kv"><dt>Extension PHP openssl</dt><dd><?php echo $hasOpenssl ? '<span class="badge-green badge">Chargée</span>' : '<span class="badge-orange badge">Manquante</span>'; ?></dd></div>
                <div class="kv"><dt>Expéditeur</dt><dd><?php echo htmlspecialchars(MAIL_FROM . ' (' . MAIL_FROM_NAME . ')'); ?></dd></div>
            </dl>

            <form method="POST" novalidate class="mt-4">
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="recipient">E-mail du destinataire (test)</label>
                    <input type="email" class="form-control" id="recipient" name="recipient"
                           value="<?php echo htmlspecialchars($recipient); ?>" placeholder="vous@exemple.com" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="subject">Sujet</label>
                    <input type="text" class="form-control" id="subject" name="subject"
                           value="<?php echo htmlspecialchars($subject !== '' ? $subject : 'Test SMTP - PharmaGeo'); ?>" required>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold" for="message">Message</label>
                    <textarea class="form-control" id="message" name="message" rows="4" required><?php echo htmlspecialchars($message !== '' ? $message : 'Ceci est un e-mail de test envoyé depuis PharmaGeo.'); ?></textarea>
                </div>
                <button type="submit" class="btn btn-send" id="sendBtn">
                    <span class="btn-label"><i class="fas fa-paper-plane me-2"></i>Envoyer le test</span>
                </button>
            </form>

            <?php if (!$configured): ?>
                <div class="alert alert-warning mt-4 mb-0" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Renseignez les constantes <code>MAIL_USERNAME</code>, <code>MAIL_PASSWORD</code>, <code>MAIL_HOST</code>… dans <code>includes/config.php</code> puis réessayez.
                </div>
            <?php endif; ?>

            <div class="text-center mt-4">
                <a href="motdepasse-oublie.php" class="text-decoration-none fw-semibold" style="color:var(--primary-green);">
                    <i class="fas fa-arrow-left me-1"></i>Revenir à « Mot de passe oublié »
                </a>
            </div>

        <?php endif; ?>
    </div>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        const sendBtn = document.getElementById('sendBtn');
        if (sendBtn) {
            document.querySelector('form').addEventListener('submit', () => {
                sendBtn.disabled = true;
                sendBtn.querySelector('.btn-label').innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Envoi en cours...';
            });
        }
    </script>
</body>
</html>