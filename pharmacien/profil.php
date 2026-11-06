<?php
// ============================================================
// PharmaGeo — Espace pharmacien : Mon profil
// Permet de modifier les informations personnelles du compte,
// le mot de passe et de gérer la suppression du compte.
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
        SELECT u.*, ph.Id_pharmacien, ph.Numero_ordre, ph.Statut_validation, ph.Date_validation,
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
    $pharmacie_id = $user['Id_pharmacie'] ?? null;

} catch(PDOException $e) {
    die("Erreur : " . $e->getMessage());
}

// ===== TRAITEMENT DES FORMULAIRES =====
$error = '';
$success = '';

// ===== MISE À JOUR DU PROFIL =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    
    $errors = [];
    if (empty($nom)) $errors[] = "Le nom est obligatoire.";
    if (empty($prenom)) $errors[] = "Le prénom est obligatoire.";
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "L'email est invalide.";
    
    // Vérifier si l'email est déjà utilisé par un autre utilisateur
    if (empty($errors) && $email !== $user['Email']) {
        $check = $pdo->prepare("SELECT COUNT(*) as total FROM utilisateur WHERE LOWER(Email) = LOWER(:email) AND Id_utilisateur != :id");
        $check->execute([':email' => $email, ':id' => $_SESSION['user_id']]);
        if ($check->fetch()['total'] > 0) {
            $errors[] = "Cet email est déjà utilisé par un autre compte.";
        }
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE utilisateur 
                SET Nom = :nom, Prenom = :prenom, Email = :email, Telephone = :telephone 
                WHERE Id_utilisateur = :id
            ");
            $stmt->execute([
                ':nom' => $nom,
                ':prenom' => $prenom,
                ':email' => $email,
                ':telephone' => $telephone,
                ':id' => $_SESSION['user_id']
            ]);
            
            // Mise à jour de la session
            $_SESSION['nom'] = $nom;
            $_SESSION['prenom'] = $prenom;
            $_SESSION['email'] = $email;
            
            // Recharger les données
            $stmt = $pdo->prepare("
                SELECT u.*, ph.Id_pharmacien, ph.Numero_ordre, ph.Statut_validation, ph.Date_validation,
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
            
            $success = "Profil mis à jour avec succès !";
            
            // Journalisation
            $log = $pdo->prepare("INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur) 
                                  VALUES ('Mise à jour du profil pharmacien', :ip, :ua, 'succes', :user)");
            $log->execute([
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':user' => $_SESSION['user_id']
            ]);
            
        } catch(PDOException $e) {
            $error = "Erreur lors de la mise à jour : " . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// ===== CHANGEMENT DE MOT DE PASSE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    $errors = [];
    if (empty($current_password)) $errors[] = "Le mot de passe actuel est obligatoire.";
    if (empty($new_password)) $errors[] = "Le nouveau mot de passe est obligatoire.";
    if (strlen($new_password) < 8) $errors[] = "Le mot de passe doit contenir au moins 8 caractères.";
    if (!preg_match('/[A-Z]/', $new_password)) $errors[] = "Le mot de passe doit contenir au moins une majuscule.";
    if (!preg_match('/[a-z]/', $new_password)) $errors[] = "Le mot de passe doit contenir au moins une minuscule.";
    if (!preg_match('/[0-9]/', $new_password)) $errors[] = "Le mot de passe doit contenir au moins un chiffre.";
    if ($new_password !== $confirm_password) $errors[] = "Les mots de passe ne correspondent pas.";
    
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT Mot_de_passe FROM utilisateur WHERE Id_utilisateur = :id");
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user_data = $stmt->fetch();
        
        if ($user_data && password_verify($current_password, $user_data['Mot_de_passe'])) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE utilisateur SET Mot_de_passe = :password WHERE Id_utilisateur = :id");
            $stmt->execute([
                ':password' => $hashed_password,
                ':id' => $_SESSION['user_id']
            ]);
            
            $success = "Mot de passe modifié avec succès !";
            
            $log = $pdo->prepare("INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur) 
                                  VALUES ('Changement de mot de passe pharmacien', :ip, :ua, 'succes', :user)");
            $log->execute([
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':user' => $_SESSION['user_id']
            ]);
            
        } else {
            $error = "Le mot de passe actuel est incorrect.";
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// ===== PHOTO DE PROFIL DU PHARMACIEN =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_photo_pharmacien') {
    if (!isset($_FILES['photo_pharmacien']) || ($_FILES['photo_pharmacien']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $error = "Veuillez sélectionner une image.";
    } else {
        try {
            $nouvelle = pharma_upload_image($_FILES['photo_pharmacien'], 'photos', 'pharmacien_' . $_SESSION['user_id']);
            $ancienne = $user['Photo'] ?? null;

            $pdo->prepare("UPDATE utilisateur SET Photo = :photo WHERE Id_utilisateur = :id")
                ->execute([':photo' => $nouvelle, ':id' => $_SESSION['user_id']]);

            // Remplace l'ancienne image pour ne pas accumuler de fichiers orphelins.
            if (!empty($ancienne) && $ancienne !== $nouvelle) {
                pharma_supprimer_image($ancienne);
            }

            $_SESSION['photo'] = $nouvelle;
            $user['Photo'] = $nouvelle;
            $success = "Photo de profil mise à jour avec succès !";

            $log = $pdo->prepare("INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur)
                                  VALUES ('Mise à jour photo de profil pharmacien', :ip, :ua, 'succes', :user)");
            $log->execute([
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':user' => $_SESSION['user_id']
            ]);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Erreur lors de l'enregistrement de la photo.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_photo_pharmacien') {
    try {
        $ancienne = $user['Photo'] ?? null;
        $pdo->prepare("UPDATE utilisateur SET Photo = NULL WHERE Id_utilisateur = :id")
            ->execute([':id' => $_SESSION['user_id']]);
        if (!empty($ancienne)) {
            pharma_supprimer_image($ancienne);
        }
        unset($_SESSION['photo']);
        $user['Photo'] = null;
        $success = "Photo de profil supprimée.";
    } catch (PDOException $e) {
        $error = "Erreur lors de la suppression de la photo.";
    }
}

// ===== PHOTO DE LA PHARMACIE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_photo_pharmacie' && $has_pharmacy) {
    if (!isset($_FILES['photo_pharmacie']) || ($_FILES['photo_pharmacie']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $error = "Veuillez sélectionner une image.";
    } else {
        try {
            $nouvelle = pharma_upload_image($_FILES['photo_pharmacie'], 'logos', 'pharmacie_' . $user['Id_pharmacie']);
            $ancienne = $user['Logo'] ?? null;

            $pdo->prepare("UPDATE pharmacie SET Logo = :logo WHERE Id_pharmacie = :id")
                ->execute([':logo' => $nouvelle, ':id' => $user['Id_pharmacie']]);

            if (!empty($ancienne) && $ancienne !== $nouvelle) {
                pharma_supprimer_image($ancienne);
            }

            $user['Logo'] = $nouvelle;
            $success = "Photo de la pharmacie mise à jour avec succès !";

            $log = $pdo->prepare("INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur)
                                  VALUES ('Mise à jour photo de la pharmacie', :ip, :ua, 'succes', :user)");
            $log->execute([
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':user' => $_SESSION['user_id']
            ]);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Erreur lors de l'enregistrement de la photo.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_photo_pharmacie' && $has_pharmacy) {
    try {
        $ancienne = $user['Logo'] ?? null;
        $pdo->prepare("UPDATE pharmacie SET Logo = NULL WHERE Id_pharmacie = :id")
            ->execute([':id' => $user['Id_pharmacie']]);
        if (!empty($ancienne)) {
            pharma_supprimer_image($ancienne);
        }
        $user['Logo'] = null;
        $success = "Photo de la pharmacie supprimée.";
    } catch (PDOException $e) {
        $error = "Erreur lors de la suppression de la photo.";
    }
}

// ===== RÉCUPÉRATION DES STATISTIQUES (CORRIGÉ) =====
try {
    // Total des réservations
    $stmt_reservations = $pdo->prepare("
        SELECT COUNT(*) as total FROM reservation r 
        JOIN stock s ON r.Id_stock = s.Id_stock 
        WHERE s.Id_pharmacie = :id
    ");
    $stmt_reservations->execute([':id' => $pharmacie_id]);
    $total_reservations = $stmt_reservations->fetch()['total'] ?? 0;

    // Total des médicaments en stock
    $stmt_medicaments = $pdo->prepare("
        SELECT COUNT(*) as total FROM stock WHERE Id_pharmacie = :id
    ");
    $stmt_medicaments->execute([':id' => $pharmacie_id]);
    $total_medicaments = $stmt_medicaments->fetch()['total'] ?? 0;

    // Réservations en attente
    $stmt_attente = $pdo->prepare("
        SELECT COUNT(*) as total FROM reservation r 
        JOIN stock s ON r.Id_stock = s.Id_stock 
        WHERE s.Id_pharmacie = :id AND r.Statut = 'en_attente'
    ");
    $stmt_attente->execute([':id' => $pharmacie_id]);
    $total_attente = $stmt_attente->fetch()['total'] ?? 0;

    // Regrouper les statistiques
    $stats = [
        'reservations' => $total_reservations,
        'medicaments' => $total_medicaments,
        'en_attente' => $total_attente
    ];

} catch(PDOException $e) {
    $stats = ['reservations' => 0, 'medicaments' => 0, 'en_attente' => 0];
}

// ===== PHOTOS =====
$photo_pharmacien = $user['Photo'] ?? null;
$photo_pharmacie = $user['Logo'] ?? null;
$photo_pharmacien_dispo = pharma_image_disponible($photo_pharmacien);
$photo_pharmacie_dispo = $has_pharmacy && pharma_image_disponible($photo_pharmacie);

$page_title = 'Mon profil - PharmaGeo';
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
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
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
            --shadow-xl: 0 30px 80px rgba(0,0,0,0.16);
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

        /* ===== PROFILE CARD ===== */
        .profile-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-sm);
            border: 1px solid rgba(0,0,0,0.02);
            transition: var(--transition);
        }

        .profile-card:hover {
            box-shadow: var(--shadow-md);
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3.5rem;
            font-weight: 800;
            color: white;
            margin: 0 auto 16px;
            box-shadow: var(--shadow-glow-green);
            border: 4px solid var(--white);
            overflow: hidden;
        }

        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }

        /* ===== GESTION DES PHOTOS ===== */
        .photo-widget {
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
            padding: 16px;
            border: 1px dashed #CBE7D6;
            border-radius: var(--radius);
            background: #FAFFFC;
            margin-bottom: 18px;
        }
        .photo-widget__preview {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            overflow: hidden;
            flex-shrink: 0;
            border: 3px solid #fff;
            box-shadow: var(--shadow-sm);
            background: #F0FDF4;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .photo-widget__preview img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .photo-widget--pharmacie .photo-widget__preview { border-radius: 18px; }
        .photo-widget__body { flex: 1; min-width: 220px; }
        .photo-widget__body h6 { font-weight: 800; margin-bottom: 2px; }
        .photo-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
        .photo-file {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 16px;
            border-radius: 50px;
            border: 1px solid var(--primary-green);
            color: var(--primary-green);
            background: #fff;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.85rem;
            transition: var(--transition);
            margin: 0;
        }
        .photo-file:hover { background: rgba(22,163,74,0.08); }
        .photo-file input[type=file] { display: none; }
        .btn-photo {
            border: none;
            padding: 9px 16px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: var(--transition);
        }
        .btn-photo--primary { background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); color: #fff; }
        .btn-photo--primary:hover { transform: translateY(-2px); color: #fff; }
        .btn-photo--danger { background: #FEF2F2; color: #DC2626; border: 1px solid #FECACA; }
        .btn-photo--danger:disabled { opacity: 0.45; cursor: not-allowed; }
        .photo-hint { font-size: 0.78rem; color: var(--gray-text); display: block; margin-top: 6px; }
        .pharmacy-info-card .pharmacy-photo {
            width: 100%;
            max-height: 150px;
            object-fit: cover;
            border-radius: 14px;
            margin-bottom: 14px;
            display: block;
            border: 1px solid rgba(0,0,0,0.04);
        }

        .profile-name {
            text-align: center;
            font-size: 1.4rem;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .profile-email {
            text-align: center;
            color: var(--gray-text);
            margin-bottom: 16px;
        }

        .profile-badge {
            display: inline-block;
            padding: 6px 18px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .profile-badge.valide {
            background: rgba(22,163,74,0.1);
            color: var(--primary-green);
        }

        .profile-badge.en_attente {
            background: rgba(21,128,61,0.1);
            color: var(--primary-orange);
        }

        .profile-badge.rejete {
            background: rgba(220,38,38,0.1);
            color: #DC2626;
        }

        .profile-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            padding-top: 16px;
            border-top: 1px solid #F0FDF4;
        }

        .profile-stats .stat-item {
            text-align: center;
        }

        .profile-stats .stat-item .number {
            font-size: 1.4rem;
            font-weight: 800;
            display: block;
        }

        .profile-stats .stat-item .label {
            font-size: 0.7rem;
            color: var(--gray-text);
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

        .form-modern .input-group-custom .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray-text);
            cursor: pointer;
            padding: 4px;
            transition: var(--transition);
            z-index: 3;
        }

        .form-modern .input-group-custom .toggle-password:hover {
            color: var(--primary-green);
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

        /* ===== PASSWORD STRENGTH ===== */
        .password-strength {
            height: 4px;
            border-radius: 4px;
            background: #D9E9DF;
            margin-top: 8px;
            overflow: hidden;
            transition: var(--transition);
        }

        .password-strength .bar {
            height: 100%;
            width: 0%;
            border-radius: 4px;
            transition: width 0.4s ease, background 0.4s ease;
        }

        .password-strength .bar.weak { width: 25%; background: #DC2626; }
        .password-strength .bar.medium { width: 50%; background: #166534; }
        .password-strength .bar.good { width: 75%; background: #16A34A; }
        .password-strength .bar.strong { width: 100%; background: var(--primary-green); }

        .password-strength-label {
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 4px;
            text-align: right;
        }

        /* ===== PHARMACY INFO ===== */
        .pharmacy-info-card {
            background: var(--light-bg);
            border-radius: var(--radius);
            padding: 16px 20px;
            margin-top: 16px;
            border-left: 4px solid var(--primary-green);
        }

        .pharmacy-info-card .pharmacy-name {
            font-weight: 700;
            font-size: 1.1rem;
        }

        .pharmacy-info-card .pharmacy-detail {
            font-size: 0.85rem;
            color: var(--gray-text);
            margin-top: 2px;
        }

        .pharmacy-info-card .pharmacy-detail i {
            width: 20px;
            color: var(--primary-green);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 991px) {
            .main-content { padding: 15px; }
            .top-bar .page-title h1 { font-size: 1.4rem; }
        }

        @media (max-width: 768px) {
            .top-bar { flex-direction: column; align-items: stretch; }
            .profile-avatar { width: 90px; height: 90px; font-size: 2.5rem; }
            .profile-name { font-size: 1.2rem; }
            .profile-stats { grid-template-columns: repeat(3, 1fr); }
            .settings-card { padding: 18px; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 10px; }
            .top-bar .page-title h1 { font-size: 1.2rem; }
            .profile-card { padding: 20px; }
            .profile-avatar { width: 70px; height: 70px; font-size: 2rem; }
            .profile-stats { grid-template-columns: repeat(3, 1fr); gap: 8px; }
            .profile-stats .stat-item .number { font-size: 1.1rem; }
            .settings-card { padding: 14px; }
            .settings-card .card-header-custom .header-icon { width: 40px; height: 40px; font-size: 1.1rem; }
            .form-modern .input-group-custom .form-control { padding: 10px 14px 10px 38px; font-size: 0.9rem; }
            .btn-primary-custom { padding: 10px 20px; font-size: 0.9rem; }
            .navbar-brand-custom { font-size: 1.3rem; }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/responsive.css">    <!-- Palette verte PharmaGeo -->
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

    <div class="layout">

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar-admin" id="sidebar">
        <a href="<?php echo BASE_URL; ?>index.php" class="brand-admin" style="text-decoration: none;">
            <div class="brand-icon"><img class="pg-brand-logo" src="<?php echo BASE_URL; ?>assets/identite/icones/pharmageo-icone-blanc.svg" alt=""></div>
            <div><h4>PharmaGeo</h4><small><?php echo htmlspecialchars(pharma_libelle_role('pharmacien', $user['Prenom'] ?? '')); ?></small></div>
        </a>
        <nav class="nav-menu">
            <div class="nav-label">Navigation</div>
            <a href="<?php echo BASE_URL; ?>pharmacien/dashboard.php" class="nav-item"><i class="fas fa-tachometer-alt"></i><span>Tableau de bord</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/pharmacie.php" class="nav-item"><i class="fas fa-store"></i><span>Ma pharmacie</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/medicaments.php" class="nav-item"><i class="fas fa-capsules"></i><span>Médicaments</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/stocks.php" class="nav-item"><i class="fas fa-boxes"></i><span>Stocks</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/historique_stocks.php" class="nav-item"><i class="fas fa-history"></i><span>Historique</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/reservations.php" class="nav-item"><i class="fas fa-calendar-check"></i><span>Réservations</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/statistiques.php" class="nav-item"><i class="fas fa-chart-bar"></i><span>Statistiques</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/profil.php" class="nav-item active"><i class="fas fa-user"></i><span>Profil</span></a>
            <div class="nav-label" style="margin-top: 20px;">Système</div>
            <?php require_once __DIR__ . '/../includes/reinitialiser.php'; echo html_bloc_reinitialiser(); ?>
            <a href="<?php echo BASE_URL; ?>deconnexion.php" class="nav-item" style="color: #FEF2F2; margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.15); margin-bottom: 12px;"><i class="fas fa-sign-out-alt"></i><span>Déconnexion</span></a>
        </nav>
        <a class="user-profile" href="<?php echo BASE_URL; ?>pharmacien/profil.php" aria-current="page">
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
                <h1>Mon <span class="highlight">profil</span></h1>
                <p>
                    <i class="fas fa-user me-1" style="color: var(--primary-green);"></i>
                    Gérez vos informations personnelles
                </p>
            </div>
        </div>

        <!-- ===== MESSAGES ===== -->
        <?php if (!empty($error)): ?>
            <div class="alert-custom alert-custom-danger animate-fade-up">
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

        <div class="row g-4">

            <!-- ===== COLONNE GAUCHE - PROFIL ===== -->
            <div class="col-lg-4 animate-fade-up delay-1">
                <div class="profile-card">
                    <div class="profile-avatar">
                        <img src="<?php echo htmlspecialchars(pharma_image_url_ou_defaut($user['Photo'] ?? null, 'pharmacien')); ?>"
                             alt="Photo de <?php echo htmlspecialchars($user['Prenom'] . ' ' . $user['Nom']); ?>">
                    </div>
                    <div class="profile-name">
                        <?php echo htmlspecialchars($user['Prenom'] . ' ' . $user['Nom']); ?>
                    </div>
                    <div class="profile-email">
                        <i class="fas fa-envelope me-1"></i>
                        <?php echo htmlspecialchars($user['Email']); ?>
                    </div>
                    <div class="text-center">
                        <span class="profile-badge <?php echo $user['Statut_validation']; ?>">
                            <i class="fas fa-<?php echo $user['Statut_validation'] === 'valide' ? 'check-circle' : ($user['Statut_validation'] === 'en_attente' ? 'clock' : 'times-circle'); ?>"></i>
                            <?php 
                                $statuts_validation = [
                                    'valide' => 'Compte validé',
                                    'en_attente' => 'En attente de validation',
                                    'rejete' => 'Compte rejeté'
                                ];
                                echo $statuts_validation[$user['Statut_validation']] ?? $user['Statut_validation'];
                            ?>
                        </span>
                    </div>
                    <div class="profile-stats">
                        <div class="stat-item">
                            <span class="number" style="color: var(--primary-green);"><?php echo $stats['reservations']; ?></span>
                            <span class="label">Réservations</span>
                        </div>
                        <div class="stat-item">
                            <span class="number" style="color: #166534;"><?php echo $stats['medicaments']; ?></span>
                            <span class="label">Médicaments</span>
                        </div>
                        <div class="stat-item">
                            <span class="number" style="color: var(--primary-orange);"><?php echo $stats['en_attente']; ?></span>
                            <span class="label">En attente</span>
                        </div>
                    </div>

                    <!-- Informations pharmacie -->
                    <?php if ($has_pharmacy): ?>
                        <div class="pharmacy-info-card">
                            <img class="pharmacy-photo"
                                 src="<?php echo htmlspecialchars(pharma_image_url_ou_defaut($user['Logo'] ?? null, 'pharmacie')); ?>"
                                 alt="Photo de <?php echo htmlspecialchars($user['Pharmacie_Nom']); ?>">
                            <div class="pharmacy-name">
                                <i class="fas fa-store me-2"></i>
                                <?php echo htmlspecialchars($user['Pharmacie_Nom']); ?>
                            </div>
                            <div class="pharmacy-detail">
                                <i class="fas fa-map-marker-alt"></i>
                                <?php echo htmlspecialchars($user['Quartier'] ?? ''); ?>, <?php echo htmlspecialchars($user['Commune'] ?? 'Porto-Novo'); ?>
                            </div>
                            <?php if ($user['Pharmacie_Tel']): ?>
                                <div class="pharmacy-detail">
                                    <i class="fas fa-phone"></i>
                                    <?php echo htmlspecialchars($user['Pharmacie_Tel']); ?>
                                </div>
                            <?php endif; ?>
                            <div class="pharmacy-detail">
                                <i class="fas fa-id-card"></i>
                                N° ordre: <?php echo htmlspecialchars($user['Numero_ordre']); ?>
                            </div>
                            <div class="pharmacy-detail">
                                <i class="fas fa-calendar-check"></i>
                                <?php if ($user['Date_validation']): ?>
                                    Validé le: <?php echo date('d/m/Y', strtotime($user['Date_validation'])); ?>
                                <?php else: ?>
                                    En attente de validation
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="pharmacy-info-card" style="border-left-color: var(--primary-orange);">
                            <div class="pharmacy-name" style="color: var(--primary-orange);">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Aucune pharmacie associée
                            </div>
                            <div class="pharmacy-detail">
                                Veuillez contacter l'administrateur pour créer votre officine.
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="mt-3 pt-3 text-center" style="border-top: 1px solid #F0FDF4;">
                        <small class="text-muted">
                            <i class="fas fa-calendar-alt me-1"></i>
                            Membre depuis le <?php echo date('d/m/Y', strtotime($user['Date_creation'])); ?>
                        </small>
                    </div>
                </div>
            </div>

            <!-- ===== COLONNE DROITE - FORMULAIRES ===== -->
            <div class="col-lg-8">

                <!-- ===== SECTION PHOTOS ===== -->
                <div class="settings-card animate-fade-up delay-2">
                    <div class="card-header-custom">
                        <div class="header-icon green">
                            <i class="fas fa-camera"></i>
                        </div>
                        <div class="header-title">
                            <h5>Photos</h5>
                            <p>Personnalisez votre profil et votre établissement</p>
                        </div>
                    </div>

                    <!-- Photo du pharmacien -->
                    <div class="photo-widget">
                        <div class="photo-widget__preview">
                            <img id="previewPhotoPharmacien"
                                 src="<?php echo htmlspecialchars(pharma_image_url_ou_defaut($photo_pharmacien, 'pharmacien')); ?>"
                                 alt="Photo de profil">
                        </div>
                        <div class="photo-widget__body">
                            <h6><i class="fas fa-user-circle me-1" style="color: var(--primary-green);"></i>Photo du pharmacien</h6>
                            <span class="photo-hint">JPG, PNG ou WebP — 2 Mo maximum.</span>
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="upload_photo_pharmacien">
                                <div class="photo-actions">
                                    <label class="photo-file" for="photo_pharmacien">
                                        <i class="fas fa-camera"></i> Choisir une image
                                        <input type="file" id="photo_pharmacien" name="photo_pharmacien"
                                               accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                               data-preview="previewPhotoPharmacien">
                                    </label>
                                    <button type="submit" class="btn-photo btn-photo--primary">
                                        <i class="fas fa-upload"></i> <?php echo $photo_pharmacien_dispo ? 'Remplacer' : 'Ajouter'; ?>
                                    </button>
                                </div>
                            </form>
                            <form method="POST" onsubmit="return confirm('Supprimer votre photo de profil ?');">
                                <input type="hidden" name="action" value="delete_photo_pharmacien">
                                <div class="photo-actions">
                                    <button type="submit" class="btn-photo btn-photo--danger" <?php echo $photo_pharmacien_dispo ? '' : 'disabled'; ?>>
                                        <i class="fas fa-trash"></i> Supprimer
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <?php if ($has_pharmacy): ?>
                    <!-- Photo de la pharmacie -->
                    <div class="photo-widget photo-widget--pharmacie">
                        <div class="photo-widget__preview">
                            <img id="previewPhotoPharmacie"
                                 src="<?php echo htmlspecialchars(pharma_image_url_ou_defaut($photo_pharmacie, 'pharmacie')); ?>"
                                 alt="Photo de la pharmacie">
                        </div>
                        <div class="photo-widget__body">
                            <h6><i class="fas fa-store me-1" style="color: var(--primary-green);"></i>Photo de la pharmacie</h6>
                            <span class="photo-hint">JPG, PNG ou WebP — 2 Mo maximum.</span>
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="upload_photo_pharmacie">
                                <div class="photo-actions">
                                    <label class="photo-file" for="photo_pharmacie">
                                        <i class="fas fa-camera"></i> Choisir une image
                                        <input type="file" id="photo_pharmacie" name="photo_pharmacie"
                                               accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                               data-preview="previewPhotoPharmacie">
                                    </label>
                                    <button type="submit" class="btn-photo btn-photo--primary">
                                        <i class="fas fa-upload"></i> <?php echo $photo_pharmacie_dispo ? 'Remplacer' : 'Ajouter'; ?>
                                    </button>
                                </div>
                            </form>
                            <form method="POST" onsubmit="return confirm('Supprimer la photo de la pharmacie ?');">
                                <input type="hidden" name="action" value="delete_photo_pharmacie">
                                <div class="photo-actions">
                                    <button type="submit" class="btn-photo btn-photo--danger" <?php echo $photo_pharmacie_dispo ? '' : 'disabled'; ?>>
                                        <i class="fas fa-trash"></i> Supprimer
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ===== SECTION 1: INFORMATIONS PERSONNELLES ===== -->
                <div class="settings-card animate-fade-up delay-2">
                    <div class="card-header-custom">
                        <div class="header-icon green">
                            <i class="fas fa-user-edit"></i>
                        </div>
                        <div class="header-title">
                            <h5>Informations personnelles</h5>
                            <p>Mettez à jour vos informations de profil</p>
                        </div>
                    </div>

                    <form method="POST" class="form-modern">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="row g-3">
                            <div class="col-md-6 form-group">
                                <label for="nom" class="form-label">Nom <span class="required">*</span></label>
                                <div class="input-group-custom">
                                    <span class="input-icon"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control" id="nom" name="nom" 
                                           value="<?php echo htmlspecialchars($user['Nom']); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6 form-group">
                                <label for="prenom" class="form-label">Prénom <span class="required">*</span></label>
                                <div class="input-group-custom">
                                    <span class="input-icon"><i class="fas fa-user-tie"></i></span>
                                    <input type="text" class="form-control" id="prenom" name="prenom" 
                                           value="<?php echo htmlspecialchars($user['Prenom']); ?>" required>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="email" class="form-label">Adresse email <span class="required">*</span></label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-envelope"></i></span>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($user['Email']); ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="telephone" class="form-label">Téléphone</label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-phone"></i></span>
                                <input type="tel" class="form-control" id="telephone" name="telephone" 
                                       value="<?php echo htmlspecialchars($user['Telephone'] ?? ''); ?>">
                            </div>
                        </div>

                        <button type="submit" class="btn-primary-custom">
                            <i class="fas fa-save"></i> Mettre à jour le profil
                        </button>
                    </form>
                </div>

                <!-- ===== SECTION 2: CHANGEMENT DE MOT DE PASSE ===== -->
                <div class="settings-card animate-fade-up delay-3">
                    <div class="card-header-custom">
                        <div class="header-icon orange">
                            <i class="fas fa-lock"></i>
                        </div>
                        <div class="header-title">
                            <h5>Changer le mot de passe</h5>
                            <p>Modifiez votre mot de passe de connexion</p>
                        </div>
                    </div>

                    <form method="POST" class="form-modern" id="passwordForm">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="form-group">
                            <label for="current_password" class="form-label">Mot de passe actuel <span class="required">*</span></label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-key"></i></span>
                                <input type="password" class="form-control" id="current_password" name="current_password" required>
                                <button type="button" class="toggle-password" onclick="togglePassword('current_password')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="new_password" class="form-label">Nouveau mot de passe <span class="required">*</span></label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-lock"></i></span>
                                <input type="password" class="form-control" id="new_password" name="new_password" required>
                                <button type="button" class="toggle-password" onclick="togglePassword('new_password')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="password-strength" id="passwordStrength">
                                <div class="bar" id="strengthBar"></div>
                            </div>
                            <div class="password-strength-label" id="strengthLabel"></div>
                            <div class="form-text">
                                <small>Le mot de passe doit contenir au moins 8 caractères, une majuscule, une minuscule et un chiffre.</small>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password" class="form-label">Confirmer le mot de passe <span class="required">*</span></label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-check-double"></i></span>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                            </div>
                            <div class="form-text" id="passwordMatch"></div>
                        </div>

                        <button type="submit" class="btn-primary-custom">
                            <i class="fas fa-shield-alt"></i> Changer le mot de passe
                        </button>
                    </form>
                </div>

            </div>
        </div>

    </main>

    </div>

    <!-- ===== SCRIPTS ===== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {

            // ===== SIDEBAR MOBILE TOGGLE (Ctrl + B) =====
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.key.toLowerCase() === 'b') {
                    document.getElementById('sidebar').classList.toggle('open');
                }
            });

            // ===== PRÉVISUALISATION DES PHOTOS =====
            // Affiche l'image choisie avant l'envoi et vérifie format/taille.
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

            // ===== TOGGLE PASSWORD =====
            window.togglePassword = function(fieldId) {
                const field = document.getElementById(fieldId);
                const icon = field.parentElement.querySelector('.toggle-password i');
                if (field.type === 'password') {
                    field.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    field.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            };

            // ===== PASSWORD STRENGTH =====
            const newPassword = document.getElementById('new_password');
            const strengthBar = document.getElementById('strengthBar');
            const strengthLabel = document.getElementById('strengthLabel');

            if (newPassword) {
                newPassword.addEventListener('input', function() {
                    const pwd = this.value;
                    let score = 0;

                    if (pwd.length >= 8) score++;
                    if (/[A-Z]/.test(pwd)) score++;
                    if (/[a-z]/.test(pwd)) score++;
                    if (/[0-9]/.test(pwd)) score++;

                    if (pwd.length === 0) {
                        strengthBar.style.width = '0%';
                        strengthLabel.textContent = '';
                        return;
                    }

                    if (score <= 1) {
                        strengthBar.className = 'bar weak';
                        strengthLabel.textContent = '⚠️ Mot de passe faible';
                        strengthLabel.style.color = '#DC2626';
                    } else if (score === 2) {
                        strengthBar.className = 'bar medium';
                        strengthLabel.textContent = '⚠️ Mot de passe moyen';
                        strengthLabel.style.color = '#DC2626';
                    } else if (score === 3) {
                        strengthBar.className = 'bar good';
                        strengthLabel.textContent = '✅ Mot de passe bon';
                        strengthLabel.style.color = '#16A34A';
                    } else {
                        strengthBar.className = 'bar strong';
                        strengthLabel.textContent = '✅ Mot de passe fort';
                        strengthLabel.style.color = '#16A34A';
                    }
                });
            }

            // ===== PASSWORD MATCH =====
            const confirmPassword = document.getElementById('confirm_password');
            const passwordMatch = document.getElementById('passwordMatch');

            if (confirmPassword && newPassword) {
                confirmPassword.addEventListener('input', function() {
                    if (this.value.length === 0) {
                        passwordMatch.textContent = '';
                        return;
                    }
                    if (this.value === newPassword.value) {
                        passwordMatch.innerHTML = '<i class="fas fa-check-circle" style="color: var(--primary-green);"></i> Les mots de passe correspondent';
                        passwordMatch.style.color = 'var(--primary-green)';
                    } else {
                        passwordMatch.innerHTML = '<i class="fas fa-exclamation-circle" style="color: #DC2626;"></i> Les mots de passe ne correspondent pas';
                        passwordMatch.style.color = '#DC2626';
                    }
                });
            }

            // ===== KEYBOARD SHORTCUTS =====
            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    const form = document.querySelector('form');
                    if (form) form.submit();
                }
                if (e.key === 'Escape') {
                    const modal = document.querySelector('.modal.show');
                    if (modal) {
                        const closeBtn = modal.querySelector('.btn-close');
                        if (closeBtn) closeBtn.click();
                    }
                }
            });

            // ===== CONSOLE LOG =====
            console.log('%c PharmaGeo - Mon profil pharmacien ', 'background: linear-gradient(135deg, #16A34A, #166534); color: white; font-size: 16px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');
            console.log('%c Gérez vos informations personnelles.', 'color: #666; font-size: 14px;');

        });
    </script>

</body>
    <script src="../assets/js/responsive.js"></script>
</html>