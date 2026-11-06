<?php
session_start();
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'administrateur') {
    header('Location: ' . BASE_URL . 'connexion.php');
    exit();
}

$pdo = new PDO("mysql:host=localhost;dbname=gestion_soutenance;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$error = '';
$success = '';

$admin = $pdo->prepare("SELECT * FROM utilisateur WHERE Id_utilisateur = :id");
$admin->execute([':id' => $_SESSION['user_id']]);
$user = $admin->fetch();

$stats = [
    'utilisateurs' => $pdo->query("SELECT COUNT(*) FROM utilisateur WHERE Statut='actif'")->fetchColumn(),
    'pharmacies' => $pdo->query("SELECT COUNT(*) FROM pharmacie WHERE Statut='active'")->fetchColumn(),
    'medicaments' => $pdo->query("SELECT COUNT(*) FROM medicament")->fetchColumn(),
    'reservations' => $pdo->query("SELECT COUNT(*) FROM reservation")->fetchColumn(),
];

// ===== UPDATE PROFILE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');

    if ($nom === '' || $prenom === '' || $email === '') {
        $error = 'Les champs Nom, Prénom et Email sont obligatoires.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'L\'adresse email n\'est pas valide.';
    } else {
        $check = $pdo->prepare("SELECT COUNT(*) FROM utilisateur WHERE LOWER(Email) = LOWER(:email) AND Id_utilisateur != :id");
        $check->execute([':email' => $email, ':id' => $_SESSION['user_id']]);
        if ($check->fetchColumn() > 0) {
            $error = 'Cet email est déjà utilisé par un autre compte.';
        } else {
            $stmt = $pdo->prepare("UPDATE utilisateur SET Nom = :nom, Prenom = :prenom, Email = :email, Telephone = :telephone WHERE Id_utilisateur = :id");
            $stmt->execute([':nom' => $nom, ':prenom' => $prenom, ':email' => $email, ':telephone' => $telephone, ':id' => $_SESSION['user_id']]);

            $_SESSION['nom'] = $nom;
            $_SESSION['prenom'] = $prenom;
            $_SESSION['email'] = $email;

            $admin2 = $pdo->prepare("SELECT * FROM utilisateur WHERE Id_utilisateur = :id");
            $admin2->execute([':id' => $_SESSION['user_id']]);
            $user = $admin2->fetch();

            $log = $pdo->prepare("INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur) VALUES ('Mise a jour du profil administrateur', :ip, :ua, 'succes', :user)");
            $log->execute([':ip' => $_SERVER['REMOTE_ADDR'] ?? '', ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '', ':user' => $_SESSION['user_id']]);

            $success = 'Profil mis a jour avec succes.';
        }
    }
}

