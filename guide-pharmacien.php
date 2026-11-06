<?php
// ============================================================
// PharmaGeo — Guide Pharmacien
// Guide destiné aux pharmaciens : inscription de la pharmacie,
// gestion des stocks et des réservations de patients.
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

$page_title = 'Guide Pharmacien - PharmaGeo';
?>
<!DOCTYPE html>
<html lang="fr">
<!-- ===== EN-TÊTE DE PAGE (META ET RESSOURCES) ===== -->
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <meta name="description" content="Guide complet pour les pharmaciens : comment inscrire votre pharmacie, gérer vos stocks et recevoir des réservations sur PharmaGeo.">
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

        /* NAVBAR */
        .navbar-custom {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            box-shadow: 0 1px 20px rgba(0,0,0,0.04);
            padding: 14px 0;
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
            border-radius: var(--radius-sm); transition: var(--transition); font-size: 0.95rem;
            text-decoration: none;
        }
        .nav-link-custom:hover { color: var(--primary-green); background: rgba(22,163,74,0.08); }
        .btn-nav-connect {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white !important; padding: 10px 30px !important; border-radius: 50px !important;
            font-weight: 600; transition: var(--transition); border: none; box-shadow: 0 8px 40px rgba(22,163,74,0.30);
            text-decoration: none;
        }
        .btn-nav-connect:hover { transform: translateY(-3px); color: white !important; }

        /* HERO */
        .guide-hero {
            background: linear-gradient(135deg, rgba(6,14,26,0.92), rgba(22,163,74,0.85));
            padding: 120px 0 80px;
            color: white;
            position: relative;
            overflow: hidden;
        }
        .guide-hero::before {
            content: ''; position: absolute; inset: 0;
            background-image: radial-gradient(circle at 20% 50%, rgba(255,255,255,0.06) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255,255,255,0.04) 0%, transparent 50%);
            pointer-events: none;
        }
        .guide-hero h1 { font-size: clamp(2rem, 4vw + 0.5rem, 3.2rem); font-weight: 900; letter-spacing: -1.5px; }
        .guide-hero p { font-size: 1.15rem; opacity: 0.88; max-width: 600px; }

        /* STEP CARDS */
        .step-card {
            background: var(--white); border-radius: var(--radius-lg); padding: 36px 32px;
            box-shadow: var(--shadow-md); border: 1px solid rgba(255,255,255,0.5);
            transition: var(--transition); position: relative; overflow: hidden; height: 100%;
        }
        .step-card:hover { transform: translateY(-8px); box-shadow: var(--shadow-lg); }
        .step-card::after {
            content: ''; position: absolute; bottom: 0; left: 0; width: 100%; height: 4px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            transform: scaleX(0); transition: var(--transition); transform-origin: left;
        }
        .step-card:hover::after { transform: scaleX(1); }
        .step-number {
            width: 56px; height: 56px; border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white; display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem; font-weight: 800; margin-bottom: 18px;
            box-shadow: 0 8px 30px rgba(22,163,74,0.25);
        }
        .step-card h5 { font-weight: 700; margin-bottom: 10px; }
        .step-card p { color: var(--gray-text); font-size: 0.95rem; }

        /* FEATURE LIST */
        .feature-list { list-style: none; padding: 0; }
        .feature-list li {
            display: flex; align-items: flex-start; gap: 14px; padding: 14px 0;
            border-bottom: 1px solid rgba(0,0,0,0.04);
        }
        .feature-list li:last-child { border-bottom: none; }
        .feature-list .feat-icon {
            width: 42px; height: 42px; border-radius: var(--radius-sm); flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
        }
        .feature-list .feat-icon.green { background: rgba(22,163,74,0.10); color: var(--primary-green); }
        .feature-list .feat-icon.orange { background: rgba(212,136,15,0.10); color: var(--primary-orange); }
        .feature-list .feat-icon.blue { background: rgba(22,83,52,0.10); color: #166534; }
        .feature-list .feat-icon.purple { background: rgba(155,89,182,0.10); color: #1F2937; }
        .feature-list h6 { font-weight: 700; margin-bottom: 2px; }
        .feature-list p { margin: 0; font-size: 0.9rem; color: var(--gray-text); }

        /* TIPS */
        .tip-card {
            background: linear-gradient(135deg, rgba(22,163,74,0.06), rgba(212,136,15,0.04));
            border-radius: var(--radius); padding: 28px 24px; border-left: 5px solid var(--primary-green);
        }
        .tip-card h6 { font-weight: 700; color: var(--primary-green); margin-bottom: 8px; }
        .tip-card p { color: var(--gray-text); font-size: 0.9rem; margin: 0; }

        /* FOOTER */
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

        /* BACK TO TOP */
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
            .guide-hero { padding: 100px 0 50px; }
            .step-card { padding: 24px 20px; }
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
    <section class="guide-hero">
        <div class="container position-relative" style="z-index:2;">
            <div class="row align-items-center">
                <div class="col-lg-8" data-aos="fade-up">
                    <span class="badge mb-3" style="background: rgba(255,255,255,0.15); padding: 8px 20px; border-radius: 50px; font-size: 0.85rem;">
                        <i class="fas fa-book-medical me-1"></i> Guide officiel
                    </span>
                    <h1>Guide <span style="color: rgba(255,255,255,0.7);">Pharmacien</span></h1>
                    <p class="mt-3">Tout ce que vous devez savoir pour inscrire votre pharmacie, gérer vos stocks et recevoir des réservations de patients via PharmaGeo.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- CONTENT -->
    <section class="py-5">
        <div class="container">

            <!-- INTRO -->
            <div class="row mb-5" data-aos="fade-up">
                <div class="col-lg-8 mx-auto text-center">
                    <h2 class="fw-bold mb-3" style="letter-spacing: -1px;">Comment ça <span style="background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">fonctionne</span> ?</h2>
                    <p style="color: var(--gray-text); font-size: 1.05rem;">PharmaGeo connecte les patients aux pharmacies de Porto-Novo. En tant que pharmacien, vous gérez votre officine et recevez des réservations en temps réel.</p>
                </div>
            </div>

            <!-- STEPS -->
            <div class="row g-4 mb-5">
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="0">
                    <div class="step-card">
                        <div class="step-number">1</div>
                        <h5>Inscription</h5>
                        <p>Créez votre compte pharmacien en renseignant vos informations personnelles et les détails de votre pharmacie (nom, adresse, numéro d'ordre, coordonnées GPS).</p>
                    </div>
                </div>
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                    <div class="step-card">
                        <div class="step-number">2</div>
                        <h5>Validation</h5>
                        <p>L'administrateur vérifie votre numéro d'ordre et valide votre compte. Vous recevez une notification une fois approuvé.</p>
                    </div>
                </div>
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                    <div class="step-card">
                        <div class="step-number">3</div>
                        <h5>Gestion & Réservations</h5>
                        <p>Ajoutez vos médicaments, gérez vos stocks et recevez les réservations des patients directement depuis votre tableau de bord.</p>
                    </div>
                </div>
            </div>

            <!-- FONCTIONNALITÉS -->
            <div class="row g-4 mb-5">
                <div class="col-lg-6" data-aos="fade-up">
                    <div class="step-card">
                        <h5 class="mb-3"><i class="fas fa-pills me-2" style="color: var(--primary-green);"></i> Gestion des médicaments</h5>
                        <ul class="feature-list">
                            <li>
                                <div class="feat-icon green"><i class="fas fa-plus-circle"></i></div>
                                <div><h6>Ajouter des médicaments</h6><p>Enregistrez chaque médicament avec son nom commercial, DCI, forme, dosage et catégorie.</p></div>
                            </li>
                            <li>
                                <div class="feat-icon orange"><i class="fas fa-boxes"></i></div>
                                <div><h6>Gérer les stocks</h6><p>Mettez à jour les quantités, les prix et les seuils d'alerte pour chaque médicament.</p></div>
                            </li>
                            <li>
                                <div class="feat-icon blue"><i class="fas fa-bell"></i></div>
                                <div><h6>Alertes de stock</h6><p>Recevez des notifications automatiques quand un médicament est en stock faible ou en rupture.</p></div>
                            </li>
                            <li>
                                <div class="feat-icon purple"><i class="fas fa-history"></i></div>
                                <div><h6>Historique complet</h6><p>Consultez l'historique de toutes les opérations de stock avec traçabilité complète.</p></div>
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="col-lg-6" data-aos="fade-up" data-aos-delay="100">
                    <div class="step-card">
                        <h5 class="mb-3"><i class="fas fa-calendar-alt me-2" style="color: var(--primary-orange);"></i> Gestion des réservations</h5>
                        <ul class="feature-list">
                            <li>
                                <div class="feat-icon green"><i class="fas fa-inbox"></i></div>
                                <div><h6>Recevoir des réservations</h6><p>Les patients réservent vos médicaments en ligne. Vous voyez toutes les demandes en temps réel.</p></div>
                            </li>
                            <li>
                                <div class="feat-icon orange"><i class="fas fa-check-circle"></i></div>
                                <div><h6>Confirmer ou refuser</h6><p>Acceptez ou refusez chaque réservation selon la disponibilité de votre stock.</p></div>
                            </li>
                            <li>
                                <div class="feat-icon blue"><i class="fas fa-users"></i></div>
                                <div><h6>Suivi des clients</h6><p>Consultez l'historique des réservations par patient pour un meilleur suivi.</p></div>
                            </li>
                            <li>
                                <div class="feat-icon purple"><i class="fas fa-chart-bar"></i></div>
                                <div><h6>Statistiques</h6><p>Analysez vos performances : médicaments les plus demandés, taux de confirmation, etc.</p></div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- AVANTAGES -->
            <div class="row mb-5" data-aos="fade-up">
                <div class="col-lg-8 mx-auto">
                    <div class="step-card">
                        <h5 class="mb-3"><i class="fas fa-trophy me-2" style="color: var(--primary-green);"></i> Avantages pour votre pharmacie</h5>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="d-flex align-items-start gap-3 p-3 rounded-3" style="background: rgba(22,163,74,0.04);">
                                    <i class="fas fa-eye" style="color: var(--primary-green); font-size: 1.2rem; margin-top: 2px;"></i>
                                    <div>
                                        <h6 class="fw-bold mb-1">Visibilité accrue</h6>
                                        <p style="font-size: 0.85rem; color: var(--gray-text); margin: 0;">Apparaissez dans les résultats de recherche des patients de Porto-Novo.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex align-items-start gap-3 p-3 rounded-3" style="background: rgba(212,136,15,0.04);">
                                    <i class="fas fa-map-marked-alt" style="color: var(--primary-orange); font-size: 1.2rem; margin-top: 2px;"></i>
                                    <div>
                                        <h6 class="fw-bold mb-1">Géolocalisation</h6>
                                        <p style="font-size: 0.85rem; color: var(--gray-text); margin: 0;">Les patients vous trouvent facilement grâce à la carte interactive.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex align-items-start gap-3 p-3 rounded-3" style="background: rgba(22,83,52,0.04);">
                                    <i class="fas fa-clock" style="color: #166534; font-size: 1.2rem; margin-top: 2px;"></i>
                                    <div>
                                        <h6 class="fw-bold mb-1">Gain de temps</h6>
                                        <p style="font-size: 0.85rem; color: var(--gray-text); margin: 0;">Gérez vos stocks et réservations depuis un seul tableau de bord.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex align-items-start gap-3 p-3 rounded-3" style="background: rgba(155,89,182,0.04);">
                                    <i class="fas fa-handshake" style="color: #1F2937; font-size: 1.2rem; margin-top: 2px;"></i>
                                    <div>
                                        <h6 class="fw-bold mb-1">Fidélisation</h6>
                                        <p style="font-size: 0.85rem; color: var(--gray-text); margin: 0;">Créez une relation de confiance avec vos patients via le service en ligne.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CONSEILS -->
            <div class="row g-4 mb-5">
                <div class="col-lg-4" data-aos="fade-up">
                    <div class="tip-card">
                        <h6><i class="fas fa-lightbulb me-1"></i> Conseil #1</h6>
                        <p>Mettez à jour vos stocks quotidiennement pour que les patients voient toujours les informations à jour.</p>
                    </div>
                </div>
                <div class="col-lg-4" data-aos="fade-up" data-aos-delay="100">
                    <div class="tip-card">
                        <h6><i class="fas fa-lightbulb me-1"></i> Conseil #2</h6>
                        <p>Répondez rapidement aux réservations pour maintenir un bon score de satisfaction.</p>
                    </div>
                </div>
                <div class="col-lg-4" data-aos="fade-up" data-aos-delay="200">
                    <div class="tip-card">
                        <h6><i class="fas fa-lightbulb me-1"></i> Conseil #3</h6>
                        <p>Utilisez les seuils d'alerte pour anticiper les ruptures de stock et commander à temps.</p>
                    </div>
                </div>
            </div>

            <!-- CTA -->
            <div class="text-center py-5" data-aos="fade-up">
                <h3 class="fw-bold mb-3">Prêt à rejoindre PharmaGeo ?</h3>
                <p style="color: var(--gray-text); max-width: 500px; margin: 0 auto 24px;">Inscrivez votre pharmacie maintenant et commencez à recevoir des réservations de patients.</p>
                <a href="<?php echo BASE_URL; ?>inscription.php" class="btn btn-lg px-5 py-3 rounded-50 fw-bold text-white" style="background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); box-shadow: 0 8px 40px rgba(22,163,74,0.30);">
                    <i class="fas fa-user-plus me-2"></i> Devenir partenaire
                </a>
            </div>

        </div>
    </section>

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

    <!-- BACK TO TOP -->
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
