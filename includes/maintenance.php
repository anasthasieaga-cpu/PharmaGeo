<?php
// ============================================================
// PharmaGeo — Mode maintenance
// Lecture/écriture du paramètre maintenance_mode dans la table
// `settings` et blocage de la plateforme pour tous les
// utilisateurs qui ne sont pas administrateurs.
//
// Ce fichier est autonome : il n'exécute aucun include et n'exécute
// que des `define()` protégés pour rester compatible avec les pages
// qui chargent includes/config.php (chargé plus tard dans la requête).
// ============================================================

/**
 * Accès aux paramètres de connexion MySQL.
 * Réutilise les constantes définies par includes/config.php si elles
 * existent déjà, sinon retombe sur les valeurs par défaut (XAMPP)
 * SANS définir de constante globale (pour ne pas écraser config.php).
 */
function maintenance_parametres_bdd() {
    return [
        'hote' => defined('DB_HOST') ? DB_HOST : 'localhost',
        'base' => defined('DB_NAME') ? DB_NAME : 'gestion_soutenance',
        'user' => defined('DB_USER') ? DB_USER : 'root',
        'pass' => defined('DB_PASS') ? DB_PASS : ''
    ];
}

/**
 * Connexion PDO dédiée à la gestion du mode maintenance.
 * Retourne null si la base est inaccessible (aucune levée d'exception).
 */
