<?php
// ============================================================
// PharmaGeo — Administration : Validation des pharmacies
// Approuver ou refuser les nouvelles pharmacies enregistrées
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

$message = '';
$message_type = '';

// ===== TRAITEMENT ACTIONS =====
if (isset($_POST['action']) && isset($_POST['id'])) {
    $action = $_POST['action'];
    $id = (int)$_POST['id'];

    try {
        if ($action === 'valider') {
            // Valider la pharmacie
            $stmt = $pdo->prepare("UPDATE pharmacie SET Statut = 'active' WHERE Id_pharmacie = :id");
            $stmt->execute([':id' => $id]);

            // Valider le pharmacien associé
            $stmt = $pdo->prepare("
                UPDATE pharmacien ph
                JOIN pharmacie p ON p.Id_pharmacien = ph.Id_pharmacien
                SET ph.Statut_validation = 'valide', ph.Date_validation = NOW()
                WHERE p.Id_pharmacie = :id
            ");
            $stmt->execute([':id' => $id]);

            $message = "Pharmacie validée avec succès.";
            $message_type = 'success';
        } elseif ($action === 'refuser') {
            $stmt = $pdo->prepare("UPDATE pharmacie SET Statut = 'suspendue' WHERE Id_pharmacie = :id");
            $stmt->execute([':id' => $id]);

            $stmt = $pdo->prepare("
                UPDATE pharmacien ph
                JOIN pharmacie p ON p.Id_pharmacien = ph.Id_pharmacien
                SET ph.Statut_validation = 'refuse'
                WHERE p.Id_pharmacie = :id
            ");
            $stmt->execute([':id' => $id]);

            $message = "Pharmacie refusée.";
            $message_type = 'warning';
        } elseif ($action === 'suspendre') {
            $stmt = $pdo->prepare("UPDATE pharmacie SET Statut = 'suspendue' WHERE Id_pharmacie = :id");
            $stmt->execute([':id' => $id]);

            $message = "Pharmacie suspendue.";
            $message_type = 'warning';
        } elseif ($action === 'reactiver') {
            $stmt = $pdo->prepare("UPDATE pharmacie SET Statut = 'active' WHERE Id_pharmacie = :id");
            $stmt->execute([':id' => $id]);

            $message = "Pharmacie réactivée.";
            $message_type = 'success';
        }

        // Journalisation
        try {
            $log = $pdo->prepare("INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur)
                                  VALUES (:action, :ip, :ua, 'succes', :user)");
            $log->execute([
                ':action' => 'Validation : ' . $action . ' (pharmacie #' . $id . ')',
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':user' => $_SESSION['user_id']
            ]);
        } catch(PDOException $e) {
            // Ignorer
        }
    } catch(PDOException $e) {
        $message = "Erreur lors de l'opération.";
        $message_type = 'danger';
    }
}

// ===== STATISTIQUES + LISTE =====
try {
    $stats = [
        'en_attente' => $pdo->query("SELECT COUNT(*) as t FROM pharmacie WHERE Statut = 'en_attente'")->fetch()['t'] ?? 0,
        'actives' => $pdo->query("SELECT COUNT(*) as t FROM pharmacie WHERE Statut = 'active'")->fetch()['t'] ?? 0,
        'suspendues' => $pdo->query("SELECT COUNT(*) as t FROM pharmacie WHERE Statut = 'suspendue'")->fetch()['t'] ?? 0,
        'total' => $pdo->query("SELECT COUNT(*) as t FROM pharmacie")->fetch()['t'] ?? 0
    ];

$filtre = isset($_GET['filtre']) ? $_GET['filtre'] : 'en_attente';

    $from = "FROM pharmacie p
             JOIN pharmacien ph ON p.Id_pharmacien = ph.Id_pharmacien
             JOIN utilisateur u ON ph.Id_utilisateur = u.Id_utilisateur";

    if ($filtre === 'en_attente') {
        $clauses = " WHERE p.Statut = 'en_attente'";
    } elseif ($filtre === 'actives') {
        $clauses = " WHERE p.Statut = 'active'";
    } elseif ($filtre === 'suspendues') {
        $clauses = " WHERE p.Statut = 'suspendue'";
    } else {
        $clauses = '';
    }

    // Nombre total de pharmacies pour l'onglet courant
    $stmt_count = $pdo->query("SELECT COUNT(*) $from$clauses");
    $pagination = pagination_calculer((int) $stmt_count->fetchColumn());

    // La sous-requête compte les médicaments en stock de chaque pharmacie.
    // Seule la page courante est chargée (pagination côté serveur).
    $sql = "SELECT
                p.*, u.Nom, u.Prenom, u.Email, u.Telephone as Utilisateur_Tel,
                ph.Numero_ordre, ph.Statut_validation, ph.Date_validation,
                (SELECT COUNT(*) FROM stock WHERE Id_pharmacie = p.Id_pharmacie) as total_medicaments
            $from$clauses
            ORDER BY p.Id_pharmacie DESC" . pagination_sql_limite($pagination);

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $pharmacies = $stmt->fetchAll();
} catch(PDOException $e) {
    $pharmacies = [];
    $pagination = pagination_calculer(0);
    $stats = ['en_attente' => 0, 'actives' => 0, 'suspendues' => 0, 'total' => 0];
}

$page_title = 'Validation - PharmaGeo';
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
        .stat-mini .num { font-size: 1.8rem; font-weight: 800; color: var(--dark-text); }
        .stat-mini .lbl { color: var(--gray-text); font-size: 0.85rem; }
        .card-admin { background: #fff; border-radius: 16px; box-shadow: 0 8px 30px rgba(0,0,0,0.05); }
        .tabs { display: flex; gap: 8px; padding: 16px; border-bottom: 1px solid #F0FDF4; flex-wrap: wrap; }
        .tab { padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 0.9rem; color: var(--dark-text); background: #F0FDF4; }
        .tab.active { background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); color: #fff; font-weight: 600; }
        /* ===== TABLEAU ===== */
        .table { margin: 0; }
        .table thead th { background: #F0FDF4; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--gray-text); border-bottom: 1px solid #F0FDF4; padding: 12px; white-space: nowrap; }
        .table tbody td { padding: 12px; vertical-align: middle; font-size: 0.9rem; border-color: #F0FDF4; }
        .badge-statut { padding: 4px 10px; border-radius: 50px; font-size: 0.78rem; font-weight: 600; }
        .empty-state { text-align: center; padding: 60px 20px; color: var(--gray-text); }
        .empty-state i { font-size: 3rem; color: #D9E9DF; }
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
                <a href="<?php echo BASE_URL; ?>admin/rapports.php" class="nav-item"><i class="fas fa-file-alt"></i><span>Rapports</span></a>
                <a href="<?php echo BASE_URL; ?>admin/logs.php" class="nav-item"><i class="fas fa-history"></i><span>Journaux</span></a>
                <a href="<?php echo BASE_URL; ?>admin/validation.php" class="nav-item active"><i class="fas fa-check-double"></i><span>Validation</span></a>
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
                    <h1><span class="highlight">Validation</span> des pharmacies</h1>
                    <p style="color:var(--gray-text);font-size:0.9rem;margin:4px 0 0;"><i class="fas fa-check-double me-1" style="color:var(--primary-green);"></i> Approuvez les nouvelles pharmacies</p>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>"><i class="fas fa-info-circle me-2"></i><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <!-- ===== STATS ===== -->
            <div class="stats-grid">
                <div class="stat-mini"><div class="num" style="color:var(--primary-orange);"><?php echo $stats['en_attente']; ?></div><div class="lbl"><i class="fas fa-clock me-1"></i>En attente</div></div>
                <div class="stat-mini"><div class="num" style="color:var(--primary-green);"><?php echo $stats['actives']; ?></div><div class="lbl"><i class="fas fa-check-circle me-1"></i>Actives</div></div>
                <div class="stat-mini"><div class="num" style="color:#DC2626;"><?php echo $stats['suspendues']; ?></div><div class="lbl"><i class="fas fa-ban me-1"></i>Suspendues</div></div>
                <div class="stat-mini"><div class="num"><?php echo $stats['total']; ?></div><div class="lbl"><i class="fas fa-store me-1"></i>Total</div></div>
            </div>

            <div class="card-admin">
                <!-- ===== TABS ===== -->
                <div class="tabs">
                    <a href="validation.php?filtre=en_attente" class="tab <?php echo $filtre === 'en_attente' ? 'active' : ''; ?>"><i class="fas fa-clock me-1"></i>En attente</a>
                    <a href="validation.php?filtre=actives" class="tab <?php echo $filtre === 'actives' ? 'active' : ''; ?>"><i class="fas fa-check-circle me-1"></i>Actives</a>
                    <a href="validation.php?filtre=suspendues" class="tab <?php echo $filtre === 'suspendues' ? 'active' : ''; ?>"><i class="fas fa-ban me-1"></i>Suspendues</a>
                    <a href="validation.php?filtre=toutes" class="tab <?php echo $filtre === 'toutes' ? 'active' : ''; ?>"><i class="fas fa-store me-1"></i>Toutes</a>
                </div>

                <?php if (!empty($pharmacies)): ?>
                    <!-- ===== LISTE DES PHARMACIES ===== -->
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Pharmacie</th>
                                    <th>Pharmacien</th>
                                    <th>Contact</th>
                                    <th>Numéro d'ordre</th>
                                    <th>Statut</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pharmacies as $p): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($p['Nom']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($p['Quartier'] ?? ''); ?> - <?php echo htmlspecialchars($p['Commune'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars(($p['Prenom'] ?? '') . ' ' . ($p['Nom'] ?? '')); ?>
                                            <small class="text-muted d-block"><?php echo htmlspecialchars($p['Email'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <small class="d-block"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($p['Telephone'] ?? '—'); ?></small>
                                            <small class="d-block text-muted"><?php echo (int)$p['total_medicaments']; ?> médicament(s)</small>
                                        </td>
                                        <td><?php echo htmlspecialchars($p['Numero_ordre'] ?? '—'); ?></td>
                                        <td>
                                            <?php
                                            $s = $p['Statut'];
                                            $cls = $s === 'active' ? 'text-success' : ($s === 'en_attente' ? 'text-warning' : 'text-danger');
                                            ?>
                                            <span class="badge-statut <?php echo $cls; ?> bg-light"><?php echo htmlspecialchars($s); ?></span>
                                        </td>
                                        <td style="white-space:nowrap;">
                                            <?php if ($p['Statut'] === 'en_attente'): ?>
                                                <form method="POST" action="" class="d-inline">
                                                    <input type="hidden" name="id" value="<?php echo $p['Id_pharmacie']; ?>">
                                                    <button type="submit" name="action" value="valider" class="btn btn-success btn-sm"><i class="fas fa-check me-1"></i>Valider</button>
                                                </form>
                                                <form method="POST" action="" class="d-inline" onsubmit="return confirm('Refuser cette pharmacie ?');">
                                                    <input type="hidden" name="id" value="<?php echo $p['Id_pharmacie']; ?>">
                                                    <button type="submit" name="action" value="refuser" class="btn btn-danger btn-sm"><i class="fas fa-times me-1"></i>Refuser</button>
                                                </form>
                                            <?php elseif ($p['Statut'] === 'active'): ?>
                                                <form method="POST" action="" class="d-inline" onsubmit="return confirm('Suspendre cette pharmacie ?');">
                                                    <input type="hidden" name="id" value="<?php echo $p['Id_pharmacie']; ?>">
                                                    <button type="submit" name="action" value="suspendre" class="btn btn-outline-warning btn-sm"><i class="fas fa-pause me-1"></i>Suspendre</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" action="" class="d-inline">
                                                    <input type="hidden" name="id" value="<?php echo $p['Id_pharmacie']; ?>">
                                                    <button type="submit" name="action" value="reactiver" class="btn btn-outline-success btn-sm"><i class="fas fa-play me-1"></i>Réactiver</button>
                                                </form>
                                            <?php endif; ?>
                                            <a href="<?php echo BASE_URL; ?>admin/pharmacies.php?id=<?php echo $p['Id_pharmacie']; ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-eye"></i></a>
                                        </td>
                                    </tr>
<?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- ===== PAGINATION ===== -->
                    <?php pagination_afficher($pagination, ['filtre' => $filtre], 'pharmacies'); ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <h5 class="mt-3">Aucune pharmacie dans cette catégorie</h5>
                        <p>Essayez un autre filtre.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
    <script src="../assets/js/responsive.js"></script>
</html>
