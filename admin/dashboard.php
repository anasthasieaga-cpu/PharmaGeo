<?php
// ============================================================
// PharmaGeo — Administration : Tableau de bord
// Page de synthèse affichant les statistiques globales de la
// plateforme (utilisateurs, pharmacies, stocks, réservations).
// ============================================================
// ===== ACTIVATION DES ERREURS =====
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ===== DÉFINITION DES CONSTANTES =====
define('BASE_URL', 'http://localhost/pharmageo/');

// ===== GESTION DES IMAGES (photos de profil) =====
require_once __DIR__ . '/../includes/images.php';

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

// ===== VÉRIFICATION ADMIN =====
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'administrateur') {
    header('Location: ' . BASE_URL . 'connexion.php');
    exit();
}

// ===== RÉCUPÉRATION DES STATISTIQUES =====
try {
    // Statistiques générales
    $total_utilisateurs = $pdo->query("SELECT COUNT(*) as total FROM utilisateur WHERE Statut = 'actif'")->fetch();
    $total_patients = $pdo->query("SELECT COUNT(*) as total FROM utilisateur WHERE Role = 'patient' AND Statut = 'actif'")->fetch();
    $total_pharmaciens = $pdo->query("SELECT COUNT(*) as total FROM pharmacien WHERE Statut_validation = 'valide'")->fetch();
    $total_pharmacies = $pdo->query("SELECT COUNT(*) as total FROM pharmacie WHERE Statut = 'active'")->fetch();
    $total_pharmacies_attente = $pdo->query("SELECT COUNT(*) as total FROM pharmacie WHERE Statut = 'en_attente'")->fetch();
    $total_medicaments = $pdo->query("SELECT COUNT(*) as total FROM medicament")->fetch();
    $total_stocks = $pdo->query("SELECT COUNT(*) as total FROM stock WHERE Quantite > 0")->fetch();
    $total_reservations = $pdo->query("SELECT COUNT(*) as total FROM reservation")->fetch();
    $total_reservations_attente = $pdo->query("SELECT COUNT(*) as total FROM reservation WHERE Statut = 'en_attente'")->fetch();
    $total_recherches = $pdo->query("SELECT COUNT(*) as total FROM recherche")->fetch();
    $total_logs = $pdo->query("SELECT COUNT(*) as total FROM log")->fetch();

    // Regrouper les statistiques
    $stats = [
        'total_utilisateurs' => $total_utilisateurs['total'] ?? 0,
        'total_patients' => $total_patients['total'] ?? 0,
        'total_pharmaciens' => $total_pharmaciens['total'] ?? 0,
        'total_pharmacies' => $total_pharmacies['total'] ?? 0,
        'total_pharmacies_attente' => $total_pharmacies_attente['total'] ?? 0,
        'total_medicaments' => $total_medicaments['total'] ?? 0,
        'total_stocks' => $total_stocks['total'] ?? 0,
        'total_reservations' => $total_reservations['total'] ?? 0,
        'total_reservations_attente' => $total_reservations_attente['total'] ?? 0,
        'total_recherches' => $total_recherches['total'] ?? 0,
        'total_logs' => $total_logs['total'] ?? 0
    ];

    // Évolution mensuelle des inscriptions
    $evolution = $pdo->query("
        SELECT 
            DATE_FORMAT(Date_creation, '%Y-%m') as mois, 
            COUNT(*) as total,
            SUM(CASE WHEN Role = 'patient' THEN 1 ELSE 0 END) as patients,
            SUM(CASE WHEN Role = 'pharmacien' THEN 1 ELSE 0 END) as pharmaciens,
            SUM(CASE WHEN Role = 'administrateur' THEN 1 ELSE 0 END) as admins
        FROM utilisateur 
        WHERE Date_creation >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(Date_creation, '%Y-%m')
        ORDER BY mois ASC
    ")->fetchAll();

    // Médicaments les plus recherchés
    // Jointure LEFT sur la table recherche pour garder les médicaments sans recherche
    $medicaments_populaires = $pdo->query("
        SELECT m.Nom_commercial, m.DCI, COUNT(r.Id_recherche) as total_recherches
        FROM medicament m
        LEFT JOIN recherche r ON r.Mot_recherche LIKE CONCAT('%', m.Nom_commercial, '%')
        GROUP BY m.Id_medicament
        ORDER BY total_recherches DESC
        LIMIT 10
    ")->fetchAll();

    // Pharmacies les plus consultées
    $pharmacies_populaires = $pdo->query("
        SELECT p.Nom, p.Quartier, p.Commune, 
               COUNT(r.Id_recherche) as total_consultations
        FROM pharmacie p
        LEFT JOIN recherche r ON r.Latitude = p.Latitude AND r.Longitude = p.Longitude
        WHERE p.Statut = 'active'
        GROUP BY p.Id_pharmacie
        ORDER BY total_consultations DESC
        LIMIT 10
    ")->fetchAll();

    // Dernières activités
    $dernieres_activites = $pdo->query("
        SELECT l.*, u.Nom, u.Prenom, u.Email
        FROM log l
        JOIN utilisateur u ON l.Id_utilisateur = u.Id_utilisateur
        ORDER BY l.Date_action DESC
        LIMIT 10
    ")->fetchAll();

    // Pharmacies en attente de validation
    $pharmacies_attente = $pdo->query("
        SELECT p.*, u.Nom, u.Prenom, u.Email, u.Telephone
        FROM pharmacie p
        JOIN pharmacien ph ON p.Id_pharmacien = ph.Id_pharmacien
        JOIN utilisateur u ON ph.Id_utilisateur = u.Id_utilisateur
        WHERE p.Statut = 'en_attente'
        ORDER BY p.Id_pharmacie DESC
        LIMIT 5
    ")->fetchAll();

    // Taux de disponibilité des médicaments
    $taux_disponibilite = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN Statut = 'disponible' AND Quantite > 0 THEN 1 ELSE 0 END) as disponibles,
            SUM(CASE WHEN Statut = 'stock_faible' AND Quantite > 0 THEN 1 ELSE 0 END) as stock_faible,
            SUM(CASE WHEN Statut = 'rupture' OR Quantite = 0 THEN 1 ELSE 0 END) as rupture
        FROM stock
    ")->fetch();

} catch(PDOException $e) {
    // En cas d'erreur, définir des valeurs par défaut
    $stats = [
        'total_utilisateurs' => 0, 'total_patients' => 0, 'total_pharmaciens' => 0,
        'total_pharmacies' => 0, 'total_pharmacies_attente' => 0, 'total_medicaments' => 0,
        'total_stocks' => 0, 'total_reservations' => 0, 'total_reservations_attente' => 0,
        'total_recherches' => 0, 'total_logs' => 0
    ];
    $evolution = [];
    $medicaments_populaires = [];
    $pharmacies_populaires = [];
    $dernieres_activites = [];
    $pharmacies_attente = [];
    $taux_disponibilite = ['total' => 0, 'disponibles' => 0, 'stock_faible' => 0, 'rupture' => 0];
}

// Calcul du taux de disponibilité
// Pourcentage arrondi des stocks disponibles par rapport au total des stocks
$taux_dispo = 0;
if ($taux_disponibilite['total'] > 0) {
    $taux_dispo = round(($taux_disponibilite['disponibles'] / $taux_disponibilite['total']) * 100);
}

$page_title = 'Tableau de bord - Administration PharmaGeo';
// ===== CHARGE UTILE (images, composants de tableau de bord) =====
require_once __DIR__ . '/../includes/dashboard.php';

$base = pgd_base_url();

$actions = '<a href="' . $base . 'admin/pharmacies.php?action=validation" class="pgd-btn pgd-btn--primary">'
    . '<i class="fas fa-check-double"></i> Valider les pharmacies'
    . ($stats['total_pharmacies_attente'] > 0
        ? '<span class="pgd-nav__badge" style="background:rgba(255,255,255,.22);color:#fff;margin-left:4px">'
            . $stats['total_pharmacies_attente'] . '</span>'
        : '')
    . '</a>'
    . '<a href="' . $base . 'admin/statistiques.php" class="pgd-btn pgd-btn--outline">'
    . '<i class="fas fa-file-lines"></i> Rapport</a>';

pgd_debut([
    'role' => 'administrateur',
    'titre' => 'Tableau de <span class="highlight">bord</span>',
    'sous_titre' => 'Bienvenue, ' . htmlspecialchars($_SESSION['prenom'] ?? 'Administrateur') . ' !',
    'datetime' => true,
    'chart' => true,
    'actions' => $actions,
    'sidebar_badges' => [
        'admin/pharmacies.php' => $stats['total_pharmacies_attente'],
        'admin/reservations.php' => $stats['total_reservations_attente'],
        'admin/logs.php' => $stats['total_logs'],
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
        'libelle' => 'Utilisateurs',
        'valeur' => number_format($stats['total_utilisateurs']),
        'icone' => 'fa-users',
        'ton' => 'blue',
        'delta' => '<i class="fas fa-user"></i> ' . $stats['total_patients'] . ' patients',
        'delta_ton' => 'up',
        'lien' => $base . 'admin/utilisateurs.php',
    ]);

    echo pgd_stat_carte([
        'libelle' => 'Pharmacies',
        'valeur' => number_format($stats['total_pharmacies']),
        'icone' => 'fa-store',
        'ton' => 'green',
        'delta' => '<i class="fas fa-clock"></i> ' . $stats['total_pharmacies_attente'] . ' en attente',
        'delta_ton' => 'up',
        'lien' => $base . 'admin/pharmacies.php',
    ]);

    echo pgd_stat_carte([
        'libelle' => 'Médicaments',
        'valeur' => number_format($stats['total_medicaments']),
        'icone' => 'fa-pills',
        'ton' => 'violet',
        'delta' => '<i class="fas fa-boxes-stacked"></i> ' . $stats['total_stocks'] . ' en stock',
        'lien' => $base . 'admin/medicaments.php',
    ]);

    echo pgd_stat_carte([
        'libelle' => 'Réservations',
        'valeur' => number_format($stats['total_reservations']),
        'icone' => 'fa-calendar-check',
        'ton' => 'amber',
        'delta' => $stats['total_reservations_attente'] > 0
            ? '<i class="fas fa-clock"></i> ' . $stats['total_reservations_attente'] . ' en attente'
            : '<i class="fas fa-check"></i> Aucune en attente',
        'delta_ton' => $stats['total_reservations_attente'] > 0 ? 'up' : '',
        'lien' => $base . 'admin/reservations.php',
    ]);

    echo pgd_stat_carte([
        'libelle' => 'Recherches',
        'valeur' => number_format($stats['total_recherches']),
        'icone' => 'fa-magnifying-glass',
        'ton' => 'teal',
        'delta' => '<i class="fas fa-arrow-up"></i> Recherches effectuées',
        'delta_ton' => 'up',
        'lien' => $base . 'admin/statistiques.php',
    ]);

    echo pgd_stat_carte([
        'libelle' => 'Pharmaciens',
        'valeur' => number_format($stats['total_pharmaciens']),
        'icone' => 'fa-user-nurse',
        'ton' => 'red',
        'delta' => '<i class="fas fa-check"></i> Comptes validés',
        'delta_ton' => 'up',
        'lien' => $base . 'admin/utilisateurs.php',
    ]);
    ?>
</section>

<!-- ===== GRAPHIQUES ===== -->
<div class="pgd-grid pgd-grid-3" style="grid-template-columns:2fr 1fr">
    <div class="pgd-card">
        <div class="pgd-card__head">
            <h5><i class="fas fa-chart-line"></i> Évolution des inscriptions</h5>
            <span class="pgd-badge pgd-badge--neutral" style="font-size:.6875rem">6 derniers mois</span>
        </div>
        <div class="pgd-card__body">
            <?php if (!empty($evolution)): ?>
                <div class="pgd-chart"><canvas id="evolutionChart"></canvas></div>
            <?php else: ?>
                <?= pgd_vide('Pas encore d\'historique', 'Les inscriptions des six derniers mois apparaîtront ici.', 'fa-chart-line') ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="pgd-card">
        <div class="pgd-card__head">
            <h5><i class="fas fa-chart-pie"></i> Disponibilité des stocks</h5>
        </div>
        <div class="pgd-card__body">
            <?php if (($taux_disponibilite['total'] ?? 0) > 0): ?>
                <div class="pgd-chart pgd-chart--sm"><canvas id="donutChart"></canvas></div>
                <div class="pgd-legend">
                    <div class="pgd-legend__item"><span class="pgd-legend__dot" style="background:#16A34A"></span> Disponible <span class="pgd-legend__value"><?php echo (int) $taux_disponibilite['disponibles']; ?></span></div>
                    <div class="pgd-legend__item"><span class="pgd-legend__dot" style="background:#166534"></span> Stock faible <span class="pgd-legend__value"><?php echo (int) $taux_disponibilite['stock_faible']; ?></span></div>
                    <div class="pgd-legend__item"><span class="pgd-legend__dot" style="background:#DC2626"></span> Rupture <span class="pgd-legend__value"><?php echo (int) $taux_disponibilite['rupture']; ?></span></div>
                    <div class="pgd-legend__item" style="border-top:1px solid var(--pg-border);padding-top:8px;width:100%">
                        <span class="pgd-legend__dot" style="background:#14532D"></span> Taux global <span class="pgd-legend__value"><?php echo $taux_dispo; ?> %</span>
                    </div>
                </div>
            <?php else: ?>
                <?= pgd_vide('Aucun stock enregistré', 'Les stocks des pharmacies apparaîtront ici.', 'fa-boxes-stacked') ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ===== CLASSEMENTS ===== -->
<div class="pgd-grid pgd-grid-2">
    <div class="pgd-card">
        <div class="pgd-card__head">
            <h5><i class="fas fa-fire" style="color:var(--pg-amber)"></i> Médicaments les plus recherchés</h5>
            <a href="<?php echo $base; ?>admin/statistiques.php" class="pgd-card__link">Voir tout <i class="fas fa-arrow-right"></i></a>
        </div>
        <?php if (!empty($medicaments_populaires)): ?>
            <div class="pgd-table-wrap">
                <table class="pgd-table pgd-table--cards">
                    <thead>
                        <tr>
                            <th>Médicament</th>
                            <th>DCI</th>
                            <th class="text-end">Recherches</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($medicaments_populaires as $med): ?>
                            <tr>
                                <td data-label="Médicament"><strong><?php echo htmlspecialchars($med['Nom_commercial']); ?></strong></td>
                                <td data-label="DCI"><?php echo htmlspecialchars($med['DCI'] ?? '-'); ?></td>
                                <td data-label="Recherches" class="text-end"><?php echo pgd_badge_statut('valide'); ?> <strong><?php echo (int) $med['total_recherches']; ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?= pgd_vide('Aucune recherche enregistrée', 'Les données apparaîtront ici lorsque les utilisateurs effectueront des recherches.', 'fa-magnifying-glass') ?>
        <?php endif; ?>
    </div>

    <div class="pgd-card">
        <div class="pgd-card__head">
            <h5><i class="fas fa-store"></i> Pharmacies les plus consultées</h5>
            <a href="<?php echo $base; ?>admin/pharmacies.php" class="pgd-card__link">Voir tout <i class="fas fa-arrow-right"></i></a>
        </div>
        <?php if (!empty($pharmacies_populaires)): ?>
            <div class="pgd-table-wrap">
                <table class="pgd-table pgd-table--cards">
                    <thead>
                        <tr>
                            <th>Pharmacie</th>
                            <th>Localisation</th>
                            <th class="text-end">Consultations</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pharmacies_populaires as $pharm): ?>
                            <tr>
                                <td data-label="Pharmacie"><strong><?php echo htmlspecialchars($pharm['Nom']); ?></strong></td>
                                <td data-label="Localisation"><?php echo htmlspecialchars($pharm['Quartier'] ?? $pharm['Commune'] ?? 'Porto-Novo'); ?></td>
                                <td data-label="Consultations" class="text-end"><span class="pgd-badge pgd-badge--info"><?php echo (int) $pharm['total_consultations']; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?= pgd_vide('Aucune consultation enregistrée', 'Les données apparaîtront ici lorsque les pharmacies seront consultées.', 'fa-store') ?>
        <?php endif; ?>
    </div>
</div>

<!-- ===== VALIDATIONS & ACTIVITÉ ===== -->
<div class="pgd-grid" style="grid-template-columns:5fr 7fr">
    <div class="pgd-card">
        <div class="pgd-card__head">
            <h5><i class="fas fa-clock" style="color:var(--pg-amber)"></i> Pharmacies en attente</h5>
            <?php if ($stats['total_pharmacies_attente'] > 0): ?>
                <a href="<?php echo $base; ?>admin/validation.php" class="pgd-card__link">
                    <span class="pgd-badge pgd-badge--danger"><?php echo (int) $stats['total_pharmacies_attente']; ?></span> Gérer
                </a>
            <?php endif; ?>
        </div>
        <div class="pgd-card__body">
            <?php if (!empty($pharmacies_attente)): ?>
                <div class="pgd-activity">
                    <?php foreach ($pharmacies_attente as $pharm): ?>
                        <div class="pgd-pending">
                            <span class="pgd-pending__avatar"><i class="fas fa-store"></i></span>
                            <div class="pgd-pending__info">
                                <strong><?php echo htmlspecialchars($pharm['Nom']); ?></strong>
                                <small><i class="fas fa-user"></i> <?php echo htmlspecialchars(trim($pharm['Prenom'] . ' ' . $pharm['Nom'])); ?></small>
                                <small><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($pharm['Quartier'] ?? 'Porto-Novo'); ?></small>
                            </div>
                            <div class="pgd-pending__actions">
                                <form method="POST" action="<?php echo $base; ?>admin/pharmacies.php?action=valider" style="display:inline">
                                    <input type="hidden" name="id_pharmacie" value="<?php echo (int) $pharm['Id_pharmacie']; ?>">
                                    <button type="submit" class="pgd-btn-icon pgd-btn-icon--success" data-tip="Valider cette pharmacie" title="Valider cette pharmacie">
                                        <i class="fas fa-check"></i>
                                    </button>
                                </form>
                                <form method="POST" action="<?php echo $base; ?>admin/pharmacies.php?action=rejeter" style="display:inline">
                                    <input type="hidden" name="id_pharmacie" value="<?php echo (int) $pharm['Id_pharmacie']; ?>">
                                    <button type="submit" class="pgd-btn-icon pgd-btn-icon--danger pgd-confirm"
                                            data-pgd-confirm="Rejeter la pharmacie <?php echo htmlspecialchars($pharm['Nom']); ?> ?"
                                            title="Rejeter cette pharmacie">
                                        <i class="fas fa-xmark"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <?= pgd_vide('Aucune pharmacie en attente', 'Toutes les pharmacies ont été validées.', 'fa-circle-check') ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="pgd-card">
        <div class="pgd-card__head">
            <h5><i class="fas fa-clock-rotate-left"></i> Dernières activités</h5>
            <a href="<?php echo $base; ?>admin/logs.php" class="pgd-card__link">Voir tout <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="pgd-card__body">
            <?php if (!empty($dernieres_activites)): ?>
                <div class="pgd-activity">
                    <?php foreach ($dernieres_activites as $log): ?>
                        <?php $succes = ($log['Resultat'] ?? '') === 'succes'; ?>
                        <div class="pgd-activity__item">
                            <span class="pgd-activity__icon pgd-activity__icon--<?php echo $succes ? 'teal' : 'red'; ?>">
                                <i class="fas fa-<?php echo $succes ? 'check' : 'xmark'; ?>"></i>
                            </span>
                            <div class="pgd-activity__text">
                                <p><strong><?php echo htmlspecialchars(trim($log['Prenom'] . ' ' . $log['Nom'])); ?></strong>
                                    <?php echo htmlspecialchars($log['Action']); ?></p>
                                <span class="pgd-activity__time">
                                    <i class="far fa-clock"></i> <?php echo date('d/m/Y à H:i', strtotime($log['Date_action'])); ?>
                                    · <i class="fas fa-globe"></i> <?php echo htmlspecialchars($log['Adresse_ip'] ?? 'Local'); ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <?= pgd_vide('Aucune activité récente', 'Les journaux apparaîtront ici dès qu\'une action sera effectuée.', 'fa-clock-rotate-left') ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var evolutionCanvas = document.getElementById('evolutionChart');
    <?php if (!empty($evolution)): ?>
    var evolutionData = <?php echo json_encode($evolution, JSON_UNESCAPED_UNICODE); ?>;
    var moisCourt = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];

    new Chart(evolutionCanvas.getContext('2d'), {
        type: 'line',
        data: {
            labels: evolutionData.map(function (item) {
                var parties = item.mois.split('-');
                return moisCourt[parseInt(parties[1], 10) - 1] + ' ' + parties[0];
            }),
            datasets: [
                {
                    label: 'Patients',
                    data: evolutionData.map(function (i) { return parseInt(i.patients || 0, 10); }),
                    borderColor: '#166534',
                    backgroundColor: 'rgba(22,83,52,0.10)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#166534'
                },
                {
                    label: 'Pharmaciens',
                    data: evolutionData.map(function (i) { return parseInt(i.pharmaciens || 0, 10); }),
                    borderColor: '#16A34A',
                    backgroundColor: 'rgba(22,163,74,0.10)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#16A34A'
                },
                {
                    label: 'Administrateurs',
                    data: evolutionData.map(function (i) { return parseInt(i.admins || 0, 10); }),
                    borderColor: '#14532D',
                    backgroundColor: 'rgba(20,83,45,0.08)',
                    fill: false,
                    tension: 0.4,
                    pointRadius: 3,
                    pointBackgroundColor: '#14532D',
                    borderDash: [5, 5]
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: {
                    position: 'top',
                    labels: { usePointStyle: true, padding: 18, font: { size: 11, family: 'Inter' } }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1, font: { size: 10 } },
                    grid: { color: 'rgba(15,61,34,0.06)' }
                },
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 10 } }
                }
            }
        }
    });
    <?php endif; ?>

    var donutCanvas = document.getElementById('donutChart');
    if (donutCanvas) {
        new Chart(donutCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Disponible', 'Stock faible', 'Rupture'],
                datasets: [{
                    data: [<?php echo (int) ($taux_disponibilite['disponibles'] ?? 0); ?>,
                           <?php echo (int) ($taux_disponibilite['stock_faible'] ?? 0); ?>,
                           <?php echo (int) ($taux_disponibilite['rupture'] ?? 0); ?>],
                    backgroundColor: ['#16A34A', '#166534', '#DC2626'],
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
    }
});
</script>
<?php
$scripts = ob_get_clean();

pgd_pied(['scripts' => $scripts]);
