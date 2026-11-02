<?php
// ============================================================
// PharmaGeo — Espace pharmacien : Tableau de bord
// Affiche une synthèse de l'activité de la pharmacie (stocks,
// réservations, médicaments populaires et dernières mises à jour).
// ============================================================

// ===== ACTIVATION DES ERREURS =====
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ===== DÉFINITION DES CONSTANTES =====
define('BASE_URL', 'http://localhost/pharmageo/');

// ===== FONCTIONS UTILITAIRES =====
require_once __DIR__ . '/../includes/fonctions.php';

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
               p.Id_pharmacie, p.Nom as Pharmacie_Nom, p.Statut as Pharmacie_Statut,
               p.Quartier, p.Commune
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

// ===== RÉCUPÉRATION DES STATISTIQUES =====
try {
    if ($has_pharmacy) {
        $pharmacie_id = $user['Id_pharmacie'];

        // Nombre total de médicaments
        $total_medicaments = $pdo->prepare("
            SELECT COUNT(*) as total FROM stock WHERE Id_pharmacie = :id
        ");
        $total_medicaments->execute([':id' => $pharmacie_id]);
        $total_medicaments = $total_medicaments->fetch()['total'] ?? 0;

        // Médicaments disponibles
        $medicaments_disponibles = $pdo->prepare("
            SELECT COUNT(*) as total FROM stock WHERE Id_pharmacie = :id AND Quantite > 0 AND Statut = 'disponible'
        ");
        $medicaments_disponibles->execute([':id' => $pharmacie_id]);
        $medicaments_disponibles = $medicaments_disponibles->fetch()['total'] ?? 0;

        // Médicaments en rupture
        $medicaments_rupture = $pdo->prepare("
            SELECT COUNT(*) as total FROM stock WHERE Id_pharmacie = :id AND (Quantite = 0 OR Statut = 'rupture')
        ");
        $medicaments_rupture->execute([':id' => $pharmacie_id]);
        $medicaments_rupture = $medicaments_rupture->fetch()['total'] ?? 0;

        // Stock faible
        $stock_faible = $pdo->prepare("
            SELECT COUNT(*) as total FROM stock WHERE Id_pharmacie = :id AND Statut = 'stock_faible' AND Quantite > 0
        ");
        $stock_faible->execute([':id' => $pharmacie_id]);
        $stock_faible = $stock_faible->fetch()['total'] ?? 0;

        // Total des réservations reçues
        $total_reservations = $pdo->prepare("
            SELECT COUNT(*) as total FROM reservation r
            JOIN stock s ON r.Id_stock = s.Id_stock
            WHERE s.Id_pharmacie = :id
        ");
        $total_reservations->execute([':id' => $pharmacie_id]);
        $total_reservations = $total_reservations->fetch()['total'] ?? 0;

        // Réservations en attente
        $reservations_attente = $pdo->prepare("
            SELECT COUNT(*) as total FROM reservation r
            JOIN stock s ON r.Id_stock = s.Id_stock
            WHERE s.Id_pharmacie = :id AND r.Statut = 'en_attente'
        ");
        $reservations_attente->execute([':id' => $pharmacie_id]);
        $reservations_attente = $reservations_attente->fetch()['total'] ?? 0;

        // Réservations confirmées
        $reservations_confirmees = $pdo->prepare("
            SELECT COUNT(*) as total FROM reservation r
            JOIN stock s ON r.Id_stock = s.Id_stock
            WHERE s.Id_pharmacie = :id AND r.Statut = 'confirmee'
        ");
        $reservations_confirmees->execute([':id' => $pharmacie_id]);
        $reservations_confirmees = $reservations_confirmees->fetch()['total'] ?? 0;

        // Dernières réservations
        $dernieres_reservations = $pdo->prepare("
            SELECT r.*, u.Nom, u.Prenom, u.Email, u.Telephone,
                   m.Nom_commercial, m.DCI, m.Dosage
            FROM reservation r
            JOIN stock s ON r.Id_stock = s.Id_stock
            JOIN utilisateur u ON r.Id_utilisateur = u.Id_utilisateur
            JOIN medicament m ON s.Id_medicament = m.Id_medicament
            WHERE s.Id_pharmacie = :id
            ORDER BY r.Date_reservation DESC
            LIMIT 5
        ");
        $dernieres_reservations->execute([':id' => $pharmacie_id]);
        $dernieres_reservations = $dernieres_reservations->fetchAll();

        // Médicaments les plus réservés
        $medicaments_populaires = $pdo->prepare("
            SELECT m.Nom_commercial, m.DCI, COUNT(r.Id_reservation) as total
            FROM medicament m
            JOIN stock s ON m.Id_medicament = s.Id_medicament
            JOIN reservation r ON s.Id_stock = r.Id_stock
            WHERE s.Id_pharmacie = :id AND r.Statut = 'confirmee'
            GROUP BY m.Id_medicament
            ORDER BY total DESC
            LIMIT 5
        ");
        $medicaments_populaires->execute([':id' => $pharmacie_id]);
        $medicaments_populaires = $medicaments_populaires->fetchAll();

        // Statistiques des stocks par statut
        $stats_stocks = [
            'disponible' => $medicaments_disponibles,
            'stock_faible' => $stock_faible,
            'rupture' => $medicaments_rupture
        ];

        // Taux de disponibilité
        $taux_disponibilite = 0;
        if ($total_medicaments > 0) {
            $taux_disponibilite = round(($medicaments_disponibles / $total_medicaments) * 100);
        }

        // Dernières mises à jour de stock
        $dernieres_mises_a_jour = $pdo->prepare("
            SELECT hs.*, m.Nom_commercial, u.Nom, u.Prenom
            FROM historique_stock hs
            JOIN stock s ON hs.Id_stock = s.Id_stock
            JOIN medicament m ON s.Id_medicament = m.Id_medicament
            JOIN utilisateur u ON hs.Id_utilisateur = u.Id_utilisateur
            WHERE s.Id_pharmacie = :id
            ORDER BY hs.Date_operation DESC
            LIMIT 5
        ");
        $dernieres_mises_a_jour->execute([':id' => $pharmacie_id]);
        $dernieres_mises_a_jour = $dernieres_mises_a_jour->fetchAll();

    } else {
        // Le pharmacien n'a pas encore de pharmacie
        $total_medicaments = 0;
        $medicaments_disponibles = 0;
        $medicaments_rupture = 0;
        $stock_faible = 0;
        $total_reservations = 0;
        $reservations_attente = 0;
        $reservations_confirmees = 0;
        $dernieres_reservations = [];
        $medicaments_populaires = [];
        $stats_stocks = ['disponible' => 0, 'stock_faible' => 0, 'rupture' => 0];
        $taux_disponibilite = 0;
        $dernieres_mises_a_jour = [];
    }

} catch(PDOException $e) {
    die("Erreur : " . $e->getMessage());
}

// ===== STATUTS POUR AFFICHAGE =====
$statuts_labels = [
    'en_attente' => ['label' => 'En attente', 'class' => 'en_attente', 'icon' => 'fa-clock'],
    'confirmee' => ['label' => 'Confirmée', 'class' => 'confirmee', 'icon' => 'fa-check-circle'],
    'refusee' => ['label' => 'Refusée', 'class' => 'refusee', 'icon' => 'fa-times-circle'],
    'expiree' => ['label' => 'Expirée', 'class' => 'expiree', 'icon' => 'fa-hourglass-end'],
    'retiree' => ['label' => 'Retirée', 'class' => 'retiree', 'icon' => 'fa-shopping-bag'],
    'annulee' => ['label' => 'Annulée', 'class' => 'annulee', 'icon' => 'fa-ban']
];

$page_title = 'Tableau de bord - Pharmacie';
// ===== CHARGE UTILE (composants de tableau de bord) =====
require_once __DIR__ . '/../includes/dashboard.php';

$base = pgd_base_url();

$quartier = trim($user['Quartier'] ?? '');
$commune = trim($user['Commune'] ?? '');
if ($commune === '') {
    $commune = 'Porto-Novo';
}
$sousTitre = trim(($user['Pharmacie_Nom'] ?? '') . ' ' . ($quartier !== '' ? '(' . $quartier . ')' : '') . ' - ' . $commune);

$actions = '<a href="' . $base . 'pharmacien/medicaments.php" class="pgd-btn pgd-btn--primary">'
    . '<i class="fas fa-plus"></i> Ajouter un médicament</a>'
    . '<a href="' . $base . 'pharmacien/reservations.php" class="pgd-btn pgd-btn--outline">'
    . '<i class="fas fa-calendar-check"></i> Voir les réservations'
    . ($reservations_attente > 0
        ? '<span class="pgd-badge pgd-badge--danger" style="margin-left:6px">' . (int) $reservations_attente . '</span>'
        : '')
    . '</a>';

pgd_debut([
    'role' => 'pharmacien',
    'titre' => 'Tableau de <span class="highlight">bord</span>',
    'sous_titre' => $sousTitre,
    'datetime' => true,
    'chart' => true,
    'actions' => $actions,
    'sidebar_badges' => [
        'pharmacien/reservations.php' => $reservations_attente,
    ],
    'sidebar_extra' => (function () {
        require_once __DIR__ . '/../includes/reinitialiser.php';
        return html_bloc_reinitialiser();
    })(),
]);
?>

<!-- ===== STATISTIQUES ===== -->
<section class="pgd-stats">
    <?php
    echo pgd_stat_carte([
        'libelle' => 'Total médicaments',
        'valeur' => (int) $total_medicaments,
        'icone' => 'fa-pills',
        'ton' => 'violet',
        'delta' => '<i class="fas fa-boxes-stacked"></i> Références en stock',
        'lien' => $base . 'pharmacien/medicaments.php',
    ]);

    echo pgd_stat_carte([
        'libelle' => 'Disponibles',
        'valeur' => (int) $medicaments_disponibles,
        'icone' => 'fa-circle-check',
        'ton' => 'green',
        'delta' => '<i class="fas fa-arrow-up"></i> Taux : ' . (int) $taux_disponibilite . ' %',
        'delta_ton' => 'up',
    ]);

    echo pgd_stat_carte([
        'libelle' => 'En rupture',
        'valeur' => (int) $medicaments_rupture,
        'icone' => 'fa-triangle-exclamation',
        'ton' => 'red',
        'delta' => $medicaments_rupture > 0
            ? '<i class="fas fa-triangle-exclamation"></i> ' . (int) $medicaments_rupture . ' à réapprovisionner'
            : '<i class="fas fa-check"></i> Tout est disponible',
        'delta_ton' => $medicaments_rupture > 0 ? 'down' : 'up',
        'lien' => $base . 'pharmacien/stocks.php',
    ]);

    echo pgd_stat_carte([
        'libelle' => 'Réservations en attente',
        'valeur' => (int) $reservations_attente,
        'icone' => 'fa-clock',
        'ton' => 'amber',
        'delta' => $reservations_attente > 0
            ? '<i class="fas fa-spinner fa-spin"></i> À traiter'
            : '<i class="fas fa-check"></i> Aucune en attente',
        'lien' => $base . 'pharmacien/reservations.php',
    ]);
    ?>
</section>

<!-- ===== DISPONIBILITÉ & RÉSERVATIONS ===== -->
<div class="pgd-grid pgd-grid-2">
    <div class="pgd-card">
        <div class="pgd-card__head">
            <h5><i class="fas fa-chart-pie"></i> Disponibilité des stocks</h5>
        </div>
        <div class="pgd-card__body">
            <?php if ($total_medicaments > 0): ?>
                <div class="pgd-chart pgd-chart--sm"><canvas id="donutChart"></canvas></div>
                <div class="pgd-legend">
                    <div class="pgd-legend__item"><span class="pgd-legend__dot" style="background:#16A34A"></span> Disponible <span class="pgd-legend__value"><?php echo (int) $stats_stocks['disponible']; ?></span></div>
                    <div class="pgd-legend__item"><span class="pgd-legend__dot" style="background:#D97706"></span> Stock faible <span class="pgd-legend__value"><?php echo (int) $stats_stocks['stock_faible']; ?></span></div>
                    <div class="pgd-legend__item"><span class="pgd-legend__dot" style="background:#DC2626"></span> Rupture <span class="pgd-legend__value"><?php echo (int) $stats_stocks['rupture']; ?></span></div>
                    <div class="pgd-legend__item" style="border-top:1px solid var(--pg-border);padding-top:8px;width:100%">
                        <span class="pgd-legend__dot" style="background:#14532D"></span> Taux global <span class="pgd-legend__value"><?php echo (int) $taux_disponibilite; ?> %</span>
                    </div>
                </div>
            <?php else: ?>
                <?= pgd_vide('Aucun stock enregistré', 'Ajoutez vos médicaments pour suivre leur disponibilité.', 'fa-boxes-stacked',
                    '<a href="' . $base . 'pharmacien/medicaments.php" class="pgd-btn pgd-btn--primary"><i class="fas fa-plus"></i> Ajouter un médicament</a>') ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="pgd-card">
        <div class="pgd-card__head">
            <h5><i class="fas fa-calendar-check" style="color:var(--pg-amber)"></i> Dernières réservations</h5>
            <a href="<?php echo $base; ?>pharmacien/reservations.php" class="pgd-card__link">Voir tout <i class="fas fa-arrow-right"></i></a>
        </div>
        <?php if (!empty($dernieres_reservations)): ?>
            <div class="pgd-table-wrap">
                <table class="pgd-table pgd-table--cards">
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Médicament</th>
                            <th class="text-center">Qté</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dernieres_reservations as $r): ?>
                            <tr>
                                <td data-label="Patient">
                                    <?= pgd_cellule_user('', trim($r['Prenom'] . ' ' . $r['Nom']), $r['Email']) ?>
                                </td>
                                <td data-label="Médicament">
                                    <strong><?php echo htmlspecialchars($r['Nom_commercial']); ?></strong>
                                    <?php if (!empty($r['DCI'])): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($r['DCI']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Quantité" class="text-center"><strong><?php echo (int) $r['Quantite']; ?></strong></td>
                                <td data-label="Statut"><?php echo pgd_badge_statut($r['Statut']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?= pgd_vide('Aucune réservation', 'Vous n\'avez pas encore reçu de réservation.', 'fa-calendar-check') ?>
        <?php endif; ?>
    </div>
</div>

<!-- ===== CLASSEMENTS & JOURNAL DE STOCK ===== -->
<div class="pgd-grid pgd-grid-2">
    <div class="pgd-card">
        <div class="pgd-card__head">
            <h5><i class="fas fa-fire" style="color:var(--pg-amber)"></i> Médicaments les plus réservés</h5>
        </div>
        <div class="pgd-card__body">
            <?php if (!empty($medicaments_populaires)): ?>
                <div class="pgd-activity">
                    <?php foreach ($medicaments_populaires as $med): ?>
                        <div class="pgd-pending">
                            <span class="pgd-pending__avatar"><i class="fas fa-pills"></i></span>
                            <div class="pgd-pending__info">
                                <strong><?php echo htmlspecialchars($med['Nom_commercial']); ?></strong>
                                <?php if (!empty($med['DCI'])): ?>
                                    <small><?php echo htmlspecialchars($med['DCI']); ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="pgd-pending__actions">
                                <span class="pgd-badge pgd-badge--success"><i class="fas fa-shopping-cart"></i> <?php echo (int) $med['total']; ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <?= pgd_vide('Aucune donnée', 'Les médicaments les plus réservés apparaîtront ici.', 'fa-pills') ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="pgd-card">
        <div class="pgd-card__head">
            <h5><i class="fas fa-clock-rotate-left"></i> Dernières mises à jour de stock</h5>
            <a href="<?php echo $base; ?>pharmacien/historique_stocks.php" class="pgd-card__link">Voir tout <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="pgd-card__body">
            <?php if (!empty($dernieres_mises_a_jour)): ?>
                <div class="pgd-activity">
                    <?php foreach ($dernieres_mises_a_jour as $maj): ?>
                        <?php $hausse = $maj['Ancienne_quantite'] < $maj['Nouvelle_quantite']; ?>
                        <div class="pgd-activity__item">
                            <span class="pgd-activity__icon pgd-activity__icon--<?php echo $hausse ? 'teal' : 'red'; ?>">
                                <i class="fas fa-<?php echo $hausse ? 'arrow-up' : 'arrow-down'; ?>"></i>
                            </span>
                            <div class="pgd-activity__text">
                                <p><strong><?php echo htmlspecialchars($maj['Nom_commercial']); ?></strong>
                                    <?php echo (int) $maj['Ancienne_quantite']; ?> &rarr; <strong><?php echo (int) $maj['Nouvelle_quantite']; ?></strong></p>
                                <span class="pgd-activity__time">
                                    <i class="far fa-clock"></i> <?php echo date('d/m/Y H:i', strtotime($maj['Date_operation'])); ?>
                                    · <i class="fas fa-user"></i> <?php echo htmlspecialchars(trim($maj['Prenom'] . ' ' . $maj['Nom'])); ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <?= pgd_vide('Aucune mise à jour', 'Les mises à jour de stock apparaîtront ici.', 'fa-clock-rotate-left') ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var canvas = document.getElementById('donutChart');
    if (!canvas) { return; }

    new Chart(canvas.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: ['Disponible', 'Stock faible', 'Rupture'],
            datasets: [{
                data: [<?php echo (int) $stats_stocks['disponible']; ?>,
                       <?php echo (int) $stats_stocks['stock_faible']; ?>,
                       <?php echo (int) $stats_stocks['rupture']; ?>],
                backgroundColor: ['#16A34A', '#D97706', '#DC2626'],
                borderWidth: 3,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: { legend: { display: false } }
        }
    });
});
</script>
<?php
$scripts = ob_get_clean();

pgd_pied(['scripts' => $scripts]);
