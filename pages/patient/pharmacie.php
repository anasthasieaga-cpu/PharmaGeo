<?php
// ============================================================
// PharmaGeo — Espace patient : Pharmacie
// Affiche les détails d'une pharmacie, ses médicaments et permanences.
// ============================================================

$page_title = 'Détail de la pharmacie - PharmaGeo';
require_once '../../includes/config.php';
require_once '../../includes/session.php';

// ===== PARAMÈTRES =====
$id_pharmacie = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$pharmacie = null;
$medicaments = [];
$permanences = [];

if ($id_pharmacie > 0) {
    try {
        $sql = "SELECT p.*, u.Nom as Pharmacien_Nom, u.Prenom as Pharmacien_Prenom, u.Email as Pharmacien_Email, u.Photo as Pharmacien_Photo
                FROM pharmacie p
                JOIN pharmacien ph ON p.Id_pharmacien = ph.Id_pharmacien
                JOIN utilisateur u ON ph.Id_utilisateur = u.Id_utilisateur
                WHERE p.Id_pharmacie = :id AND p.Statut = 'active'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $id_pharmacie]);
        $pharmacie = $stmt->fetch();

        if ($pharmacie) {
            // Médicaments disponibles
            $sqlM = "SELECT m.Id_medicament, m.Nom_commercial, m.DCI, m.Forme, m.Dosage,
                            s.Id_stock, s.Quantite, s.Prix, s.Statut as Statut_stock
                     FROM stock s
                     JOIN medicament m ON s.Id_medicament = m.Id_medicament
                     WHERE s.Id_pharmacie = :ph
                       AND m.Statut = 'actif'
                       AND s.Quantite > 0
                       AND s.Statut = 'disponible'
                     ORDER BY m.Nom_commercial ASC";
            $stmtM = $pdo->prepare($sqlM);
            $stmtM->execute([':ph' => $id_pharmacie]);
            $medicaments = $stmtM->fetchAll();

            // Permanences
            $sqlP = "SELECT * FROM jour_permanence
                     WHERE Id_pharmacie = :ph
                     ORDER BY FIELD(Jour_semaine, 'lundi','mardi','mercredi','jeudi','vendredi','samedi','dimanche')";
            $stmtP = $pdo->prepare($sqlP);
            $stmtP->execute([':ph' => $id_pharmacie]);
            $permanences = $stmtP->fetchAll();
        }
    } catch (PDOException $e) {
        $erreur = "Erreur lors du chargement de la pharmacie.";
    }
}

if (!$pharmacie) {
    header('Location: ' . BASE_URL . 'pages/patient/carte.php');
    exit();
}

// ===== SÉLECTION DU PATIENT =====
// Le bouton « Tout réserver » est un STANDARD de la plateforme : il est
// toujours présent sur la page d'une pharmacie, quelle que soit la
// sélection. Il ne doit jamais disparaître sous prétexte qu'un médicament
// manque : dans ce cas on réserve ce qui est disponible et on indique
// clairement ce qui manque.
$selection = [];
if (isset($_GET['med']) && is_array($_GET['med'])) {
    foreach ($_GET['med'] as $m) {
        $m = (int)$m;
        if ($m > 0 && !in_array($m, $selection, true)) $selection[] = $m;
    }
}

// Sans sélection transmise depuis la recherche, on propose tout le stock
// disponible dans cette pharmacie : c'est le cas le plus courant.
$selection_explicite = !empty($selection);
if (!$selection_explicite) {
    foreach ($medicaments as $m) {
        $id = (int)$m['Id_medicament'];
        if (!in_array($id, $selection, true)) $selection[] = $id;
    }
}

$presents = [];
foreach ($medicaments as $m) {
    $presents[(int)$m['Id_medicament']] = true;
}

$disponibles = [];
$absents = [];
foreach ($selection as $mid) {
    if (isset($presents[$mid])) $disponibles[] = $mid;
    else $absents[] = $mid;
}

