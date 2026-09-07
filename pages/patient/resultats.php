<?php
// ============================================================
// PharmaGeo — Espace patient : Résultats de recherche
// Affiche les pharmacies où le médicament recherché est disponible.
// ============================================================

$page_title = 'Résultats de recherche - PharmaGeo';
require_once '../../includes/config.php';
require_once '../../includes/session.php';

// ===== VARIABLES INITIALES =====
$resultats = [];
$recherche_effectuee = false;
$terme_recherche = isset($_GET['q']) ? cleanInput($_GET['q']) : '';
$error = '';

// ===== RECHERCHE DE MÉDICAMENTS =====
if (!empty($terme_recherche)) {
    $recherche_effectuee = true;

    try {
        $sql = "SELECT
                    m.Id_medicament, m.Nom_commercial, m.DCI, m.Forme, m.Dosage,
                    p.Id_pharmacie, p.Nom as Pharmacie, p.Adresse, p.Telephone, p.Quartier, p.Commune,
                    p.Latitude, p.Longitude, p.Horaire,
                    s.Quantite, s.Prix, s.Statut as Statut_stock,
                    (6371 * acos(cos(radians(:lat)) * cos(radians(p.Latitude)) *
                    cos(radians(p.Longitude) - radians(:lng)) +
                    sin(radians(:lat)) * sin(radians(p.Latitude)))) as Distance
                FROM medicament m
                JOIN stock s ON m.Id_medicament = s.Id_medicament
                JOIN pharmacie p ON s.Id_pharmacie = p.Id_pharmacie
                WHERE (m.Nom_commercial LIKE :terme
                    OR m.DCI LIKE :terme
                    OR m.Id_medicament IN (
                        SELECT Id_medicament FROM medicament_synonyme WHERE Libelle LIKE :terme
                    ))
                AND s.Statut = 'disponible'
                AND s.Quantite > 0
                AND p.Statut = 'active'
                ORDER BY Distance ASC";

        $stmt = $pdo->prepare($sql);
        $term = '%' . $terme_recherche . '%';
        $lat = $_SESSION['latitude'] ?? 6.4970;
        $lng = $_SESSION['longitude'] ?? 2.6050;
        $stmt->execute([':terme' => $term, ':lat' => $lat, ':lng' => $lng]);
        $resultats = $stmt->fetchAll();
    } catch(PDOException $e) {
        $error = "Erreur lors de la recherche.";
    }
}
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
</head>
<body>
    <!-- ===== HEADER ===== -->
    <?php include_once '../../includes/header.php'; ?>

    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="mb-0">
                <i class="fas fa-capsules" style="color: rgb(42, 195, 42);"></i>
                Résultats de recherche
            </h1>
            <a href="recherche.php" class="btn btn-outline-custom">
                <i class="fas fa-arrow-left me-2"></i>Nouvelle recherche
            </a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
            </div>
        <?php else: ?>

        <?php if ($recherche_effectuee): ?>
            <div class="alert alert-info">
                <i class="fas fa-search me-2"></i>
                Recherche de "<strong><?php echo htmlspecialchars($terme_recherche); ?></strong>" :
                <?php echo count($resultats); ?> résultat(s) trouvé(s).
            </div>

            <?php if (!empty($resultats)): ?>
                <div class="row">
                    <?php foreach ($resultats as $r): ?>
                        <div class="col-lg-6 col-md-12 mb-4">
                            <div class="card h-100 shadow-sm border-0">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h5 class="fw-bold" style="color: rgb(42, 195, 42);">
                                                <?php echo htmlspecialchars($r['Nom_commercial']); ?>
                                            </h5>
                                            <?php if ($r['DCI']): ?><span class="badge bg-secondary">DCI: <?php echo htmlspecialchars($r['DCI']); ?></span><?php endif; ?>
                                            <?php if ($r['Forme']): ?><span class="badge bg-info"><?php echo htmlspecialchars($r['Forme']); ?></span><?php endif; ?>
                                        </div>
                                        <span class="badge bg-success">
                                            <i class="fas fa-check-circle me-1"></i><?php echo (int)$r['Quantite']; ?> dispo.
                                        </span>
                                    </div>
                                    <hr>
                                    <h6 class="fw-bold" style="color: hsl(25, 75%, 47%);">
                                        <i class="fas fa-store me-1"></i><?php echo htmlspecialchars($r['Pharmacie']); ?>
                                    </h6>
                                    <p class="text-muted small mb-1">
                                        <i class="fas fa-map-marker-alt me-1"></i>
                                        <?php echo htmlspecialchars($r['Quartier'] ?? ''); ?>, <?php echo htmlspecialchars($r['Commune'] ?? ''); ?>
                                    </p>
                                    <p class="text-muted small mb-1"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($r['Telephone'] ?? 'Non disponible'); ?></p>
                                    <p class="mb-2">
                                        <span class="badge bg-light text-dark"><?php echo round($r['Distance'], 2); ?> km</span>
                                        <?php if ($r['Prix']): ?>
                                            <span class="badge bg-warning text-dark"><?php echo number_format($r['Prix'], 0, ',', ' '); ?> FCFA</span>
                                        <?php endif; ?>
                                    </p>
                                    <div class="d-flex gap-2 mt-3">
                                        <a href="carte.php?id=<?php echo $r['Id_pharmacie']; ?>" class="btn btn-secondary-custom btn-sm">
                                            <i class="fas fa-map-marker-alt me-1"></i>Carte
                                        </a>
                                        <?php $urlReservation = BASE_URL . 'pages/patient/reservation.php?stock=' . $r['Id_medicament'] . '&pharmacie=' . $r['Id_pharmacie']; ?>
                                            <a href="<?php echo estConnecte() ? $urlReservation : BASE_URL . 'connexion.php?redirect=' . urlencode($urlReservation); ?>" class="btn btn-primary-custom btn-sm">
                                                <i class="fas fa-calendar-check me-1"></i>Réserver
                                            </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-warning text-center">
                    <i class="fas fa-frown me-2"></i>Aucun résultat trouvé pour "<?php echo htmlspecialchars($terme_recherche); ?>".
                    <br><small>Vérifiez l'orthographe ou essayez un autre nom.</small>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="text-center py-5">
                <i class="fas fa-search" style="font-size: 4rem; color: #ddd;"></i>
                <h4 class="mt-3 text-muted">Aucune recherche effectuée</h4>
                <p>Saisissez un terme dans la barre de recherche.</p>
                <a href="recherche.php" class="btn btn-primary-custom">Rechercher</a>
            </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>

    <!-- ===== FOOTER ===== -->
    <?php include_once '../../includes/footer.php'; ?>
    <!-- ===== SCRIPTS ===== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