function maintenance_obtenir_pdo() {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $params = maintenance_parametres_bdd();
    try {
        $pdo = new PDO(
            'mysql:host=' . $params['hote'] . ';dbname=' . $params['base'] . ';charset=utf8mb4',
            $params['user'],
            $params['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]
        );
    } catch (PDOException $e) {
        return null;
    }
    return $pdo;
}

/**
 * Indique si le mode maintenance est actuellement actif.
 * $forcer_relecture = true relit la base et ignore le cache de la requête
 * (utilisé après un enregistrement pour refléter immédiatement le nouvel état).
 */
function maintenance_est_active($forcer_relecture = false) {
    static $actif = null;
    if ($actif !== null && !$forcer_relecture) {
        return $actif;
    }
    $pdo = maintenance_obtenir_pdo();
    if ($pdo === null) {
        $actif = false;
        return $actif;
    }
    try {
        $stmt = $pdo->prepare("SELECT Valeur FROM settings WHERE Cle = :cle");
        $stmt->execute([':cle' => 'maintenance_mode']);
        $row = $stmt->fetch();
        $actif = ($row && $row['Valeur'] === '1');
    } catch (PDOException $e) {
        $actif = false;
    }
    return $actif;
}

/**
 * Enregistre le mode maintenance (true = actif, false = inactif).
 * Crée la table `settings` si besoin.
 */
function maintenance_enregistrer($actif) {
    $pdo = maintenance_obtenir_pdo();
    if ($pdo === null) {
        return;
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                Cle              VARCHAR(50) NOT NULL,
                Valeur           TEXT DEFAULT NULL,
                Date_mise_a_jour TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (Cle)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        // déjà créée ou aucune permission
    }
    $valeur = $actif ? '1' : '0';
    try {
        $stmt = $pdo->prepare("INSERT INTO settings (Cle, Valeur) VALUES (:cle, :valeur)
                               ON DUPLICATE KEY UPDATE Valeur = VALUES(Valeur)");
        $stmt->execute([':cle' => 'maintenance_mode', ':valeur' => $valeur]);
    } catch (PDOException $e) {
        // ignorer silencieusement
    }
    // Invalide le cache pour que la page en cours reflète l'état enregistré.
    maintenance_est_active(true);
}

/**
 * Chemin de la page courante relatif à la racine du projet
 * (ex : "/connexion.php", "/admin/parametres.php").
 */
function maintenance_chemin_relatif() {
    static $chemin = null;
    if ($chemin !== null) {
        return $chemin;
    }
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
    $racine = str_replace('\\', '/', dirname(__DIR__));
    $chemin = '/' . ltrim(str_replace($racine, '', $script), '/');
    return $chemin;
}

/**
 * Pages autorisées pendant une maintenance (connexion des
 * administrateurs, réinitialisation de mot de passe, déconnexion).
 */
function maintenance_page_autorisee($chemin_relatif = null) {
    if ($chemin_relatif === null) {
        $chemin_relatif = maintenance_chemin_relatif();
    }
    $basename = basename($chemin_relatif);
    return in_array($basename, [
        'connexion.php',
        'deconnexion.php',
        'motdepasse-oublie.php',
        'reinitialiser-mot-de-passe.php'
    ], true);
}

/**
 * URL de base du projet, utilisable AVANT que includes/config.php ne soit
 * chargé (c'est le cas lorsque cette page est affichée par l'auto_prepend).
 * La valeur est déduite de l'emplacement du fichier sur le disque par rapport
 * à la racine web, ce qui reste juste quelle que soit la profondeur de la page
 * demandée (/connexion.php, /admin/dashboard.php, ...).
 */
function maintenance_base_url() {
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    if (defined('BASE_URL')) {
        $base = BASE_URL;
        return $base;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $racine = str_replace('\\', '/', dirname(__DIR__));
    $docroot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\'));
    if ($docroot !== '' && stripos($racine, $docroot) === 0) {
        $prefixe = rtrim(substr($racine, strlen($docroot)), '/');
        $base = $scheme . '://' . $host . $prefixe . '/';
    } else {
        // Repli : le projet n'est pas sous la racine web (alias Apache).
        $base = $scheme . '://' . $host . '/';
    }
    return $base;
}

/**
 * Affiche la page de maintenance (HTML, ou JSON pour /api) puis arrête
 * l'exécution du script.
 */
function maintenance_afficher_page() {
    $chemin_relatif = maintenance_chemin_relatif();
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $est_api = stripos($uri, '/api/') !== false || stripos($chemin_relatif, '/api/') !== false;

    if ($est_api) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode([
            'succes' => false,
            'erreur' => 'La plateforme est temporairement indisponible pour maintenance.',
            'code' => 'MAINTENANCE'
        ]);
        exit();
    }

    http_response_code(503);
    header('Retry-After: 3600');
    ?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance - PharmaGeo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(maintenance_base_url(), ENT_QUOTES, 'UTF-8'); ?>assets/css/palette.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(22,163,74,0.93), rgba(212,136,15,0.90));
            padding: 20px;
            overflow: hidden;
            position: relative;
        }
        body::after {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(circle at 20% 50%, rgba(255,255,255,0.08) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255,255,255,0.06) 0%, transparent 50%);
            z-index: 0;
            pointer-events: none;
        }
        .card-maintenance {
            position: relative;
            z-index: 1;
            background: rgba(255,255,255,0.96);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border-radius: 28px;
            box-shadow: 0 40px 90px rgba(0,0,0,0.25);
            border: 1px solid rgba(255,255,255,0.3);
            max-width: 520px;
            width: 100%;
            padding: 48px 40px;
            text-align: center;
            animation: fadeUp 0.7s ease-out;
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(40px) scale(0.97); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .icon-maintenance {
            width: 96px;
            height: 96px;
            margin: 0 auto 22px;
            border-radius: 50%;
            background: rgba(212,136,15,0.10);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.6rem;
            color: #B91C1C;
            box-shadow: 0 10px 34px rgba(212,136,15,0.18);
            animation: float 3.2s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        .card-maintenance h1 {
            font-size: 1.9rem;
            font-weight: 800;
            color: #1F2937;
            margin-bottom: 8px;
        }
        .card-maintenance h1 span {
            background: linear-gradient(135deg, #16A34A, #B91C1C);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .card-maintenance .badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(22,163,74,0.10);
            color: #166534;
            padding: 6px 18px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 18px;
        }
        .card-maintenance p {
            color: #64748b;
            font-size: 0.98rem;
            line-height: 1.75;
            margin-bottom: 26px;
        }
        .btn-maintenance {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(135deg, #16A34A, #B91C1C);
            color: white;
            border: none;
            padding: 13px 34px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 0.95rem;
            text-decoration: none;
            box-shadow: 0 10px 36px rgba(22,163,74,0.28);
            transition: all 0.3s ease;
        }
        .btn-maintenance:hover {
            transform: translateY(-3px);
            box-shadow: 0 16px 46px rgba(22,163,74,0.36);
            color: white;
        }
        .card-maintenance .spinner-border {
            color: #B91C1C;
        }
        @media (max-width: 576px) {
            .card-maintenance { padding: 36px 22px; }
            .card-maintenance h1 { font-size: 1.5rem; }
        }
    </style>
</head>
<body>
    <div class="card-maintenance">
        <div class="icon-maintenance">
            <i class="fas fa-tools"></i>
        </div>
        <span class="badge-pill"><i class="fas fa-circle-notch fa-spin"></i> Bientôt de retour</span>
        <h1>Maintenance <span>en cours</span></h1>
        <p>
            La plateforme <strong>PharmaGeo</strong> est actuellement en maintenance.
            Nous travaillons à améliorer votre expérience. Merci de revenir plus tard.
        </p>
        <div class="spinner-border mb-3" role="status" style="width: 2rem; height: 2rem;">
            <span class="visually-hidden">Chargement...</span>
        </div>
        <br>
        <a href="<?php echo htmlspecialchars(maintenance_base_url(), ENT_QUOTES, 'UTF-8'); ?>connexion.php" class="btn-maintenance">
            <i class="fas fa-sign-in-alt"></i> Espace administrateur
        </a>
    </div>
</body>
</html>
<?php
    exit();
}

/**
 * Vérifie le mode maintenance : si actif et que l'utilisateur n'est
 * pas administrateur (sauf pages de connexion), on bloque l'accès.
 * Sans argument PDO : ce fichier gère lui-même sa connexion.
 * À appeler le plus tôt possible (fichier auto-prepend).
 */
function bloquer_si_maintenance() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $est_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'administrateur';

    // Aperçu réservé aux administrateurs : affiche la page de maintenance
    // telle qu'un visiteur la verrait (via index.php?maintenance_preview=1),
    // quelle que soit l'état réel du mode.
    if ($est_admin && isset($_GET['maintenance_preview']) && $_GET['maintenance_preview'] === '1') {
        maintenance_afficher_page();
    }

    if (maintenance_est_active() && !$est_admin && !maintenance_page_autorisee()) {
        maintenance_afficher_page();
    }
}