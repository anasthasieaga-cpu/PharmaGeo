<?php
// ============================================================
// PharmaGeo — Point d'entrée de la réinitialisation
// ------------------------------------------------------------
// Appelé uniquement depuis les boutons des sidebars (zones
// admin et pharmacien), après une confirmation par saisie du
// mot de sécurité (RESET).
//
//   GET ?niveau=donnees&code=RESET  → vide les données métier
//   GET ?niveau=total&code=RESET    → vide toute la base
//
// Accessible uniquement aux utilisateurs connectés ayant le
// rôle administrateur ou pharmacien.
// ============================================================

require_once __DIR__ . '/../includes/reinitialiser.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['administrateur', 'pharmacien'], true)) {
    http_response_code(403);
    exit('Accès refusé.');
}

$niveau = $_GET['niveau'] ?? '';
if (!in_array($niveau, ['donnees', 'total'], true)) {
    http_response_code(400);
    exit('Niveau de réinitialisation invalide.');
}

$base = reinitialiser_base_url();
$dashboard = $role === 'administrateur'
    ? $base . 'admin/dashboard.php'
    : $base . 'pharmacien/dashboard.php';

// Confirmation obligatoire : le mot de sécurité doit correspondre
$code = (string)($_GET['code'] ?? '');
if (!hash_equals(REINIT_CODE_CONFIRMATION, $code)) {
    header('Location: ' . $dashboard . '?reset=code_incorrect');
    exit;
}

try {
    reinitialiser_executer($niveau);
} catch (Throwable $e) {
    header('Location: ' . $dashboard . '?reset=erreur');
    exit;
}

if ($niveau === 'total') {
    // Plus aucun compte : détruire la session et renvoyer à la connexion
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: ' . $base . 'connexion.php');
    exit;
}

header('Location: ' . $dashboard . '?reset=donnees');
exit;