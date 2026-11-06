<?php
// ============================================================
// PharmaGeo — Espace pharmacien : Gestion des stocks
// Permet de consulter les médicaments, ajuster les quantités,
// approvisionner le stock et suivre les derniers mouvements.
// ============================================================

// ===== ACTIVATION DES ERREURS =====
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ===== DÉFINITION DES CONSTANTES =====
define('BASE_URL', 'http://localhost/pharmageo/');

// ===== FONCTIONS UTILITAIRES =====
require_once __DIR__ . '/../includes/fonctions.php';

// ===== PAGINATION (10 éléments par page) =====
require_once __DIR__ . '/../includes/pagination.php';

// ===== CONNEXION À LA BASE DE DONNÉES =====
try {
    $pdo = new PDO("mysql:host=localhost;dbname=gestion_soutenance;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Erreur de connexion à la base de données : " . $e->getMessage());
}

// ===== GESTION DES SESSIONS =====
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== VÉRIFICATION PHARMACIEN =====
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'pharmacien') {
    header('Location: ' . BASE_URL . 'connexion.php');
    exit();
}

// ===== RÉCUPÉRATION DES INFORMATIONS =====
try {
    // Récupérer les informations du pharmacien et de sa pharmacie
    $stmt = $pdo->prepare("
        SELECT u.*, ph.Id_pharmacien, ph.Numero_ordre, ph.Statut_validation,
               p.Id_pharmacie, p.Nom as Pharmacie_Nom, p.Statut as Pharmacie_Statut
        FROM utilisateur u
        JOIN pharmacien ph ON u.Id_utilisateur = ph.Id_utilisateur
        LEFT JOIN pharmacie p ON ph.Id_pharmacien = p.Id_pharmacien
        WHERE u.Id_utilisateur = :id
    ");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        header('Location: ' . BASE_URL . 'connexion.php');
        exit();
    }

    // Vérifier si le pharmacien a une pharmacie
    $has_pharmacy = !empty($user['Id_pharmacie']);
    $pharmacie_id = $user['Id_pharmacie'] ?? null;

    if (!$has_pharmacy) {
        header('Location: ' . BASE_URL . 'pharmacien/dashboard.php?error=no_pharmacy');
        exit();
    }

} catch(PDOException $e) {
    die("Erreur : " . $e->getMessage());
}

// ===== TRAITEMENT DES ACTIONS =====
$message = '';
$message_type = '';

