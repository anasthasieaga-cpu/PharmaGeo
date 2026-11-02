<?php
// ============================================================
// API : Stocks
// - GET /api/stock_api.php?pharmacie=ID            => stocks d'une pharmacie
// - GET /api/stock_api.php?medicament=ID           => stocks d'un médicament
// - GET /api/stock_api.php?all=1                   => tous les stocks disponibles
// - POST /api/stock_api.php                        => créer/modifier un stock (pharmacien)
// Retourne un tableau JSON (GET) ou un objet (POST)
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

// ===== GET : Consultation des stocks =====
if ($method === 'GET') {
    $pharmacie = isset($_GET['pharmacie']) ? (int)$_GET['pharmacie'] : 0;
    $medicament = isset($_GET['medicament']) ? (int)$_GET['medicament'] : 0;
    $all = isset($_GET['all']) && $_GET['all'] == 1;

    try {
        $sql = "SELECT
                    s.Id_stock, s.Quantite, s.Prix, s.Seuil_alerte, s.Statut,
                    s.Date_mise_a_jour,
                    m.Id_medicament, m.Nom_commercial, m.DCI, m.Forme, m.Dosage,
                    p.Id_pharmacie, p.Nom as Pharmacie, p.Quartier, p.Commune
                FROM stock s
                JOIN medicament m ON s.Id_medicament = m.Id_medicament
                JOIN pharmacie p ON s.Id_pharmacie = p.Id_pharmacie
                WHERE 1=1";

        $params = [];

        if ($pharmacie > 0) {
            $sql .= " AND s.Id_pharmacie = :pharmacie";
            $params[':pharmacie'] = $pharmacie;
        }
        if ($medicament > 0) {
            $sql .= " AND s.Id_medicament = :medicament";
            $params[':medicament'] = $medicament;
        }
        // Hors mode "all", on n'expose que les stocks actifs des pharmacies actives
        if (!$all) {
            $sql .= " AND s.Statut != 'desactive' AND m.Statut = 'actif' AND p.Statut = 'active'";
        }

        $sql .= " ORDER BY m.Nom_commercial ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erreur lors du chargement des stocks.']);
    }
    exit;
}

