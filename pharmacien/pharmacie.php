<?php
// ============================================================
// PharmaGeo — Espace pharmacien : Ma pharmacie
// Permet de consulter et modifier les informations de l'officine
// (coordonnées, horaires, jours de permanence et statut).
// ============================================================

// ===== ACTIVATION DES ERREURS =====
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ===== DÉFINITION DES CONSTANTES =====
define('BASE_URL', 'http://localhost/pharmageo/');

// ===== FONCTIONS UTILITAIRES =====
require_once __DIR__ . '/../includes/fonctions.php';
require_once __DIR__ . '/../includes/coordonnees.php';

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
               p.Id_pharmacie, p.Nom as Pharmacie_Nom, p.Adresse, p.Quartier, p.Commune,
               p.Latitude, p.Longitude, p.Telephone as Pharmacie_Tel, p.Email as Pharmacie_Email,
               p.Horaire, p.Statut as Pharmacie_Statut, p.Logo
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

    // Récupérer les jours de permanence si la pharmacie existe
    $jours_permanence = [];
    if ($has_pharmacy) {
        $stmt = $pdo->prepare("
            SELECT * FROM jour_permanence WHERE Id_pharmacie = :id
        ");
        $stmt->execute([':id' => $user['Id_pharmacie']]);
        $jours_permanence = $stmt->fetchAll();
    }

} catch(PDOException $e) {
    die("Erreur : " . $e->getMessage());
}

// ===== TRAITEMENT DES FORMULAIRES =====
$error = '';
$success = '';

