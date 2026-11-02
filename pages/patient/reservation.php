<?php
// ============================================================
// PharmaGeo — Réservation
// Permet de réserver un médicament dans une pharmacie.
// Un visiteur sans compte peut réserver : ses informations
// (nom, prénom, email, mot de passe) créent son compte patient
// automatiquement, puis la réservation est enregistrée.
// La logique de réservation est partagée avec reservation_groupe.php
// (voir includes/reservation_fonctions.php).
// ============================================================

require_once '../../includes/config.php';
require_once '../../includes/session.php';
require_once '../../includes/reservation_fonctions.php';

// ===== UTILISATEUR CONNECTÉ ? =====
$est_connecte = isset($_SESSION['user_id']);
$user = null;
if ($est_connecte) {
    $stmt = $pdo->prepare("SELECT * FROM utilisateur WHERE Id_utilisateur = ?");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch();
}

// Identifier le stock demandé
$id_stock = isset($_GET['id_stock']) ? (int)$_GET['id_stock'] : 0;
$id_medicament = isset($_GET['stock']) ? (int)$_GET['stock'] : 0;
$id_pharmacie = isset($_GET['pharmacie']) ? (int)$_GET['pharmacie'] : 0;

$stock_info = null;
$error = '';
$success_message = '';
$compte_creer = false;
$id_reservation = 0;

