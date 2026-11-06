<?php
// ============================================================
// PharmaGeo — Ancienne page de résultats
//
// Cette page affichait un bouton « Réserver » par médicament, sans
// possibilité de tout réserver d'un coup : c'est l'ancien format,
// remplacé par recherche.php qui regroupe la sélection et propose
// « Tout réserver » dès qu'une pharmacie possède tous les médicaments.
//
// On redirige donc ici, en conservant la recherche en cours, plutôt que
// de maintenir deux pages de résultats qui divergent.
// ============================================================

require_once '../../includes/config.php';

$redirection = BASE_URL . 'pages/patient/recherche.php';
if (isset($_GET['q'])) {
    $redirection .= '?q=' . urlencode((string)$_GET['q']);
} elseif (isset($_GET['med']) && is_array($_GET['med'])) {
    $ids = array();
    foreach ($_GET['med'] as $m) {
        $m = (int)$m;
        if ($m > 0) $ids[] = $m;
    }
    if (!empty($ids)) {
        $redirection .= '?med%5B%5D=' . implode('&med%5B%5D=', $ids);
    }
}

header('Location: ' . $redirection);
exit;
?>

$page_title = 'Résultats de recherche - PharmaGeo';
$geo_reload = true; // recalcul des distances si la position change
require_once '../../includes/config.php';
require_once '../../includes/session.php';
require_once '../../includes/geo.php';

// ===== POSITION DE RÉFÉRENCE POUR LES DISTANCES =====
$position = getPositionUtilisateur($pdo);
// Tant que la position n'est pas un GPS exploitable, aucune distance n'est
// calculée : on classe par nom plutôt que d'inventer un classement.
$geo_precise = geoPositionEstPrecise($position);

// ===== VARIABLES INITIALES =====
$resultats = [];
$recherche_effectuee = false;
$terme_recherche = isset($_GET['q']) ? cleanInput($_GET['q']) : '';
$error = '';

// ===== RECHERCHE DE MÉDICAMENTS =====
if (!empty($terme_recherche)) {
    $recherche_effectuee = true;

try {
        // Fragment conditionnel : le classement par distance n'a de sens que
        // si la position de référence est réellement connue.
        $sql_distance = $geo_precise
            ? "(6371 * acos(least(1, greatest(-1,
                cos(radians(:lat)) * cos(radians(p.Latitude)) *
                cos(radians(p.Longitude) - radians(:lng)) +
                sin(radians(:lat)) * sin(radians(p.Latitude))
                )))) as Distance"
            : 'NULL as Distance';
        $sql_tri = $geo_precise ? 'Distance ASC' : 'p.Nom ASC';

        $sql = "SELECT
                    m.Id_medicament, m.Nom_commercial, m.DCI, m.Forme, m.Dosage,
                    p.Id_pharmacie, p.Nom as Pharmacie, p.Adresse, p.Telephone, p.Quartier, p.Commune,
                    p.Latitude, p.Longitude, p.Horaire,
                    s.Quantite, s.Prix, s.Statut as Statut_stock,
                    $sql_distance
                FROM medicament m
                JOIN stock s ON m.Id_medicament = s.Id_medicament
                JOIN pharmacie p ON s.Id_pharmacie = p.Id_pharmacie
                WHERE (m.Nom_commercial LIKE :terme
                    OR m.DCI LIKE :terme
                    OR m.Id_medicament IN (
                        SELECT Id_medicament FROM medicament_synonyme WHERE Libelle LIKE :terme
                    ))
                AND m.Statut = 'actif'
                AND s.Statut = 'disponible'
                AND s.Quantite > 0
                AND p.Statut = 'active'
                ORDER BY $sql_tri";

        $stmt = $pdo->prepare($sql);
        $params = [':terme' => '%' . $terme_recherche . '%'];
        if ($geo_precise) {
            $params[':lat'] = $position['lat'];
            $params[':lng'] = $position['lng'];
        }
        $stmt->execute($params);
        $resultats = $stmt->fetchAll();

        // Distance en mètres, recalculée en PHP : c'est la valeur de référence
        // (mêmes coordonnées que geoFormaterDistance()).
        foreach ($resultats as $k => $r) {
            $resultats[$k]['distance_metres'] = $geo_precise
                ? geoDistanceMetres($position['lat'], $position['lng'], $r['Latitude'], $r['Longitude'])
                : null;
        }
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
    <?php require_once '../../includes/geo_style.php'; ?>    <!-- Palette verte PharmaGeo -->
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
    <!-- ===== HEADER ===== -->
    <?php include_once '../../includes/header.php'; ?>

    <div class="container py-4">
        <?php echo geoAfficherPosition($position); ?>
        <?php echo geoBandeauPosition($position); ?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="mb-0">
                <i class="fas fa-capsules" style="color: #16A34A;"></i>
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
                                            <h5 class="fw-bold" style="color: #16A34A;">
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
                                    <h6 class="fw-bold" style="color: #166534;">
                                        <i class="fas fa-store me-1"></i><?php echo htmlspecialchars($r['Pharmacie']); ?>
                                    </h6>
                                    <p class="text-muted small mb-1">
                                        <i class="fas fa-map-marker-alt me-1"></i>
                                        <?php echo htmlspecialchars($r['Quartier'] ?? ''); ?>, <?php echo htmlspecialchars($r['Commune'] ?? ''); ?>
                                    </p>
                                    <p class="text-muted small mb-1"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($r['Telephone'] ?? 'Non disponible'); ?></p>
<p class="mb-2">
                                        <?php $distance_affichee = geoFormaterDistance($r['distance_metres'] ?? null); ?>
                                        <?php if ($distance_affichee !== null): ?>
                                            <span class="badge bg-light text-dark">
                                                <i class="fas fa-route me-1"></i><?php echo $distance_affichee; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted" title="Activez la localisation pour connaître la distance">
                                                <i class="fas fa-location-arrow me-1"></i>Distance inconnue
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($r['Prix']): ?>
                                            <span class="badge bg-warning text-dark"><?php echo number_format($r['Prix'], 0, ',', ' '); ?> FCFA</span>
                                        <?php endif; ?>
                                    </p>
                                    <div class="d-flex gap-2 mt-3">
                                        <a href="carte.php?id=<?php echo $r['Id_pharmacie']; ?>" class="btn btn-secondary-custom btn-sm">
                                            <i class="fas fa-map-marker-alt me-1"></i>Carte
                                        </a>
                                        <?php $urlReservation = BASE_URL . 'pages/patient/reservation.php?stock=' . $r['Id_medicament'] . '&pharmacie=' . $r['Id_pharmacie']; ?>
                                            <a href="<?php echo $urlReservation; ?>" class="btn btn-primary-custom btn-sm">
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
