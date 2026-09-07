<?php
// ============================================================
// PharmaGeo — Configuration globale
// Centralise les constantes de connexion, l'URL de base,
// la connexion PDO et les fonctions utilitaires partagées.
// ============================================================

// ===== ACTIVATION DES ERREURS POUR LE DÉVELOPPEMENT =====
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// ===== CONFIGURATION DE LA BASE DE DONNÉES =====
// Nom confirmé par ton dump phpMyAdmin (gestion_soutenance__1_.sql).
// Si tu importes ce dump sous un autre nom de base, adapte cette ligne.
define('DB_HOST', 'localhost');
define('DB_NAME', 'gestion_soutenance');
define('DB_USER', 'root');
define('DB_PASS', '');

// ===== URL DE BASE =====
// Détection automatique de l'URL de base.
// BASE_URL représente TOUJOURS la racine du projet, quel que soit le
// sous-dossier de la page courante (pages/patient, admin, pharmacien...).
// On se base sur l'emplacement physique d'includes/config.php plutôt que
// sur le dossier de la page, pour éviter les URLs doublées
// (ex : /pharmageo/pages/patient/pages/patient/carte.php).
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];

$base_path = '/';
if (isset($_SERVER['DOCUMENT_ROOT']) && !empty($_SERVER['DOCUMENT_ROOT'])) {
    // Le dossier réel d'includes (où se trouve ce fichier)
    $includes_dir = str_replace('\\', '/', rtrim(realpath(__DIR__), '/'));
    $doc_root = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/'));
    // Racine du projet = parent d'includes, exprimée en relatif par rapport à DOCUMENT_ROOT
    if (!empty($doc_root) && strpos($includes_dir, $doc_root) === 0) {
        $rel = substr($includes_dir, strlen($doc_root));
        $rel = rtrim($rel, '/');
        if (strpos($rel, '/includes') !== false) {
            $rel = substr($rel, 0, strpos($rel, '/includes'));
        }
        $base_path = rtrim($rel, '/') . '/';
    }
}

define('BASE_URL', $protocol . $host . $base_path);
define('BASE_PATH', realpath(__DIR__ . '/..') . '/');

// ===== CONNEXION À LA BASE DE DONNÉES =====
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // L'émulation des requêtes préparées doit rester INACTIVE ailleurs :
            // avec PDO::ATTR_EMULATE_PREPARES => false, un même paramètre nommé
            // réutilisé plusieurs fois dans une requête (ex : :terme, :lat) déclenche
            // l'erreur SQLSTATE[HY093] "Invalid parameter number".
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]
    );
} catch (PDOException $e) {
    die("Erreur de connexion à la base de données : " . $e->getMessage());
}

// ===== FONCTIONS UTILITAIRES =====

/**
 * Nettoyer une entrée utilisateur
 */
function cleanInput($data) {
    if (is_array($data)) {
        return array_map('cleanInput', $data);
    }
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Vérifier si l'utilisateur est connecté
 */
function estConnecte() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Vérifier si l'utilisateur a un rôle spécifique
 */
function aRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

/**
 * Récupérer l'ID de l'utilisateur connecté
 */
function getIdUtilisateur() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Récupérer le rôle de l'utilisateur
 */
function getRoleUtilisateur() {
    return $_SESSION['role'] ?? null;
}

/**
 * Rediriger si non connecté
 */
function requireAuth() {
    if (!estConnecte()) {
        header('Location: ' . BASE_URL . 'connexion.php');
        exit();
    }
}

/**
 * Rediriger si n'a pas le rôle requis
 */
function requireRole($role) {
    requireAuth();
    if (!aRole($role)) {
        header('Location: ' . BASE_URL . 'index.php');
        exit();
    }
}

/**
 * Fonction de debug
 */
function debug($data, $die = false) {
    echo '<pre style="background: #f4f4f4; padding: 15px; border: 2px solid #e74c3c; border-radius: 8px; margin: 10px; font-size: 14px; max-height: 500px; overflow: auto;">';
    if (is_array($data) || is_object($data)) {
        print_r($data);
    } else {
        var_dump($data);
    }
    echo '</pre>';
    if ($die) {
        die();
    }
}

/**
 * Générer une alerte Bootstrap
 */
function showAlert($message, $type = 'info', $dismissible = true) {
    $classes = [
        'success' => 'alert-success',
        'danger' => 'alert-danger',
        'warning' => 'alert-warning',
        'info' => 'alert-info'
    ];
    $icon = [
        'success' => 'fa-check-circle',
        'danger' => 'fa-exclamation-circle',
        'warning' => 'fa-exclamation-triangle',
        'info' => 'fa-info-circle'
    ];
    $class = $classes[$type] ?? 'alert-info';
    $iconClass = $icon[$type] ?? 'fa-info-circle';
    $dismiss = $dismissible ? 'alert-dismissible fade show' : '';

    return '<div class="alert ' . $class . ' ' . $dismiss . '" role="alert">
                <i class="fas ' . $iconClass . ' me-2"></i>' . $message . '
                ' . ($dismissible ? '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' : '') . '
            </div>';
}

/**
 * Formater la distance
 */
function formatDistance($km) {
    if ($km < 1) {
        return round($km * 1000) . ' m';
    }
    return number_format($km, 2, ',', ' ') . ' km';
}

/**
 * Formater la date
 */
function formatDate($date, $format = 'd/m/Y H:i') {
    if (empty($date)) return '';
    $timestamp = is_numeric($date) ? $date : strtotime($date);
    return date($format, $timestamp);
}

/**
 * Tronquer un texte
 */
function truncateText($text, $length = 100, $suffix = '...') {
    if (strlen($text) <= $length) return $text;
    return substr($text, 0, $length) . $suffix;
}

// ===== CONSTANTES DE STATUT =====
define('STATUTS_RESERVATION', [
    'en_attente' => 'En attente',
    'confirmee' => 'Confirmée',
    'refusee' => 'Refusée',
    'expiree' => 'Expirée',
    'retiree' => 'Retirée',
    'annulee' => 'Annulée'
]);

define('STATUTS_STOCK', [
    'disponible' => 'Disponible',
    'stock_faible' => 'Stock faible',
    'rupture' => 'Rupture de stock',
    'en_approvisionnement' => 'En approvisionnement',
    'desactive' => 'Désactivé'
]);

define('STATUTS_PHARMACIE', [
    'en_attente' => 'En attente de validation',
    'active' => 'Active',
    'suspendue' => 'Suspendue'
]);

define('ROLES_UTILISATEUR', [
    'administrateur' => 'Administrateur',
    'pharmacien' => 'Pharmacien',
    'patient' => 'Patient'
]);