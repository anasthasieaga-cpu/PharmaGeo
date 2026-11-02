<?php
// ============================================================
// PharmaGeo — Helper d'envoi d'e-mails
// Enveloppe PHPMailer avec la configuration SMTP centralisée
// dans includes/config.php. Sert la réinitialisation du mot de
// passe et peut être réutilisé pour tout autre e-mail.
// ============================================================

// Autoloader PSR-4 minimal pour PHPMailer (le projet n'utilise pas Composer)
spl_autoload_register(function ($class) {
    $prefix = 'PHPMailer\\PHPMailer\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = __DIR__ . '/phpmailer/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Envoie un e-mail via SMTP (PHPMailer).
 *
 * @param string $to      Adresse du destinataire
 * @param string $subject Sujet du message
 * @param string $message Corps du message (texte simple ou HTML)
 * @param array  $options Options facultatives :
 *                        - 'isHtml'  (bool)   true ⇒ le corps est du HTML
 *                        - 'replyTo' (string|array) adresse(s) pour Reply-To
 *                        - 'attach'  (string) chemin d'un fichier à joindre
 * @param string|null &$errorInfo Reçoit le message d'erreur SMTP détaillé (par référence)
 * @return bool true si l'e-mail a été envoyé, false sinon (SMTP désactivé ou erreur)
 */
function sendMail($to, $subject, $message, array $options = [], &$errorInfo = null) {
    $errorInfo = null;

    if (!MAIL_ENABLE) {
        $errorInfo = 'Envoi SMTP désactivé (MAIL_ENABLE = false) : mode démo actif.';
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_ENCRYPTION; // 'tls', 'ssl' ou '' (aucun)
        $mail->Port       = MAIL_PORT;
        $mail->Timeout    = 15;   // délai d'attente (s) pour chaque échange SMTP
        $mail->CharSet    = 'UTF-8';

        // PHPMailer ne propage pas Timelimit vers l'objet SMTP (il reste à 300 s).
        // Or Timelimit pilote le stream_select() de get_lines() : sans lui, si le
        // serveur SMTP ne répond jamais (port joignable mais muet), l'envoi peut
        // bloquer le script jusqu'à 5 minutes. On plafonne donc par sécurité.
        $smtpInstance = $mail->getSMTPInstance();
        $smtpInstance->Timelimit = 15;
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to);

        if (isset($options['replyTo'])) {
            if (is_array($options['replyTo'])) {
                $mail->addReplyTo($options['replyTo'][0], $options['replyTo'][1] ?? '');
            } else {
                $mail->addReplyTo($options['replyTo']);
            }
        }

        if (isset($options['attach']) && is_file($options['attach'])) {
            $mail->addAttachment($options['attach']);
        }

        $isHtml = !empty($options['isHtml']);
        $mail->isHTML($isHtml);
        $mail->Subject = $subject;
        $mail->Body    = $message;
        $mail->AltBody = $isHtml ? strip_tags($message) : '';

        return $mail->send();
    } catch (Exception $e) {
        $errorInfo = $mail->ErrorInfo;
        error_log('[PharmaGeo][Mail] Échec SMTP : ' . $mail->ErrorInfo);
        return false;
    }
}