// ===== CHANGE PASSWORD =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($current === '' || $new === '' || $confirm === '') {
        $error = 'Tous les champs de mot de passe sont obligatoires.';
    } elseif (strlen($new) < 8) {
        $error = 'Le nouveau mot de passe doit contenir au moins 8 caracteres.';
    } elseif (!preg_match('/[A-Z]/', $new) || !preg_match('/[a-z]/', $new) || !preg_match('/[0-9]/', $new)) {
        $error = 'Le mot de passe doit contenir au moins une majuscule, une minuscule et un chiffre.';
    } elseif ($new !== $confirm) {
        $error = 'Les mots de passe ne correspondent pas.';
    } else {
        $stmt = $pdo->prepare("SELECT Mot_de_passe FROM utilisateur WHERE Id_utilisateur = :id");
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($current, $row['Mot_de_passe'])) {
            $error = 'Le mot de passe actuel est incorrect.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE utilisateur SET Mot_de_passe = :pwd WHERE Id_utilisateur = :id");
            $upd->execute([':pwd' => $hash, ':id' => $_SESSION['user_id']]);

            $log = $pdo->prepare("INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur) VALUES ('Changement de mot de passe administrateur', :ip, :ua, 'succes', :user)");
            $log->execute([':ip' => $_SERVER['REMOTE_ADDR'] ?? '', ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '', ':user' => $_SESSION['user_id']]);

            $success = 'Mot de passe change avec succes.';
        }
    }
}
// ===== PHOTO DE PROFIL =====
// Formats acceptes : JPG, JPEG, PNG, WEBP (max 2 Mo).
// Le nom du fichier est genere de facon unique, l'ancienne photo est
// supprimee apres la mise a jour pour eviter les fichiers orphelins.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_photo') {
    if (!isset($_FILES['photo']) || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $error = 'Veuillez selectionner une image.';
    } else {
        try {
            $nouvelle = pharma_upload_image($_FILES['photo'], 'photos', 'admin_' . $_SESSION['user_id']);
            $ancienne = $user['Photo'] ?? null;

            $pdo->prepare("UPDATE utilisateur SET Photo = :photo WHERE Id_utilisateur = :id")
                ->execute([':photo' => $nouvelle, ':id' => $_SESSION['user_id']]);

            if (!empty($ancienne) && $ancienne !== $nouvelle) {
                pharma_supprimer_image($ancienne);
            }

            $_SESSION['photo'] = $nouvelle;
            $user['Photo'] = $nouvelle;

            $log = $pdo->prepare("INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur) VALUES ('Mise a jour de la photo de profil administrateur', :ip, :ua, 'succes', :user)");
            $log->execute([':ip' => $_SERVER['REMOTE_ADDR'] ?? '', ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '', ':user' => $_SESSION['user_id']]);

            $success = 'Photo de profil mise a jour avec succes.';
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = 'Erreur lors de l\'enregistrement de la photo.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_photo') {
    try {
        $ancienne = $user['Photo'] ?? null;
        $pdo->prepare("UPDATE utilisateur SET Photo = NULL WHERE Id_utilisateur = :id")
            ->execute([':id' => $_SESSION['user_id']]);

        if (!empty($ancienne)) {
            pharma_supprimer_image($ancienne);
        }

        unset($_SESSION['photo']);
        $user['Photo'] = null;
        $success = 'Photo de profil supprimee.';
    } catch (PDOException $e) {
        $error = 'Erreur lors de la suppression de la photo.';
    }
}

// Photo actuelle (utilisee par le profil et la navigation)
$photo_admin = $user['Photo'] ?? null;
$photo_admin_dispo = pharma_image_disponible($photo_admin);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon profil - PharmaGeo Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/responsive.css">
    <style>
        :root {
            --primary-green: #16A34A;
            --primary-orange: #166534;
            --white: #ffffff;
            --light-bg: #F0FDF4;
            --dark-text: #1F2937;
            --gray-text: #64748B;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.1);
            --shadow-lg: 0 8px 32px rgba(0,0,0,0.12);
            --radius: 16px;
            --transition: all 0.3s ease;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--light-bg);
            color: var(--dark-text);
            min-height: 100vh;
        }

        /* ===== SIDEBAR ===== */
        .sidebar {
            position: fixed;
            top: 0; left: 0;
            width: 260px;
            height: 100vh;
            background: linear-gradient(180deg, #1F2937 0%, #0F2E19 50%, #166534 100%);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 20px;
            color: var(--white);
            text-decoration: none;
        }
        .brand-icon {
            width: 40px; height: 40px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.1rem;
        }
        .brand h4 { font-size: 1.2rem; font-weight: 700; margin: 0; }
        .brand small { font-size: 0.7rem; opacity: 0.7; }

        .nav-menu { flex: 1; padding: 10px 0; }

        .nav-label {
            padding: 10px 20px 5px;
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255,255,255,0.35);
            font-weight: 600;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 20px;
            color: rgba(255,255,255,0.65);
            text-decoration: none;
            font-size: 0.88rem;
            transition: var(--transition);
            margin: 2px 10px;
            border-radius: 10px;
        }
        .nav-item:hover { background: rgba(255,255,255,0.08); color: var(--white); }
        .nav-item.active { background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); color: var(--white); font-weight: 600; }
        .nav-item i { width: 20px; text-align: center; font-size: 0.95rem; }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 20px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        .user-profile .avatar {
            width: 38px; height: 38px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 0.8rem;
        }
        .user-profile .user-info .name { color: var(--white); font-weight: 600; font-size: 0.85rem; }
        .user-profile .user-info .role { color: rgba(255,255,255,0.5); font-size: 0.7rem; }
        a.user-profile { text-decoration: none; color: inherit; transition: background-color 0.2s ease; }
        a.user-profile:hover { background-color: rgba(255,255,255,0.07); }
        a.user-profile .profile-chevron { margin-left: auto; font-size: 0.7rem; color: rgba(255,255,255,0.45); }
        a.user-profile:hover .profile-chevron { color: #fff; }

        /* ===== MAIN CONTENT ===== */
        .main-content {
            margin-left: 260px;
            min-height: 100vh;
            padding: 0 0 40px 0;
        }

        .top-bar {
            background: var(--white);
            padding: 18px 30px;
            border-bottom: 1px solid rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .top-bar .page-title h1 { font-size: 1.3rem; font-weight: 700; }
        .top-bar .page-title .highlight { background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .top-bar .page-title p { font-size: 0.8rem; color: var(--gray-text); margin-top: 2px; }
        .toggle-sidebar { display: none; background: none; border: none; font-size: 1.3rem; color: var(--dark-text); cursor: pointer; }

        .content-wrapper { padding: 30px; }

        /* ===== ALERTS ===== */
        .alert-custom {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            border-radius: 12px;
            margin: 0 30px 20px;
            font-size: 0.9rem;
            animation: fadeInUp 0.4s ease;
        }
        .alert-custom-success { background: #DCFCE7; border-left: 4px solid var(--primary-green); color: #166534; }
        .alert-custom-danger { background: #FEF2F2; border-left: 4px solid #DC2626; color: #B91C1C; }

        /* ===== PROFILE CARD ===== */
        .profile-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 30px;
            box-shadow: var(--shadow-sm);
            text-align: center;
            animation: fadeInUp 0.4s ease;
        }
.profile-avatar {
            width: 120px; height: 120px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2.8rem;
            font-weight: 700;
            margin: 0 auto 16px;
            box-shadow: 0 4px 20px rgba(22,163,74,0.3);
        }
        /* Photo de profil : l'image occupe tout le cercle. */
        .profile-avatar { overflow: hidden; }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }

        /* ===== PHOTO DE PROFIL ===== */
        .photo-widget { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; }
        .photo-preview {
            width: 110px; height: 110px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid var(--primary-green);
            box-shadow: 0 4px 16px rgba(22,163,74,0.2);
            background: #ffffff;
            flex-shrink: 0;
        }
        .photo-preview img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .photo-actions { flex: 1; min-width: 220px; }
        .photo-file {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border: 2px dashed rgba(22,163,74,0.35);
            border-radius: 12px;
            background: rgba(22,163,74,0.06);
            color: var(--primary-green);
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
        }
        .photo-file:hover { background: rgba(22,163,74,0.12); }
        .profile-name { font-size: 1.4rem; font-weight: 700; margin-bottom: 4px; }
        .profile-email { color: var(--gray-text); font-size: 0.88rem; margin-bottom: 12px; }
        .profile-badge {
            display: inline-block;
            padding: 5px 16px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .profile-badge.admin { background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); color: white; }

        .profile-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid rgba(0,0,0,0.06);
        }
        .profile-stat { text-align: center; }
        .profile-stat .number { font-size: 1.3rem; font-weight: 700; color: var(--primary-green); }
        .profile-stat .label { font-size: 0.72rem; color: var(--gray-text); }

        .profile-date {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid rgba(0,0,0,0.06);
            font-size: 0.8rem;
            color: var(--gray-text);
        }

        /* ===== SETTINGS CARDS ===== */
        .settings-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 24px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 20px;
            animation: fadeInUp 0.4s ease;
        }
        .settings-card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 14px;
            border-bottom: 1px solid rgba(0,0,0,0.06);
        }
        .settings-card-header .icon {
            width: 40px; height: 40px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
        }
        .settings-card-header h3 { font-size: 1.05rem; font-weight: 600; margin: 0; }

        /* ===== FORMS ===== */
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-weight: 600; font-size: 0.82rem; margin-bottom: 6px; color: var(--dark-text); }
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid rgba(0,0,0,0.08);
            border-radius: 10px;
            font-size: 0.9rem;
            font-family: 'Inter', sans-serif;
            transition: var(--transition);
            background: var(--white);
        }
        .form-group input:focus { outline: none; border-color: var(--primary-green); box-shadow: 0 0 0 3px rgba(22,163,74,0.1); }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

        .btn-primary-custom {
            padding: 12px 28px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary-custom:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(22,163,74,0.3); }

        .btn-danger-custom {
            padding: 12px 28px;
            background: linear-gradient(135deg, #DC2626, #DC2626);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-danger-custom:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(239,83,80,0.3); }

        .password-group { position: relative; }
        .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray-text);
            cursor: pointer;
        }

        .password-strength {
            margin-top: 8px;
            height: 4px;
            background: rgba(0,0,0,0.06);
            border-radius: 2px;
            overflow: hidden;
        }
        .password-strength .bar { height: 100%; width: 0%; border-radius: 2px; transition: var(--transition); }
        .password-strength .bar.weak { width: 25%; background: #DC2626; }
        .password-strength .bar.medium { width: 50%; background: #DC2626; }
        .password-strength .bar.good { width: 75%; background: #16A34A; }
        .password-strength .bar.strong { width: 100%; background: var(--primary-green); }

        .password-match {
            font-size: 0.78rem;
            margin-top: 4px;
            display: none;
        }
        .password-match.ok { color: var(--primary-green); }
        .password-match.ko { color: #DC2626; }

        /* ===== ANIMATIONS ===== */
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .toggle-sidebar { display: block; }
        }
        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; }
            .content-wrapper { padding: 20px; }
            .profile-avatar { width: 90px; height: 90px; font-size: 2.2rem; }
        }
        @media (max-width: 576px) {
            .top-bar { padding: 14px 16px; }
            .content-wrapper { padding: 16px; }
            .alert-custom { margin: 0 16px 16px; }
        }
    </style>    <!-- Palette verte PharmaGeo -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/palette.css">
    <!-- Favicon & identité visuelle PharmaGéo -->
    <link rel="icon" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon.ico" sizes="any">
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo BASE_URL; ?>assets/identite/favicons/apple-touch-icon.png">
    <link rel="manifest" href="<?php echo BASE_URL; ?>assets/identite/favicons/site.webmanifest">
    <meta property="og:image" content="<?php echo BASE_URL; ?>assets/identite/reseaux-sociaux/og-image-1200x630.png">
</head>
<body>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar" id="sidebar">
        <a href="<?php echo BASE_URL; ?>index.php" class="brand" style="text-decoration: none;">
            <div class="brand-icon"><img class="pg-brand-logo" src="<?php echo BASE_URL; ?>assets/identite/icones/pharmageo-icone-blanc.svg" alt=""></div>
            <div><h4>PharmaGeo</h4><small>Administration</small></div>
        </a>

        <nav class="nav-menu">
            <div class="nav-label">Navigation</div>
            <a href="<?php echo BASE_URL; ?>admin/dashboard.php" class="nav-item">
                <i class="fas fa-tachometer-alt"></i><span>Tableau de bord</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/pharmacies.php" class="nav-item">
                <i class="fas fa-store"></i><span>Pharmacies</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/utilisateurs.php" class="nav-item">
                <i class="fas fa-users"></i><span>Utilisateurs</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/medicaments.php" class="nav-item">
                <i class="fas fa-capsules"></i><span>Médicaments</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/reservations.php" class="nav-item">
                <i class="fas fa-calendar-check"></i><span>Réservations</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/statistiques.php" class="nav-item">
                <i class="fas fa-chart-bar"></i><span>Statistiques</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/logs.php" class="nav-item">
                <i class="fas fa-history"></i><span>Journaux d'activité</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/parametres.php" class="nav-item">
                <i class="fas fa-cog"></i><span>Paramètres</span>
            </a>

            <div class="nav-label" style="margin-top: 20px;">Mon compte</div>
            <a href="<?php echo BASE_URL; ?>admin/profil.php" class="nav-item active">
                <i class="fas fa-user-edit"></i><span>Mon profil</span>
            </a>
        </nav>

        <a class="user-profile" href="<?php echo BASE_URL; ?>admin/profil.php" aria-current="page">
<div class="avatar">
                <?php if (!empty($_SESSION['photo']) && pharma_image_disponible($_SESSION['photo'])): ?>
                    <img src="<?php echo htmlspecialchars(pharma_image_url($_SESSION['photo'])); ?>" alt="">
                <?php else: ?>
                    <?php echo strtoupper(substr($_SESSION['prenom'] ?? 'A', 0, 1) . substr($_SESSION['nom'] ?? 'D', 0, 1)); ?>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <div class="name"><?php echo htmlspecialchars(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')); ?></div>
                <div class="role"><i class="fas fa-crown me-1" style="color: #DC2626;"></i> Administrateur</div>
            </div>
            <i class="fas fa-chevron-right profile-chevron"></i>
        </a>

        <?php require_once __DIR__ . '/../includes/reinitialiser.php'; echo html_bloc_reinitialiser(); ?>
        <a href="<?php echo BASE_URL; ?>deconnexion.php" class="nav-item" style="color: #FEF2F2; padding: 14px 20px; border-top: 1px solid rgba(255,255,255,0.15); margin-top: auto; text-decoration: none;">
            <i class="fas fa-sign-out-alt"></i><span>Déconnexion</span>
        </a>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">

        <div class="top-bar">
            <div>
                <div class="d-flex align-items-center gap-3">
                    <button class="toggle-sidebar" id="toggleSidebar"><i class="fas fa-bars"></i></button>
                    <div class="page-title">
                        <h1><span class="highlight">Mon profil</span></h1>
                        <p><i class="fas fa-user me-1" style="color: var(--primary-green);"></i> Gérez vos informations personnelles</p>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert-custom alert-custom-danger">
                <i class="fas fa-exclamation-circle"></i><div><?php echo $error; ?></div>
            </div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert-custom alert-custom-success">
                <i class="fas fa-check-circle"></i><div><?php echo $success; ?></div>
            </div>
        <?php endif; ?>

        <div class="content-wrapper">
            <div class="row g-4">

                <!-- LEFT: Profile Card -->
                <div class="col-lg-4">
                    <div class="profile-card">
<div class="profile-avatar">
                            <?php if ($photo_admin_dispo): ?>
                                <img src="<?php echo htmlspecialchars(pharma_image_url($photo_admin)); ?>"
                                     alt="Photo de profil"
                                     onerror="this.onerror=null;this.src='<?php echo htmlspecialchars(pharma_image_defaut('pharmacien')); ?>';">
                            <?php else: ?>
                                <?php echo strtoupper(substr($user['Prenom'] ?? 'A', 0, 1) . substr($user['Nom'] ?? 'D', 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="profile-name"><?php echo htmlspecialchars(($user['Prenom'] ?? '') . ' ' . ($user['Nom'] ?? '')); ?></div>
                        <div class="profile-email"><?php echo htmlspecialchars($user['Email'] ?? ''); ?></div>
                        <span class="profile-badge admin">
                            <i class="fas fa-crown"></i> Administrateur
                        </span>

                        <div class="profile-stats">
                            <div class="profile-stat">
                                <div class="number"><?php echo number_format($stats['utilisateurs']); ?></div>
                                <div class="label">Utilisateurs</div>
                            </div>
                            <div class="profile-stat">
                                <div class="number"><?php echo number_format($stats['pharmacies']); ?></div>
                                <div class="label">Pharmacies</div>
                            </div>
                            <div class="profile-stat">
                                <div class="number"><?php echo number_format($stats['medicaments']); ?></div>
                                <div class="label">Médicaments</div>
                            </div>
                            <div class="profile-stat">
                                <div class="number"><?php echo number_format($stats['reservations']); ?></div>
                                <div class="label">Réservations</div>
                            </div>
                        </div>

                        <div class="profile-date">
                            <i class="fas fa-calendar-alt"></i>
                            Membre depuis le <?php echo date('d/m/Y', strtotime($user['Date_creation'] ?? 'now')); ?>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: Settings Forms -->
                <div class="col-lg-8">

                    <!-- Photo de profil -->
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <div class="icon"><i class="fas fa-camera"></i></div>
                            <h3>Photo de profil</h3>
                        </div>
                        <div class="photo-widget">
                            <div class="photo-preview">
                                <img id="adminPhotoPreview"
                                     src="<?php echo htmlspecialchars(pharma_image_url_ou_defaut($photo_admin, 'pharmacien')); ?>"
                                     alt="Aperçu de la photo de profil"
                                     onerror="this.onerror=null;this.src='<?php echo htmlspecialchars(pharma_image_defaut('pharmacien')); ?>';">
                            </div>
                            <div class="photo-actions">
                                <?php if ($photo_admin_dispo): ?>
                                    <p style="margin:0 0 10px; font-size:.85rem; color:var(--gray-text);">
                                        <i class="fas fa-check-circle me-1" style="color:var(--primary-green);"></i>
                                        Une photo est enregistrée. Vous pouvez la remplacer ci-dessous.
                                    </p>
                                <?php else: ?>
                                    <p style="margin:0 0 10px; font-size:.85rem; color:var(--gray-text);">
                                        <i class="fas fa-image me-1"></i>
                                        Aucune photo : l'initiale et l'image par defaut sont affichees.
                                    </p>
                                <?php endif; ?>
                                <form method="POST" action="" enctype="multipart/form-data" class="photo-form">
                                    <input type="hidden" name="action" value="upload_photo">
                                    <label class="photo-file" for="photo">
                                        <i class="fas fa-upload"></i>
                                        <?php echo $photo_admin_dispo ? 'Choisir une nouvelle photo' : 'Ajouter une photo'; ?>
                                    </label>
                                    <input type="file" id="photo" name="photo"
                                           accept="image/jpeg,image/png,image/webp"
                                           data-preview="adminPhotoPreview" hidden>
                                    <small style="display:block; margin-top:8px; color:var(--gray-text); font-size:.75rem;">
                                        Formats acceptes : JPG, JPEG, PNG, WEBP - Taille maximale : 2 Mo
                                    </small>
                                    <button type="submit" class="btn-primary-custom" style="margin-top:12px;">
                                        <i class="fas fa-save"></i>
                                        <?php echo $photo_admin_dispo ? 'Enregistrer la nouvelle photo' : 'Enregistrer la photo'; ?>
                                    </button>
                                </form>
                                <?php if ($photo_admin_dispo): ?>
                                    <form method="POST" action="" class="photo-form" style="margin-top:10px;">
                                        <input type="hidden" name="action" value="delete_photo">
                                        <button type="submit" class="btn-danger-custom">
                                            <i class="fas fa-trash"></i> Supprimer la photo
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Personal Info -->
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <div class="icon"><i class="fas fa-user-edit"></i></div>
                            <h3>Informations personnelles</h3>
                        </div>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="update_profile">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Nom</label>
                                    <input type="text" name="nom" value="<?php echo htmlspecialchars($user['Nom'] ?? ''); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Prénom</label>
                                    <input type="text" name="prenom" value="<?php echo htmlspecialchars($user['Prenom'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Email</label>
                                    <input type="email" name="email" value="<?php echo htmlspecialchars($user['Email'] ?? ''); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Téléphone</label>
                                    <input type="tel" name="telephone" value="<?php echo htmlspecialchars($user['Telephone'] ?? ''); ?>">
                                </div>
                            </div>
                            <button type="submit" class="btn-primary-custom">
                                <i class="fas fa-save"></i> Enregistrer
                            </button>
                        </form>
                    </div>

                    <!-- Change Password -->
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <div class="icon"><i class="fas fa-lock"></i></div>
                            <h3>Changer le mot de passe</h3>
                        </div>
                        <form method="POST" action="" id="passwordForm">
                            <input type="hidden" name="action" value="change_password">
                            <div class="form-group">
                                <label>Mot de passe actuel</label>
                                <div class="password-group">
                                    <input type="password" name="current_password" required>
                                    <button type="button" class="toggle-password" onclick="togglePwd(this)"><i class="fas fa-eye"></i></button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Nouveau mot de passe</label>
                                <div class="password-group">
                                    <input type="password" name="new_password" id="newPwd" required oninput="checkStrength(this.value)">
                                    <button type="button" class="toggle-password" onclick="togglePwd(this)"><i class="fas fa-eye"></i></button>
                                </div>
                                <div class="password-strength"><div class="bar" id="strBar"></div></div>
                                <small style="color:var(--gray-text);font-size:0.75rem;">Min. 8 caractères, 1 majuscule, 1 minuscule, 1 chiffre</small>
                            </div>
                            <div class="form-group">
                                <label>Confirmer le mot de passe</label>
                                <div class="password-group">
                                    <input type="password" name="confirm_password" id="confirmPwd" required oninput="checkMatch()">
                                    <button type="button" class="toggle-password" onclick="togglePwd(this)"><i class="fas fa-eye"></i></button>
                                </div>
                                <div class="password-match" id="matchMsg"></div>
                            </div>
                            <button type="submit" class="btn-primary-custom">
                                <i class="fas fa-key"></i> Changer le mot de passe
                            </button>
                        </form>
                    </div>

                </div>
            </div>
        </div>
    </main>

    <script>
        // Sidebar toggle
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        document.getElementById('toggleSidebar').addEventListener('click', () => {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
        });
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
        });

        // Password visibility
        function togglePwd(btn) {
            const input = btn.previousElementSibling;
            const icon = btn.querySelector('i');
            if (input.type === 'password') { input.type = 'text'; icon.className = 'fas fa-eye-slash'; }
            else { input.type = 'password'; icon.className = 'fas fa-eye'; }
        }

        // Password strength
        function checkStrength(p) {
            const bar = document.getElementById('strBar');
            bar.className = 'bar';
            if (p.length === 0) return;
            let s = 0;
            if (p.length >= 8) s++;
            if (/[A-Z]/.test(p)) s++;
            if (/[a-z]/.test(p)) s++;
            if (/[0-9]/.test(p)) s++;
            if (s <= 1) bar.classList.add('weak');
            else if (s === 2) bar.classList.add('medium');
            else if (s === 3) bar.classList.add('good');
            else bar.classList.add('strong');
        }

        // Password match
        function checkMatch() {
            const msg = document.getElementById('matchMsg');
            const a = document.getElementById('newPwd').value;
            const b = document.getElementById('confirmPwd').value;
            if (b.length === 0) { msg.style.display = 'none'; return; }
            msg.style.display = 'block';
            if (a === b) { msg.textContent = 'Les mots de passe correspondent'; msg.className = 'password-match ok'; }
            else { msg.textContent = 'Les mots de passe ne correspondent pas'; msg.className = 'password-match ko'; }
        }

        // Photo de profil : aperçu avant envoi + contrôle format / taille
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
                lecteur.onload = function(e) { cible.src = e.target.result; };
                lecteur.readAsDataURL(fichier);
            });
        });

        // Keyboard shortcut Ctrl+B
        document.addEventListener('keydown', e => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'b') { e.preventDefault(); sidebar.classList.toggle('open'); overlay.classList.toggle('active'); }
            if (e.key === 'Escape' && sidebar.classList.contains('open')) { sidebar.classList.remove('open'); overlay.classList.remove('active'); }
        });
    </script>
</body>
</html>