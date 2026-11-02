<?php
// ============================================================
// PharmaGeo — Administration : Journaux d'activité (logs)
// Consultation des actions enregistrées sur la plateforme
// ============================================================

// ===== ACTIVATION DES ERREURS =====
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ===== DÉFINITION DES CONSTANTES =====
define('BASE_URL', 'http://localhost/pharmageo/');

// ===== GESTION DES IMAGES (photos de profil) =====
require_once __DIR__ . '/../includes/images.php';

// ===== PAGINATION (10 éléments par page) =====
require_once __DIR__ . '/../includes/pagination.php';

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

// ===== FILTRES =====
$filtre_action = isset($_GET['action']) ? trim($_GET['action']) : '';
$filtre_resultat = isset($_GET['resultat']) ? trim($_GET['resultat']) : '';
$filtre_date_debut = isset($_GET['date_debut']) ? trim($_GET['date_debut']) : '';
$filtre_date_fin = isset($_GET['date_fin']) ? trim($_GET['date_fin']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// ===== PAGINATION =====
$pagination = pagination_calculer(0);

// ===== RÉCUPÉRATION DES LOGS =====
$logs = [];
$total_logs = 0;
$actions_uniques = [];

try {
    // ===== LISTE DES ACTIONS DISPONIBLES =====
    $actions_uniques = $pdo->query("SELECT DISTINCT Action FROM log ORDER BY Action ASC")->fetchAll();

    // L'astuce WHERE 1=1 permet d'ajouter dynamiquement des filtres
    $sql = "SELECT l.*, u.Nom, u.Prenom, u.Email
            FROM log l
            LEFT JOIN utilisateur u ON l.Id_utilisateur = u.Id_utilisateur
            WHERE 1=1";
    // Le compteur doit reprendre la même jointure que la liste, sinon la
    // recherche sur u.Nom / u.Prenom / u.Email échoue (colonne inconnue).
    $count_sql = "SELECT COUNT(*) as total
            FROM log l
            LEFT JOIN utilisateur u ON l.Id_utilisateur = u.Id_utilisateur
            WHERE 1=1";
    $params = [];

    if ($filtre_action !== '') {
        $sql .= " AND l.Action = :action";
        $count_sql .= " AND l.Action = :action";
        $params[':action'] = $filtre_action;
    }
    if ($filtre_resultat !== '') {
        $sql .= " AND l.Resultat = :resultat";
        $count_sql .= " AND l.Resultat = :resultat";
        $params[':resultat'] = $filtre_resultat;
    }
    if (!empty($filtre_date_debut)) {
        $sql .= " AND DATE(l.Date_action) >= :date_debut";
        $count_sql .= " AND DATE(l.Date_action) >= :date_debut";
        $params[':date_debut'] = $filtre_date_debut;
    }
    if (!empty($filtre_date_fin)) {
        $sql .= " AND DATE(l.Date_action) <= :date_fin";
        $count_sql .= " AND DATE(l.Date_action) <= :date_fin";
        $params[':date_fin'] = $filtre_date_fin;
    }
    if (!empty($search)) {
        $sql .= " AND (l.Action LIKE :search OR u.Nom LIKE :search OR u.Prenom LIKE :search OR u.Email LIKE :search)";
        $count_sql .= " AND (l.Action LIKE :search OR u.Nom LIKE :search OR u.Prenom LIKE :search OR u.Email LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

$stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_logs = $stmt->fetch()['total'] ?? 0;

    $pagination = pagination_calculer($total_logs);

    // LIMIT/OFFSET injectés en dur : cette version de MariaDB refuse les
    // placeholders préparés dans LIMIT (cf. includes/pagination.php).
    $sql .= " ORDER BY l.Date_action DESC" . pagination_sql_limite($pagination);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Erreur lors du chargement des journaux.";
}

// ===== FONCTION D'AFFICHAGE DU RÉSULTAT =====
function badgeResultat($r) {
    return $r === 'succes'
        ? '<span class="badge badge-succes">Succès</span>'
        : '<span class="badge badge-echec">Échec</span>';
}

$page_title = 'Journaux d\'activité - PharmaGeo';
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
        .sidebar-admin {
            width: 250px; background: linear-gradient(180deg, #0F2E19, #14532D);
            color: #fff; flex-shrink: 0; display: flex; flex-direction: column;
            position: fixed; top: 0; bottom: 0; left: 0; overflow-y: auto; z-index: 100;
        }
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

        /* ===== CARTE & FILTRES ===== */
        .card-admin { background: #fff; border-radius: 16px; box-shadow: 0 8px 30px rgba(0,0,0,0.05); overflow: hidden; }
        .filter-bar { background: #F0FDF4; padding: 16px; border-bottom: 1px solid #F0FDF4; display: flex; flex-wrap: wrap; gap: 10px; }
        .filter-bar input, .filter-bar select { border: 1.5px solid #D9E9DF; border-radius: 8px; padding: 9px 12px; font-size: 0.9rem; }
        .badge-succes { background: rgba(22,163,74,0.12); color: #166534; }
        .badge-echec { background: rgba(220,38,38,0.12); color: #B91C1C; }
        /* ===== TABLEAU ===== */
        .table { margin: 0; }
        .table thead th { background: #F0FDF4; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--gray-text); border-bottom: 1px solid #F0FDF4; padding: 12px; white-space: nowrap; }
        .table tbody td { padding: 12px; vertical-align: middle; font-size: 0.9rem; border-color: #F0FDF4; }
        /* ===== PAGINATION ===== */
        .pagination-custom { display: flex; gap: 6px; justify-content: center; padding: 16px; }
        .pagination-custom a, .pagination-custom span { padding: 8px 13px; border-radius: 8px; text-decoration: none; font-size: 0.9rem; background: #fff; color: var(--dark-text); border: 1px solid #F0FDF4; }
        .pagination-custom a.current { background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); color: #fff; border: none; }
        .empty-state { text-align: center; padding: 60px 20px; color: var(--gray-text); }
        .empty-state i { font-size: 3rem; color: #D9E9DF; }
        @media (max-width: 991px) {
            .sidebar-admin { position: fixed; transform: translateX(-100%); transition: transform 0.3s ease; }
            .sidebar-admin.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
        }    </style>
    <link rel="stylesheet" href="../assets/css/responsive.css">    <!-- Palette verte PharmaGeo -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/palette.css">
</head>
<body>
    <div class="layout">
        <!-- ===== SIDEBAR ===== -->
        <aside class="sidebar-admin" id="sidebar">
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
                <a href="<?php echo BASE_URL; ?>admin/rapports.php" class="nav-item"><i class="fas fa-file-alt"></i><span>Rapports</span></a>
                <a href="<?php echo BASE_URL; ?>admin/logs.php" class="nav-item active"><i class="fas fa-history"></i><span>Journaux</span></a>
                <div class="nav-label" style="margin-top: 20px;">Système</div>
                <a href="<?php echo BASE_URL; ?>admin/parametres.php" class="nav-item"><i class="fas fa-cog"></i><span>Paramètres</span></a>

            </nav>
            <a class="user-profile" href="<?php echo BASE_URL; ?>admin/profil.php" title="Voir mon profil">
                <div class="avatar"><?php if (!empty($_SESSION['photo']) && pharma_image_disponible($_SESSION['photo'])): ?><img src="<?php echo htmlspecialchars(pharma_image_url($_SESSION['photo'])); ?>" alt=""><?php else: ?><?php echo strtoupper(substr($_SESSION['prenom'] ?? 'A', 0, 1) . substr($_SESSION['nom'] ?? 'D', 0, 1)); ?><?php endif; ?></div>
                <div>
                    <div class="name"><?php echo htmlspecialchars(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')); ?></div>
                    <div class="role"><i class="fas fa-crown me-1" style="color:#DC2626;"></i> Administrateur</div>
                <i class="fas fa-chevron-right profile-chevron"></i>
            </a>
        
    <?php require_once __DIR__ . '/../includes/reinitialiser.php'; echo html_bloc_reinitialiser(); ?>
    <a href="<?php echo BASE_URL; ?>deconnexion.php" class="nav-item" style="color: #FEF2F2; padding: 14px 20px; border-top: 1px solid rgba(255,255,255,0.15); margin-top: auto; text-decoration: none;">
        <i class="fas fa-sign-out-alt"></i>
        <span>Déconnexion</span>
    </a>
    </aside>

        <!-- ===== MAIN ===== -->
        <main class="main-content">
            <div class="top-bar">
                <div>
                    <h1><span class="highlight">Journaux</span> d'activité</h1>
                    <p style="color:var(--gray-text);font-size:0.9rem;margin:4px 0 0;"><i class="fas fa-history me-1" style="color:var(--primary-green);"></i> Suivi des actions sur la plateforme</p>
                </div>
                <a href="javascript:;" onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="fas fa-print me-1"></i>Imprimer</a>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="card-admin">
                <!-- ===== BARRE DE FILTRES ===== -->
                <form method="GET" class="filter-bar">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Rechercher (action, utilisateur, email)..." style="flex:1;min-width:220px;">
                    <select name="action">
                        <option value="">Toutes les actions</option>
                        <?php foreach ($actions_uniques as $a): ?>
                            <option value="<?php echo htmlspecialchars($a['Action']); ?>" <?php echo $filtre_action === $a['Action'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($a['Action']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="resultat">
                        <option value="">Tous les résultats</option>
                        <option value="succes" <?php echo $filtre_resultat === 'succes' ? 'selected' : ''; ?>>Succès</option>
                        <option value="echec" <?php echo $filtre_resultat === 'echec' ? 'selected' : ''; ?>>Échec</option>
                    </select>
                    <input type="date" name="date_debut" value="<?php echo htmlspecialchars($filtre_date_debut); ?>">
                    <input type="date" name="date_fin" value="<?php echo htmlspecialchars($filtre_date_fin); ?>">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i>Filtrer</button>
                    <a href="logs.php" class="btn btn-secondary btn-sm"><i class="fas fa-undo me-1"></i>Réinitialiser</a>
                </form>

                <?php if (!empty($logs)): ?>
                    <!-- ===== TABLEAU DES LOGS ===== -->
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                    <th>Utilisateur</th>
                                    <th>IP</th>
                                    <th>Navigateur</th>
                                    <th>Résultat</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $i => $log): ?>
                                    <tr>
                                        <td><?php echo $pagination['debut'] + $i; ?></td>
                                        <td class="text-nowrap"><?php echo date('d/m/Y H:i', strtotime($log['Date_action'])); ?></td>
                                        <td><i class="fas fa-circle me-1" style="font-size:0.5rem;color:var(--primary-green);"></i><?php echo htmlspecialchars($log['Action']); ?></td>
                                        <td>
                                            <?php if ($log['Id_utilisateur']): ?>
                                                <?php echo htmlspecialchars(($log['Prenom'] ?? '') . ' ' . ($log['Nom'] ?? '')); ?>
                                                <small class="text-muted d-block"><?php echo htmlspecialchars($log['Email'] ?? ''); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($log['Adresse_ip'] ?? '—'); ?></td>
                                        <td class="text-muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($log['Navigateur'] ?? ''); ?>"><?php echo htmlspecialchars($log['Navigateur'] ?? '—'); ?></td>
                                        <td><?php echo badgeResultat($log['Resultat'] ?? 'echec'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

<!-- ===== PAGINATION ===== -->
                    <?php pagination_afficher($pagination, [
                        'search'     => $search,
                        'action'     => $filtre_action,
                        'resultat'   => $filtre_resultat,
                        'date_debut' => $filtre_date_debut,
                        'date_fin'   => $filtre_date_fin
                    ], 'journaux'); ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <h5 class="mt-3">Aucun journal trouvé</h5>
                        <p>Aucune activité enregistrée avec ces critères.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar mobile
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key.toLowerCase() === 'b') {
                document.getElementById('sidebar').classList.toggle('open');
            }
        });
    </script>
</body>
    <script src="../assets/js/responsive.js"></script>
</html>