// ===== POST : Création / mise à jour de stock (pharmacien) =====
if ($method === 'POST') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'pharmacien') {
        http_response_code(401);
        echo json_encode(['error' => 'Accès réservé aux pharmaciens.']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    $id_stock   = isset($body['id_stock']) ? (int)$body['id_stock'] : (isset($_POST['id_stock']) ? (int)$_POST['id_stock'] : 0);
    $id_medicament = isset($body['id_medicament']) ? (int)$body['id_medicament'] : (isset($_POST['id_medicament']) ? (int)$_POST['id_medicament'] : 0);
    $quantite   = isset($body['quantite']) ? (int)$body['quantite'] : (isset($_POST['quantite']) ? (int)$_POST['quantite'] : 0);
    $prix       = isset($body['prix']) ? (float)$body['prix'] : (isset($_POST['prix']) ? (float)$_POST['prix'] : 0);
    $seuil      = isset($body['seuil_alerte']) ? (int)$body['seuil_alerte'] : (isset($_POST['seuil_alerte']) ? (int)$_POST['seuil_alerte'] : 5);

    try {
        // Récupérer la pharmacie du pharmacien connecté
        $stmt = $pdo->prepare("
            SELECT p.Id_pharmacie
            FROM pharmacie p
            JOIN pharmacien ph ON p.Id_pharmacien = ph.Id_pharmacien
            WHERE ph.Id_utilisateur = :user
        ");
        $stmt->execute([':user' => $_SESSION['user_id']]);
        $pharmacie = $stmt->fetch();

        if (!$pharmacie) {
            http_response_code(404);
            echo json_encode(['error' => 'Aucune pharmacie associée.']);
            exit;
        }
        $id_pharmacie = $pharmacie['Id_pharmacie'];

        // Mise à jour d'un stock existant
        if ($id_stock > 0) {
            $stmt = $pdo->prepare("SELECT Quantite FROM stock WHERE Id_stock = :id AND Id_pharmacie = :pharmacie");
            $stmt->execute([':id' => $id_stock, ':pharmacie' => $id_pharmacie]);
            $old = $stmt->fetch();

            if (!$old) {
                http_response_code(404);
                echo json_encode(['error' => 'Stock introuvable.']);
                exit;
            }

            // Statut déduit du seuil d'alerte : rupture, stock faible ou disponible
            $statut = $quantite <= 0 ? 'rupture' : ($quantite <= $seuil ? 'stock_faible' : 'disponible');

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE stock
                SET Quantite = :quantite, Prix = :prix, Seuil_alerte = :seuil,
                    Statut = :statut, Date_mise_a_jour = NOW()
                WHERE Id_stock = :id
            ");
            $stmt->execute([
                ':quantite' => $quantite,
                ':prix' => $prix,
                ':seuil' => $seuil,
                ':statut' => $statut,
                ':id' => $id_stock
            ]);

            $stmt = $pdo->prepare("
                INSERT INTO historique_stock (Ancienne_quantite, Nouvelle_quantite, Type_operation, Id_stock, Id_utilisateur)
                VALUES (:ancienne, :nouvelle, 'mise_a_jour', :stock, :user)
            ");
            $stmt->execute([
                ':ancienne' => $old['Quantite'],
                ':nouvelle' => $quantite,
                ':stock' => $id_stock,
                ':user' => $_SESSION['user_id']
            ]);

            $stmt = $pdo->prepare("
                INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur)
                VALUES ('Modification de stock', :ip, :user_agent, 'succes', :user)
            ");
            $stmt->execute([
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':user' => $_SESSION['user_id']
            ]);

            $pdo->commit();

            echo json_encode(['succes' => true, 'id_stock' => $id_stock, 'message' => 'Stock mis à jour.']);
        }
        // Création d'un nouveau stock
        elseif ($id_medicament > 0) {
            // Refuser les médicaments suspendus
            $stmt = $pdo->prepare("SELECT Statut FROM medicament WHERE Id_medicament = :med");
            $stmt->execute([':med' => $id_medicament]);
            $med_row = $stmt->fetch();
            if (!$med_row || $med_row['Statut'] === 'suspendu') {
                http_response_code(400);
                echo json_encode(['error' => 'Ce médicament est suspendu.']);
                exit;
            }

            $statut = $quantite <= 0 ? 'rupture' : ($quantite <= $seuil ? 'stock_faible' : 'disponible');

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO stock (Quantite, Prix, Seuil_alerte, Statut, Id_pharmacie, Id_medicament)
                VALUES (:quantite, :prix, :seuil, :statut, :pharmacie, :medicament)
            ");
            $stmt->execute([
                ':quantite' => $quantite,
                ':prix' => $prix,
                ':seuil' => $seuil,
                ':statut' => $statut,
                ':pharmacie' => $id_pharmacie,
                ':medicament' => $id_medicament
            ]);
            $new_stock_id = $pdo->lastInsertId();

            $stmt = $pdo->prepare("
                INSERT INTO historique_stock (Ancienne_quantite, Nouvelle_quantite, Type_operation, Id_stock, Id_utilisateur)
                VALUES (0, :nouvelle, 'ajout', :stock, :user)
            ");
            $stmt->execute([
                ':nouvelle' => $quantite,
                ':stock' => $new_stock_id,
                ':user' => $_SESSION['user_id']
            ]);

            $pdo->commit();

            echo json_encode(['succes' => true, 'id_stock' => $new_stock_id, 'message' => 'Stock créé.']);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Paramètres invalides.']);
        }
    } catch(PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Erreur lors de l\'opération sur le stock.']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Méthode non autorisée.']);
