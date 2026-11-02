<?php
// ============================================================
// PharmaGeo — Administration : Rapports
// Vue synthétique des indicateurs d'activité de la plateforme
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

// ===== PERIODE DU RAPPORT =====
$periode = isset($_GET['periode']) ? $_GET['periode'] : '30';
$export = isset($_GET['export']) ? $_GET['export'] : '';

// Date de début selon la période
$date_debut = date('Y-m-d');
switch ($periode) {
    case '7':   $date_debut = date('Y-m-d', strtotime('-7 days'));   break;
    case '30':  $date_debut = date('Y-m-d', strtotime('-30 days'));  break;
    case '90':  $date_debut = date('Y-m-d', strtotime('-90 days'));  break;
    case '365': $date_debut = date('Y-m-d', strtotime('-365 days')); break;
    // Date très ancienne pour couvrir toutes les données
    case 'all': $date_debut = '1970-01-01'; break;
    default:    $date_debut = date('Y-m-d', strtotime('-30 days'));
}

// ===== AGREGATS =====
$stats = [];
$reservations_evolution = [];
$top_medicaments = [];
$top_pharmacies = [];
$repartition_reservations = [];

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as t FROM reservation WHERE Date_reservation >= :debut");
    $stmt->execute([':debut' => $date_debut]);
    $stats['reservations'] = (int)$stmt->fetch()['t'];

    $stmt = $pdo->prepare("SELECT COUNT(*) as t FROM reservation WHERE Statut = 'confirmee' AND Date_reservation >= :debut");
    $stmt->execute([':debut' => $date_debut]);
    $stats['reservations_confirmees'] = (int)$stmt->fetch()['t'];

    $stmt = $pdo->prepare("SELECT COUNT(*) as t FROM utilisateur WHERE Date_creation >= :debut");
    $stmt->execute([':debut' => $date_debut]);
    $stats['inscriptions'] = (int)$stmt->fetch()['t'];

    $stats['utilisateurs_total'] = (int)$pdo->query("SELECT COUNT(*) as t FROM utilisateur")->fetch()['t'];
    $stats['pharmacies_total'] = (int)$pdo->query("SELECT COUNT(*) as t FROM pharmacie")->fetch()['t'];
    $stats['medicaments_total'] = (int)$pdo->query("SELECT COUNT(*) as t FROM medicament")->fetch()['t'];

    $stmt = $pdo->prepare("SELECT COUNT(*) as t FROM recherche WHERE Date_recherche >= :debut");
    $stmt->execute([':debut' => $date_debut]);
    $stats['recherches_total'] = (int)$stmt->fetch()['t'];

    // Évolution des réservations par jour
    $reservations_evolution = $pdo->prepare("
        SELECT DATE(Date_reservation) as jour, COUNT(*) as total,
               SUM(CASE WHEN Statut = 'confirmee' THEN 1 ELSE 0 END) as confirmees
        FROM reservation
        WHERE Date_reservation >= :debut
        GROUP BY DATE(Date_reservation)
        ORDER BY jour ASC
    ");
    $reservations_evolution->execute([':debut' => $date_debut]);
    $reservations_evolution = $reservations_evolution->fetchAll();

    // Médicaments les plus réservés
    $top_medicaments = $pdo->prepare("
        SELECT m.Nom_commercial, m.DCI, COUNT(r.Id_reservation) as total
        FROM reservation r
        JOIN stock s ON r.Id_stock = s.Id_stock
        JOIN medicament m ON s.Id_medicament = m.Id_medicament
        WHERE r.Date_reservation >= :debut
        GROUP BY m.Id_medicament
        ORDER BY total DESC
        LIMIT 10
    ");
    $top_medicaments->execute([':debut' => $date_debut]);
    $top_medicaments = $top_medicaments->fetchAll();

    // Pharmacies les plus actives
    $top_pharmacies = $pdo->prepare("
        SELECT p.Nom, p.Quartier, p.Commune, COUNT(r.Id_reservation) as total,
               COUNT(DISTINCT r.Id_utilisateur) as patients
        FROM reservation r
        JOIN stock s ON r.Id_stock = s.Id_stock
        JOIN pharmacie p ON s.Id_pharmacie = p.Id_pharmacie
        WHERE r.Date_reservation >= :debut
        GROUP BY p.Id_pharmacie
        ORDER BY total DESC
        LIMIT 10
    ");
    $top_pharmacies->execute([':debut' => $date_debut]);
    $top_pharmacies = $top_pharmacies->fetchAll();

    // Répartition des réservations par statut
    $repartition_reservations = $pdo->prepare("
        SELECT Statut, COUNT(*) as total
        FROM reservation
        WHERE Date_reservation >= :debut
        GROUP BY Statut
    ");
    $repartition_reservations->execute([':debut' => $date_debut]);
    $repartition_reservations = $repartition_reservations->fetchAll();

    // ===== EXPORT CSV =====
    if ($export === 'reservations') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rapport_reservations.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Statut', 'Nombre']);
        foreach ($repartition_reservations as $row) {
            fputcsv($out, [$row['Statut'], $row['total']]);
        }
        fputcsv($out, ['Date', 'Réservations', 'Confirmées']);
        foreach ($reservations_evolution as $row) {
            fputcsv($out, [$row['jour'], $row['total'], $row['confirmees']]);
        }
        fclose($out);
        exit;
    }
    if ($export === 'medicaments') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rapport_medicaments.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Médicament', 'DCI', 'Réservations']);
        foreach ($top_medicaments as $row) {
            fputcsv($out, [$row['Nom_commercial'], $row['DCI'], $row['total']]);
        }
        fclose($out);
        exit;
    }
    if ($export === 'pharmacies') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rapport_pharmacies.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Pharmacie', 'Quartier', 'Commune', 'Réservations', 'Patients']);
        foreach ($top_pharmacies as $row) {
            fputcsv($out, [$row['Nom'], $row['Quartier'], $row['Commune'], $row['total'], $row['patients']]);
        }
        fclose($out);
        exit;
    }
} catch(PDOException $e) {
    $error = "Erreur lors de la génération du rapport.";
}

