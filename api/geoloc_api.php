<?php
// ============================================================
// API : Géolocalisation
// - GET /api/geoloc_api.php?all=1           => toutes les pharmacies actives
// - GET /api/geoloc_api.php?lat=X&lng=Y&radius=N => pharmacies proches
// - POST /api/geoloc_api.php                => enregistrer la position (JSON)
// Retourne un tableau JSON de pharmacies
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('BASE_URL', 'http://localhost/pharmageo/');

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = new PDO("mysql:host=localhost;dbname=gestion_soutenance;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur de connexion à la base de données.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ===== POST : Enregistrement de la position =====
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $lat = isset($body['latitude']) ? (float)$body['latitude'] : null;
    $lng = isset($body['longitude']) ? (float)$body['longitude'] : null;

    if ($lat === null || $lng === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Latitude et longitude requises.']);
        exit;
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['user_id'])) {
        $_SESSION['latitude'] = $lat;
        $_SESSION['longitude'] = $lng;
    }

    // Utilisé pour la recherche : stocker en session même pour les visiteurs
    session_write_close();
    echo json_encode(['succes' => true]);
    exit;
}

// ===== GET : Liste des pharmacies =====
$all = isset($_GET['all']) && $_GET['all'] == 1;
$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
$radius = isset($_GET['radius']) ? (float)$_GET['radius'] : 10;

try {
    if ($all) {
        $sql = "SELECT Id_pharmacie, Nom, Adresse, Quartier, Commune,
                       Latitude, Longitude, Telephone, Horaire
                FROM pharmacie
                WHERE Statut = 'active'
                ORDER BY Nom ASC";
        $stmt = $pdo->query($sql);
        $pharmacies = $stmt->fetchAll();
    } elseif ($lat !== null && $lng !== null) {
        // Distance en km via la formule de la haversine, filtrée par rayon
        $sql = "SELECT
                    Id_pharmacie, Nom, Adresse, Quartier, Commune,
                    Latitude, Longitude, Telephone, Horaire,
                    (6371 * acos(cos(radians(:lat)) * cos(radians(Latitude)) *
                    cos(radians(Longitude) - radians(:lng)) +
                    sin(radians(:lat)) * sin(radians(Latitude)))) as Distance
                FROM pharmacie
                WHERE Statut = 'active'
                HAVING Distance <= :radius
                ORDER BY Distance ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':lat' => $lat, ':lng' => $lng, ':radius' => $radius]);
        $pharmacies = $stmt->fetchAll();
    } else {
        $sql = "SELECT Id_pharmacie, Nom, Adresse, Quartier, Commune,
                       Latitude, Longitude, Telephone, Horaire
                FROM pharmacie
                WHERE Statut = 'active'
                ORDER BY Nom ASC";
        $stmt = $pdo->query($sql);
        $pharmacies = $stmt->fetchAll();
    }

    echo json_encode($pharmacies);
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur lors du chargement des pharmacies.']);
}
