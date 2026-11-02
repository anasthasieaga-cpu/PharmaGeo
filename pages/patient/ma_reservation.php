<?php
// ============================================================
// PharmaGeo — Ma réservation
//
// Le patient est envoyé ici après avoir réservé : il arrive
// DIRECTEMENT sur ses réservations, avec leur détail complet
// (médicament, pharmacie, quantité, montant, statut), et non
// dans l'historique général où il devrait les retrouver.
//
// Paramètres acceptés :
//   ?id=12        une réservation précise
//   ?ids=12,13,14 une réservation groupée (une par médicament)
//
// Sans paramètre, on affiche les dernières réservations du patient.
// On ne montre JAMAIS une réservation qui ne lui appartient pas.
// ============================================================

require_once '../../includes/config.php';
require_once '../../includes/session.php';

// ===== PAGINATION (10 éléments par page) =====
require_once '../../includes/pagination.php';

$page_title = 'Ma réservation - PharmaGeo';

$est_connecte = isset($_SESSION['user_id']);
$user_id = $est_connecte ? (int)$_SESSION['user_id'] : 0;

// ===== IDENTIFIANTS DEMANDÉS =====
$ids = array();
if (isset($_GET['id'])) {
    foreach (explode(',', (string)$_GET['id']) as $v) {
        $i = (int)trim($v);
        if ($i > 0) $ids[] = $i;
    }
}
if (isset($_GET['ids'])) {
    foreach (explode(',', (string)$_GET['ids']) as $v) {
        $i = (int)trim($v);
        if ($i > 0 && !in_array($i, $ids, true)) $ids[] = $i;
    }
}
$ids = array_values(array_unique($ids));

$reservations = array();
$total = 0;
$total_connu = true;
$refusees = 0;
$pagination = pagination_calculer(0);
$reservation_precise = !empty($ids);