$page_title = 'Rapports - PharmaGeo';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* ===== VARIABLES ===== */
        :root { --primary-green: #16A34A; --primary-orange: #B91C1C; --dark-text: #0F2E19; --gray-text: #64748b; }
        * { box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: #F0FDF4; margin: 0; }
        .layout { display: flex; min-height: 100vh; }
        /* ===== SIDEBAR ===== */
        .sidebar-admin { width: 250px; background: linear-gradient(180deg, #0F2E19, #14532D); color: #fff; flex-shrink: 0; display: flex; flex-direction: column; position: fixed; top: 0; bottom: 0; left: 0; overflow-y: auto; z-index: 100; }
        .brand-admin { padding: 22px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .brand-admin .brand-icon { width: 40px; height: 40px; border-radius: 12px; background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); display: flex; align-items: center; justify-content: center;  color: #fff;}
        .brand-admin h4 { margin: 0; font-weight: 800; font-size: 1.1rem; 
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
        /* ===== CONTENU PRINCIPAL ===== */
        .main-content { flex: 1; margin-left: 250px; padding: 24px; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
        .top-bar h1 { font-size: 1.5rem; font-weight: 800; color: var(--dark-text); margin: 0; }
        .top-bar .highlight { background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        /* ===== STATISTIQUES ===== */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
        .stat-mini { background: #fff; border-radius: 14px; padding: 18px; box-shadow: 0 8px 30px rgba(0,0,0,0.05); }
        .stat-mini .num { font-size: 1.7rem; font-weight: 800; color: var(--dark-text); }
        .stat-mini .lbl { color: var(--gray-text); font-size: 0.85rem; }
        .card-admin { background: #fff; border-radius: 16px; box-shadow: 0 8px 30px rgba(0,0,0,0.05); margin-bottom: 24px; padding: 20px; }
        .card-admin h5 { font-weight: 700; color: var(--dark-text); margin-bottom: 16px; }
        /* ===== SÉLECTEUR DE PÉRIODE ===== */
        .periode-bar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 20px; }
        .periode-btn { padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 0.9rem; color: var(--dark-text); background: #fff; border: 1px solid #D9E9DF; }
        .periode-btn.active { background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); color: #fff; font-weight: 600; border: none; }
        /* ===== TABLEAU ===== */
        .table { margin: 0; }
        .table thead th { background: #F0FDF4; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--gray-text); border-bottom: 1px solid #F0FDF4; padding: 12px; white-space: nowrap; }
        .table tbody td { padding: 12px; vertical-align: middle; font-size: 0.9rem; border-color: #F0FDF4; }
        @media (max-width: 991px) { .sidebar-admin { transform: translateX(-100%); } .main-content { margin-left: 0; } .stats-grid { grid-template-columns: repeat(2, 1fr); } }    </style>
    <link rel="stylesheet" href="../assets/css/responsive.css">    <!-- Palette verte PharmaGeo -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/palette.css">
</head>
<body>
    <div class="layout">
        <!-- ===== SIDEBAR ===== -->
        <aside class="sidebar-admin">
            <a href="<?php echo BASE_URL; ?>index.php" class="brand-admin" style="text-decoration: none;">
                <div class="brand-icon"><i class="fas fa-hospital"></i></div>
                <div><h4>PharmaGeo</h4><small>Administration</small></div>
            </a>
            <nav class="nav-menu">
                <div class="nav-label">Navigation</div>
                <a href="<?php echo BASE_URL; ?>admin/dashboard.php" class="nav-item"><i class="fas fa-tachometer-alt"></i><span>Tableau de bord</span></a>
                <a href="<?php echo BASE_URL; ?>admin/pharmacies.php" class="nav-item"><i class="fas fa-store"></i><span>Pharmacies</span></a>
                <a href="<?php echo BASE_URL; ?>admin/utilisateurs.php" class="nav-item"><i class="fas fa-users"></i><span>Utilisateurs</span></a>
                <a href="<?php echo BASE_URL; ?>admin/categories.php" class="nav-item"><i class="fas fa-tags"></i><span>Catégories</span></a>
                <a href="<?php echo BASE_URL; ?>admin/medicaments.php" class="nav-item"><i class="fas fa-capsules"></i><span>Médicaments</span></a>
                <a href="<?php echo BASE_URL; ?>admin/reservations.php" class="nav-item"><i class="fas fa-calendar-check"></i><span>Réservations</span></a>
                <a href="<?php echo BASE_URL; ?>admin/statistiques.php" class="nav-item"><i class="fas fa-chart-bar"></i><span>Statistiques</span></a>
                <a href="<?php echo BASE_URL; ?>admin/rapports.php" class="nav-item active"><i class="fas fa-file-alt"></i><span>Rapports</span></a>
                <a href="<?php echo BASE_URL; ?>admin/logs.php" class="nav-item"><i class="fas fa-history"></i><span>Journaux</span></a>
                <a href="<?php echo BASE_URL; ?>admin/validation.php" class="nav-item"><i class="fas fa-check-double"></i><span>Validation</span></a>
                <div class="nav-label" style="margin-top: 20px;">Système</div>
                <a href="<?php echo BASE_URL; ?>admin/parametres.php" class="nav-item"><i class="fas fa-cog"></i><span>Paramètres</span></a>

            </nav>
            <a class="user-profile" href="<?php echo BASE_URL; ?>admin/profil.php" title="Voir mon profil">
                <div class="avatar"><?php if (!empty($_SESSION['photo']) && pharma_image_disponible($_SESSION['photo'])): ?><img src="<?php echo htmlspecialchars(pharma_image_url($_SESSION['photo'])); ?>" alt=""><?php else: ?><?php echo strtoupper(substr($_SESSION['prenom'] ?? 'A', 0, 1) . substr($_SESSION['nom'] ?? 'D', 0, 1)); ?><?php endif; ?></div>
                <i class="fas fa-chevron-right profile-chevron"></i>
            </a>
        
    <?php require_once __DIR__ . '/../includes/reinitialiser.php'; echo html_bloc_reinitialiser(); ?>
    <a href="<?php echo BASE_URL; ?>deconnexion.php" class="nav-item" style="color: #FEF2F2; padding: 14px 20px; border-top: 1px solid rgba(255,255,255,0.15); margin-top: auto; text-decoration: none;">
        <i class="fas fa-sign-out-alt"></i>
        <span>Déconnexion</span>
    </a>
    </aside>

        <main class="main-content">
            <!-- ===== TOP BAR ===== -->
            <div class="top-bar">
                <div>
                    <h1><span class="highlight">Rapports</span> de la plateforme</h1>
                    <p style="color:var(--gray-text);font-size:0.9rem;margin:4px 0 0;"><i class="fas fa-file-alt me-1" style="color:var(--primary-green);"></i> Indicateurs synthétiques de l'activité</p>
                </div>
                <div class="dropdown">
                    <button class="btn btn-dark btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="fas fa-file-export me-1"></i>Exporter</button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="rapports.php?periode=<?php echo $periode; ?>&export=reservations"><i class="fas fa-calendar-check me-2"></i>Réservations (CSV)</a></li>
                        <li><a class="dropdown-item" href="rapports.php?periode=<?php echo $periode; ?>&export=medicaments"><i class="fas fa-capsules me-2"></i>Médicaments (CSV)</a></li>
                        <li><a class="dropdown-item" href="rapports.php?periode=<?php echo $periode; ?>&export=pharmacies"><i class="fas fa-store me-2"></i>Pharmacies (CSV)</a></li>
                    </ul>
                </div>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- ===== PERIODE ===== -->
            <div class="periode-bar">
                <span class="me-1 text-muted small"><i class="fas fa-filter me-1"></i>Période :</span>
                <a href="rapports.php?periode=7" class="periode-btn <?php echo $periode === '7' ? 'active' : ''; ?>">7 jours</a>
                <a href="rapports.php?periode=30" class="periode-btn <?php echo $periode === '30' ? 'active' : ''; ?>">30 jours</a>
                <a href="rapports.php?periode=90" class="periode-btn <?php echo $periode === '90' ? 'active' : ''; ?>">90 jours</a>
                <a href="rapports.php?periode=365" class="periode-btn <?php echo $periode === '365' ? 'active' : ''; ?>">1 an</a>
                <a href="rapports.php?periode=all" class="periode-btn <?php echo $periode === 'all' ? 'active' : ''; ?>">Tout</a>
            </div>

            <!-- ===== STATS ===== -->
            <div class="stats-grid">
                <div class="stat-mini"><div class="num" style="color:var(--primary-green);"><?php echo $stats['reservations']; ?></div><div class="lbl"><i class="fas fa-calendar-check me-1"></i>Réservations</div></div>
                <div class="stat-mini"><div class="num" style="color:var(--primary-orange);"><?php echo $stats['reservations_confirmees']; ?></div><div class="lbl"><i class="fas fa-check-circle me-1"></i>Confirmées</div></div>
                <div class="stat-mini"><div class="num" style="color:#166534;"><?php echo $stats['recherches_total']; ?></div><div class="lbl"><i class="fas fa-search me-1"></i>Recherches</div></div>
                <div class="stat-mini"><div class="num"><?php echo $stats['inscriptions']; ?></div><div class="lbl"><i class="fas fa-user-plus me-1"></i>Inscriptions</div></div>
            </div>

            <div class="row g-4">
                <!-- ===== ÉVOLUTION RÉSERVATIONS ===== -->
                <div class="col-lg-8">
                    <div class="card-admin">
                        <h5><i class="fas fa-chart-line me-2" style="color:var(--primary-green);"></i>Évolution des réservations</h5>
                        <div style="height:280px;"><canvas id="chartEvolution"></canvas></div>
                    </div>
                </div>

                <!-- ===== RÉPARTITION PAR STATUT ===== -->
                <div class="col-lg-4">
                    <div class="card-admin">
                        <h5><i class="fas fa-chart-pie me-2" style="color:var(--primary-orange);"></i>Répartition par statut</h5>
                        <div style="height:280px;"><canvas id="chartRepartition"></canvas></div>
                    </div>
                </div>
            </div>

            <!-- ===== TOP MÉDICAMENTS ===== -->
            <div class="card-admin">
                <h5><i class="fas fa-capsules me-2" style="color:var(--primary-green);"></i>Médicaments les plus réservés</h5>
                <?php if (!empty($top_medicaments)): ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead><tr><th>#</th><th>Médicament</th><th>DCI</th><th>Réservations</th></tr></thead>
                            <tbody>
                                <?php foreach ($top_medicaments as $i => $m): ?>
                                    <tr>
                                        <td><?php echo $i + 1; ?></td>
                                        <td class="fw-bold"><?php echo htmlspecialchars($m['Nom_commercial']); ?></td>
                                        <td><?php echo htmlspecialchars($m['DCI'] ?? '—'); ?></td>
                                        <td><span class="badge bg-success"><?php echo $m['total']; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted text-center py-4">Aucune donnée sur la période.</p>
                <?php endif; ?>
            </div>

            <!-- ===== TOP PHARMACIES ===== -->
            <div class="card-admin">
                <h5><i class="fas fa-store me-2" style="color:var(--primary-orange);"></i>Pharmacies les plus actives</h5>
                <?php if (!empty($top_pharmacies)): ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead><tr><th>#</th><th>Pharmacie</th><th>Localisation</th><th>Réservations</th><th>Patients</th></tr></thead>
                            <tbody>
                                <?php foreach ($top_pharmacies as $i => $p): ?>
                                    <tr>
                                        <td><?php echo $i + 1; ?></td>
                                        <td class="fw-bold"><?php echo htmlspecialchars($p['Nom']); ?></td>
                                        <td><?php echo htmlspecialchars($p['Quartier'] ?? ''); ?> - <?php echo htmlspecialchars($p['Commune'] ?? ''); ?></td>
                                        <td><span class="badge bg-success"><?php echo $p['total']; ?></span></td>
                                        <td><?php echo $p['patients']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted text-center py-4">Aucune donnée sur la période.</p>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Évolution des réservations
        var evolData = <?php echo json_encode($reservations_evolution); ?>;
        var ctxEvol = document.getElementById('chartEvolution');
        if (ctxEvol && evolData.length) {
            new Chart(ctxEvol, {
                type: 'line',
                data: {
                    labels: evolData.map(r => r.jour),
                    datasets: [
                        { label: 'Réservations', data: evolData.map(r => r.total), borderColor: '#16A34A', backgroundColor: 'rgba(22,163,74,0.1)', fill: true, tension: 0.3 },
                        { label: 'Confirmées', data: evolData.map(r => r.confirmees), borderColor: '#B91C1C', tension: 0.3 }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });
        }

        // Répartition par statut
        var repartData = <?php echo json_encode($repartition_reservations); ?>;
        var ctxRepart = document.getElementById('chartRepartition');
        if (ctxRepart && repartData.length) {
            new Chart(ctxRepart, {
                type: 'doughnut',
                data: {
                    labels: repartData.map(r => r.Statut),
                    datasets: [{
                        data: repartData.map(r => r.total),
                        backgroundColor: ['#16A34A', '#B91C1C', '#DC2626', '#166534', '#1F2937', '#64748B']
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });
        }
    </script>
</body>
    <script src="../assets/js/responsive.js"></script>
</html>
