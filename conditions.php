<?php
// ============================================================
// PharmaGeo — Conditions d'utilisation
// Page légale présentant les conditions d'utilisation de la
// plateforme PharmaGeo (service, comptes, responsabilités).
// ============================================================
// ===== ACTIVATION DES ERREURS =====
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ===== DÉFINITION DES CONSTANTES =====
define('BASE_URL', 'http://localhost/pharmageo/');

// ===== GESTION DES SESSIONS =====
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ===== FONCTIONS UTILITAIRES =====
function estConnecte() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function lienDashboard() {
    switch ($_SESSION['role'] ?? '') {
        case 'administrateur': return 'admin/dashboard.php';
        case 'pharmacien': return 'pharmacien/dashboard.php';
        default: return 'pages/patient/dashboard.php';
    }
}

$page_title = 'Conditions d\'utilisation - PharmaGeo';
?>
<!DOCTYPE html>
<html lang="fr">
<!-- ===== EN-TÊTE DE PAGE (META ET RESSOURCES) ===== -->
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <meta name="description" content="Conditions d'utilisation de la plateforme PharmaGeo.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <style>
        /* ===== VARIABLES & BASE ===== */
        :root {
            --primary-green: #16A34A;
            --primary-green-dark: #166534;
            --primary-orange: #B91C1C;
            --primary-orange-dark: #B91C1C;
            --dark-text: #0F2E19;
            --gray-text: #64748b;
            --light-bg: #F0FDF4;
            --white: #ffffff;
            --shadow-md: 0 8px 30px rgba(0,0,0,0.08);
            --shadow-lg: 0 20px 60px rgba(0,0,0,0.12);
            --transition: all 0.5s cubic-bezier(0.23,1,0.32,1);
            --radius: 20px;
            --radius-lg: 28px;
            --radius-sm: 12px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--light-bg);
            color: var(--dark-text);
            line-height: 1.7;
        }

        /* ===== NAVIGATION ===== */
        .navbar-custom {
            background: rgba(255,255,255,0.92); backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px);
            box-shadow: 0 1px 20px rgba(0,0,0,0.04); padding: 14px 0;
            border-bottom: 1px solid rgba(255,255,255,0.3);
        }
        .navbar-brand-custom {
            font-weight: 800; font-size: 1.7rem; color: var(--dark-text);
            display: flex; align-items: center; gap: 10px; text-decoration: none;
        }
        .navbar-brand-custom .brand-icon {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            padding: 8px 12px; border-radius: var(--radius-sm); color: white; font-size: 1.2rem;
        }
        .navbar-brand-custom .brand-text {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
        }
        .nav-link-custom {
            font-weight: 500; color: var(--dark-text); padding: 8px 20px !important;
            border-radius: var(--radius-sm); transition: var(--transition); font-size: 0.95rem; text-decoration: none;
        }
        .nav-link-custom:hover { color: var(--primary-green); background: rgba(22,163,74,0.08); }
        .btn-nav-connect {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white !important; padding: 10px 30px !important; border-radius: 50px !important;
            font-weight: 600; transition: var(--transition); border: none; box-shadow: 0 8px 40px rgba(22,163,74,0.30);
            text-decoration: none;
        }
        .btn-nav-connect:hover { transform: translateY(-3px); color: white !important; }

        /* ===== BANNIÈRE D'EN-TÊTE DE PAGE ===== */
        .page-hero {
            background: linear-gradient(135deg, rgba(6,14,26,0.92), rgba(22,163,74,0.85));
            padding: 120px 0 70px; color: white; position: relative; overflow: hidden;
        }
        .page-hero::before {
            content: ''; position: absolute; inset: 0;
            background-image: radial-gradient(circle at 20% 50%, rgba(255,255,255,0.06) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255,255,255,0.04) 0%, transparent 50%);
            pointer-events: none;
        }
        .page-hero h1 { font-size: clamp(2rem, 4vw + 0.5rem, 3rem); font-weight: 900; letter-spacing: -1.5px; }

        /* ===== CONTENU LÉGAL ===== */
        .legal-content {
            background: var(--white); border-radius: var(--radius-lg); padding: 48px 44px;
            box-shadow: var(--shadow-md); max-width: 900px; margin: -40px auto 60px; position: relative; z-index: 2;
        }
        .legal-content h2 {
            font-size: 1.5rem; font-weight: 800; color: var(--dark-text); margin: 36px 0 14px;
            padding-bottom: 10px; border-bottom: 2px solid rgba(22,163,74,0.15);
        }
        .legal-content h2:first-child { margin-top: 0; }
        .legal-content h3 { font-size: 1.15rem; font-weight: 700; color: var(--dark-text); margin: 24px 0 8px; }
        .legal-content p { color: var(--gray-text); font-size: 0.95rem; margin-bottom: 12px; }
        .legal-content ul { padding-left: 20px; margin-bottom: 12px; }
        .legal-content li { color: var(--gray-text); font-size: 0.95rem; margin-bottom: 6px; }
        .legal-content strong { color: var(--dark-text); }

        /* ===== BADGE DE MISE À JOUR ===== */
        .update-badge {
            display: inline-flex; align-items: center; gap: 8px; padding: 6px 18px; border-radius: 50px;
            background: rgba(22,163,74,0.08); color: var(--primary-green); font-size: 0.8rem; font-weight: 600;
        }

        /* ===== PIED DE PAGE ===== */
        .footer-custom {
            background: #0F2E19; color: rgba(255,255,255,0.70); padding: 50px 0 24px; position: relative;
        }
        .footer-custom::before {
            content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
        }
        .footer-custom .footer-brand {
            font-size: 1.8rem; font-weight: 800; color: white; display: flex; align-items: center; gap: 10px;
        }
        .footer-custom .footer-brand .brand-icon {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            padding: 6px 10px; border-radius: var(--radius-sm); color: white;
        }
        .footer-custom .footer-links { list-style: none; padding: 0; }
        .footer-custom .footer-links li { margin-bottom: 10px; }
        .footer-custom .footer-links a {
            color: rgba(255,255,255,0.55); text-decoration: none; transition: var(--transition);
            display: inline-flex; align-items: center; gap: 8px; font-size: 0.95rem;
        }
        .footer-custom .footer-links a:hover { color: var(--primary-green); transform: translateX(4px); }
        .footer-custom .footer-title { color: white; font-weight: 700; font-size: 1.1rem; margin-bottom: 20px; }
        .footer-custom .footer-bottom {
            border-top: 1px solid rgba(255,255,255,0.04); padding-top: 24px; margin-top: 40px; font-size: 0.9rem;
        }

        /* ===== BOUTON RETOUR EN HAUT ===== */
        .back-to-top {
            position: fixed; bottom: 30px; right: 30px; width: 50px; height: 50px; border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white; border: none; display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; box-shadow: 0 8px 40px rgba(22,163,74,0.30); cursor: pointer; z-index: 998;
            opacity: 0; visibility: hidden; transform: translateY(30px); transition: var(--transition);
        }
        .back-to-top.show { opacity: 1; visibility: visible; transform: translateY(0); }
        .back-to-top:hover { transform: translateY(-6px); }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .page-hero { padding: 100px 0 50px; }
            .legal-content { padding: 28px 20px; margin: -30px 15px 40px; }
        }
    </style>
    <link rel="stylesheet" href="assets/css/responsive.css">    <!-- Palette verte PharmaGeo -->
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

    <!-- NAVIGATION -->
    <nav class="navbar navbar-expand-lg navbar-custom sticky-top">
        <div class="container">
            <a class="navbar-brand-custom" href="<?php echo BASE_URL; ?>">
                <img class="pg-brand-wordmark" src="<?php echo BASE_URL; ?>assets/identite/logos/svg/pharmageo-logo-horizontal-blanc.svg" alt="PharmaGeo">
            </a>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon" style="background-image: url(&quot;data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3E%3Cpath stroke='#16A34A' stroke-width='2.5' stroke-linecap='round' stroke-miterlimit='10' d='M4 7h22M4 15h22M4 23h22'/%3E%3C/svg%3E&quot;) !important;"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-lg-center gap-1">
                    <li><a class="nav-link nav-link-custom" href="<?php echo BASE_URL; ?>"><i class="fas fa-home me-1"></i>Accueil</a></li>
                    <li><a class="nav-link nav-link-custom" href="<?php echo BASE_URL; ?>pages/patient/recherche.php"><i class="fas fa-search me-1"></i>Rechercher</a></li>
                    <li><a class="nav-link nav-link-custom" href="<?php echo BASE_URL; ?>pages/patient/carte.php"><i class="fas fa-map-marker-alt me-1"></i>Carte</a></li>
                    <?php if (estConnecte()): ?>
                        <li><a class="nav-link nav-link-custom" href="<?php echo BASE_URL . lienDashboard(); ?>"><i class="fas fa-user me-1"></i>Dashboard</a></li>
                        <li><a class="nav-link btn-nav-connect" href="<?php echo BASE_URL; ?>deconnexion.php"><i class="fas fa-sign-out-alt me-1"></i>Déconnexion</a></li>
                    <?php else: ?>
                        <li><a class="nav-link btn-nav-connect" href="<?php echo BASE_URL; ?>connexion.php"><i class="fas fa-sign-in-alt me-1"></i>Connexion</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- HERO -->
    <section class="page-hero">
        <div class="container position-relative" style="z-index:2;">
            <div data-aos="fade-up">
                <span class="update-badge mb-3"><i class="fas fa-calendar-alt"></i> Dernière mise à jour : <?php echo date('d/m/Y'); ?></span>
                <h1>Conditions d'utilisation</h1>
                <p class="mt-2" style="opacity: 0.85; max-width: 600px;">Veuillez lire attentivement les conditions suivantes avant d'utiliser la plateforme PharmaGeo.</p>
            </div>
        </div>
    </section>

    <!-- CONTENT -->
    <div class="container">
        <div class="legal-content" data-aos="fade-up">

            <h2>1. Acceptation des conditions</h2>
            <p>En accédant et en utilisant la plateforme <strong>PharmaGeo</strong> (ci-après « la Plateforme »), vous acceptez sans réserve les présentes conditions d'utilisation. Si vous n'acceptez pas ces conditions, veuillez ne pas utiliser la Plateforme.</p>
            <p>PharmaGeo se réserve le droit de modifier ces conditions à tout moment. Les utilisateurs seront informés de toute modification substantielle.</p>

            <h2>2. Description du service</h2>
            <p>PharmaGeo est une plateforme de géolocalisation des pharmacies et de recherche de disponibilité des médicaments à Porto-Novo, Bénin. La Plateforme permet aux utilisateurs de :</p>
            <ul>
                <li>Rechercher des médicaments par nom, DCI ou catégorie</li>
                <li>Géolocaliser les pharmacies proches sur une carte interactive</li>
                <li>Consulter la disponibilité des stocks en temps réel</li>
                <li>Réserver des médicaments en ligne</li>
                <li>Gérer les pharmacies, stocks et réservations (pour les pharmaciens)</li>
            </ul>

            <h2>3. Inscription et compte utilisateur</h2>
            <h3>3.1 Conditions d'inscription</h3>
            <p>Pour utiliser certaines fonctionnalités de la Plateforme, vous devez créer un compte. L'inscription est ouverte à toute personne physique majeure (18 ans et plus) résidant au Bénin.</p>
            <h3>3.2 Informations requises</h3>
            <p>Lors de l'inscription, vous devez fournir des informations exactes et à jour, notamment :</p>
            <ul>
                <li>Nom et prénom</li>
                <li>Adresse email valide (utilisée pour la connexion et la vérification)</li>
                <li>Mot de passe sécurisé</li>
            </ul>
            <h3>3.3 Responsabilité du compte</h3>
            <p>Vous êtes responsable de la confidentialité de votre mot de passe et de toutes les activités effectuées sous votre compte. En cas d'utilisation non autorisée, vous devez en informer PharmaGeo immédiatement.</p>

            <h2>4. Conditions spécifiques aux pharmaciens</h2>
            <h3>4.1 Validation du compte</h3>
            <p>Les comptes pharmaciens sont soumis à une validation par l'administrateur de la Plateforme. Cette validation comprend la vérification du numéro d'ordre et des informations de la pharmacie.</p>
            <h3>4.2 Obligations du pharmacien</h3>
            <ul>
                <li>Maintenir les informations de la pharmacie à jour (adresse, horaires, coordonnées GPS)</li>
                <li>Mettre à jour régulièrement les stocks de médicaments</li>
                <li>Traiter les réservations dans un délai raisonnable</li>
                <li>Respecter la réglementation pharmaceutique en vigueur au Bénin</li>
            </ul>
            <h3>4.3 Suspension du compte</h3>
            <p>PharmaGeo se réserve le droit de suspendre ou de désactiver un compte pharmacien en cas de non-respect des obligations, de fourniture d'informations erronées ou de comportement frauduleux.</p>

            <h2>5. Utilisation de la Plateforme</h2>
            <h3>5.1 Utilisations interdites</h3>
            <p>Il est strictement interdit de :</p>
            <ul>
                <li>Utiliser la Plateforme à des fins illégales ou non autorisées</li>
                <li>Tenter d'accéder aux comptes d'autres utilisateurs</li>
                <li>Introduire des virus, malwares ou tout autre code nuisible</li>
                <li>Collecter des données d'autres utilisateurs sans leur consentement</li>
                <li>Reproduire, copier ou revendre tout ou partie de la Plateforme</li>
                <li>Utiliser des robots, scripts ou tout autre automate pour accéder à la Plateforme</li>
            </ul>
            <h3>5.2 Disponibilité du service</h3>
            <p>PharmaGeo s'efforce d'assurer une disponibilité maximale de la Plateforme. Cependant, PharmaGeo ne saurait être tenu responsable des interruptions temporaires pour maintenance, mises à jour ou causes de force majeure.</p>

            <h2>6. Propriété intellectuelle</h2>
            <p>L'ensemble du contenu de la Plateforme (code source, design, textes, images, logos, bases de données) est la propriété exclusive de PharmaGeo ou de ses partenaires et est protégé par les lois relatives à la propriété intellectuelle. Toute reproduction non autorisée est interdite.</p>

            <h2>7. Protection des données personnelles</h2>
            <p>PharmaGeo collecte et traite les données personnelles des utilisateurs conformément à la réglementation en vigueur. Les données collectées sont :</p>
            <ul>
                <li>Données d'inscription (nom, prénom, email, téléphone)</li>
                <li>Données de localisation (avec consentement de l'utilisateur)</li>
                <li>Données de navigation et d'utilisation de la Plateforme</li>
            </ul>
            <p>Pour plus d'informations, consultez notre politique de confidentialité.</p>

            <h2>8. Limitation de responsabilité</h2>
            <p>PharmaGeo agit en qualité d'intermédiaire technologique entre les patients et les pharmacies. La Plateforme :</p>
            <ul>
                <li>Ne vend pas de médicaments directement</li>
                <li>Ne fournit pas de conseils médicaux</li>
                <li>Ne garantit pas la disponibilité permanente des médicaments affichés</li>
                <li>Ne saurait être tenue responsable des erreurs dans les informations fournies par les pharmaciens</li>
            </ul>
            <p>Les transactions de réservation sont conclues directement entre le patient et la pharmacie. PharmaGeo n'est partie à ces transactions.</p>

            <h2>9. Modification et résiliation</h2>
            <p>PharmaGeo se réserve le droit de modifier, suspendre ou interrompre tout ou partie de la Plateforme à tout moment, sans préavis. Les utilisateurs seront informés des changements majeurs via email ou notification sur la Plateforme.</p>

            <h2>10. Droit applicable et juridiction</h2>
            <p>Les présentes conditions sont régies par les lois de la République du Bénin. Tout litige relatif à l'interprétation ou à l'exécution des présentes conditions sera soumis aux tribunaux compétents de Porto-Novo.</p>

            <h2>11. Contact</h2>
            <p>Pour toute question relative aux présentes conditions d'utilisation, vous pouvez nous contacter :</p>
            <ul>
                <li><strong>Email :</strong> contact@pharmageo.bj</li>
                <li><strong>Adresse :</strong> Porto-Novo, Bénin</li>
            </ul>

        </div>
    </div>

    <!-- FOOTER -->
    <footer class="footer-custom">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4">
                    <a href="<?php echo BASE_URL; ?>index.php" class="footer-brand" style="text-decoration: none;"><img class="pg-footer-logo" src="<?php echo BASE_URL; ?>assets/identite/logos/svg/pharmageo-logo-horizontal-blanc.svg" alt="PharmaGeo"></a>
                    <p class="mt-3" style="max-width: 320px; font-size: 0.95rem;">Plateforme intelligente de géolocalisation des pharmacies à Porto-Novo.</p>
                </div>
                <div class="col-lg-2 col-md-4">
                    <h6 class="footer-title">Navigation</h6>
                    <ul class="footer-links">
                        <li><a href="<?php echo BASE_URL; ?>"><i class="fas fa-chevron-right" style="font-size: 0.5rem;"></i> Accueil</a></li>
                        <li><a href="<?php echo BASE_URL; ?>pages/patient/recherche.php"><i class="fas fa-chevron-right" style="font-size: 0.5rem;"></i> Rechercher</a></li>
                        <li><a href="<?php echo BASE_URL; ?>pages/patient/carte.php"><i class="fas fa-chevron-right" style="font-size: 0.5rem;"></i> Carte</a></li>
                    </ul>
                </div>
                <div class="col-lg-2 col-md-4">
                    <h6 class="footer-title">Professionnels</h6>
                    <ul class="footer-links">
                        <li><a href="<?php echo BASE_URL; ?>inscription.php?role=pharmacien"><i class="fas fa-chevron-right" style="font-size: 0.5rem;"></i> Devenir partenaire</a></li>
                        <li><a href="<?php echo BASE_URL; ?>guide-pharmacien.php"><i class="fas fa-chevron-right" style="font-size: 0.5rem;"></i> Guide pharmacien</a></li>
                        <li><a href="<?php echo BASE_URL; ?>conditions.php"><i class="fas fa-chevron-right" style="font-size: 0.5rem;"></i> Conditions</a></li>
                    </ul>
                </div>
                <div class="col-lg-2 col-md-4">
                    <h6 class="footer-title">Contact</h6>
                    <ul class="footer-links">
                        <li><a href="mailto:contact@pharmageo.bj"><i class="fas fa-envelope me-2"></i> contact@pharmageo.bj</a></li>
                        <li><a href="tel:+229XXXXXXXX"><i class="fas fa-phone me-2"></i> +229 XX XX XX XX</a></li>
                        <li><a href="#"><i class="fas fa-map-marker-alt me-2"></i> Porto-Novo, Bénin</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom text-center">
                <p class="mb-0">&copy; <?php echo date('Y'); ?> PharmaGeo. Tous droits réservés.</p>
            </div>
        </div>
    </footer>

    <button class="back-to-top" id="backToTop"><i class="fas fa-arrow-up"></i></button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        // Initialisation des animations AOS
        AOS.init({ duration: 800, once: true, offset: 40 });

        // Affichage du bouton retour en haut après défilement
        const backToTop = document.getElementById('backToTop');
        window.addEventListener('scroll', () => {
            backToTop.classList.toggle('show', window.pageYOffset > 500);
        }, { passive: true });
        backToTop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
    </script>
</body>
</html>