if (!$est_connecte) {
    $message = "Connectez-vous pour consulter vos réservations.";
} else {
// On ne consulte QUE les réservations du patient connecté.
    $params = array($user_id);

    // Jointures communes au compteur et à la liste
    $depuis = "
        FROM reservation r
        JOIN stock s       ON s.Id_stock = r.Id_stock
        JOIN medicament m  ON m.Id_medicament = s.Id_medicament
        JOIN pharmacie p   ON p.Id_pharmacie = s.Id_pharmacie
        WHERE r.Id_utilisateur = ?
    ";

    if (!empty($ids)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $depuis .= " AND r.Id_reservation IN ($in)";
        foreach ($ids as $i) $params[] = $i;
    }

    try {
        // Avec ?id / ?ids on affiche la ou les réservations demandées :
        // elles font partie d'une commande groupée, pas d'une liste.
        // Sans paramètre, on pagine les dernières réservations du patient.
        $champs = "r.Id_reservation, r.Quantite, r.Montant, r.Statut,
                   r.Date_reservation, r.Date_expiration,
                   m.Nom_commercial, m.DCI, m.Forme, m.Dosage,
                   s.Prix, s.Quantite as Stock_actuel, s.Statut as Stock_statut,
                   p.Id_pharmacie, p.Nom, p.Adresse, p.Quartier, p.Commune,
                   p.Telephone, p.Horaire, p.Latitude, p.Longitude, p.Logo";

        $stmt = $pdo->prepare("SELECT COUNT(*) $depuis");
        $stmt->execute($params);
        $pagination = pagination_calculer((int)$stmt->fetchColumn());

        $ordre = " ORDER BY r.Date_reservation DESC, r.Id_reservation DESC";

        if ($reservation_precise) {
            $sql = "SELECT $champs $depuis$ordre";
        } else {
            $sql = "SELECT $champs $depuis$ordre" . pagination_sql_limite($pagination);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $reservations = $stmt->fetchAll();

        foreach ($reservations as $r) {
            if ($r['Montant'] === null) {
                $total_connu = false;
            } else {
                $total += (float)$r['Montant'];
            }
            if ($r['Statut'] === 'refusee') $refusees++;
        }
    } catch (PDOException $e) {
        $message = "Erreur lors du chargement de la réservation.";
    }
}

// Libellés et couleurs de statut
$statuts = array(
    'en_attente' => array('En attente de validation', 'warning', 'hourglass-half'),
    'confirmee'  => array('Confirmée par la pharmacie', 'success', 'check-circle'),
    'refusee'    => array('Refusée', 'danger', 'times-circle'),
    'expiree'    => array('Expirée', 'secondary', 'hourglass-end'),
    'retiree'    => array('Retirée', 'info', 'box-open'),
    'annulee'    => array('Annulée', 'dark', 'ban'),
);
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
    <link rel="stylesheet" href="../../assets/css/responsive.css">    <!-- Palette verte PharmaGeo -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/palette.css">
</head>
<body>
    <?php include_once '../../includes/header.php'; ?>

    <div class="container py-4">
        <?php if (isset($message)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
            <div>
                <h1 class="mb-1"><i class="fas fa-ticket me-2" style="color: #16A34A;"></i>Ma réservation</h1>
                <?php if ($est_connecte && !empty($reservations)): ?>
                    <p class="text-muted mb-0">
                        <?php echo count($reservations); ?> réservation(s)
                        <?php if (!empty($ids)): ?>concernée(s)<?php else: ?>récente(s)<?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
            <div>
                <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-search me-1"></i>Nouvelle recherche
                </a>
                <a href="<?php echo BASE_URL; ?>pages/patient/historique.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-history me-1"></i>Tout l'historique
                </a>
            </div>
        </div>

        <?php if (!$est_connecte): ?>
            <div class="text-center py-4">
                <i class="fas fa-user-lock text-muted" style="font-size: 3.5rem; color: #ddd;"></i>
                <p class="text-muted mt-3">Vous devez être connecté pour voir vos réservations.</p>
                <a href="<?php echo BASE_URL; ?>connexion.php" class="btn btn-primary-custom">Se connecter</a>
            </div>

        <?php elseif (empty($reservations)): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-calendar-check me-2"></i>
                <?php if (!empty($ids)): ?>
                    Aucune de ces réservations ne figure dans votre compte.
                <?php else: ?>
                    Vous n'avez encore aucune réservation.
                <?php endif; ?>
            </div>

        <?php else: ?>

            <?php if (count($reservations) > 1 && $total_connu): ?>
                <div class="alert alert-success d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-receipt me-2"></i>Montant total à payer</span>
                    <strong><?php echo number_format($total, 0, ',', ' '); ?> FCFA</strong>
                </div>
            <?php endif; ?>

            <?php foreach ($reservations as $r):
                $st = isset($statuts[$r['Statut']]) ? $statuts[$r['Statut']] : array($r['Statut'], 'secondary', 'question');
                ?>
                <div class="card shadow-sm border-0 mb-4" style="border-radius: 18px;">
                    <div class="card-body">

                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                            <div>
                                <h4 class="mb-1"><?php echo htmlspecialchars($r['Nom_commercial']); ?></h4>
                                <div class="text-muted small">
                                    <?php if (!empty($r['DCI'])): ?>
                                        DCI : <?php echo htmlspecialchars($r['DCI']); ?>
                                        <?php if (!empty($r['Dosage'])): ?> · <?php echo htmlspecialchars($r['Dosage']); ?><?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="badge bg-<?php echo $st[1]; ?> fs-6">
                                <i class="fas fa-<?php echo $st[2]; ?> me-1"></i><?php echo htmlspecialchars($st[0]); ?>
                            </span>
                        </div>

                        <hr>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="d-flex align-items-start mb-2">
                                    <i class="fas fa-store me-2 mt-1" style="color: #16A34A;"></i>
                                    <div>
                                        <strong><?php echo htmlspecialchars($r['Nom']); ?></strong>
                                        <?php if (!empty($r['Adresse'])): ?>
                                            <div class="text-muted small">
                                                <i class="fas fa-location-dot me-1"></i><?php echo htmlspecialchars($r['Adresse']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="text-muted small">
                                            <?php echo htmlspecialchars($r['Quartier'] ?? ''); ?><?php
                                                if (!empty($r['Commune'])): ?>, <?php echo htmlspecialchars($r['Commune']); ?><?php endif; ?>
                                        </div>
                                        <?php if (!empty($r['Telephone'])): ?>
                                            <div class="text-muted small">
                                                <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($r['Telephone']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($r['Horaire'])): ?>
                                            <div class="text-muted small">
                                                <i class="fas fa-clock me-1"></i><?php echo htmlspecialchars($r['Horaire']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <table class="table table-sm mb-0">
                                    <tbody>
                                        <tr>
                                            <td class="text-muted">Référence</td>
                                            <td class="text-end fw-semibold">#<?php echo (int)$r['Id_reservation']; ?></td>
                                        </tr>
                                        <tr>
                                            <td class="text-muted">Quantité</td>
                                            <td class="text-end fw-semibold"><?php echo (int)$r['Quantite']; ?></td>
                                        </tr>
                                        <tr>
                                            <td class="text-muted">Montant</td>
                                            <td class="text-end fw-bold" style="color: #16A34A;">
                                                <?php echo $r['Montant'] === null
                                                    ? 'Prix non communiqué'
                                                    : number_format((float)$r['Montant'], 0, ',', ' ') . ' FCFA'; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="text-muted">Demandée le</td>
                                            <td class="text-end"><?php echo date('d/m/Y à H:i', strtotime($r['Date_reservation'])); ?></td>
                                        </tr>
                                        <?php if (!empty($r['Date_expiration'])): ?>
                                            <tr>
                                                <td class="text-muted">Valable jusqu'au</td>
                                                <td class="text-end"><?php echo date('d/m/Y à H:i', strtotime($r['Date_expiration'])); ?></td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-3 pt-3 border-top">
                            <a href="<?php echo BASE_URL; ?>pages/patient/pharmacie.php?id=<?php echo (int)$r['Id_pharmacie']; ?>"
                               class="btn btn-outline-custom btn-sm">
                                <i class="fas fa-store me-1"></i>Voir cette pharmacie
                            </a>
                            <a href="<?php echo BASE_URL; ?>pages/patient/historique.php?type=reservations"
                               class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-list me-1"></i>Toutes mes réservations
                            </a>
                        </div>
                    </div>
</div>
            <?php endforeach; ?>

            <?php if (!$reservation_precise): ?>
                <!-- ===== PAGINATION ===== -->
                <?php pagination_afficher($pagination, [], 'réservations'); ?>
            <?php endif; ?>

        <?php endif; ?>
    </div>

    <?php include_once '../../includes/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>