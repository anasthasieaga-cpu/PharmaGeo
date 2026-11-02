<?php
// ============================================================
// API : Réservation
// - POST /api/reservation_api.php   => créer une réservation (JSON)
//     { id_stock: X, quantite: N }
// - GET  /api/reservation_api.php?id=RES  => infos d'une réservation
// Retourne un objet JSON
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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'];

// ===== GET : Détails d'une réservation =====
if ($method === 'GET') {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Non autorisé.']);
        exit;
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    try {
        $stmt = $pdo->prepare("
            SELECT r.*, m.Nom_commercial, m.DCI, p.Nom as Pharmacie, p.Quartier, p.Commune,
                   s.Quantite as Stock_Quantite, s.Prix
            FROM reservation r
            JOIN stock s ON r.Id_stock = s.Id_stock
            JOIN medicament m ON s.Id_medicament = m.Id_medicament
            JOIN pharmacie p ON s.Id_pharmacie = p.Id_pharmacie
            WHERE r.Id_reservation = :id AND r.Id_utilisateur = :user
        ");
        $stmt->execute([':id' => $id, ':user' => $_SESSION['user_id']]);
        $reservation = $stmt->fetch();

        if (!$reservation) {
            http_response_code(404);
            echo json_encode(['error' => 'Réservation introuvable.']);
            exit;
        }
        echo json_encode($reservation);
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erreur lors du chargement.']);
    }
    exit;
}

// ===== POST : Créer une réservation =====
if ($method === 'POST') {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Vous devez être connecté pour réserver.']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    $id_stock = isset($body['id_stock']) ? (int)$body['id_stock'] : (isset($_POST['id_stock']) ? (int)$_POST['id_stock'] : 0);
    $quantite = isset($body['quantite']) ? (int)$body['quantite'] : (isset($_POST['quantite']) ? (int)$_POST['quantite'] : 1);

    if ($id_stock <= 0 || $quantite <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Paramètres invalides.']);
        exit;
    }

    try {
        // Vérifier le stock et la disponibilité
        $stmt = $pdo->prepare("
            SELECT s.*, p.Statut as Pharmacie_Statut, m.Statut as Medicament_Statut
            FROM stock s
            JOIN medicament m ON s.Id_medicament = m.Id_medicament
            JOIN pharmacie p ON s.Id_pharmacie = p.Id_pharmacie
            WHERE s.Id_stock = :id
        ");
        $stmt->execute([':id' => $id_stock]);
        $stock = $stmt->fetch();

        if (!$stock) {
            http_response_code(404);
            echo json_encode(['error' => 'Stock introuvable.']);
            exit;
        }

        // On ne réserve que dans une pharmacie active
        if ($stock['Pharmacie_Statut'] !== 'active') {
            http_response_code(400);
            echo json_encode(['error' => 'La pharmacie n\'est pas active.']);
            exit;
        }

        // Vérifier le stock suffisant : quantité demanded vs quantité, hors rupture
        if ($stock['Quantite'] < $quantite || $stock['Statut'] === 'rupture' || $stock['Statut'] === 'desactive' ||
            (isset($stock['Medicament_Statut']) && $stock['Medicament_Statut'] === 'suspendu')) {
            http_response_code(400);
            echo json_encode(['error' => 'Stock insuffisant pour cette quantité.']);
            exit;
        }

        // Créer la réservation
        $stmt = $pdo->prepare("
            INSERT INTO reservation (Id_utilisateur, Id_stock, Quantite, Statut)
            VALUES (:user, :stock, :quantite, 'en_attente')
        ");
        $stmt->execute([
            ':user' => $_SESSION['user_id'],
            ':stock' => $id_stock,
            ':quantite' => $quantite
        ]);
        $reservation_id = $pdo->lastInsertId();

        // Créer une notification pour le pharmacien
        try {
            $stmtPharm = $pdo->prepare("
                SELECT ph.Id_utilisateur as Id_pharmacien
                FROM pharmacie p
                JOIN pharmacien ph ON p.Id_pharmacien = ph.Id_pharmacien
                WHERE p.Id_pharmacie = :id
            ");
            $stmtPharm->execute([':id' => $stock['Id_pharmacie']]);
            $pharmacien = $stmtPharm->fetch();

            if ($pharmacien) {
                $stmtNotif = $pdo->prepare("
                    INSERT INTO notification (Titre, Message, Id_utilisateur, Lu)
                    VALUES ('Nouvelle réservation', :message, :user, 0)
                ");
                $stmtNotif->execute([
                    ':user' => $pharmacien['Id_pharmacien'],
                    ':message' => 'Nouvelle réservation de médicament à traiter.'
                ]);
            }
        } catch(PDOException $e) {
            // Ne pas bloquer la réservation si la notification échoue
        }

        echo json_encode([
            'succes' => true,
            'id_reservation' => $reservation_id,
            'message' => 'Réservation créée avec succès.'
        ]);
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erreur lors de la création de la réservation.']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Méthode non autorisée.']);
