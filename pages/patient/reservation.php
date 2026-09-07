<?php
// ============================================================
// PharmaGeo — Espace patient : Réservation
// Permet au patient de réserver un médicament dans une pharmacie.
// ============================================================

// ===== ACTIVATION DES ERREURS =====
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ===== DÉFINITION DES CONSTANTES =====
define('BASE_URL', 'http://localhost/pharmageo/');

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

// ===== VÉRIFICATION CONNEXION =====
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'connexion.php');
    exit();
}

// ===== RÉCUPÉRATION UTILISATEUR =====
$stmt = $pdo->prepare("SELECT * FROM utilisateur WHERE Id_utilisateur = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch();

// Identifier le stock demandé
$id_stock = isset($_GET['id_stock']) ? (int)$_GET['id_stock'] : 0;
$id_medicament = isset($_GET['stock']) ? (int)$_GET['stock'] : 0;
$id_pharmacie = isset($_GET['pharmacie']) ? (int)$_GET['pharmacie'] : 0;

$stock_info = null;
$error = '';
$success_message = '';

// Résoudre le stock à partir du médicament + pharmacie si nécessaire
if ($id_stock <= 0 && $id_medicament > 0 && $id_pharmacie > 0) {
    $stmt = $pdo->prepare("SELECT Id_stock FROM stock WHERE Id_medicament = :med AND Id_pharmacie = :phar");
    $stmt->execute([':med' => $id_medicament, ':phar' => $id_pharmacie]);
    $found = $stmt->fetch();
    $id_stock = $found ? (int)$found['Id_stock'] : 0;
}

// Charger les infos du stock
if ($id_stock > 0) {
    $stmt = $pdo->prepare("
        SELECT
            s.Id_stock, s.Quantite, s.Prix, s.Statut as Statut_stock, s.Seuil_alerte,
            m.Id_medicament, m.Nom_commercial, m.DCI, m.Forme, m.Dosage, m.Description,
            p.Id_pharmacie, p.Nom as Pharmacie, p.Adresse, p.Quartier, p.Commune,
            p.Telephone as Pharmacie_Tel, p.Horaire, p.Latitude, p.Longitude,
            p.Statut as Pharmacie_Statut
        FROM stock s
        JOIN medicament m ON s.Id_medicament = m.Id_medicament
        JOIN pharmacie p ON s.Id_pharmacie = p.Id_pharmacie
        WHERE s.Id_stock = :id
    ");
    $stmt->execute([':id' => $id_stock]);
    $stock_info = $stmt->fetch();

    if (!$stock_info) {
        $error = "Ce médicament n'est plus disponible.";
    }
}

// Traitement de la réservation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id_stock > 0 && $stock_info) {
    $quantite = isset($_POST['quantite']) ? (int)$_POST['quantite'] : 1;
    $quantite = max(1, $quantite);

    if ($stock_info['Pharmacie_Statut'] !== 'active') {
        $error = "La pharmacie n'est pas active pour le moment.";
    } elseif ($quantite > $stock_info['Quantite'] || $stock_info['Quantite'] <= 0 || in_array($stock_info['Statut_stock'], ['rupture', 'desactive'])) {
        $error = "Stock insuffisant. Quantité disponible : " . $stock_info['Quantite'];
    } else {
        try {
            // Vérifier qu'il n'existe pas déjà une réservation en attente pour ce stock/utilisateur
            $check = $pdo->prepare("
                SELECT COUNT(*) as total FROM reservation
                WHERE Id_utilisateur = :user AND Id_stock = :stock AND Statut = 'en_attente'
            ");
            $check->execute([':user' => $_SESSION['user_id'], ':stock' => $id_stock]);
            $deja_reserve = $check->fetch()['total'] > 0;

            if ($deja_reserve) {
                $error = "Vous avez déjà une réservation en attente pour ce médicament dans cette pharmacie.";
            } else {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO reservation (Id_utilisateur, Id_stock, Quantite, Statut, Date_reservation)
                    VALUES (:user, :stock, :quantite, 'en_attente', NOW())
                ");
                $stmt->execute([
                    ':user' => $_SESSION['user_id'],
                    ':stock' => $id_stock,
                    ':quantite' => $quantite
                ]);

                // Créer une notification pour le pharmacien
                try {
                    $stmtPharm = $pdo->prepare("
                        SELECT ph.Id_utilisateur as Id_pharmacien
                        FROM pharmacie p
                        JOIN pharmacien ph ON p.Id_pharmacien = ph.Id_pharmacien
                        WHERE p.Id_pharmacie = :id
                    ");
                    $stmtPharm->execute([':id' => $stock_info['Id_pharmacie']]);
                    $pharmacien = $stmtPharm->fetch();

                    if ($pharmacien) {
                        $stmtNotif = $pdo->prepare("
                            INSERT INTO notification (Titre, Message, Id_utilisateur, Lu)
                            VALUES ('Nouvelle réservation', :message, :user, 0)
                        ");
                        $stmtNotif->execute([
                            ':user' => $pharmacien['Id_pharmacien'],
                            ':message' => 'Nouvelle réservation : ' . $stock_info['Nom_commercial'] . ' (x' . $quantite . ')'
                        ]);
                    }
                } catch(PDOException $e) {
                    // Ne pas bloquer si la notification échoue
                }

                // Journalisation
                try {
                    $log = $pdo->prepare("INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur)
                                          VALUES ('Réservation créée', :ip, :ua, 'succes', :user)");
                    $log->execute([
                        ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                        ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                        ':user' => $_SESSION['user_id']
                    ]);
                } catch(PDOException $e) {
                    // Ignorer
                }

                $pdo->commit();

                $success_message = "Votre réservation a bien été enregistrée. La pharmacie va la traiter.";
                $stock_info['Quantite'] -= $quantite;
            }
        } catch(PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Erreur lors de l'enregistrement de la réservation.";
        }
    }
}

$page_title = 'Réservation - PharmaGeo';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        /* ===== VARIABLES ===== */
        :root {
            --primary-green: #2ac32a;
            --primary-orange: #d4880f;
            --dark-text: #0a1628;
            --gray-text: #64748b;
        }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f4f7f6;
        }
        .reservation-wrap { max-width: 1100px; margin: 0 auto; padding: 24px 0; }
        .reservation-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(10,22,40,0.08);
            overflow: hidden;
        }
        .reservation-header {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: #fff;
            padding: 28px 32px;
        }
        .reservation-header h1 { font-weight: 800; font-size: 1.5rem; margin: 0; }
        .reservation-body { padding: 32px; }
        .med-title { color: var(--primary-green); font-weight: 800; }
        .pharmacy-name { color: var(--primary-orange); font-weight: 700; }
        .info-line { color: var(--gray-text); font-size: 0.95rem; }
        .qty-box {
            display: flex; align-items: center; gap: 12px;
            background: #f8faf9; border-radius: 12px; padding: 14px 18px;
        }
        .qty-input {
            width: 90px; text-align: center; font-weight: 700;
            border: 2px solid #e6ebe9; border-radius: 8px; padding: 8px;
        }
        .btn-submit {
            background: linear-gradient(135deg, var(--primary-green), #1e8e1e);
            border: none; color: #fff; font-weight: 700;
            border-radius: 12px; padding: 13px 30px;
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 10px 34px rgba(42,195,42,0.28); }
        .price-badge {
            background: rgba(42,195,42,0.1); color: var(--primary-green);
            font-weight: 700; padding: 6px 14px; border-radius: 50px;
        }
        .text-danger { color: #dc3545 !important; }
        /* ===== RESPONSIVE ===== */
        @media (max-width: 576px) {
            .reservation-body { padding: 20px; }
            .reservation-header { padding: 20px; }
        }
    </style>
    <link rel="stylesheet" href="../../assets/css/responsive.css">
</head>
<body>
    <!-- ===== NAVIGATION ===== -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand fw-bold" href="<?php echo BASE_URL; ?>">
                <i class="fas fa-hospital me-2"></i>PharmaGeo
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navRes">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navRes">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>pages/patient/dashboard.php"><i class="fas fa-tachometer-alt me-1"></i>Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>pages/patient/recherche.php"><i class="fas fa-search me-1"></i>Rechercher</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>pages/patient/historique.php"><i class="fas fa-history me-1"></i>Historique</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>deconnexion.php"><i class="fas fa-sign-out-alt me-1"></i>Déconnexion</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- ===== CONTENU DE LA RÉSERVATION ===== -->
    <div class="container reservation-wrap">
        <div class="reservation-card animate-fade-in">
            <div class="reservation-header">
                <h1><i class="fas fa-calendar-check me-2"></i>Réservation de médicament</h1>
            </div>
            <div class="reservation-body">
                <?php if ($success_message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                    </div>
                    <div class="text-center mt-4">
                        <a href="<?php echo BASE_URL; ?>pages/patient/historique.php" class="btn btn-success btn-submit">
                            <i class="fas fa-history me-2"></i>Voir mes réservations
                        </a>
                        <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="btn btn-outline-dark ms-2">
                            <i class="fas fa-search me-2"></i>Nouvelle recherche
                        </a>
                    </div>

                <?php elseif ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                    </div>
                    <div class="text-center mt-4">
                        <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="btn btn-primary btn-submit">
                            <i class="fas fa-search me-2"></i>Retour à la recherche
                        </a>
                    </div>

                <?php elseif ($stock_info): ?>
                    <div class="row g-4">
                        <div class="col-lg-7">
                            <h3 class="med-title"><i class="fas fa-capsules me-2"></i><?php echo htmlspecialchars($stock_info['Nom_commercial']); ?></h3>
                            <?php if ($stock_info['DCI']): ?>
                                <span class="badge bg-secondary">DCI : <?php echo htmlspecialchars($stock_info['DCI']); ?></span>
                            <?php endif; ?>
                            <?php if ($stock_info['Forme']): ?>
                                <span class="badge bg-info"><?php echo htmlspecialchars($stock_info['Forme']); ?></span>
                            <?php endif; ?>
                            <?php if ($stock_info['Dosage']): ?>
                                <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($stock_info['Dosage']); ?></span>
                            <?php endif; ?>

                            <hr>

                            <h5 class="pharmacy-name"><i class="fas fa-store me-2"></i><?php echo htmlspecialchars($stock_info['Pharmacie']); ?></h5>
                            <p class="info-line mb-1">
                                <i class="fas fa-map-marker-alt me-1"></i>
                                <?php echo htmlspecialchars($stock_info['Quartier'] ?? ''); ?> -
                                <?php echo htmlspecialchars($stock_info['Adresse'] ?? $stock_info['Commune'] ?? ''); ?>
                            </p>
                            <?php if ($stock_info['Pharmacie_Tel']): ?>
                                <p class="info-line mb-1"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($stock_info['Pharmacie_Tel']); ?></p>
                            <?php endif; ?>
                            <?php if ($stock_info['Horaire']): ?>
                                <p class="info-line mb-1"><i class="fas fa-clock me-1"></i><?php echo htmlspecialchars($stock_info['Horaire']); ?></p>
                            <?php endif; ?>

                            <?php if ($stock_info['Description']): ?>
                                <p class="info-line mt-3"><?php echo htmlspecialchars($stock_info['Description']); ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="col-lg-5">
                            <div class="p-4 bg-light rounded-4">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="fw-bold">Disponibilité</span>
                                    <?php if ($stock_info['Quantite'] > 0): ?>
                                        <span class="badge bg-success">
                                            <i class="fas fa-check-circle me-1"></i><?php echo (int)$stock_info['Quantite']; ?> disponible(s)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>Rupture</span>
                                    <?php endif; ?>
                                </div>

                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <span class="fw-bold">Prix</span>
                                    <span class="price-badge">
                                        <?php echo $stock_info['Prix'] ? number_format($stock_info['Prix'], 0, ',', ' ') . ' FCFA' : 'Prix non communiqué'; ?>
                                    </span>
                                </div>

                                <?php if ($stock_info['Quantite'] > 0 && $stock_info['Pharmacie_Statut'] === 'active'): ?>
                                    <form method="POST" action="">
                                        <div class="qty-box mb-3">
                                            <i class="fas fa-box-open" style="color: var(--primary-green);"></i>
                                            <span class="fw-bold">Quantité :</span>
                                            <input type="number" name="quantite" class="qty-input" value="1" min="1"
                                                   max="<?php echo (int)$stock_info['Quantite']; ?>" required>
                                        </div>
                                        <button type="submit" class="btn btn-submit w-100">
                                            <i class="fas fa-calendar-check me-2"></i>Confirmer la réservation
                                        </button>
                                        <p class="text-muted small text-center mt-3 mb-0">
                                            <i class="fas fa-info-circle me-1"></i>
                                            Le pharmacien traitera votre demande. Vous serez notifié.
                                        </p>
                                    </form>
                                <?php else: ?>
                                    <div class="alert alert-warning mb-0">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        Ce médicament n'est pas réservable actuellement.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-capsules text-muted" style="font-size: 4rem;"></i>
                        <h4 class="mt-3 text-muted">Aucun médicament sélectionné</h4>
                        <p>Veuillez rechercher un médicament puis cliquer sur "Réserver".</p>
                        <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="btn btn-primary btn-submit">
                            <i class="fas fa-search me-2"></i>Aller à la recherche
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ===== SCRIPTS ===== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
