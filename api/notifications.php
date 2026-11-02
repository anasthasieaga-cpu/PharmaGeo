<?php
// ============================================================
// API : Notifications
// - GET /api/notifications.php  => nombre de notifications non lues
//
// L'identifiant de la personne vient exclusivement de la session :
// aucun paramètre d'identifiant n'est accepté, sinon il serait
// possible de lire le compteur de quelqu'un d'autre.
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('BASE_URL', 'http://localhost/pharmageo/');

header('Content-Type: application/json; charset=utf-8');

// ===== SESSION DE LA PERSONNE CONNECTÉE =====
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentification requise.', 'unread' => 0]);
    exit;
}

try {
    $pdo = new PDO("mysql:host=localhost;dbname=gestion_soutenance;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur de connexion à la base de données.', 'unread' => 0]);
    exit;
}

$stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM notification WHERE Id_utilisateur = ? AND Lu = 0");
$stmt->execute([(int)$_SESSION['user_id']]);
$unread = (int)($stmt->fetch()['total'] ?? 0);

header('Cache-Control: no-store');
echo json_encode([
    'unread' => $unread,
    'label'  => $unread > 9 ? '9+' : (string)$unread,
]);