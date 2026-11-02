<?php
// ============================================================
// PharmaGeo — API : Géolocalisation
// - GET /api/geoloc_api.php?all=1                   => toutes les pharmacies actives
// - GET /api/geoloc_api.php?lat=X&lng=Y&radius=N    => pharmacies proches
// - POST /api/geoloc_api.php                        => enregistrer la position
//
// La position enregistrée alimente le calcul des distances de la
// plateforme : elle est stockée en session (visiteurs) et en base
// (utilisateurs connectés) via includes/geo.php.
// ============================================================

require_once __DIR__ . '/../includes/geo.php';

// Une API doit renvoyer du JSON valide : aucune erreur PHP ne doit
// être imprimée dans le corps de la réponse.
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

// ===== POST : ENREGISTREMENT DE LA POSITION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = $_POST;

    $lat = isset($body['latitude']) ? $body['latitude'] : null;
    $lng = isset($body['longitude']) ? $body['longitude'] : null;

    if (!geoCoordonneesValides($lat, $lng)) {
        http_response_code(400);
        echo json_encode(['succes' => false, 'error' => 'Coordonnées invalides.']);
        exit;
    }

    $source = isset($body['source']) && in_array($body['source'], ['gps', 'ip'], true)
        ? $body['source']
        : 'gps';

    // Rayon d'erreur annonce par le navigateur (coords.accuracy). Il est
    // conserve pour l'information, mais son absence ou sa taille elevee ne
    // bloque plus l'enregistrement : une position, meme approximative,
    // permet d'afficher une distance utile.
    $precision = geoNormaliserPrecision(
        isset($body['precision']) ? $body['precision'] : null
    );

    $accepte = geoEnregistrerPosition($lat, $lng, $source, true, $precision);

    if (!$accepte) {
        http_response_code(422);
        echo json_encode(['succes' => false, 'error' => 'Coordonnées inexploitables.']);
        exit;
    }

    // La session est fermee pour ne pas bloquer la page qui l'a appelée.
    session_write_close();

    echo json_encode([
        'succes' => true,
        'position' => [
            'latitude'  => round((float)$lat, 6),
            'longitude' => round((float)$lng, 6),
            'source'    => $source,
            'precision' => $precision,
            'precise'   => ($source === 'gps')
        ]
    ]);
    exit;
}

// ===== GET : LISTE DES PHARMACIES =====
$all = isset($_GET['all']) && $_GET['all'] == 1;
$lat = isset($_GET['lat']) ? $_GET['lat'] : null;
$lng = isset($_GET['lng']) ? $_GET['lng'] : null;
$radius = isset($_GET['radius']) ? (float)$_GET['radius'] : 10;

try {
    if (!$all && geoCoordonneesValides($lat, $lng)) {
        // Distance en km via la formule de la haversine, filtrée par rayon.
        // LEAST/GREATEST protègent ACOS des dépassements dus aux arrondis
        // flottants, qui renverraient sinon NULL (et une distance fausse).
        $sql = "SELECT
                    Id_pharmacie, Nom, Adresse, Quartier, Commune,
                    Latitude, Longitude, Telephone, Horaire,
                    (6371 * ACOS(LEAST(1, GREATEST(-1,
                        COS(RADIANS(:lat)) * COS(RADIANS(Latitude)) *
                        COS(RADIANS(Longitude) - RADIANS(:lng)) +
                        SIN(RADIANS(:lat)) * SIN(RADIANS(Latitude))
                    )))) as Distance
                FROM pharmacie
                WHERE Statut = 'active'
                  AND Latitude IS NOT NULL AND Longitude IS NOT NULL
                HAVING Distance <= :radius
                ORDER BY Distance ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':lat' => (float)$lat, ':lng' => (float)$lng, ':radius' => $radius]);
        $pharmacies = $stmt->fetchAll();
    } else {
        $sql = "SELECT Id_pharmacie, Nom, Adresse, Quartier, Commune,
                       Latitude, Longitude, Telephone, Horaire
                FROM pharmacie
                WHERE Statut = 'active'
                ORDER BY Nom ASC";
        $pharmacies = $pdo->query($sql)->fetchAll();
    }

    echo json_encode($pharmacies);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur lors du chargement des pharmacies.']);
}
