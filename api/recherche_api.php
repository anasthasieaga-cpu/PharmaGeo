<?php
// ============================================================
// API : Recherche de médicaments (autocomplétion)
// GET /api/recherche_api.php?q=terme
// Retourne un tableau JSON de médicaments disponibles
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

$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if (mb_strlen($query) < 1) {
    echo json_encode([]);
    exit;
}

try {
    // Recherche par nom commercial, DCI ou synonyme ;
    // "disponible" compte les pharmacies actives avec du stock
    $sql = "SELECT DISTINCT
                m.Id_medicament,
                m.Nom_commercial,
                m.DCI,
                m.Forme,
                m.Dosage,
                (SELECT COUNT(*) FROM stock s
                 WHERE s.Id_medicament = m.Id_medicament
                   AND s.Quantite > 0
                   AND s.Statut = 'disponible'
                   AND EXISTS (SELECT 1 FROM pharmacie p WHERE p.Id_pharmacie = s.Id_pharmacie AND p.Statut = 'active')
                ) as disponible
            FROM medicament m
            WHERE (m.Nom_commercial LIKE :terme
               OR m.DCI LIKE :terme
               OR m.Id_medicament IN (
                   SELECT Id_medicament FROM medicament_synonyme WHERE Libelle LIKE :terme
               ))
            AND m.Statut = 'actif'
            ORDER BY m.Nom_commercial ASC
            LIMIT 10";

    $stmt = $pdo->prepare($sql);
    $terme = '%' . $query . '%';
    $stmt->execute([':terme' => $terme]);
    $resultats = $stmt->fetchAll();

    echo json_encode($resultats);
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur lors de la recherche.']);
}