// ===== MISE À JOUR DE LA PHARMACIE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_pharmacy') {
    $nom = trim($_POST['nom'] ?? '');
    $adresse = trim($_POST['adresse'] ?? '');
    $quartier = trim($_POST['quartier'] ?? '');
    $commune = trim($_POST['commune'] ?? 'Porto-Novo');
    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $horaire = trim($_POST['horaire'] ?? '');
    
    $errors = [];
    if (empty($nom)) $errors[] = "Le nom de la pharmacie est obligatoire.";
    if (empty($adresse)) $errors[] = "L'adresse est obligatoire.";
    if (empty($quartier)) $errors[] = "Le quartier est obligatoire.";
    $coord = pharmaValiderCoordonnees($latitude, $longitude);
    if (!$coord['ok']) {
        $errors[] = $coord['erreur'];
    } else {
        $latitude = $coord['lat'];
        $longitude = $coord['lng'];
    }
    
    // ===== GESTION DU LOGO (upload sécurisé) =====
    $logo_path = null;
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        try {
            $logo_path = pharma_upload_image($_FILES['logo'], 'logos', 'pharmacie_' . $user['Id_pharmacie']);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
    
    if (empty($errors)) {
        try {
            $sql = "
                UPDATE pharmacie 
                SET Nom = :nom, Adresse = :adresse, Quartier = :quartier, Commune = :commune,
                    Latitude = :lat, Longitude = :lng, Telephone = :tel, Email = :email,
                    Horaire = :horaire
            ";
            $params = [
                ':nom' => $nom,
                ':adresse' => $adresse,
                ':quartier' => $quartier,
                ':commune' => $commune,
                ':lat' => $latitude,
                ':lng' => $longitude,
                ':tel' => $telephone,
                ':email' => $email,
                ':horaire' => $horaire,
                ':id' => $user['Id_pharmacie']
            ];
            
            if ($logo_path) {
                $sql .= ", Logo = :logo";
                $params[':logo'] = $logo_path;
            }
            
            $sql .= " WHERE Id_pharmacie = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            // Supprime l'ancien logo pour ne pas laisser de fichier orphelin.
            if ($logo_path && !empty($user['Logo']) && $user['Logo'] !== $logo_path) {
                pharma_supprimer_image($user['Logo']);
            }
            
            // Mise à jour des jours de permanence (suppression puis réinsertion)
            $pdo->prepare("DELETE FROM jour_permanence WHERE Id_pharmacie = :id")->execute([':id' => $user['Id_pharmacie']]);
            
            if (isset($_POST['jours_permanence']) && is_array($_POST['jours_permanence'])) {
                $stmt = $pdo->prepare("
                    INSERT INTO jour_permanence (Jour_semaine, Id_pharmacie) 
                    VALUES (:jour, :id)
                ");
                foreach ($_POST['jours_permanence'] as $jour) {
                    $stmt->execute([':jour' => $jour, ':id' => $user['Id_pharmacie']]);
                }
            }
            
            // Journalisation
            $log = $pdo->prepare("
                INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur)
                VALUES ('Mise à jour de la pharmacie', :ip, :ua, 'succes', :user)
            ");
            $log->execute([
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':user' => $_SESSION['user_id']
            ]);
            
            $success = "Pharmacie mise à jour avec succès !";
            
            // Recharger les données
            $stmt = $pdo->prepare("
                SELECT u.*, ph.Id_pharmacien, ph.Numero_ordre, ph.Statut_validation,
                       p.Id_pharmacie, p.Nom as Pharmacie_Nom, p.Adresse, p.Quartier, p.Commune,
                       p.Latitude, p.Longitude, p.Telephone as Pharmacie_Tel, p.Email as Pharmacie_Email,
                       p.Horaire, p.Statut as Pharmacie_Statut, p.Logo
                FROM utilisateur u
                JOIN pharmacien ph ON u.Id_utilisateur = ph.Id_utilisateur
                LEFT JOIN pharmacie p ON ph.Id_pharmacien = p.Id_pharmacien
                WHERE u.Id_utilisateur = :id
            ");
            $stmt->execute([':id' => $_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            // Recharger les jours de permanence
            $stmt = $pdo->prepare("SELECT * FROM jour_permanence WHERE Id_pharmacie = :id");
            $stmt->execute([':id' => $user['Id_pharmacie']]);
            $jours_permanence = $stmt->fetchAll();
            
        } catch(PDOException $e) {
            $error = "Erreur lors de la mise à jour : " . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// ===== DEMANDE DE MODIFICATION DU STATUT =====
// La suspension / réactivation d'une pharmacie est une action réservée à l'administrateur.
if (isset($_GET['action']) && $_GET['action'] === 'changer_statut') {
    $error = "La suspension ou la réactivation de votre pharmacie est gérée par l'administrateur.";
    $error_type = "warning";
}

$page_title = 'Ma pharmacie - PharmaGeo';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        /* ===== VARIABLES ===== */
        :root {
            --primary-green: #16A34A;
            --primary-orange: #166534;
            --white: #ffffff;
            --light-bg: #F0FDF4;
            --dark-text: #1F2937;
            --gray-text: #64748B;
            --shadow-sm: 0 2px 20px rgba(0,0,0,0.04);
            --shadow-md: 0 8px 40px rgba(0,0,0,0.08);
            --shadow-lg: 0 20px 60px rgba(0,0,0,0.12);
            --shadow-glow-green: 0 8px 40px rgba(22,163,74, 0.20);
            --shadow-glow-orange: 0 8px 40px rgba(21,128,61, 0.20);
            --radius: 16px;
            --radius-lg: 24px;
            --transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        /* ===== BASE ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--light-bg);
            color: var(--dark-text);
            overflow-x: hidden;
        }

        /* ===== SCROLLBAR ===== */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: var(--light-bg); }
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            border-radius: 10px;
        }

        /* ===== ANIMATIONS ===== */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-30px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .animate-fade-up { animation: fadeInUp 0.6s ease-out forwards; }
        .animate-slide-left { animation: slideInLeft 0.5s ease-out forwards; }
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }

        /* ===== NAVBAR ===== */
        .navbar-custom {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 2px 30px rgba(0,0,0,0.06);
            padding: 12px 0;
            transition: var(--transition);
            border-bottom: 1px solid rgba(0,0,0,0.02);
        }

        .navbar-custom.scrolled {
            box-shadow: 0 4px 40px rgba(0,0,0,0.1);
            padding: 8px 0;
        }

        .navbar-brand-custom {
            font-weight: 800;
            font-size: 1.6rem;
            color: var(--dark-text);
            letter-spacing: -0.5px;
        }

        .navbar-brand-custom .brand-icon {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            padding: 8px 12px;
            border-radius: 12px;
            color: white;
            margin-right: 10px;
            box-shadow: var(--shadow-glow-green);
        }

        .navbar-brand-custom .brand-text {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .nav-link-custom {
            font-weight: 500;
            color: var(--dark-text);
            padding: 8px 18px !important;
            border-radius: 12px;
            transition: var(--transition);
            position: relative;
        }

        .nav-link-custom:hover,
        .nav-link-custom.active {
            color: var(--primary-green);
            background: rgba(22,163,74,0.08);
        }

        .nav-link-custom::after {
            content: '';
            position: absolute;
            bottom: 2px;
            left: 50%;
            width: 0;
            height: 3px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            border-radius: 10px;
            transition: var(--transition);
            transform: translateX(-50%);
        }

        .nav-link-custom:hover::after,
        .nav-link-custom.active::after {
            width: 60%;
        }

        .btn-nav-logout {
            background: linear-gradient(135deg, #DC2626, #B91C1C);
            color: white !important;
            padding: 8px 24px !important;
            border-radius: 50px !important;
            font-weight: 600;
            transition: var(--transition);
            border: none;
            box-shadow: 0 4px 15px rgba(220,38,38,0.2);
        }

        .btn-nav-logout:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(220,38,38,0.35);
            color: white !important;
        }

        /* ===== LAYOUT / SIDEBAR ===== */
        .layout { display: flex; min-height: 100vh; }
        .sidebar-admin {
            width: 250px; background: linear-gradient(180deg, #0F2E19, #14532D);
            color: #fff; flex-shrink: 0; display: flex; flex-direction: column;
            position: fixed; top: 0; bottom: 0; left: 0; overflow-y: auto; z-index: 100;
        }
        .brand-admin { padding: 22px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .brand-admin .brand-icon { width: 40px; height: 40px; border-radius: 12px; background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); display: flex; align-items: center; justify-content: center;  color: #fff;}
        .brand-admin h4 { margin: 0; font-weight: 800; font-size: 1.1rem; color: #fff; 
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
        @media (max-width: 991px) {
            .sidebar-admin { position: fixed; transform: translateX(-100%); transition: transform 0.3s ease; }
            .sidebar-admin.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
        }

        /* ===== MAIN CONTENT ===== */
        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 24px 30px 30px;
            min-height: 100vh;
        }

        /* ===== TOP BAR ===== */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .top-bar .page-title h1 {
            font-size: 1.6rem;
            font-weight: 800;
            margin: 0;
        }

        .top-bar .page-title h1 .highlight {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .top-bar .page-title p {
            color: var(--gray-text);
            margin: 0;
            font-size: 0.9rem;
        }

        /* ===== PHARMACY CARD ===== */
        .pharmacy-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-sm);
            border: 1px solid rgba(0,0,0,0.02);
            transition: var(--transition);
            margin-bottom: 24px;
        }

        .pharmacy-card:hover {
            box-shadow: var(--shadow-md);
        }

        .pharmacy-card .pharmacy-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #F0FDF4;
            flex-wrap: wrap;
        }

        .pharmacy-card .pharmacy-logo {
            width: 100px;
            height: 100px;
            border-radius: 16px;
            background: linear-gradient(135deg, rgba(22,163,74,0.1), rgba(21,128,61,0.1));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: var(--primary-green);
            flex-shrink: 0;
            overflow: hidden;
            border: 2px solid #F0FDF4;
        }

        .pharmacy-card .pharmacy-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .pharmacy-card .pharmacy-title h3 {
            font-weight: 800;
            margin: 0;
        }

        .pharmacy-card .pharmacy-title .status-badge {
            padding: 4px 16px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }

        .pharmacy-card .pharmacy-title .status-badge.active {
            background: rgba(22,163,74,0.1);
            color: var(--primary-green);
        }

        .pharmacy-card .pharmacy-title .status-badge.en_attente {
            background: rgba(21,128,61,0.1);
            color: var(--primary-orange);
        }

        .pharmacy-card .pharmacy-title .status-badge.suspendue {
            background: rgba(220,38,38,0.1);
            color: #DC2626;
        }

        /* ===== INFO GRID ===== */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .info-item {
            background: var(--light-bg);
            border-radius: var(--radius);
            padding: 14px 18px;
            transition: var(--transition);
        }

        .info-item:hover {
            background: rgba(22,163,74,0.04);
        }

        .info-item .info-label {
            font-size: 0.75rem;
            color: var(--gray-text);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-item .info-value {
            font-weight: 600;
            font-size: 0.95rem;
            margin-top: 2px;
        }

        .info-item .info-value i {
            color: var(--primary-green);
            width: 20px;
        }

        /* ===== SETTINGS CARD ===== */
        .settings-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-sm);
            border: 1px solid rgba(0,0,0,0.02);
            transition: var(--transition);
            margin-bottom: 24px;
        }

        .settings-card:hover {
            box-shadow: var(--shadow-md);
        }

        .settings-card .card-header-custom {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--light-bg);
        }

        .settings-card .card-header-custom .header-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }

        .settings-card .card-header-custom .header-icon.green {
            background: rgba(22,163,74,0.1);
            color: var(--primary-green);
        }

        .settings-card .card-header-custom .header-icon.orange {
            background: rgba(21,128,61,0.1);
            color: var(--primary-orange);
        }

        .settings-card .card-header-custom .header-title h5 {
            font-weight: 700;
            margin: 0;
            font-size: 1.1rem;
        }

        .settings-card .card-header-custom .header-title p {
            color: var(--gray-text);
            margin: 0;
            font-size: 0.85rem;
        }

        /* ===== FORMULAIRE ===== */
        .form-modern .form-group { margin-bottom: 18px; }

        .form-modern .form-label {
            font-weight: 600;
            color: var(--dark-text);
            font-size: 0.9rem;
            margin-bottom: 6px;
        }

        .form-modern .form-label .required {
            color: #DC2626;
            margin-left: 2px;
        }

        .form-modern .input-group-custom {
            position: relative;
        }

        .form-modern .input-group-custom .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-text);
            font-size: 1rem;
            transition: var(--transition);
            z-index: 3;
        }

        .form-modern .input-group-custom .form-control {
            padding: 12px 16px 12px 44px;
            border: 2px solid #D9E9DF;
            border-radius: 14px;
            font-size: 0.95rem;
            transition: var(--transition);
            background: #F0FDF4;
            color: var(--dark-text);
        }

        .form-modern .input-group-custom .form-control:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 4px rgba(22,163,74,0.1);
            background: var(--white);
        }

        .form-modern .input-group-custom .form-control:disabled {
            background: #F0FDF4;
            cursor: not-allowed;
        }

        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 0.95rem;
            transition: var(--transition);
            box-shadow: var(--shadow-glow-green);
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-primary-custom:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 40px rgba(22,163,74,0.4);
            color: white;
        }

        .btn-primary-custom:active { transform: scale(0.98); }

        /* ===== ALERTES ===== */
        .alert-custom {
            border-radius: 14px;
            padding: 14px 18px;
            border: none;
            font-weight: 500;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 20px;
        }

        .alert-custom i { font-size: 1.2rem; margin-top: 2px; }

        .alert-custom-success {
            background: rgba(22,163,74,0.08);
            color: var(--primary-green);
            border-left: 4px solid var(--primary-green);
        }

        .alert-custom-danger {
            background: rgba(220,38,38,0.08);
            color: #DC2626;
            border-left: 4px solid #DC2626;
        }

        .alert-custom-warning {
            background: rgba(21,128,61,0.08);
            color: var(--primary-orange);
            border-left: 4px solid var(--primary-orange);
        }

        /* ===== PERMANENCE TAGS ===== */
        .permanence-tag {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(22,163,74,0.1);
            color: var(--primary-green);
            margin: 2px;
        }

        /* ===== DAYS CHECKBOX ===== */
        .days-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
            gap: 8px;
        }

        .day-check {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 10px;
            border: 2px solid #D9E9DF;
            cursor: pointer;
            transition: var(--transition);
            background: #F0FDF4;
        }

        .day-check:hover {
            border-color: var(--primary-green);
        }

        .day-check input[type="checkbox"] {
            accent-color: var(--primary-green);
            width: 16px;
            height: 16px;
        }

        .day-check.active {
            border-color: var(--primary-green);
            background: rgba(22,163,74,0.05);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 991px) {
            .main-content { padding: 15px; }
            .top-bar .page-title h1 { font-size: 1.4rem; }
            .pharmacy-card { padding: 20px; }
            .pharmacy-card .pharmacy-logo { width: 70px; height: 70px; font-size: 2rem; }
        }

        @media (max-width: 768px) {
            .top-bar { flex-direction: column; align-items: stretch; }
            .pharmacy-card .pharmacy-header { flex-direction: column; text-align: center; }
            .info-grid { grid-template-columns: 1fr 1fr; }
            .days-grid { grid-template-columns: repeat(4, 1fr); }
        }

        @media (max-width: 576px) {
            .main-content { padding: 10px; }
            .top-bar .page-title h1 { font-size: 1.2rem; }
            .pharmacy-card { padding: 16px; }
            .info-grid { grid-template-columns: 1fr; }
            .settings-card { padding: 16px; }
            .days-grid { grid-template-columns: repeat(3, 1fr); }
            .navbar-brand-custom { font-size: 1.3rem; }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/responsive.css">    <!-- Palette verte PharmaGeo -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/palette.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        .coord-map{height:320px;border-radius:12px;border:1px solid #d1d5db;margin-bottom:.5rem;z-index:0}
        .locate-status{font-size:.85rem;margin-top:.4rem}
        .locate-status.success{color:#15803d}
        .locate-status.error{color:#DC2626}
    </style>
</head>
<body>

    <div class="layout">

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar-admin" id="sidebar">
        <a href="<?php echo BASE_URL; ?>index.php" class="brand-admin" style="text-decoration: none;">
            <div class="brand-icon"><i class="fas fa-hospital"></i></div>
            <div><h4>PharmaGeo</h4><small><?php echo htmlspecialchars(pharma_libelle_role('pharmacien', $user['Prenom'] ?? '')); ?></small></div>
        </a>
        <nav class="nav-menu">
            <div class="nav-label">Navigation</div>
            <a href="<?php echo BASE_URL; ?>pharmacien/dashboard.php" class="nav-item"><i class="fas fa-tachometer-alt"></i><span>Tableau de bord</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/pharmacie.php" class="nav-item active"><i class="fas fa-store"></i><span>Ma pharmacie</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/medicaments.php" class="nav-item"><i class="fas fa-capsules"></i><span>Médicaments</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/stocks.php" class="nav-item"><i class="fas fa-boxes"></i><span>Stocks</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/historique_stocks.php" class="nav-item"><i class="fas fa-history"></i><span>Historique</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/reservations.php" class="nav-item"><i class="fas fa-calendar-check"></i><span>Réservations</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/statistiques.php" class="nav-item"><i class="fas fa-chart-bar"></i><span>Statistiques</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/profil.php" class="nav-item"><i class="fas fa-user"></i><span>Profil</span></a>
            <div class="nav-label" style="margin-top: 20px;">Système</div>
            <?php require_once __DIR__ . '/../includes/reinitialiser.php'; echo html_bloc_reinitialiser(); ?>
            <a href="<?php echo BASE_URL; ?>deconnexion.php" class="nav-item" style="color: #FEF2F2; margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.15); margin-bottom: 12px;"><i class="fas fa-sign-out-alt"></i><span>Déconnexion</span></a>
        </nav>
        <a class="user-profile" href="<?php echo BASE_URL; ?>pharmacien/profil.php" title="Voir mon profil">
            <div class="avatar"><?php if (!empty($_SESSION['photo']) && function_exists('pharma_image_disponible') && pharma_image_disponible($_SESSION['photo'])): ?><img src="<?php echo htmlspecialchars(pharma_image_url($_SESSION['photo'])); ?>" alt=""><?php else: ?><?php echo strtoupper(substr($_SESSION['prenom'] ?? 'A', 0, 1) . substr($_SESSION['nom'] ?? 'D', 0, 1)); ?><?php endif; ?></div>
            <div>
                <div class="name"><?php echo htmlspecialchars(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')); ?></div>
                <div class="role"><i class="fas fa-user-md me-1" style="color:#DC2626;"></i> <?php echo htmlspecialchars(pharma_libelle_role('pharmacien', $user['Prenom'] ?? '')); ?></div>
            </div>
            <i class="fas fa-chevron-right profile-chevron"></i>
        </a>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">

        <!-- ===== TOP BAR ===== -->
        <div class="top-bar animate-fade-up">
            <div class="page-title">
                <h1>Ma <span class="highlight">pharmacie</span></h1>
                <p>
                    <i class="fas fa-store me-1" style="color: var(--primary-green);"></i>
                    Gérez les informations de votre officine
                </p>
            </div>
        </div>

        <!-- ===== MESSAGES ===== -->
        <?php if (!empty($error)): ?>
            <div class="alert-custom alert-custom-<?php echo $error_type ?? 'danger'; ?> animate-fade-up">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo $error; ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-custom alert-custom-success animate-fade-up">
                <i class="fas fa-check-circle"></i>
                <div><?php echo $success; ?></div>
            </div>
        <?php endif; ?>

        <?php if ($has_pharmacy): ?>

            <!-- ===== CARTE DE LA PHARMACIE ===== -->
            <div class="pharmacy-card animate-fade-up delay-1">
                <div class="pharmacy-header">
                    <div class="pharmacy-logo">
                        <img src="<?php echo htmlspecialchars(pharma_image_url_ou_defaut($user['Logo'] ?? null, 'pharmacie')); ?>" alt="Logo de la pharmacie">
                    </div>
                    <div class="pharmacy-title">
                        <h3><?php echo htmlspecialchars($user['Pharmacie_Nom']); ?></h3>
                        <div class="mt-2">
                            <span class="status-badge <?php echo $user['Pharmacie_Statut']; ?>">
                                <i class="fas fa-<?php echo $user['Pharmacie_Statut'] === 'active' ? 'check-circle' : ($user['Pharmacie_Statut'] === 'en_attente' ? 'clock' : 'ban'); ?>"></i>
                                <?php 
                                    $statuts = [
                                        'active' => 'Active',
                                        'en_attente' => 'En attente de validation',
                                        'suspendue' => 'Suspendue'
                                    ];
                                    echo $statuts[$user['Pharmacie_Statut']] ?? $user['Pharmacie_Statut'];
                                ?>
                            </span>
                            <span class="ms-2 text-muted" style="font-size: 0.85rem;">
                                <i class="fas fa-id-card me-1"></i>
                                N° ordre: <?php echo htmlspecialchars($user['Numero_ordre']); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-map-marker-alt me-1"></i> Adresse</div>
                        <div class="info-value"><?php echo htmlspecialchars($user['Adresse']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-location-dot me-1"></i> Quartier</div>
                        <div class="info-value"><?php echo htmlspecialchars($user['Quartier']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-city me-1"></i> Commune</div>
                        <div class="info-value"><?php echo htmlspecialchars($user['Commune'] ?? 'Porto-Novo'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-phone me-1"></i> Téléphone</div>
                        <div class="info-value"><?php echo htmlspecialchars($user['Pharmacie_Tel'] ?? 'Non renseigné'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-envelope me-1"></i> Email</div>
                        <div class="info-value"><?php echo htmlspecialchars($user['Pharmacie_Email'] ?? 'Non renseigné'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-clock me-1"></i> Horaires</div>
                        <div class="info-value"><?php echo htmlspecialchars($user['Horaire'] ?? 'Non renseigné'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-globe me-1"></i> Coordonnées GPS</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($user['Latitude']); ?>, <?php echo htmlspecialchars($user['Longitude']); ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-calendar-week me-1"></i> Jours de permanence</div>
                        <div class="info-value">
                            <?php if (!empty($jours_permanence)): ?>
                                <?php 
                                    $jours = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
                                    $jours_fr = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
                                    $jours_map = array_combine($jours, $jours_fr);
                                    $permanence_jours = array_column($jours_permanence, 'Jour_semaine');
                                ?>
                                <?php foreach ($permanence_jours as $jour): ?>
                                    <span class="permanence-tag">
                                        <i class="fas fa-check-circle me-1" style="font-size: 0.6rem;"></i>
                                        <?php echo $jours_map[$jour] ?? $jour; ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-muted">Aucun jour de permanence</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 flex-wrap">
                    <a href="#edit-section" class="btn-primary-custom" style="text-decoration: none;">
                        <i class="fas fa-edit"></i> Modifier les informations
                    </a>
                </div>
            </div>

            <!-- ===== SECTION ÉDITION ===== -->
            <div id="edit-section" class="settings-card animate-fade-up delay-2">
                <div class="card-header-custom">
                    <div class="header-icon green">
                        <i class="fas fa-edit"></i>
                    </div>
                    <div class="header-title">
                        <h5>Modifier les informations</h5>
                        <p>Mettez à jour les informations de votre pharmacie</p>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data" class="form-modern">
                    <input type="hidden" name="action" value="update_pharmacy">
                    
                    <div class="row g-3">
                        <div class="col-md-6 form-group">
                            <label for="nom" class="form-label">Nom de la pharmacie <span class="required">*</span></label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-store"></i></span>
                                <input type="text" class="form-control" id="nom" name="nom" 
                                       value="<?php echo htmlspecialchars($user['Pharmacie_Nom']); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6 form-group">
                            <label for="logo" class="form-label">Logo</label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-image"></i></span>
                                <input type="file" class="form-control" id="logo" name="logo" 
                                       accept="image/jpeg,image/png,image/webp"
                                       data-preview="logoPreview" style="padding-left: 44px;">
                            </div>
                            <img id="logoPreview" src="" alt="Aperçu du logo"
                                 style="display:none; margin-top:10px; max-height:90px; border-radius:8px; border:1px solid #E5E7EB;">
                            <small class="text-muted">Format: JPG, PNG, WEBP (max 2MB)</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="adresse" class="form-label">Adresse complète <span class="required">*</span></label>
                        <div class="input-group-custom">
                            <span class="input-icon"><i class="fas fa-map-pin"></i></span>
                            <input type="text" class="form-control" id="adresse" name="adresse" 
                                   value="<?php echo htmlspecialchars($user['Adresse']); ?>" required>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6 form-group">
                            <label for="quartier" class="form-label">Quartier <span class="required">*</span></label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-map-marker-alt"></i></span>
                                <input type="text" class="form-control" id="quartier" name="quartier" 
                                       value="<?php echo htmlspecialchars($user['Quartier']); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6 form-group">
                            <label for="commune" class="form-label">Commune</label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-city"></i></span>
                                <input type="text" class="form-control" id="commune" name="commune" 
                                       list="communes-list"
                                       value="<?php echo htmlspecialchars($user['Commune'] ?? 'Porto-Novo'); ?>"
                                       placeholder="Sélectionnez ou saisissez une commune">
                            </div>
                            <datalist id="communes-list">
                                <option value="Porto-Novo">
                                <option value="Ouèdo">
                                <option value="Kingnal">
                                <option value="Togba">
                                <option value="Adjarra">
                                <option value="Sèmè-Podji">
                                <option value="Avèkèrou">
                                <option value="Glo-Djigbé">
                                <option value="Sèmè-Kpodji">
                                <option value="Kpota">
                                <option value="Djègbadji">
                                <option value="Hêvié">
                                <option value="Missérété">
                                <option value="Zinvié">
                                <option value="Calavi">
                                <option value="Cotonou">
                                <option value="Abomey-Calavi">
                                <option value="Godomey">
                                <option value="Allada">
                                <option value="Ouidah">
                                <option value="Whydah">
                                <option value="Possotomé">
                                <option value="Ahouandon">
                                <option value="Togoudo">
                                <option value="Akpro-Missérété">
                                <option value="Agblangandan">
                                <option value="Dèkpo">
                                <option value="Akpakpa">
                                <option value="Cadjèhoun">
                                <option value="Fidjrossè">
                                <option value="Gbanline">
                                <option value="Massamé">
                                <option value="Sèhouè">
                                <option value="Togoudo-Agoogle">
                                <option value="Zè">
                            </datalist>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6 form-group">
                            <label for="latitude" class="form-label">Latitude <span class="required">*</span></label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-globe"></i></span>
                                <input type="text" class="form-control" id="latitude" name="latitude" 
                                       value="<?php echo htmlspecialchars($user['Latitude']); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6 form-group">
                            <label for="longitude" class="form-label">Longitude <span class="required">*</span></label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-globe"></i></span>
                                <input type="text" class="form-control" id="longitude" name="longitude" 
                                       value="<?php echo htmlspecialchars($user['Longitude']); ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="mb-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnLocate">
                            <i class="fas fa-location-crosshairs"></i> Utiliser ma position actuelle
                        </button>
                        <div class="locate-status" id="locateStatus" aria-live="polite"></div>
                    </div>
                    <div id="coordMap" class="coord-map"></div>
                    <div class="form-text mb-2">
                        <i class="fas fa-info-circle"></i> Cliquez sur la carte ou déplacez le marqueur sur le bâtiment de la pharmacie (vue « Satellite » disponible). Les distances affichées aux patients dépendent de la précision de ce point.
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6 form-group">
                            <label for="telephone" class="form-label">Téléphone</label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-phone"></i></span>
                                <input type="text" class="form-control" id="telephone" name="telephone" 
                                       value="<?php echo htmlspecialchars($user['Pharmacie_Tel'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-6 form-group">
                            <label for="email" class="form-label">Email</label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-envelope"></i></span>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($user['Pharmacie_Email'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="horaire" class="form-label">Horaires d'ouverture</label>
                        <div class="input-group-custom">
                            <span class="input-icon"><i class="fas fa-clock"></i></span>
                            <input type="text" class="form-control" id="horaire" name="horaire" 
                                   placeholder="Ex: Lun-Ven: 8h-20h, Sam: 8h-14h" 
                                   value="<?php echo htmlspecialchars($user['Horaire'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Jours de permanence</label>
                        <div class="mb-2">
                            <label class="day-check day-select-all" id="toggleAllDaysLabel">
                                <input type="checkbox" id="toggleAllDays">
                                Tout sélectionner
                            </label>
                        </div>
                        <div class="days-grid">
                            <?php 
                                $jours = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
                                $jours_fr = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
                                $permanence_jours = array_column($jours_permanence, 'Jour_semaine');
                            ?>
                            <?php foreach ($jours as $index => $jour): ?>
                                <label class="day-check <?php echo in_array($jour, $permanence_jours) ? 'active' : ''; ?>">
                                    <input type="checkbox" name="jours_permanence[]" value="<?php echo $jour; ?>" 
                                           <?php echo in_array($jour, $permanence_jours) ? 'checked' : ''; ?>>
                                    <?php echo $jours_fr[$index]; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mt-4 d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn-primary-custom">
                            <i class="fas fa-save"></i> Mettre à jour
                        </button>
                        <a href="<?php echo BASE_URL; ?>pharmacien/pharmacie.php" class="btn btn-secondary" style="border-radius: 50px; padding: 10px 28px;">
                            <i class="fas fa-undo"></i> Annuler
                        </a>
                    </div>
                </form>
            </div>

        <?php else: ?>

            <!-- ===== PHARMACY NOT FOUND ===== -->
            <div class="pharmacy-card animate-fade-up" style="text-align: center; padding: 60px 20px;">
                <div style="font-size: 4rem; color: var(--primary-orange); margin-bottom: 16px;">
                    <i class="fas fa-store"></i>
                </div>
                <h3>Aucune pharmacie associée</h3>
                <p class="text-muted" style="max-width: 500px; margin: 0 auto 20px;">
                    Vous n'avez pas encore de pharmacie associée à votre compte. 
                    Veuillez contacter l'administrateur pour créer votre officine.
                </p>
                <a href="<?php echo BASE_URL; ?>pharmacien/profil.php" class="btn-primary-custom" style="text-decoration: none; display: inline-block;">
                    <i class="fas fa-user-edit me-1"></i> Modifier mon profil
                </a>
            </div>

        <?php endif; ?>

    </main>

    </div>

    <!-- ===== SCRIPTS ===== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="../assets/js/coord_picker.js"></script>
    <script>
        if (window.PharmaCoordPicker) {
            PharmaCoordPicker.init({ map: 'coordMap', lat: 'latitude', lng: 'longitude', bouton: 'btnLocate', statut: 'locateStatus', reference: '../assets/data/pharmacies_osm_porto_novo.json' });
        }
    </script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {

            // ===== SIDEBAR MOBILE TOGGLE (Ctrl + B) =====
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.key.toLowerCase() === 'b') {
                    document.getElementById('sidebar').classList.toggle('open');
                }
            });

            // ===== PRÉVISUALISATION DU LOGO =====
            document.querySelectorAll('input[type=file][data-preview]').forEach(function(input) {
                input.addEventListener('change', function() {
                    const cible = document.getElementById(input.getAttribute('data-preview'));
                    const fichier = input.files && input.files[0];
                    if (!cible || !fichier) return;

                    const types = ['image/jpeg', 'image/png', 'image/webp'];
                    if (types.indexOf(fichier.type) === -1) {
                        alert("Format non autorisé. Utilisez JPG, PNG ou WebP.");
                        input.value = '';
                        return;
                    }
                    if (fichier.size > 2 * 1024 * 1024) {
                        alert("L'image ne doit pas dépasser 2 Mo.");
                        input.value = '';
                        return;
                    }

                    const lecteur = new FileReader();
                    lecteur.onload = function(e) {
                        cible.src = e.target.result;
                        cible.style.display = 'block';
                    };
                    lecteur.readAsDataURL(fichier);
                });
            });

            // ===== DAYS CHECKBOX TOGGLE =====
            const dayCheckboxes = Array.from(document.querySelectorAll('.days-grid .day-check input[type="checkbox"]'));
            const allToggle = document.getElementById('toggleAllDays');

            // Cohérence du style "active" sur les cases jours
            dayCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    this.closest('.day-check').classList.toggle('active', this.checked);
                    majToggleAll();
                });
            });

            // Tout sélectionner / Tout décocher
            function majToggleAll() {
                if (!allToggle) return;
                const tousCoches = dayCheckboxes.length > 0 && dayCheckboxes.every(cb => cb.checked);
                allToggle.checked = tousCoches;
                const lbl = document.getElementById('toggleAllDaysLabel');
                if (lbl) lbl.classList.toggle('active', tousCoches);
            }

            if (allToggle) {
                allToggle.addEventListener('change', function() {
                    dayCheckboxes.forEach(cb => {
                        cb.checked = this.checked;
                        cb.closest('.day-check').classList.toggle('active', this.checked);
                    });
                });
                majToggleAll();
            }

            // ===== KEYBOARD SHORTCUTS =====
            document.addEventListener('keydown', function(e) {
                // Escape pour revenir à la page précédente
                if (e.key === 'Escape') {
                    window.history.back();
                }
            });

            // ===== CONSOLE LOG =====
            console.log('%c PharmaGeo - Ma pharmacie ', 'background: linear-gradient(135deg, #16A34A, #166534); color: white; font-size: 16px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');
            console.log('%c Gérez les informations de votre officine.', 'color: #666; font-size: 14px;');

        });
    </script>

</body>
    <script src="../assets/js/responsive.js"></script>
</html>