// Noms des médicaments demandés mais absents ici, pour les afficher.
$noms_manquants = [];
if (!empty($absents)) {
    $inM = implode(',', array_fill(0, count($absents), '?'));
    $st = $pdo->prepare("SELECT Id_medicament, Nom_commercial FROM medicament WHERE Id_medicament IN ($inM)");
    $st->execute($absents);
    foreach ($st->fetchAll() as $r) $noms_manquants[] = $r['Nom_commercial'];
}

$urlToutReserver = '';
if (!empty($disponibles)) {
    $urlToutReserver = BASE_URL . 'pages/patient/reservation_groupe.php?pharmacie=' . (int)$id_pharmacie;
    foreach ($disponibles as $mid) {
        $urlToutReserver .= '&med[]=' . (int)$mid;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pharmacie['Nom'] . ' - PharmaGeo'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/responsive.css">    <!-- Palette verte PharmaGeo -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/palette.css">
</head>
<body>
    <!-- ===== HEADER ===== -->
    <?php include_once '../../includes/header.php'; ?>

    <div class="container py-4">
        <?php if (isset($erreur)): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($erreur); ?></div>
        <?php endif; ?>

        <!-- ===== EN-TÊTE PHARMACIE ===== -->
        <div class="card mb-4 shadow-sm border-0" style="border-radius: 20px;">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <img src="<?php echo htmlspecialchars(pharma_image_url_ou_defaut($pharmacie['Logo'] ?? null, 'pharmacie')); ?>"
                             alt="Logo" class="rounded-circle"
                             style="width: 90px; height: 90px; object-fit: cover; border: 3px solid #16A34A;">
                        <div>
                            <h2 class="mb-1 fw-bold"><?php echo htmlspecialchars($pharmacie['Nom']); ?></h2>
                            <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Pharmacie active</span>
                        </div>
                    </div>
                    <a href="carte.php?id=<?php echo $pharmacie['Id_pharmacie']; ?>" class="btn btn-outline-custom">
                        <i class="fas fa-map-marker-alt me-2"></i>Voir sur la carte
                    </a>
                </div>

                <hr>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-map-marker-alt me-2" style="color: #16A34A;"></i>
                            <span><?php echo htmlspecialchars($pharmacie['Adresse'] ?? ''); ?></span>
                        </div>
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-city me-2" style="color: #16A34A;"></i>
                            <span><?php echo htmlspecialchars($pharmacie['Quartier'] ?? ''); ?>, <?php echo htmlspecialchars($pharmacie['Commune'] ?? ''); ?></span>
                        </div>
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-phone me-2" style="color: #16A34A;"></i>
                            <span><?php echo htmlspecialchars($pharmacie['Telephone'] ?? 'Non renseigné'); ?></span>
                        </div>
                        <div class="d-flex align-items-center">
                            <i class="fas fa-envelope me-2" style="color: #16A34A;"></i>
                            <span><?php echo htmlspecialchars($pharmacie['Email'] ?? 'Non renseigné'); ?></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-clock me-2" style="color: #16A34A;"></i>
                            <span><strong>Horaires :</strong> <?php echo htmlspecialchars($pharmacie['Horaire'] ?? 'Non renseignés'); ?></span>
                        </div>
                        <div class="d-flex align-items-center mb-2">
                            <img src="<?php echo htmlspecialchars(pharma_image_url_ou_defaut($pharmacie['Pharmacien_Photo'] ?? null, 'pharmacien')); ?>"
                                 alt="Pharmacien" class="rounded-circle me-2"
                                 style="width: 40px; height: 40px; object-fit: cover; border: 2px solid #16A34A;">
                            <span><strong>Pharmacien :</strong> <?php echo htmlspecialchars($pharmacie['Pharmacien_Prenom'] . ' ' . $pharmacie['Pharmacien_Nom']); ?></span>
                        </div>
                        <?php if (!empty($permanences)): ?>
                            <div class="d-flex align-items-start mb-2">
                                <i class="fas fa-calendar-alt me-2 mt-1" style="color: #166534;"></i>
                                <div>
                                    <strong>Permanences :</strong>
                                    <div class="mt-1">
                                        <?php foreach ($permanences as $j): ?>
                                            <span class="badge bg-warning text-dark me-1 mb-1"><?php echo ucfirst($j['Jour_semaine']); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== MÉDICAMENTS DISPONIBLES ===== -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="fas fa-capsules me-2" style="color: #16A34A;"></i>Médicaments disponibles</h4>
            <span class="badge bg-success"><?php echo count($medicaments); ?> produit(s)</span>
        </div>

        <?php if (!empty($medicaments)): ?>
            <?php if (!empty($urlToutReserver)): ?>
                <div class="carte-tout-reserver mb-3">
                    <div>
                        <?php if (empty($noms_manquants)): ?>
                            <h5 class="mb-1"><i class="fas fa-layer-group me-2"></i>Cette pharmacie possède tous vos médicaments</h5>
                            <p class="mb-0">
                                <?php echo count($disponibles); ?> médicament(s) réservé(s) en un seul clic,
                                au lieu de les réserver un par un.
                            </p>
                        <?php else: ?>
                            <h5 class="mb-1" style="color:#991B1B;"><i class="fas fa-layer-group me-2"></i>
                                <?php echo count($disponibles); ?> médicament<?php echo count($disponibles) > 1 ? 's' : ''; ?> disponible<?php echo count($disponibles) > 1 ? 's' : ''; ?> sur <?php echo count($selection); ?>
                            </h5>
                            <p class="mb-0">
                                <?php if ($selection_explicite): ?>
                                    Non disponible ici : <strong><?php echo htmlspecialchars(implode(', ', $noms_manquants)); ?></strong>.
                                <?php endif; ?>
                                Le bouton ci-dessous réserve tout ce qui est disponible, en un seul clic.
                            </p>
                        <?php endif; ?>
                    </div>
                    <a href="<?php echo htmlspecialchars($urlToutReserver); ?>" class="btn btn-tout-reserver-inline">
                        <i class="fas fa-calendar-check me-2"></i>Tout réserver<?php if ($selection_explicite): ?> (<?php echo count($disponibles); ?>)<?php endif; ?>
                    </a>
                </div>
            <?php endif; ?>
            <div class="row g-3">
                <?php foreach ($medicaments as $m): ?>
                    <?php
                    // Les boutons unitaires restent toujours visibles : rien ne
                    // ne doit disparaître. Le bouton groupé est l'action
                    // principale, les autres restent accessibles.
                    ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card h-100 shadow-sm border-0" style="border-radius: 16px;">
                            <div class="card-body">
                                <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($m['Nom_commercial']); ?></h5>
                                <?php if (!empty($m['DCI'])): ?>
                                    <p class="text-muted small mb-1">DCI : <?php echo htmlspecialchars($m['DCI']); ?></p>
                                <?php endif; ?>
                                <div class="d-flex flex-wrap gap-2 mb-2">
                                    <?php if (!empty($m['Forme'])): ?><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($m['Forme']); ?></span><?php endif; ?>
                                    <?php if (!empty($m['Dosage'])): ?><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($m['Dosage']); ?></span><?php endif; ?>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="fw-bold text-success"><?php echo isset($m['Prix']) ? number_format($m['Prix'], 0, ',', ' ') . ' FCFA' : 'Prix non indiqué'; ?></span>
                                        <div class="text-muted small"><i class="fas fa-boxes me-1"></i><?php echo (int)$m['Quantite']; ?> en stock</div>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <?php $urlReservation = BASE_URL . 'pages/patient/reservation.php?id_stock=' . $m['Id_stock']; ?>
                                    <a href="<?php echo $urlReservation; ?>" class="btn btn-outline-custom btn-sm w-100">
                                        <i class="fas fa-calendar-check me-1"></i>Réserver celui-ci
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-warning text-center">
                <i class="fas fa-box-open me-2"></i>Aucun médicament disponible actuellement dans cette pharmacie.
            </div>
        <?php endif; ?>
    </div>

    <!-- ===== FOOTER ===== -->
    <?php include_once '../../includes/footer.php'; ?>
</body>
</html>
