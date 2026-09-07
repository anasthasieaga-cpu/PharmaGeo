<?php
// ============================================================
// PharmaGeo — En-tête du site
// Bloc commun affiché en haut de chaque page : navigation
// principale, méta-données et récupération des notifications.
// ============================================================

// ===== VARIABLES PAR DÉFAUT =====
$page_title = $page_title ?? 'PharmaGeo - Recherche de médicaments';
$page_description = $page_description ?? 'Plateforme de géolocalisation des pharmacies et recherche de disponibilité des médicaments à Porto-Novo';
$page_keywords = $page_keywords ?? 'pharmacie, médicaments, Porto-Novo, géolocalisation, santé, Bénin';
$page_author = 'PharmaGeo';

// Récupérer les notifications non lues si l'utilisateur est connecté
$notifications_non_lues = 0;
if (estConnecte() && isset($pdo)) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM notification WHERE Id_utilisateur = :user AND Lu = 0");
        $stmt->execute([':user' => getIdUtilisateur()]);
        $result = $stmt->fetch();
        $notifications_non_lues = $result['total'] ?? 0;
    } catch(PDOException $e) {
        // Ignorer les erreurs de notification
    }
}

// Récupérer le nom complet de l'utilisateur
$user_fullname = '';
if (estConnecte()) {
    $user_fullname = ($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '');
    $user_fullname = trim($user_fullname) ?: 'Utilisateur';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($page_description); ?>">
    <meta name="keywords" content="<?php echo htmlspecialchars($page_keywords); ?>">
    <meta name="author" content="<?php echo htmlspecialchars($page_author); ?>">
    <meta name="robots" content="index, follow">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo htmlspecialchars($page_title); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($page_description); ?>">
    <meta property="og:url" content="<?php echo BASE_URL; ?>">
    
    <!-- Favicon -->
    <link rel="icon" href="<?php echo BASE_URL; ?>assets/images/favicon.ico" type="image/x-icon">
    <link rel="shortcut icon" href="<?php echo BASE_URL; ?>assets/images/favicon.ico" type="image/x-icon">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Leaflet CSS pour la cartographie -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.css" />
    
    <!-- Styles personnalisés -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
    
    <!-- Responsivité globale -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/responsive.css">
    
    <?php if (isset($extra_css)): ?>
        <?php echo $extra_css; ?>
    <?php endif; ?>
</head>
<body>
    <!-- ===== NAVIGATION PRINCIPALE ===== -->
    <nav class="navbar navbar-expand-lg navbar-dark sticky-top" style="background: linear-gradient(135deg, rgb(42, 195, 42), hsl(25, 75%, 47%)); box-shadow: 0 4px 20px rgba(0,0,0,0.2);">
        <div class="container">
            <!-- Logo / Marque -->
            <a class="navbar-brand fw-bold" href="<?php echo BASE_URL; ?>">
                <i class="fas fa-hospital me-2"></i>
                <span>PharmaGeo</span>
            </a>
            
            <!-- Bouton Toggle pour mobile -->
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" 
                    aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <!-- Navigation -->
            <div class="collapse navbar-collapse" id="navbarNav">
                <!-- Menu principal -->
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" 
                           href="<?php echo BASE_URL; ?>">
                            <i class="fas fa-home"></i> Accueil
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'recherche.php') !== false ? 'active' : ''; ?>" 
                           href="<?php echo BASE_URL; ?>pages/patient/recherche.php">
                            <i class="fas fa-search"></i> Rechercher
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'carte.php') !== false ? 'active' : ''; ?>" 
                           href="<?php echo BASE_URL; ?>pages/patient/carte.php">
                            <i class="fas fa-map-marker-alt"></i> Carte
                        </a>
                    </li>
                    <?php if (estConnecte() && aRole('pharmacien')): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'pharmacien') !== false ? 'active' : ''; ?>" 
                               href="<?php echo BASE_URL; ?>pharmacien/dashboard.php">
                                <i class="fas fa-store"></i> Mon officine
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (estConnecte() && aRole('administrateur')): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'admin') !== false ? 'active' : ''; ?>" 
                               href="<?php echo BASE_URL; ?>admin/dashboard.php">
                                <i class="fas fa-crown"></i> Administration
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
                
                <!-- Menu utilisateur -->
                <ul class="navbar-nav">
                    <?php if (estConnecte()): ?>
                        <!-- Notifications -->
                        <li class="nav-item dropdown">
                            <a class="nav-link position-relative" href="#" id="notificationsDropdown" 
                               role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-bell"></i>
                                <?php if ($notifications_non_lues > 0): ?>
                                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" 
                                          style="font-size: 0.6rem;">
                                        <?php echo $notifications_non_lues; ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationsDropdown" style="min-width: 300px;">
                                <li><h6 class="dropdown-header"><i class="fas fa-bell me-2"></i>Notifications</h6></li>
                                <li><hr class="dropdown-divider"></li>
                                <?php if ($notifications_non_lues > 0): ?>
                                    <li><a class="dropdown-item text-primary" href="#">
                                        <i class="fas fa-circle text-primary" style="font-size: 0.5rem;"></i> 
                                        <?php echo $notifications_non_lues; ?> notification(s) non lue(s)
                                    </a></li>
                                <?php else: ?>
                                    <li><a class="dropdown-item text-muted" href="#">
                                        <i class="fas fa-check-circle text-success"></i> Aucune notification
                                    </a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-center text-muted small" href="#">
                                    Voir toutes les notifications
                                </a></li>
                            </ul>
                        </li>
                        
                        <!-- Profil utilisateur -->
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" 
                               id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-user-circle me-1" style="font-size: 1.3rem;"></i>
                                <span class="d-none d-md-inline"><?php echo htmlspecialchars($user_fullname); ?></span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>pages/patient/dashboard.php">
                                    <i class="fas fa-tachometer-alt me-2"></i>Tableau de bord
                                </a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>pages/patient/profil.php">
                                    <i class="fas fa-user-edit me-2"></i>Mon profil
                                </a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>pages/patient/historique.php">
                                    <i class="fas fa-history me-2"></i>Historique
                                </a></li>
                                <?php if (aRole('pharmacien')): ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>pharmacien/dashboard.php">
                                        <i class="fas fa-store me-2"></i>Gérer ma pharmacie
                                    </a></li>
                                <?php endif; ?>
                                <?php if (aRole('administrateur')): ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/dashboard.php">
                                        <i class="fas fa-crown me-2"></i>Administration
                                    </a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="<?php echo BASE_URL; ?>deconnexion.php">
                                    <i class="fas fa-sign-out-alt me-2"></i>Déconnexion
                                </a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <!-- Utilisateur non connecté -->
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo BASE_URL; ?>connexion.php">
                                <i class="fas fa-sign-in-alt me-1"></i>Connexion
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link btn btn-light text-dark px-3 rounded-pill mx-1" 
                               href="<?php echo BASE_URL; ?>inscription.php" 
                               style="background: rgba(255,255,255,0.9);">
                                <i class="fas fa-user-plus me-1"></i>S'inscrire
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    
    <!-- ===== CONTENU PRINCIPAL ===== -->
    <main class="py-4">
        <div class="container">
            <?php
            // Afficher les messages flash s'il y en a
            if (isset($_SESSION['flash_message'])) {
                echo showAlert($_SESSION['flash_message']['message'], $_SESSION['flash_message']['type']);
                unset($_SESSION['flash_message']);
            }
            ?>