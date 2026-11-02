<?php
// ============================================================
// PharmaGeo — Notifications
//
// Page atteinte depuis l'icône de cloche de la barre de navigation.
// Elle n'affiche QUE les notifications de la personne connectée
// et les marque comme lues à l'ouverture.
// ============================================================

require_once 'includes/config.php';
require_once 'includes/session.php';

// ===== PAGINATION (10 éléments par page) =====
require_once 'includes/pagination.php';

$page_title = 'Mes notifications - PharmaGeo';

// ===== PERSONNE CONNECTÉE ? =====
if (!estConnecte()) {
    header('Location: ' . BASE_URL . 'connexion.php');
    exit();
}

$userId = getIdUtilisateur();

$notifications = array();
$non_lues = 0;
$pagination = pagination_calculer(0);

try {
    // Total réel et notifications non lues : ces deux compteurs portent sur
    // TOUTES les notifications, pas seulement sur la page affichée.
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN Lu = 0 THEN 1 ELSE 0 END) as non_lues
        FROM notification
        WHERE Id_utilisateur = ?
    ");
    $stmt->execute([$userId]);
    $compteurs = $stmt->fetch() ?: array('total' => 0, 'non_lues' => 0);
    $non_lues = (int) $compteurs['non_lues'];
    $pagination = pagination_calculer((int) $compteurs['total']);

    // Seule la page courante est chargée (pagination côté serveur).
    $stmt = $pdo->prepare("
        SELECT Id_notification, Titre, Message, Type, Lu, Date_notification
        FROM notification
        WHERE Id_utilisateur = ?
        ORDER BY Date_notification DESC, Id_notification DESC" . pagination_sql_limite($pagination)
    );
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll();

    // Tout est lu une fois la page consultée.
    if ($non_lues > 0) {
        $marquer = $pdo->prepare("UPDATE notification SET Lu = 1 WHERE Id_utilisateur = ? AND Lu = 0");
        $marquer->execute([$userId]);
    }
} catch (PDOException $e) {
    $erreur = "Impossible de charger vos notifications pour le moment.";
    $notifications = array();
}

/**
 * Petite pastille de couleur selon le type de notification.
 */
function notifBadge($type, $titre) {
    $type = (string)$type;
    if (stripos($type, 'reservation') !== false || stripos($titre, 'réservation') !== false) {
        return array('bg-primary', 'fa-calendar-check');
    }
    if (stripos($type, 'stock') !== false || stripos($titre, 'stock') !== false) {
        return array('bg-warning text-dark', 'fa-boxes-stacked');
    }
    if (stripos($type, 'validation') !== false) {
        return array('bg-success', 'fa-circle-check');
    }
    return array('bg-secondary', 'fa-bell');
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
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/responsive.css">    <!-- Palette verte PharmaGeo -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/palette.css">
</head>
<body>
    <?php include_once 'includes/header.php'; ?>

    <div class="py-4">
        <?php if (isset($erreur)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($erreur); ?>
            </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
            <div>
                <h1 class="mb-1"><i class="fas fa-bell me-2" style="color: #16A34A;"></i>Mes notifications</h1>
<p class="text-muted mb-0">
                    <?php echo $pagination['total']; ?> notification(s)
                    <?php if ($non_lues > 0): ?>
                        &mdash; <strong><?php echo $non_lues; ?> non lue(s)</strong>
                    <?php else: ?>
                        &mdash; tout est lu
                    <?php endif; ?>
                </p>
            </div>
            <a href="<?php echo BASE_URL . lienDashboard(); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-tachometer-alt me-1"></i>Tableau de bord
            </a>
        </div>

        <?php if (empty($notifications)): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-bell-slash me-2"></i>Vous n'avez aucune notification.
            </div>
        <?php else: ?>
            <div class="list-group shadow-sm">
                <?php foreach ($notifications as $n):
                    $b = notifBadge($n['Type'], $n['Titre']);
                    ?>
                    <div class="list-group-item d-flex gap-3 py-3<?php echo (int)$n['Lu'] === 0 ? ' notif-unread' : ''; ?>">
                        <div class="notif-icone badge <?php echo $b[0]; ?>">
                            <i class="fas <?php echo $b[1]; ?>"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <strong><?php echo htmlspecialchars($n['Titre']); ?></strong>
                                <small class="text-muted">
                                    <?php echo date('d/m/Y à H:i', strtotime($n['Date_notification'])); ?>
                                </small>
                            </div>
                            <div class="text-muted small mt-1"><?php echo nl2br(htmlspecialchars($n['Message'])); ?></div>
                        </div>
                    </div>
<?php endforeach; ?>
            </div>

            <!-- ===== PAGINATION ===== -->
            <?php pagination_afficher($pagination, [], 'notifications'); ?>
        <?php endif; ?>
    </div>

    <style>
        .notif-icone {
            width: 42px; height: 42px; min-width: 42px;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-size: 1.05rem;
        }
        .notif-unread { background: rgba(22,163,74,0.06); border-left: 3px solid #16A34A; }
    </style>

    <?php include_once 'includes/footer.php'; ?>
</body>
</html>