// Informations invité (pour pré-remplir le formulaire)
$guest = [
    'nom'       => isset($_POST['nom']) ? trim($_POST['nom']) : '',
    'prenom'    => isset($_POST['prenom']) ? trim($_POST['prenom']) : '',
    'email'     => isset($_POST['email']) ? trim($_POST['email']) : '',
    'telephone' => isset($_POST['telephone']) ? trim($_POST['telephone']) : '',
];

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
            m.Id_medicament, m.Nom_commercial, m.DCI, m.Forme, m.Dosage, m.Description, m.Statut as Medicament_Statut,
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
    $quantite = isset($_POST['quantite']) ? max(1, (int)$_POST['quantite']) : 1;

    // ==== DÉTERMINER L'UTILISATEUR (connecté ou invité) ====
    $identity = reservationResoudreUtilisateur(
        $pdo,
        $guest,
        isset($_POST['password']) ? (string)$_POST['password'] : '',
        isset($_POST['confirm_password']) ? (string)$_POST['confirm_password'] : ''
    );

    if (!empty($identity['erreurs'])) {
        $error = implode('<br>', $identity['erreurs']);
    } else {
        $compte_creer = $identity['compte_creer'];
        if ($identity['session_ouverte']) $est_connecte = true;

        // ==== CRÉER LA RÉSERVATION ====
        $reservation = reservationCreer($pdo, $identity['user_id'], $stock_info, $quantite);

        if (!$reservation['ok']) {
            $error = $reservation['erreur'];
        } else {
            $montant = $reservation['montant'];

            $success_message = '';
            if ($compte_creer) {
                $success_message .= "Votre compte patient a été créé avec succès. ";
            }
            $success_message .= "Votre réservation a bien été enregistrée. ";
            if ($montant !== null) {
                $success_message .= "Montant à payer : "
                    . number_format($montant, 0, ',', ' ')
                    . " FCFA pour " . $quantite . " unité(s) à "
                    . number_format($stock_info['Prix'], 0, ',', ' ')
                    . " FCFA l'unité. ";
            }
            $success_message .= "La pharmacie va la traiter.";
            $stock_info['Quantite'] -= $quantite;
            $id_reservation = isset($reservation['id']) ? (int)$reservation['id'] : 0;
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
            --primary-green: #16A34A;
            --primary-orange: #B91C1C;
            --dark-text: #0F2E19;
            --gray-text: #64748b;
        }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #F0FDF4;
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
            background: #F0FDF4; border-radius: 12px; padding: 14px 18px;
        }
        .qty-input {
            width: 90px; text-align: center; font-weight: 700;
            border: 2px solid #D9E9DF; border-radius: 8px; padding: 8px;
        }
        .btn-submit {
            background: linear-gradient(135deg, var(--primary-green), #166534);
            border: none; color: #fff; font-weight: 700;
            border-radius: 12px; padding: 13px 30px;
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 10px 34px rgba(22,163,74,0.28); }
        .price-badge {
            background: rgba(22,163,74,0.1); color: var(--primary-green);
            font-weight: 700; padding: 6px 14px; border-radius: 50px;
        }
        .text-danger { color: #DC2626 !important; }
        .guest-account {
            background: #F0FDF4;
            border: 1px solid #D9E9DF;
            border-radius: 14px;
            padding: 18px;
        }
        .guest-account .form-label { font-weight: 600; }
        .guest-account .form-control:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 0.2rem rgba(22,163,74,0.12);
        }
        /* ===== RESPONSIVE ===== */
        @media (max-width: 576px) {
            .reservation-body { padding: 20px; }
            .reservation-header { padding: 20px; }
        }
    </style>
    <link rel="stylesheet" href="../../assets/css/responsive.css">    <!-- Palette verte PharmaGeo -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/palette.css">
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
                    <?php if ($est_connecte): ?>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>pages/patient/dashboard.php"><i class="fas fa-tachometer-alt me-1"></i>Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>pages/patient/recherche.php"><i class="fas fa-search me-1"></i>Rechercher</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>pages/patient/historique.php"><i class="fas fa-history me-1"></i>Historique</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>deconnexion.php"><i class="fas fa-sign-out-alt me-1"></i>Déconnexion</a></li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>connexion.php"><i class="fas fa-sign-in-alt me-1"></i>Se connecter</a></li>
                    <?php endif; ?>
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
                        <?php
                        // Le patient va directement sur SA réservation,
                        // pas dans l'historique général.
                        $urlMaReservation = $id_reservation > 0
                            ? BASE_URL . 'pages/patient/ma_reservation.php?id=' . $id_reservation
                            : BASE_URL . 'pages/patient/ma_reservation.php';
                        ?>
                        <a href="<?php echo htmlspecialchars($urlMaReservation); ?>" class="btn btn-success btn-submit">
                            <i class="fas fa-ticket me-2"></i>Voir ma réservation
                        </a>
                        <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="btn btn-outline-dark ms-2">
                            <i class="fas fa-search me-2"></i>Nouvelle recherche
                        </a>
                    </div>

                <?php elseif ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
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
                                    <span class="fw-bold">Prix unitaire</span>
                                    <span class="price-badge">
                                        <?php echo $stock_info['Prix'] ? number_format($stock_info['Prix'], 0, ',', ' ') . ' FCFA' : 'Prix non communiqué'; ?>
                                    </span>
                                </div>

                                <?php if ($stock_info['Quantite'] > 0 && $stock_info['Pharmacie_Statut'] === 'active'): ?>
                                    <form method="POST" action="">

                                        <?php if (!$est_connecte): ?>
                                            <!-- ===== CRÉATION DE COMPTE PATIENT (visiteur) ===== -->
                                            <div class="guest-account mb-4">
                                                <h6 class="fw-bold mb-1">
                                                    <i class="fas fa-user-plus me-2" style="color: var(--primary-green);"></i>Créer votre compte patient
                                                </h6>
                                                <p class="text-muted small mb-3">
                                                    Réservez sans compte : ces informations créeront votre compte patient automatiquement.
                                                    Si vous avez déjà un compte, entrez simplement votre email et votre mot de passe.
                                                </p>
                                                <div class="row g-2 mb-2">
                                                    <div class="col-6">
                                                        <label class="form-label small mb-1">Nom <span class="text-danger">*</span></label>
                                                        <input type="text" name="nom" class="form-control form-control-sm"
                                                               value="<?php echo htmlspecialchars($guest['nom']); ?>" required placeholder="Nom">
                                                    </div>
                                                    <div class="col-6">
                                                        <label class="form-label small mb-1">Prénom <span class="text-danger">*</span></label>
                                                        <input type="text" name="prenom" class="form-control form-control-sm"
                                                               value="<?php echo htmlspecialchars($guest['prenom']); ?>" required placeholder="Prénom">
                                                    </div>
                                                </div>
                                                <div class="mb-2">
                                                    <label class="form-label small mb-1">Adresse email <span class="text-danger">*</span></label>
                                                    <input type="email" name="email" class="form-control form-control-sm"
                                                           value="<?php echo htmlspecialchars($guest['email']); ?>" required placeholder="vous@exemple.com">
                                                </div>
                                                <div class="mb-2">
                                                    <label class="form-label small mb-1">Téléphone <span class="text-muted">(optionnel)</span></label>
                                                    <input type="tel" name="telephone" class="form-control form-control-sm"
                                                           value="<?php echo htmlspecialchars($guest['telephone']); ?>" placeholder="+229 00 00 00 00">
                                                </div>
                                                <div class="row g-2">
                                                    <div class="col-6">
                                                        <label class="form-label small mb-1">Mot de passe <span class="text-danger">*</span></label>
                                                        <input type="password" name="password" class="form-control form-control-sm"
                                                               required placeholder="8 caractères min.">
                                                    </div>
                                                    <div class="col-6">
                                                        <label class="form-label small mb-1">Confirmation <span class="text-danger">*</span></label>
                                                        <input type="password" name="confirm_password" class="form-control form-control-sm"
                                                               required placeholder="Confirmer">
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <div class="qty-box mb-3">
                                            <i class="fas fa-box-open" style="color: var(--primary-green);"></i>
                                            <span class="fw-bold">Quantité :</span>
                                            <input type="number" name="quantite" class="qty-input" id="qtyInput" value="1" min="1"
                                                   max="<?php echo (int)$stock_info['Quantite']; ?>" required>
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center rounded-3 p-3 mb-3 bg-white border">
                                            <span class="fw-bold"><i class="fas fa-money-bill-wave me-1" style="color: var(--primary-green);"></i>Total à payer</span>
                                            <span class="fw-bold fs-5" id="montantTotal" style="color: var(--primary-green);">
                                                <?php echo $stock_info['Prix'] ? number_format($stock_info['Prix'], 0, ',', ' ') . ' FCFA' : 'Prix non communiqué'; ?>
                                            </span>
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
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const qtyInput = document.getElementById('qtyInput');
            const totalElem = document.getElementById('montantTotal');
            const prixUnitaire = <?php echo !empty($stock_info['Prix']) ? (float)$stock_info['Prix'] : 0; ?>;

            if (qtyInput && totalElem) {
                function updateTotal() {
                    const qty = Math.max(1, parseInt(qtyInput.value) || 1);
                    if (prixUnitaire > 0) {
                        const total = prixUnitaire * qty;
                        totalElem.textContent = total.toLocaleString('fr-FR', { maximumFractionDigits: 0 }) + ' FCFA';
                    } else {
                        totalElem.textContent = 'Prix non communiqué';
                    }
                }
                qtyInput.addEventListener('input', updateTotal);
                updateTotal();
            }
        });
    </script>
</body>
</html>