// ===== MISE À JOUR RAPIDE D'UN STOCK =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_stock') {
    $id_stock = (int)($_POST['id_stock'] ?? 0);
    $quantite = (int)($_POST['quantite'] ?? 0);
    $prix = !empty($_POST['prix']) ? (float)$_POST['prix'] : null;
    $seuil_alerte = (int)($_POST['seuil_alerte'] ?? 5);
    
    $errors = [];
    if ($id_stock <= 0) $errors[] = "ID de stock invalide.";
    if ($quantite < 0) $errors[] = "La quantité doit être un nombre positif.";
    
    if (empty($errors)) {
        try {
            // Récupérer l'ancienne quantité
            $old = $pdo->prepare("SELECT Quantite FROM stock WHERE Id_stock = :id");
            $old->execute([':id' => $id_stock]);
            $ancienne_quantite = $old->fetch()['Quantite'] ?? 0;
            
            // Déterminer le statut
            $statut = 'disponible';
            if ($quantite == 0) {
                $statut = 'rupture';
            } elseif ($quantite <= $seuil_alerte) {
                $statut = 'stock_faible';
            }
            
            $stmt = $pdo->prepare("
                UPDATE stock 
                SET Quantite = :quantite, Prix = :prix, Seuil_alerte = :seuil, Statut = :statut,
                    Date_mise_a_jour = NOW()
                WHERE Id_stock = :id
            ");
            $stmt->execute([
                ':quantite' => $quantite,
                ':prix' => $prix,
                ':seuil' => $seuil_alerte,
                ':statut' => $statut,
                ':id' => $id_stock
            ]);
            
            // Historique du stock
            $hist = $pdo->prepare("
                INSERT INTO historique_stock (Ancienne_quantite, Nouvelle_quantite, Type_operation, Id_stock, Id_utilisateur)
                VALUES (:ancienne, :nouvelle, 'mise_a_jour', :stock, :user)
            ");
            $hist->execute([
                ':ancienne' => $ancienne_quantite,
                ':nouvelle' => $quantite,
                ':stock' => $id_stock,
                ':user' => $_SESSION['user_id']
            ]);
            
            $message = "Stock mis à jour avec succès !";
            $message_type = "success";
            
        } catch(PDOException $e) {
            $message = "Erreur lors de la mise à jour : " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = implode('<br>', $errors);
        $message_type = "danger";
    }
}

// ===== APPROVISIONNEMENT RAPIDE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approvisionner') {
    $id_stock = (int)($_POST['id_stock'] ?? 0);
    $quantite_ajout = (int)($_POST['quantite_ajout'] ?? 0);
    
    $errors = [];
    if ($id_stock <= 0) $errors[] = "ID de stock invalide.";
    if ($quantite_ajout <= 0) $errors[] = "La quantité à ajouter doit être positive.";
    
    if (empty($errors)) {
        try {
            // Récupérer la quantité actuelle
            $old = $pdo->prepare("SELECT Quantite, Seuil_alerte FROM stock WHERE Id_stock = :id");
            $old->execute([':id' => $id_stock]);
            $stock_info = $old->fetch();
            $ancienne_quantite = $stock_info['Quantite'] ?? 0;
            $seuil_alerte = $stock_info['Seuil_alerte'] ?? 5;
            
            $nouvelle_quantite = $ancienne_quantite + $quantite_ajout;
            
            // Déterminer le statut
            $statut = 'disponible';
            if ($nouvelle_quantite == 0) {
                $statut = 'rupture';
            } elseif ($nouvelle_quantite <= $seuil_alerte) {
                $statut = 'stock_faible';
            }
            
            $stmt = $pdo->prepare("
                UPDATE stock 
                SET Quantite = :quantite, Statut = :statut, Date_mise_a_jour = NOW()
                WHERE Id_stock = :id
            ");
            $stmt->execute([
                ':quantite' => $nouvelle_quantite,
                ':statut' => $statut,
                ':id' => $id_stock
            ]);
            
            // Historique du stock
            $hist = $pdo->prepare("
                INSERT INTO historique_stock (Ancienne_quantite, Nouvelle_quantite, Type_operation, Id_stock, Id_utilisateur)
                VALUES (:ancienne, :nouvelle, 'approvisionnement', :stock, :user)
            ");
            $hist->execute([
                ':ancienne' => $ancienne_quantite,
                ':nouvelle' => $nouvelle_quantite,
                ':stock' => $id_stock,
                ':user' => $_SESSION['user_id']
            ]);
            
            $message = "Stock approvisionné avec succès ! (+$quantite_ajout unités)";
            $message_type = "success";
            
        } catch(PDOException $e) {
            $message = "Erreur lors de l'approvisionnement : " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = implode('<br>', $errors);
        $message_type = "danger";
    }
}

// ===== SUPPRESSION D'UN STOCK =====
if (isset($_GET['action']) && $_GET['action'] === 'supprimer' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        // Vérifier si le stock a des réservations
        $check = $pdo->prepare("SELECT COUNT(*) as total FROM reservation WHERE Id_stock = :id");
        $check->execute([':id' => $id]);
        if ($check->fetch()['total'] > 0) {
            $message = "Ce stock a des réservations associées et ne peut pas être supprimé.";
            $message_type = "warning";
        } else {
            $stmt = $pdo->prepare("DELETE FROM stock WHERE Id_stock = :id");
            $stmt->execute([':id' => $id]);
            
            $log = $pdo->prepare("
                INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur)
                VALUES (CONCAT('Suppression stock ID: ', :id), :ip, :ua, 'succes', :user)
            ");
            $log->execute([
                ':id' => $id,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':user' => $_SESSION['user_id']
            ]);
            
            $message = "Stock supprimé avec succès !";
            $message_type = "danger";
        }
    } catch(PDOException $e) {
        $message = "Erreur lors de la suppression : " . $e->getMessage();
        $message_type = "danger";
    }
}

// ===== SUSPENSION / RÉACTIVATION D'UN STOCK =====
if (isset($_GET['action']) && in_array($_GET['action'], ['suspendre', 'reactiver']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        // Vérifier que le stock appartient bien à cette pharmacie
        $check = $pdo->prepare("
            SELECT s.Statut, s.Quantite, s.Seuil_alerte, m.Nom_commercial
            FROM stock s
            JOIN medicament m ON s.Id_medicament = m.Id_medicament
            WHERE s.Id_stock = :id AND s.Id_pharmacie = :pharmacie
        ");
        $check->execute([':id' => $id, ':pharmacie' => $pharmacie_id]);
        $stock = $check->fetch();

        if (!$stock) {
            $message = "Stock introuvable pour cette pharmacie.";
            $message_type = "danger";
        } elseif ($_GET['action'] === 'suspendre' && $stock['Statut'] === 'desactive') {
            $message = "Ce stock est déjà suspendu.";
            $message_type = "warning";
        } elseif ($_GET['action'] === 'reactiver' && $stock['Statut'] !== 'desactive') {
            $message = "Ce stock n'est pas suspendu.";
            $message_type = "warning";
        } else {
            if ($_GET['action'] === 'suspendre') {
                $stmt = $pdo->prepare("UPDATE stock SET Statut = 'desactive', Date_mise_a_jour = NOW() WHERE Id_stock = :id AND Id_pharmacie = :pharmacie");
                $stmt->execute([':id' => $id, ':pharmacie' => $pharmacie_id]);

                // Annuler les réservations en cours (en attente ou confirmées)
                // liées à ce stock et notifier les patients concernés.
                $nb_annulations = 0;
                $res = $pdo->prepare("
                    SELECT r.Id_reservation, r.Quantite, r.Id_utilisateur
                    FROM reservation r
                    WHERE r.Id_stock = :id AND r.Statut IN ('en_attente', 'confirmee')
                ");
                $res->execute([':id' => $id]);
                $reservations_a_annuler = $res->fetchAll();

                if (!empty($reservations_a_annuler)) {
                    $maj = $pdo->prepare("UPDATE reservation SET Statut = 'annulee' WHERE Id_reservation = :rid");
                    $notif = $pdo->prepare("
                        INSERT INTO notification (Titre, Message, Type, Lu, Id_utilisateur)
                        VALUES ('Réservation annulée', :message, 'annulation', 0, :user)
                    ");
                    foreach ($reservations_a_annuler as $rv) {
                        $maj->execute([':rid' => $rv['Id_reservation']]);
                        $notif->execute([
                            ':message' => 'Votre réservation pour « ' . $stock['Nom_commercial'] . ' » (x' . $rv['Quantite'] . ') '
                                        . 'a été annulée car la pharmacie a suspendu ce médicament.',
                            ':user' => $rv['Id_utilisateur']
                        ]);
                        $nb_annulations++;
                    }
                }

                $log = $pdo->prepare("
                    INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur)
                    VALUES (CONCAT('Suspension stock ID: ', :id, ' (', :nb, ' réservation(s) annulée(s))'), :ip, :ua, 'succes', :user)
                ");
                $log->execute([
                    ':id' => $id,
                    ':nb' => $nb_annulations,
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    ':user' => $_SESSION['user_id']
                ]);

                $message = "Stock de « " . $stock['Nom_commercial'] . " » suspendu avec succès !";
                if ($nb_annulations > 0) {
                    $message .= " " . $nb_annulations . " réservation(s) en cours ont été annulées et les patients ont été notifiés.";
                }
                $message_type = "success";
            } else {
                // Réactivation : le statut est recalculé selon la quantité restante
                $statut = 'disponible';
                if ($stock['Quantite'] == 0) {
                    $statut = 'rupture';
                } elseif ($stock['Quantite'] <= $stock['Seuil_alerte']) {
                    $statut = 'stock_faible';
                }

                $stmt = $pdo->prepare("UPDATE stock SET Statut = :statut, Date_mise_a_jour = NOW() WHERE Id_stock = :id AND Id_pharmacie = :pharmacie");
                $stmt->execute([':statut' => $statut, ':id' => $id, ':pharmacie' => $pharmacie_id]);

                $log = $pdo->prepare("
                    INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur)
                    VALUES (CONCAT('Réactivation stock ID: ', :id), :ip, :ua, 'succes', :user)
                ");
                $log->execute([
                    ':id' => $id,
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    ':user' => $_SESSION['user_id']
                ]);

                $message = "Stock de « " . $stock['Nom_commercial'] . " » réactivé avec succès !";
                $message_type = "success";
            }
        }
    } catch(PDOException $e) {
        $message = "Erreur lors de la suspension/réactivation : " . $e->getMessage();
        $message_type = "danger";
    }
}

// ===== RÉCUPÉRATION DES DONNÉES =====
try {
    // Statistiques calculées sur l'INTÉGRALITÉ du stock de la pharmacie
    // (indispensable : elles doivent rester justes malgré la pagination)
    $stmt_stats = $pdo->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN Statut = 'disponible' THEN 1 ELSE 0 END) as disponibles,
               SUM(CASE WHEN Statut = 'stock_faible' THEN 1 ELSE 0 END) as stock_faible,
               SUM(CASE WHEN Statut = 'rupture' THEN 1 ELSE 0 END) as rupture,
               COALESCE(SUM(Quantite), 0) as total_unites,
               COALESCE(SUM(Quantite * Prix), 0) as valeur_stock
        FROM stock
        WHERE Id_pharmacie = :pharmacie
    ");
    $stmt_stats->execute([':pharmacie' => $pharmacie_id]);
    $stats = $stmt_stats->fetch() ?: [];
    $stats = array_map(function($v) { return (int) $v; }, $stats);

    // Nombre total de stocks (sert aussi à la pagination)
    $pagination = pagination_calculer($stats['total']);

    // Récupérer les stocks de la page courante avec leurs informations
    $stocks = $pdo->prepare("
        SELECT s.*, m.Nom_commercial, m.DCI, m.Forme, m.Dosage, m.Description,
               c.Libelle as Categorie,
               (SELECT COUNT(*) FROM reservation WHERE Id_stock = s.Id_stock AND Statut = 'en_attente') as reservations_attente,
               (SELECT COUNT(*) FROM reservation WHERE Id_stock = s.Id_stock) as total_reservations
        FROM stock s
        JOIN medicament m ON s.Id_medicament = m.Id_medicament
        LEFT JOIN categorie c ON m.Id_categorie = c.Id_categorie
        WHERE s.Id_pharmacie = :pharmacie
        ORDER BY m.Nom_commercial ASC
    " . pagination_sql_limite($pagination));
    $stocks->execute([':pharmacie' => $pharmacie_id]);
    $stocks = $stocks->fetchAll();

    // Historique des mouvements de stock (derniers 20)
    $historique = $pdo->prepare("
        SELECT hs.*, m.Nom_commercial, u.Nom, u.Prenom
        FROM historique_stock hs
        JOIN stock s ON hs.Id_stock = s.Id_stock
        JOIN medicament m ON s.Id_medicament = m.Id_medicament
        JOIN utilisateur u ON hs.Id_utilisateur = u.Id_utilisateur
        WHERE s.Id_pharmacie = :pharmacie
        ORDER BY hs.Date_operation DESC
        LIMIT 20
    ");
    $historique->execute([':pharmacie' => $pharmacie_id]);
    $historique = $historique->fetchAll();

    // Alertes stock : comptées sur l'ensemble du stock, pas sur la page affichée
    $ruptures = $stats['rupture'];
    $faibles  = $stats['stock_faible'];

} catch(PDOException $e) {
    $stocks = [];
    $historique = [];
    $ruptures = 0;
    $faibles = 0;
    $pagination = pagination_calculer(0);
    $stats = ['total' => 0, 'disponibles' => 0, 'stock_faible' => 0, 'rupture' => 0, 'total_unites' => 0, 'valeur_stock' => 0];
    $message = "Erreur lors du chargement des données : " . $e->getMessage();
    $message_type = "danger";
}

// ===== STATUTS POUR AFFICHAGE =====
$statuts_labels = [
    'disponible' => ['label' => 'Disponible', 'class' => 'disponible', 'icon' => 'fa-check-circle'],
    'stock_faible' => ['label' => 'Stock faible', 'class' => 'stock_faible', 'icon' => 'fa-exclamation-triangle'],
    'rupture' => ['label' => 'Rupture', 'class' => 'rupture', 'icon' => 'fa-times-circle'],
    'en_approvisionnement' => ['label' => 'En approvisionnement', 'class' => 'en_approvisionnement', 'icon' => 'fa-truck'],
    'desactive' => ['label' => 'Désactivé', 'class' => 'desactive', 'icon' => 'fa-ban']
];

$page_title = 'Gestion des stocks - PharmaGeo';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        /* ===== VARIABLES ===== */
        :root {
            --primary-green: #16A34A;
            --primary-orange: #166534;
            --white: #ffffff;
            --light-bg: #F0FDF4;
            --dark-text: #1F2937;
            --gray-text: #64748B;
            --shadow-sm: 0 2px 20px rgba(0,0,0,0.04);
            --shadow-md: 0 8px 40px rgba(0,0,0,0.08);
            --shadow-lg: 0 20px 60px rgba(0,0,0,0.12);
            --shadow-glow-green: 0 8px 40px rgba(22,163,74, 0.20);
            --shadow-glow-orange: 0 8px 40px rgba(21,128,61, 0.20);
            --radius: 16px;
            --radius-lg: 24px;
            --transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        /* ===== BASE ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--light-bg);
            color: var(--dark-text);
            overflow-x: hidden;
        }

        /* ===== SCROLLBAR ===== */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: var(--light-bg); }
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            border-radius: 10px;
        }

        /* ===== ANIMATIONS ===== */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .animate-fade-up { animation: fadeInUp 0.6s ease-out forwards; }
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }

        /* ===== NAVBAR ===== */
        .navbar-custom {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 2px 30px rgba(0,0,0,0.06);
            padding: 12px 0;
            transition: var(--transition);
            border-bottom: 1px solid rgba(0,0,0,0.02);
        }

        .navbar-custom.scrolled {
            box-shadow: 0 4px 40px rgba(0,0,0,0.1);
            padding: 8px 0;
        }

        .navbar-brand-custom {
            font-weight: 800;
            font-size: 1.6rem;
            color: var(--dark-text);
            letter-spacing: -0.5px;
        }

        .navbar-brand-custom .brand-icon {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            padding: 8px 12px;
            border-radius: 12px;
            color: white;
            margin-right: 10px;
            box-shadow: var(--shadow-glow-green);
        }

        .navbar-brand-custom .brand-text {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .nav-link-custom {
            font-weight: 500;
            color: var(--dark-text);
            padding: 8px 18px !important;
            border-radius: 12px;
            transition: var(--transition);
            position: relative;
        }

        .nav-link-custom:hover,
        .nav-link-custom.active {
            color: var(--primary-green);
            background: rgba(22,163,74,0.08);
        }

        .nav-link-custom::after {
            content: '';
            position: absolute;
            bottom: 2px;
            left: 50%;
            width: 0;
            height: 3px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            border-radius: 10px;
            transition: var(--transition);
            transform: translateX(-50%);
        }

        .nav-link-custom:hover::after,
        .nav-link-custom.active::after {
            width: 60%;
        }

        .btn-nav-logout {
            background: linear-gradient(135deg, #DC2626, #B91C1C);
            color: white !important;
            padding: 8px 24px !important;
            border-radius: 50px !important;
            font-weight: 600;
            transition: var(--transition);
            border: none;
            box-shadow: 0 4px 15px rgba(220,38,38,0.2);
        }

        .btn-nav-logout:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(220,38,38,0.35);
            color: white !important;
        }

        /* ===== LAYOUT / SIDEBAR ===== */
        .layout { display: flex; min-height: 100vh; }
        .sidebar-admin {
            width: 250px; background: linear-gradient(180deg, #0F2E19, #14532D);
            color: #fff; flex-shrink: 0; display: flex; flex-direction: column;
            position: fixed; top: 0; bottom: 0; left: 0; overflow-y: auto; z-index: 100;
        }
        .brand-admin { padding: 22px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .brand-admin .brand-icon { width: 40px; height: 40px; border-radius: 12px; background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); display: flex; align-items: center; justify-content: center;  color: #fff;}
        .brand-admin h4 { margin: 0; font-weight: 800; font-size: 1.1rem; color: #fff; 
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            color: #fff;
        }
        
        .brand-admin small { color: rgba(255,255,255,0.6); display: block; }
        .nav-menu { padding: 16px 12px; flex: 1; }
        .nav-label { color: rgba(255,255,255,0.4); font-size: 0.72rem; text-transform: uppercase; letter-spacing: 1px; padding: 12px 12px 6px; }
        .nav-item { display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.75); text-decoration: none; padding: 11px 14px; border-radius: 10px; margin-bottom: 2px; font-size: 0.92rem; transition: all 0.25s ease; }
        .nav-item i { width: 20px; text-align: center; }
        .nav-item:hover { background: rgba(255,255,255,0.08); color: #fff; }
        .nav-item.active { background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); color: #fff; font-weight: 600; }
        .user-profile { padding: 16px; border-top: 1px solid rgba(255,255,255,0.08); display: flex; align-items: center; gap: 12px; }
        .user-profile .avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); display: flex; align-items: center; justify-content: center; font-weight: 800; }
        .user-profile .name { font-size: 0.9rem; font-weight: 600; }
        .user-profile .role { font-size: 0.75rem; color: rgba(255,255,255,0.6); }
        a.user-profile { text-decoration: none; color: inherit; transition: background-color 0.2s ease; }
        a.user-profile:hover { background-color: rgba(255,255,255,0.07); }
        a.user-profile .profile-chevron { margin-left: auto; font-size: 0.7rem; color: rgba(255,255,255,0.45); }
        a.user-profile:hover .profile-chevron { color: #fff; }
        @media (max-width: 991px) {
            .sidebar-admin { position: fixed; transform: translateX(-100%); transition: transform 0.3s ease; }
            .sidebar-admin.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
        }

        /* ===== MAIN CONTENT ===== */
        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 24px 30px 30px;
            min-height: 100vh;
        }

        /* ===== TOP BAR ===== */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .top-bar .page-title h1 {
            font-size: 1.6rem;
            font-weight: 800;
            margin: 0;
        }

        .top-bar .page-title h1 .highlight {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .top-bar .page-title p {
            color: var(--gray-text);
            margin: 0;
            font-size: 0.9rem;
        }

        .top-bar .page-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .top-bar .page-actions .btn-action {
            padding: 10px 20px;
            border-radius: 12px;
            border: none;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            text-decoration: none;
        }

        .top-bar .page-actions .btn-action.btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white;
            box-shadow: var(--shadow-glow-green);
        }

        .top-bar .page-actions .btn-action.btn-primary-custom:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 40px rgba(22,163,74,0.35);
        }

        .top-bar .page-actions .btn-action.btn-outline-custom {
            border: 2px solid var(--primary-green);
            color: var(--primary-green);
            background: transparent;
        }

        .top-bar .page-actions .btn-action.btn-outline-custom:hover {
            background: var(--primary-green);
            color: white;
            transform: translateY(-3px);
        }

        /* ===== STATS CARDS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 18px 20px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            border: 1px solid rgba(0,0,0,0.02);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            opacity: 0;
            transition: var(--transition);
        }

        .stat-card:hover::before { opacity: 1; }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
        }

        .stat-card .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .stat-card .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .stat-card .stat-icon.green {
            background: rgba(22,163,74,0.1);
            color: var(--primary-green);
        }

        .stat-card .stat-icon.orange {
            background: rgba(21,128,61,0.1);
            color: var(--primary-orange);
        }

        .stat-card .stat-icon.blue {
            background: rgba(22,83,52,0.1);
            color: #166534;
        }

        .stat-card .stat-icon.red {
            background: rgba(220,38,38,0.1);
            color: #DC2626;
        }

        .stat-card .stat-icon.purple {
            background: rgba(155,89,182,0.1);
            color: #1F2937;
        }

        .stat-card .stat-number {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--dark-text);
            line-height: 1.2;
        }

        .stat-card .stat-label {
            color: var(--gray-text);
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* ===== TABLE ===== */
        .table-container {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 20px;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            border: 1px solid rgba(0,0,0,0.02);
        }

        .table-custom {
            margin: 0;
        }

        .table-custom thead th {
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-text);
            border-bottom: 2px solid #F0FDF4;
            padding: 12px 10px;
        }

        .table-custom tbody td {
            padding: 14px 10px;
            vertical-align: middle;
            border-bottom: 1px solid #F0FDF4;
            font-size: 0.9rem;
        }

        .table-custom tbody tr:hover {
            background: rgba(22,163,74,0.02);
        }

        /* ===== BADGES ===== */
        .badge-status {
            padding: 4px 14px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-status.disponible {
            background: rgba(22,163,74,0.1);
            color: var(--primary-green);
        }

        .badge-status.stock_faible {
            background: rgba(21,128,61,0.1);
            color: var(--primary-orange);
        }

        .badge-status.rupture {
            background: rgba(220,38,38,0.1);
            color: #DC2626;
        }

        .badge-status.en_approvisionnement {
            background: rgba(22,83,52,0.1);
            color: #166534;
        }

        .badge-status.desactive {
            background: rgba(108,117,125,0.1);
            color: #64748B;
        }

        /* ===== BTN ACTION ===== */
        .btn-action-sm {
            padding: 6px 12px;
            border-radius: 8px;
            border: none;
            font-size: 0.75rem;
            font-weight: 600;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-action-sm.btn-edit {
            background: rgba(21,128,61,0.1);
            color: var(--primary-orange);
        }

        .btn-action-sm.btn-edit:hover {
            background: var(--primary-orange);
            color: white;
        }

        .btn-action-sm.btn-delete {
            background: rgba(220,38,38,0.1);
            color: #DC2626;
        }

        .btn-action-sm.btn-delete:hover {
            background: #DC2626;
            color: white;
        }

        .btn-action-sm.btn-supply {
            background: rgba(22,83,52,0.1);
            color: #166534;
        }

        .btn-action-sm.btn-supply:hover {
            background: #166534;
            color: white;
        }

        .btn-action-sm.btn-suspend {
            background: rgba(200,150,0,0.12);
            color: #B91C1C;
        }

        .btn-action-sm.btn-suspend:hover {
            background: #B91C1C;
            color: white;
        }

        .btn-action-sm.btn-reactiver {
            background: rgba(22,83,52,0.1);
            color: #166534;
        }

        .btn-action-sm.btn-reactiver:hover {
            background: #166534;
            color: white;
        }

        /* ===== ALERT ===== */
        .alert-custom {
            border-radius: 14px;
            padding: 14px 18px;
            border: none;
            font-weight: 500;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 20px;
        }

        .alert-custom i { font-size: 1.2rem; margin-top: 2px; }

        .alert-custom-success {
            background: rgba(22,163,74,0.08);
            color: var(--primary-green);
            border-left: 4px solid var(--primary-green);
        }

        .alert-custom-danger {
            background: rgba(220,38,38,0.08);
            color: #DC2626;
            border-left: 4px solid #DC2626;
        }

        .alert-custom-warning {
            background: rgba(21,128,61,0.08);
            color: var(--primary-orange);
            border-left: 4px solid var(--primary-orange);
        }

        .alert-custom-info {
            background: rgba(22,83,52,0.08);
            color: #166534;
            border-left: 4px solid #166534;
        }

        /* ===== MODAL ===== */
        .modal-content-custom {
            border-radius: var(--radius-lg);
            border: none;
            box-shadow: 0 30px 80px rgba(0,0,0,0.2);
        }

        .modal-header-custom {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            padding: 20px 24px;
        }

        .modal-header-custom .btn-close {
            filter: brightness(0) invert(1);
        }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 16px;
        }

        .empty-state h5 {
            font-weight: 700;
            color: var(--dark-text);
        }

        .empty-state p {
            color: var(--gray-text);
            max-width: 400px;
            margin: 0 auto;
        }

        /* ===== ALERT CARD ===== */
        .alert-card {
            background: rgba(220,38,38,0.05);
            border-left: 4px solid #DC2626;
            border-radius: var(--radius);
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .alert-card.warning {
            background: rgba(21,128,61,0.05);
            border-left-color: var(--primary-orange);
        }

        /* ===== HISTORIQUE ===== */
        .historique-item {
            padding: 10px 0;
            border-bottom: 1px solid #F0FDF4;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .historique-item:last-child {
            border-bottom: none;
        }

        .historique-item .h-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .historique-item .h-icon.ajout {
            background: rgba(22,163,74,0.1);
            color: var(--primary-green);
        }

        .historique-item .h-icon.mise_a_jour {
            background: rgba(21,128,61,0.1);
            color: var(--primary-orange);
        }

        .historique-item .h-icon.approvisionnement {
            background: rgba(22,83,52,0.1);
            color: #166534;
        }

        .historique-item .h-content {
            flex: 1;
        }

        .historique-item .h-content .h-text {
            font-size: 0.9rem;
            margin: 0;
        }

        .historique-item .h-content .h-time {
            font-size: 0.75rem;
            color: var(--gray-text);
        }

        .historique-item .h-quantite {
            font-weight: 700;
            font-size: 0.9rem;
            white-space: nowrap;
        }

        .historique-item .h-quantite.positive {
            color: var(--primary-green);
        }

        .historique-item .h-quantite.negative {
            color: #DC2626;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .main-content { padding: 15px; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .top-bar { flex-direction: column; align-items: stretch; }
            .top-bar .page-actions { flex-wrap: wrap; }
            .top-bar .page-actions .btn-action { font-size: 0.8rem; padding: 8px 16px; }
            .table-container { padding: 12px; overflow-x: auto; }
            .table-custom { font-size: 0.8rem; min-width: 700px; }
            .table-custom thead th,
            .table-custom tbody td { padding: 8px 6px; }
            .alert-card { flex-direction: column; text-align: center; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 10px; }
            .top-bar .page-title h1 { font-size: 1.2rem; }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card { padding: 12px; }
            .stat-card .stat-number { font-size: 1.3rem; }
            .stat-card .stat-label { font-size: 0.7rem; }
            .stat-card .stat-icon { width: 32px; height: 32px; font-size: 0.9rem; }
            .navbar-brand-custom { font-size: 1.3rem; }
        }

        /* ===== FORM ===== */
        .form-modern .form-group { margin-bottom: 18px; }

        .form-modern .form-label {
            font-weight: 600;
            color: var(--dark-text);
            font-size: 0.9rem;
            margin-bottom: 6px;
        }

        .form-modern .form-label .required {
            color: #DC2626;
            margin-left: 2px;
        }

        .form-modern .input-group-custom {
            position: relative;
        }

        .form-modern .input-group-custom .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-text);
            font-size: 1rem;
            transition: var(--transition);
            z-index: 3;
        }

        .form-modern .input-group-custom .form-control {
            padding: 12px 16px 12px 44px;
            border: 2px solid #D9E9DF;
            border-radius: 14px;
            font-size: 0.95rem;
            transition: var(--transition);
            background: #F0FDF4;
            color: var(--dark-text);
        }

        .form-modern .input-group-custom .form-control:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 4px rgba(22,163,74,0.1);
            background: var(--white);
        }

        /* ===== SECTION CARD ===== */
        .section-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-sm);
            border: 1px solid rgba(0,0,0,0.02);
            transition: var(--transition);
        }

        .section-card:hover {
            box-shadow: var(--shadow-md);
        }

        .section-card .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .section-card .section-header h5 {
            font-weight: 700;
            margin: 0;
            font-size: 1.1rem;
        }

        .section-card .section-header h5 i {
            margin-right: 8px;
            color: var(--primary-green);
        }
    </style>
    <link rel="stylesheet" href="../assets/css/responsive.css">    <!-- Palette verte PharmaGeo -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/palette.css">
    <!-- Favicon & identité visuelle PharmaGéo -->
    <link rel="icon" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon.ico" sizes="any">
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo BASE_URL; ?>assets/identite/favicons/apple-touch-icon.png">
    <link rel="manifest" href="<?php echo BASE_URL; ?>assets/identite/favicons/site.webmanifest">
    <meta property="og:image" content="<?php echo BASE_URL; ?>assets/identite/reseaux-sociaux/og-image-1200x630.png">
</head>
<body>

    <div class="layout">

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar-admin" id="sidebar">
        <a href="<?php echo BASE_URL; ?>index.php" class="brand-admin" style="text-decoration: none;">
            <div class="brand-icon"><img class="pg-brand-logo" src="<?php echo BASE_URL; ?>assets/identite/icones/pharmageo-icone-blanc.svg" alt=""></div>
            <div><h4>PharmaGeo</h4><small><?php echo htmlspecialchars(pharma_libelle_role('pharmacien', $user['Prenom'] ?? '')); ?></small></div>
        </a>
        <nav class="nav-menu">
            <div class="nav-label">Navigation</div>
            <a href="<?php echo BASE_URL; ?>pharmacien/dashboard.php" class="nav-item"><i class="fas fa-tachometer-alt"></i><span>Tableau de bord</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/pharmacie.php" class="nav-item"><i class="fas fa-store"></i><span>Ma pharmacie</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/medicaments.php" class="nav-item"><i class="fas fa-capsules"></i><span>Médicaments</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/stocks.php" class="nav-item active"><i class="fas fa-boxes"></i><span>Stocks</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/historique_stocks.php" class="nav-item"><i class="fas fa-history"></i><span>Historique</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/reservations.php" class="nav-item"><i class="fas fa-calendar-check"></i><span>Réservations</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/statistiques.php" class="nav-item"><i class="fas fa-chart-bar"></i><span>Statistiques</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/profil.php" class="nav-item"><i class="fas fa-user"></i><span>Profil</span></a>
            <div class="nav-label" style="margin-top: 20px;">Système</div>
            <?php require_once __DIR__ . '/../includes/reinitialiser.php'; echo html_bloc_reinitialiser(); ?>
            <a href="<?php echo BASE_URL; ?>deconnexion.php" class="nav-item" style="color: #FEF2F2; margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.15); margin-bottom: 12px;"><i class="fas fa-sign-out-alt"></i><span>Déconnexion</span></a>
        </nav>
        <a class="user-profile" href="<?php echo BASE_URL; ?>pharmacien/profil.php" title="Voir mon profil">
            <div class="avatar"><?php if (!empty($_SESSION['photo']) && function_exists('pharma_image_disponible') && pharma_image_disponible($_SESSION['photo'])): ?><img src="<?php echo htmlspecialchars(pharma_image_url($_SESSION['photo'])); ?>" alt=""><?php else: ?><?php echo strtoupper(substr($_SESSION['prenom'] ?? 'A', 0, 1) . substr($_SESSION['nom'] ?? 'D', 0, 1)); ?><?php endif; ?></div>
            <div>
                <div class="name"><?php echo htmlspecialchars(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')); ?></div>
                <div class="role"><i class="fas fa-user-md me-1" style="color:#DC2626;"></i> <?php echo htmlspecialchars(pharma_libelle_role('pharmacien', $user['Prenom'] ?? '')); ?></div>
            </div>
            <i class="fas fa-chevron-right profile-chevron"></i>
        </a>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">

        <!-- ===== TOP BAR ===== -->
        <div class="top-bar animate-fade-up">
            <div class="page-title">
                <h1>Gestion des <span class="highlight">stocks</span></h1>
                <p>
                    <i class="fas fa-boxes me-1" style="color: var(--primary-green);"></i>
                    Gérez les stocks de votre pharmacie
                </p>
            </div>
            <div class="page-actions">
                <a href="<?php echo BASE_URL; ?>pharmacien/medicaments.php" class="btn-action btn-primary-custom">
                    <i class="fas fa-plus"></i> Ajouter un médicament
                </a>
            </div>
        </div>

        <!-- ===== STATS ===== -->
        <div class="stats-grid animate-fade-up delay-1">
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">Total références</div>
                    </div>
                    <div class="stat-icon blue"><i class="fas fa-boxes"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['disponibles']; ?></div>
                        <div class="stat-label">Disponibles</div>
                    </div>
                    <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['stock_faible']; ?></div>
                        <div class="stat-label">Stock faible</div>
                    </div>
                    <div class="stat-icon orange"><i class="fas fa-exclamation-triangle"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['rupture']; ?></div>
                        <div class="stat-label">En rupture</div>
                    </div>
                    <div class="stat-icon red"><i class="fas fa-times-circle"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['total_unites']; ?></div>
                        <div class="stat-label">Total unités</div>
                    </div>
                    <div class="stat-icon purple"><i class="fas fa-cubes"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo number_format($stats['valeur_stock'], 0, ',', ' '); ?> F</div>
                        <div class="stat-label">Valeur du stock</div>
                    </div>
                    <div class="stat-icon purple"><i class="fas fa-money-bill-wave"></i></div>
                </div>
            </div>
        </div>

        <!-- ===== ALERTES ===== -->
        <?php if ($ruptures > 0 || $faibles > 0): ?>
            <div class="alert-card <?php echo $ruptures > 0 ? '' : 'warning'; ?> animate-fade-up delay-2">
                <div>
                    <i class="fas fa-<?php echo $ruptures > 0 ? 'exclamation-circle' : 'exclamation-triangle'; ?> me-2" 
                       style="color: <?php echo $ruptures > 0 ? '#DC2626' : 'var(--primary-orange)'; ?>;"></i>
                    <strong>Alertes stock :</strong>
                    <?php 
                        $messages = [];
                        if ($ruptures > 0) $messages[] = "$ruptures en rupture";
                        if ($faibles > 0) $messages[] = "$faibles en stock faible";
                        echo implode(' - ', $messages);
                    ?>
                </div>
                <a href="#stock-list" class="btn btn-sm btn-outline-danger" style="border-radius: 50px; padding: 4px 16px; text-decoration: none;">Voir les alertes</a>
            </div>
        <?php endif; ?>

        <!-- ===== MESSAGES ===== -->
        <?php if (!empty($message)): ?>
            <div class="alert-custom alert-custom-<?php echo $message_type; ?> animate-fade-up">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'exclamation-triangle' : 'exclamation-circle'); ?>"></i>
                <div><?php echo $message; ?></div>
            </div>
        <?php endif; ?>

        <!-- ===== LISTE DES STOCKS ===== -->
        <div id="stock-list" class="table-container animate-fade-up delay-3">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <h5 class="fw-bold mb-0"><i class="fas fa-list me-2"></i>Inventaire des stocks</h5>
                <span class="text-muted small"><?php echo count($stocks); ?> référence(s)</span>
            </div>
            <?php if (!empty($stocks)): ?>
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead>
                            <tr>
                                <th>Médicament</th>
                                <th>Quantité</th>
                                <th>Prix</th>
                                <th>Seuil</th>
                                <th>Statut</th>
                                <th>Réservations</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stocks as $s): 
                                $statut = $statuts_labels[$s['Statut']] ?? ['label' => $s['Statut'], 'class' => '', 'icon' => 'fa-circle'];
                                $is_alert = $s['Statut'] === 'stock_faible' || $s['Statut'] === 'rupture';
                            ?>
                                <tr class="<?php echo $is_alert ? 'table-warning' : ''; ?>">
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($s['Nom_commercial']); ?></strong>
                                            <?php if ($s['Dosage']): ?>
                                                <small class="text-muted d-block"><?php echo htmlspecialchars($s['Dosage']); ?></small>
                                            <?php endif; ?>
                                            <?php if ($s['DCI']): ?>
                                                <small class="text-muted d-block"><?php echo htmlspecialchars($s['DCI']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="fw-bold <?php echo $s['Quantite'] <= $s['Seuil_alerte'] && $s['Quantite'] > 0 ? 'text-warning' : ($s['Quantite'] == 0 ? 'text-danger' : ''); ?>">
                                            <?php echo $s['Quantite']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $s['Prix'] ? number_format($s['Prix'], 0, ',', ' ') . ' F' : '-'; ?>
                                    </td>
                                    <td><?php echo $s['Seuil_alerte']; ?></td>
                                    <td>
                                        <span class="badge-status <?php echo $statut['class']; ?>">
                                            <i class="fas <?php echo $statut['icon']; ?>"></i>
                                            <?php echo $statut['label']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($s['total_reservations'] > 0): ?>
                                            <span class="badge <?php echo $s['reservations_attente'] > 0 ? 'bg-warning' : 'bg-secondary'; ?> text-dark">
                                                <?php echo $s['total_reservations']; ?> 
                                                <?php if ($s['reservations_attente'] > 0): ?>
                                                    (<?php echo $s['reservations_attente']; ?> en attente)
                                                <?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex gap-1 justify-content-end flex-wrap">
                                            <button class="btn-action-sm btn-supply" data-bs-toggle="modal" data-bs-target="#supplyModal<?php echo $s['Id_stock']; ?>" title="Approvisionner le stock d'un médicament">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                            <button class="btn-action-sm btn-edit" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $s['Id_stock']; ?>" title="Modifier le stock (quantité, prix, seuil, statut)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($s['Statut'] === 'desactive'): ?>
                                                <a href="<?php echo BASE_URL; ?>pharmacien/stocks.php?action=reactiver&id=<?php echo $s['Id_stock']; ?>"
                                                   class="btn-action-sm btn-reactiver"
                                                   title="Réactiver ce stock (le rendre de nouveau disponible)"
                                                   onclick="return confirmReactivate(<?php echo $s['Id_stock']; ?>, '<?php echo addslashes($s['Nom_commercial']); ?>')">
                                                    <i class="fas fa-play"></i>
                                                </a>
                                            <?php else: ?>
                                                <a href="<?php echo BASE_URL; ?>pharmacien/stocks.php?action=suspendre&id=<?php echo $s['Id_stock']; ?>"
                                                   class="btn-action-sm btn-suspend"
title="Suspendre ce stock (le masquer aux patients)"
                                                   onclick="return confirmSuspend(<?php echo $s['Id_stock']; ?>, '<?php echo addslashes($s['Nom_commercial']); ?>')">
                                                <i class="fas fa-pause"></i>
                                            </a>
                                            <?php endif; ?>
                                            <a href="<?php echo BASE_URL; ?>pharmacien/stocks.php?action=supprimer&id=<?php echo $s['Id_stock']; ?>" 
                                               class="btn-action-sm btn-delete"
                                               title="Supprimer définitivement ce stock"
                                               onclick="return confirmDelete(<?php echo $s['Id_stock']; ?>, '<?php echo addslashes($s['Nom_commercial']); ?>', <?php echo $s['reservations_attente']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ===== PAGINATION ===== -->
                <?php pagination_afficher($pagination, [], 'stocks'); ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-boxes"></i>
                    <h5>Aucun stock disponible</h5>
                    <p>Commencez par ajouter des médicaments à votre pharmacie.</p>
                    <a href="<?php echo BASE_URL; ?>pharmacien/medicaments.php" class="btn btn-primary-custom mt-3" style="padding: 10px 28px; border-radius: 50px; text-decoration: none; display: inline-block;">
                        <i class="fas fa-plus me-1"></i> Ajouter un médicament
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- ===== HISTORIQUE ===== -->
        <div class="section-card animate-fade-up delay-4" style="margin-top: 24px;">
            <div class="section-header">
                <h5><i class="fas fa-history me-2"></i>Historique des mouvements</h5>
                <span class="text-muted small">Derniers 20 mouvements</span>
            </div>
            <?php if (!empty($historique)): ?>
                <div class="historique-list">
                    <?php foreach ($historique as $h): 
                        $type_class = $h['Type_operation'] === 'ajout' ? 'ajout' : ($h['Type_operation'] === 'approvisionnement' ? 'approvisionnement' : 'mise_a_jour');
                        $icone = $h['Type_operation'] === 'ajout' ? 'fa-plus-circle' : ($h['Type_operation'] === 'approvisionnement' ? 'fa-truck' : 'fa-edit');
                        $diff = $h['Nouvelle_quantite'] - $h['Ancienne_quantite'];
                        $is_positive = $diff > 0;
                    ?>
                        <div class="historique-item">
                            <div class="h-icon <?php echo $type_class; ?>">
                                <i class="fas <?php echo $icone; ?>"></i>
                            </div>
                            <div class="h-content">
                                <p class="h-text">
                                    <strong><?php echo htmlspecialchars($h['Nom_commercial']); ?></strong>
                                    <?php 
                                        $types = [
                                            'ajout' => 'ajouté au stock',
                                            'mise_a_jour' => 'mis à jour',
                                            'approvisionnement' => 'approvisionné'
                                        ];
                                        echo $types[$h['Type_operation']] ?? $h['Type_operation'];
                                    ?>
                                    par <strong><?php echo htmlspecialchars($h['Prenom'] . ' ' . $h['Nom']); ?></strong>
                                </p>
                                <div class="h-time">
                                    <i class="far fa-clock me-1"></i>
                                    <?php echo date('d/m/Y à H:i', strtotime($h['Date_operation'])); ?>
                                </div>
                            </div>
                            <div class="h-quantite <?php echo $is_positive ? 'positive' : 'negative'; ?>">
                                <?php echo $h['Ancienne_quantite']; ?> → <?php echo $h['Nouvelle_quantite']; ?>
                                <?php if ($diff != 0): ?>
                                    <span class="d-block small">(<?php echo $is_positive ? '+' : ''; ?><?php echo $diff; ?>)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state" style="padding: 20px;">
                    <i class="fas fa-history"></i>
                    <h6>Aucun historique</h6>
                    <p class="text-muted">Les mouvements de stock apparaîtront ici.</p>
                </div>
            <?php endif; ?>
        </div>

    </main>

    </div>

    <!-- ===== MODALS ÉDITION ===== -->
    <?php foreach ($stocks as $s): ?>
    <div class="modal fade" id="editModal<?php echo $s['Id_stock']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content modal-content-custom">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-edit me-2"></i>Modifier le stock
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" class="p-4 form-modern">
                    <input type="hidden" name="action" value="update_stock">
                    <input type="hidden" name="id_stock" value="<?php echo $s['Id_stock']; ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Médicament</label>
                        <div class="input-group-custom">
                            <span class="input-icon"><i class="fas fa-capsules"></i></span>
                            <input type="text" class="form-control" 
                                   value="<?php echo htmlspecialchars($s['Nom_commercial']); ?>" disabled>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6 form-group">
                            <label for="quantite_<?php echo $s['Id_stock']; ?>" class="form-label">Quantité <span class="required">*</span></label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-box"></i></span>
                                <input type="number" class="form-control" id="quantite_<?php echo $s['Id_stock']; ?>" 
                                       name="quantite" value="<?php echo $s['Quantite']; ?>" min="0" required>
                            </div>
                        </div>
                        <div class="col-md-6 form-group">
                            <label for="prix_<?php echo $s['Id_stock']; ?>" class="form-label">Prix (FCFA)</label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-money-bill-wave"></i></span>
                                <input type="number" class="form-control" id="prix_<?php echo $s['Id_stock']; ?>" 
                                       name="prix" value="<?php echo $s['Prix'] ?? ''; ?>" min="0" step="100">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="seuil_alerte_<?php echo $s['Id_stock']; ?>" class="form-label">Seuil d'alerte</label>
                        <div class="input-group-custom">
                            <span class="input-icon"><i class="fas fa-bell"></i></span>
                            <input type="number" class="form-control" id="seuil_alerte_<?php echo $s['Id_stock']; ?>" 
                                   name="seuil_alerte" value="<?php echo $s['Seuil_alerte']; ?>" min="1">
                        </div>
                    </div>

                    <div class="mt-4 d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 50px; padding: 10px 28px;">Annuler</button>
                        <button type="submit" class="btn btn-primary-custom" style="border-radius: 50px; padding: 10px 28px;">
                            <i class="fas fa-save me-1"></i> Mettre à jour
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ===== MODAL APPROVISIONNEMENT ===== -->
    <div class="modal fade" id="supplyModal<?php echo $s['Id_stock']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content modal-content-custom">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-truck me-2"></i>Approvisionner le stock
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" class="p-4 form-modern">
                    <input type="hidden" name="action" value="approvisionner">
                    <input type="hidden" name="id_stock" value="<?php echo $s['Id_stock']; ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Médicament</label>
                        <div class="input-group-custom">
                            <span class="input-icon"><i class="fas fa-capsules"></i></span>
                            <input type="text" class="form-control" 
                                   value="<?php echo htmlspecialchars($s['Nom_commercial']); ?>" disabled>
                        </div>
                        <small class="text-muted">Quantité actuelle: <strong><?php echo $s['Quantite']; ?></strong></small>
                    </div>

                    <div class="form-group">
                        <label for="quantite_ajout_<?php echo $s['Id_stock']; ?>" class="form-label">Quantité à ajouter <span class="required">*</span></label>
                        <div class="input-group-custom">
                            <span class="input-icon"><i class="fas fa-plus-circle"></i></span>
                            <input type="number" class="form-control" id="quantite_ajout_<?php echo $s['Id_stock']; ?>" 
                                   name="quantite_ajout" placeholder="Quantité à ajouter" min="1" required>
                        </div>
                    </div>

                    <div class="mt-4 d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 50px; padding: 10px 28px;">Annuler</button>
                        <button type="submit" class="btn btn-primary-custom" style="border-radius: 50px; padding: 10px 28px;">
                            <i class="fas fa-save me-1"></i> Approvisionner
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- ===== SCRIPTS ===== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {

            // ===== SIDEBAR MOBILE TOGGLE (Ctrl + B) =====
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.key.toLowerCase() === 'b') {
                    document.getElementById('sidebar').classList.toggle('open');
                }
            });

            // ===== CONFIRMATION SUPPRESSION =====
            window.confirmDelete = function(id, nom, reservations) {
                event.preventDefault();
                let message = `Voulez-vous vraiment supprimer le stock de "${nom}" ?`;
                if (reservations > 0) {
                    message = `⚠️ Ce stock a ${reservations} réservation(s) en attente. La suppression est impossible.`;
                    Swal.fire({
                        title: '⚠️ Suppression impossible',
                        text: message,
                        icon: 'warning',
                        confirmButtonColor: '#64748B',
                        confirmButtonText: 'OK'
                    });
                    return false;
                }
                
                Swal.fire({
                    title: '⚠️ Supprimer le stock',
                    text: message + ' Cette action est irréversible.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#DC2626',
                    cancelButtonColor: '#64748B',
                    confirmButtonText: 'Oui, supprimer',
                    cancelButtonText: 'Annuler'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = `<?php echo BASE_URL; ?>pharmacien/stocks.php?action=supprimer&id=${id}`;
                    }
                });
                return false;
            };

            // ===== CONFIRMATION SUSPENSION / RÉACTIVATION =====
            window.confirmSuspend = function(id, nom) {
                event.preventDefault();
                Swal.fire({
                    title: '🛑 Suspendre le stock',
                    text: `Voulez-vous vraiment suspendre le stock de "${nom}" ? Il ne sera plus disponible pour les patients de cette pharmacie. Les réservations en cours seront annulées.`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#B91C1C',
                    cancelButtonColor: '#64748B',
                    confirmButtonText: 'Oui, suspendre',
                    cancelButtonText: 'Annuler'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = `<?php echo BASE_URL; ?>pharmacien/stocks.php?action=suspendre&id=${id}`;
                    }
                });
                return false;
            };

            window.confirmReactivate = function(id, nom) {
                event.preventDefault();
                Swal.fire({
                    title: 'Réactiver le stock',
                    text: `Voulez-vous réactiver le stock de "${nom}" ? Il redeviendra disponible pour les patients de cette pharmacie.`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#166534',
                    cancelButtonColor: '#64748B',
                    confirmButtonText: 'Oui, réactiver',
                    cancelButtonText: 'Annuler'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = `<?php echo BASE_URL; ?>pharmacien/stocks.php?action=reactiver&id=${id}`;
                    }
                });
                return false;
            };

            // ===== KEYBOARD SHORTCUTS =====
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const modal = document.querySelector('.modal.show');
                    if (modal) {
                        const closeBtn = modal.querySelector('.btn-close');
                        if (closeBtn) closeBtn.click();
                    }
                }
            });

            // ===== ANIMATION DES STATS =====
            function animateStats() {
                const statNumbers = document.querySelectorAll('.stat-number');
                statNumbers.forEach(stat => {
                    const target = parseInt(stat.textContent.replace(/[^0-9]/g, ''));
                    if (target > 0 && !isNaN(target)) {
                        const duration = 1200;
                        const start = 0;
                        const startTime = performance.now();
                        
                        function updateCounter(currentTime) {
                            const elapsed = currentTime - startTime;
                            const progress = Math.min(elapsed / duration, 1);
                            const eased = 1 - Math.pow(1 - progress, 3);
                            const current = Math.round(eased * target);
                            
                            stat.textContent = current.toLocaleString();
                            
                            if (progress < 1) {
                                requestAnimationFrame(updateCounter);
                            } else {
                                stat.textContent = target.toLocaleString();
                            }
                        }
                        
                        const observer = new IntersectionObserver((entries) => {
                            entries.forEach(entry => {
                                if (entry.isIntersecting) {
                                    requestAnimationFrame(updateCounter);
                                    observer.unobserve(entry.target);
                                }
                            });
                        }, { threshold: 0.3 });
                        
                        observer.observe(stat);
                    }
                });
            }

            animateStats();

            // ===== CONSOLE LOG =====
            console.log('%c PharmaGeo - Gestion des stocks ', 'background: linear-gradient(135deg, #16A34A, #166534); color: white; font-size: 16px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');

        });
    </script>

</body>
    <script src="../assets/js/responsive.js"></script>
</html>