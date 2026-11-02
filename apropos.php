<?php
// ============================================================
// PharmaGeo — À propos
// Page présentant la mission, la vision, l'historique et
// la technologie de la plateforme PharmaGeo.
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

$page_title = 'À propos - PharmaGeo';
?>
<!DOCTYPE html>
<html lang="fr">
<!-- ===== EN-TÊTE DE PAGE (META ET RESSOURCES) ===== -->
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <meta name="description" content="Découvrez PharmaGeo, la plateforme intelligente de géolocalisation des pharmacies à Porto-Novo.">
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
            --shadow-glow-green: 0 8px 40px rgba(22,163,74,0.30);
            --transition: all 0.5s cubic-bezier(0.23,1,0.32,1);
            --radius: 20px;
            --radius-lg: 28px;
            --radius-sm: 12px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--light-bg); color: var(--dark-text); line-height: 1.7;
        }

        /* NAVBAR */
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
            font-weight: 600; transition: var(--transition); border: none; box-shadow: var(--shadow-glow-green);
            text-decoration: none;
        }
        .btn-nav-connect:hover { transform: translateY(-3px); color: white !important; }

        /* HERO */
        .about-hero {
            background: linear-gradient(135deg, rgba(6,14,26,0.92), rgba(22,163,74,0.85));
            padding: 120px 0 80px; color: white; position: relative; overflow: hidden;
        }
        .about-hero::before {
            content: ''; position: absolute; inset: 0;
            background-image: radial-gradient(circle at 20% 50%, rgba(255,255,255,0.06) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255,255,255,0.04) 0%, transparent 50%);
            pointer-events: none;
        }
        .about-hero h1 { font-size: clamp(2rem, 4vw + 0.5rem, 3.2rem); font-weight: 900; letter-spacing: -1.5px; }

        /* MISSION CARD */
        .mission-card {
            background: var(--white); border-radius: var(--radius-lg); padding: 40px 36px;
            box-shadow: var(--shadow-md); border: 1px solid rgba(255,255,255,0.5);
            transition: var(--transition); height: 100%;
        }
        .mission-card:hover { transform: translateY(-6px); box-shadow: var(--shadow-lg); }
        .mission-icon {
            width: 70px; height: 70px; border-radius: var(--radius-sm); display: flex;
            align-items: center; justify-content: center; font-size: 2rem; margin-bottom: 18px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
        }
        .mission-icon.green { background: linear-gradient(135deg, rgba(22,163,74,0.12), rgba(22,163,74,0.04)); color: var(--primary-green); }
        .mission-icon.orange { background: linear-gradient(135deg, rgba(212,136,15,0.12), rgba(212,136,15,0.04)); color: var(--primary-orange); }
        .mission-icon.blue { background: linear-gradient(135deg, rgba(22,83,52,0.12), rgba(22,83,52,0.04)); color: #166534; }
        .mission-card h5 { font-weight: 700; margin-bottom: 10px; }
        .mission-card p { color: var(--gray-text); font-size: 0.95rem; margin: 0; }

        /* TEAM */
        .team-card {
            background: var(--white); border-radius: var(--radius-lg); padding: 32px 28px;
            text-align: center; box-shadow: var(--shadow-md); border: 1px solid rgba(255,255,255,0.5);
            transition: var(--transition); height: 100%;
        }
        .team-card:hover { transform: translateY(-8px); box-shadow: var(--shadow-lg); }
        .team-avatar {
            width: 80px; height: 80px; border-radius: 50%; margin: 0 auto 16px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            display: flex; align-items: center; justify-content: center;
            font-size: 2rem; color: white; font-weight: 800;
        }
        .team-card h6 { font-weight: 700; margin-bottom: 2px; }
        .team-card .team-role { color: var(--primary-green); font-size: 0.85rem; font-weight: 600; }
        .team-card p { color: var(--gray-text); font-size: 0.9rem; margin-top: 10px; }

        /* TIMELINE */
        .timeline-item {
            display: flex; gap: 24px; padding: 24px 0; border-bottom: 1px solid rgba(0,0,0,0.04);
        }
        .timeline-item:last-child { border-bottom: none; }
        .timeline-dot {
            width: 48px; height: 48px; border-radius: 50%; flex-shrink: 0;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 800; font-size: 1.1rem;
            box-shadow: 0 4px 20px rgba(22,163,74,0.25);
        }
        .timeline-content h6 { font-weight: 700; margin-bottom: 4px; }
        .timeline-content p { color: var(--gray-text); font-size: 0.9rem; margin: 0; }
        .timeline-content .timeline-year { color: var(--primary-green); font-weight: 600; font-size: 0.8rem; }

        /* STATS */
        .about-stat {
            text-align: center; padding: 28px 20px;
        }
        .about-stat .stat-number {
            font-size: 2.5rem; font-weight: 900;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
        }
        .about-stat .stat-label { color: var(--gray-text); font-weight: 500; font-size: 0.9rem; }

        /* CTA */
        .about-cta {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            border-radius: var(--radius-lg); padding: 48px 40px; color: white; text-align: center;
            box-shadow: var(--shadow-glow-green);
        }
        .about-cta h3 { font-weight: 800; margin-bottom: 12px; }
        .about-cta p { opacity: 0.88; max-width: 500px; margin: 0 auto 24px; }
        .about-cta .btn-white {
            background: white; color: var(--primary-green); padding: 14px 40px; border-radius: 50px;
            font-weight: 700; border: none; transition: var(--transition); display: inline-flex;
            align-items: center; gap: 10px; text-decoration: none; box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        .about-cta .btn-white:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(0,0,0,0.18); color: var(--primary-green); }

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

        /* ===== BOUTON RETOUR EN HAUT ===== */
        .back-to-top {
            position: fixed; bottom: 30px; right: 30px; width: 50px; height: 50px; border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white; border: none; display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; box-shadow: var(--shadow-glow-green); cursor: pointer; z-index: 998;
            opacity: 0; visibility: hidden; transform: translateY(30px); transition: var(--transition);
        }
        .back-to-top.show { opacity: 1; visibility: visible; transform: translateY(0); }
        .back-to-top:hover { transform: translateY(-6px); }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .about-hero { padding: 100px 0 50px; }
            .mission-card { padding: 28px 22px; }
            .about-cta { padding: 36px 24px; }
        }
    </style>
    <link rel="stylesheet" href="assets/css/responsive.css">    <!-- Palette verte PharmaGeo -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/palette.css">
</head>
<body>

    <!-- NAVIGATION -->
    <nav class="navbar navbar-expand-lg navbar-custom sticky-top">
        <div class="container">
            <a class="navbar-brand-custom" href="<?php echo BASE_URL; ?>">
                <span class="brand-icon"><i class="fas fa-hospital"></i></span>
                <span class="brand-text">PharmaGeo</span>
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
    <section class="about-hero">
        <div class="container position-relative" style="z-index:2;">
            <div class="row align-items-center">
                <div class="col-lg-8" data-aos="fade-up">
                    <span class="badge mb-3" style="background: rgba(255,255,255,0.15); padding: 8px 20px; border-radius: 50px; font-size: 0.85rem;">
                        <i class="fas fa-info-circle me-1"></i> Notre histoire
                    </span>
                    <h1>À propos de <span style="color: rgba(255,255,255,0.7);">PharmaGeo</span></h1>
                    <p class="mt-3" style="opacity: 0.88; max-width: 600px; font-size: 1.1rem;">Nous connectons les patients aux pharmacies de Porto-Novo pour un accès plus rapide et plus simple aux médicaments.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- MISSION -->
    <section class="py-5">
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <h2 class="fw-bold" style="letter-spacing: -1px;">Notre <span style="background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">mission</span></h2>
                <p style="color: var(--gray-text); max-width: 600px; margin: 10px auto 0;">Améliorer l'accès aux soins de santé à Porto-Novo grâce à la technologie</p>
            </div>
            <div class="row g-4">
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="0">
                    <div class="mission-card">
                        <div class="mission-icon green"><i class="fas fa-bullseye"></i></div>
                        <h5>Notre objectif</h5>
                        <p>Permettre à chaque patient de trouver rapidement le médicament dont il a besoin, en temps réel, dans la pharmacie la plus proche de chez lui.</p>
                    </div>
                </div>
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                    <div class="mission-card">
                        <div class="mission-icon orange"><i class="fas fa-eye"></i></div>
                        <h5>Notre vision</h5>
                        <p>Devenir la référence de la santé connectée au Bénin, en rendant l'accès aux médicaments simple, rapide et transparent pour tous.</p>
                    </div>
                </div>
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                    <div class="mission-card">
                        <div class="mission-icon blue"><i class="fas fa-heart"></i></div>
                        <h5>Nos valeurs</h5>
                        <p>Transparence, accessibilité et innovation au service de la santé publique. Chaque décision est guidée par le bien-être des patients.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- POURQUOI -->
    <section class="py-5" style="background: var(--white);">
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <h2 class="fw-bold" style="letter-spacing: -1px;">Pourquoi <span style="background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">PharmaGeo</span> ?</h2>
                <p style="color: var(--gray-text); max-width: 600px; margin: 10px auto 0;">Le problème que nous résolvons</p>
            </div>
            <div class="row g-4 align-items-center">
                <div class="col-lg-6" data-aos="fade-right">
                    <div class="p-4 rounded-4" style="background: linear-gradient(135deg, rgba(22,163,74,0.06), rgba(212,136,15,0.04));">
                        <h4 class="fw-bold mb-3">Le défi au quotidien</h4>
                        <p style="color: var(--gray-text);">À Porto-Novo, comme dans de nombreuses villes d'Afrique, trouver un médicament spécifique peut être un véritable parcours du combattant :</p>
                        <ul style="color: var(--gray-text); padding-left: 20px;">
                            <li class="mb-2">Parcours de pharmacie en pharmacie sans savoir si le médicament est disponible</li>
                            <li class="mb-2">Perte de temps considérable pour les patients et les familles</li>
                            <li class="mb-2">Ruptures de stock non signalées</li>
                            <li class="mb-2">Absence de visibilité pour les pharmacies locales</li>
                        </ul>
                    </div>
                </div>
                <div class="col-lg-6" data-aos="fade-left">
                    <div class="p-4 rounded-4" style="background: linear-gradient(135deg, rgba(22,163,74,0.08), rgba(22,163,74,0.02));">
                        <h4 class="fw-bold mb-3" style="color: var(--primary-green);">Notre solution</h4>
                        <p style="color: var(--gray-text);">PharmaGeo résout ces problèmes avec une plateforme simple et efficace :</p>
                        <ul style="color: var(--gray-text); padding-left: 20px;">
                            <li class="mb-2"><strong style="color: var(--dark-text);">Recherche instantanée</strong> — Trouvez votre médicament en quelques secondes</li>
                            <li class="mb-2"><strong style="color: var(--dark-text);">Géolocalisation</strong> — Voyez les pharmacies proches sur une carte interactive</li>
                            <li class="mb-2"><strong style="color: var(--dark-text);">Temps réel</strong> — Consultez la disponibilité avant de vous déplacer</li>
                            <li class="mb-2"><strong style="color: var(--dark-text);">Réservation en ligne</strong> — Réservez et récupérez sans faire la queue</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- HISTORIQUE -->
    <section class="py-5">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-6" data-aos="fade-right">
                    <span class="badge mb-3" style="background: rgba(22,163,74,0.10); color: var(--primary-green); padding: 8px 20px; border-radius: 50px; font-size: 0.85rem;">
                        <i class="fas fa-road me-1"></i> Notre parcours
                    </span>
                    <h2 class="fw-bold mb-4" style="letter-spacing: -1px;">L'histoire de <span style="background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">PharmaGeo</span></h2>
                    <div class="timeline-item">
                        <div class="timeline-dot">1</div>
                        <div class="timeline-content">
                            <span class="timeline-year">L'idée</span>
                            <h6>Le constat</h6>
                            <p>Face à la difficulté de trouver des médicaments à Porto-Novo, l'idée de PharmaGeo est née : pourquoi ne pas connecter directement les patients aux pharmacies ?</p>
                        </div>
                    </div>
                    <div class="timeline-item">
                        <div class="timeline-dot">2</div>
                        <div class="timeline-content">
                            <span class="timeline-year">Le développement</span>
                            <h6>La création</h6>
                            <p>Une équipe passionnée de technologie et de santé a travaillé pour concevoir une plateforme simple, rapide et accessible à tous.</p>
                        </div>
                    </div>
                    <div class="timeline-item">
                        <div class="timeline-dot">3</div>
                        <div class="timeline-content">
                            <span class="timeline-year">Aujourd'hui</span>
                            <h6>Le lancement</h6>
                            <p>PharmaGeo est en ligne et connecte déjà des pharmacies et des patients à Porto-Novo. C'est seulement le début !</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6" data-aos="fade-left">
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="about-stat p-4 rounded-4" style="background: var(--white); box-shadow: var(--shadow-md);">
                                <div class="stat-number">50+</div>
                                <div class="stat-label">Pharmacies ciblées</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="about-stat p-4 rounded-4" style="background: var(--white); box-shadow: var(--shadow-md);">
                                <div class="stat-number">1000+</div>
                                <div class="stat-label">Médicaments référencés</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="about-stat p-4 rounded-4" style="background: var(--white); box-shadow: var(--shadow-md);">
                                <div class="stat-number">24/7</div>
                                <div class="stat-label">Service disponible</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="about-stat p-4 rounded-4" style="background: var(--white); box-shadow: var(--shadow-md);">
                                <div class="stat-number">100%</div>
                                <div class="stat-label">Gratuit pour les patients</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- TECHNOLOGIE -->
    <section class="py-5" style="background: var(--white);">
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <h2 class="fw-bold" style="letter-spacing: -1px;">La <span style="background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">technologie</span> derrière PharmaGeo</h2>
            </div>
            <div class="row g-4">
                <div class="col-md-3 col-6" data-aos="fade-up" data-aos-delay="0">
                    <div class="text-center p-4">
                        <div style="width:64px;height:64px;border-radius:16px;background:linear-gradient(135deg,rgba(22,163,74,0.10),rgba(22,163,74,0.04));display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:1.6rem;color:var(--primary-green);">
                            <i class="fas fa-map-marked-alt"></i>
                        </div>
                        <h6 class="fw-bold">Géolocalisation</h6>
                        <p style="color:var(--gray-text);font-size:0.85rem;">Leaflet.js & OpenStreetMap</p>
                    </div>
                </div>
                <div class="col-md-3 col-6" data-aos="fade-up" data-aos-delay="100">
                    <div class="text-center p-4">
                        <div style="width:64px;height:64px;border-radius:16px;background:linear-gradient(135deg,rgba(212,136,15,0.10),rgba(212,136,15,0.04));display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:1.6rem;color:var(--primary-orange);">
                            <i class="fas fa-database"></i>
                        </div>
                        <h6 class="fw-bold">Base de données</h6>
                        <p style="color:var(--gray-text);font-size:0.85rem;">MySQL & PHP PDO</p>
                    </div>
                </div>
                <div class="col-md-3 col-6" data-aos="fade-up" data-aos-delay="200">
                    <div class="text-center p-4">
                        <div style="width:64px;height:64px;border-radius:16px;background:linear-gradient(135deg,rgba(22,83,52,0.10),rgba(22,83,52,0.04));display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:1.6rem;color:#166534;">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h6 class="fw-bold">Sécurité</h6>
                        <p style="color:var(--gray-text);font-size:0.85rem;">Hachage des mots de passe</p>
                    </div>
                </div>
                <div class="col-md-3 col-6" data-aos="fade-up" data-aos-delay="300">
                    <div class="text-center p-4">
                        <div style="width:64px;height:64px;border-radius:16px;background:linear-gradient(135deg,rgba(155,89,182,0.10),rgba(155,89,182,0.04));display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:1.6rem;color:#1F2937;">
                            <i class="fas fa-mobile-alt"></i>
                        </div>
                        <h6 class="fw-bold">Responsive</h6>
                        <p style="color:var(--gray-text);font-size:0.85rem;">Adapté à tous les appareils</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="py-5">
        <div class="container" data-aos="fade-up">
            <div class="about-cta">
                <h3>Rejoignez PharmaGeo</h3>
                <p>Que vous soyez patient ou pharmacien, faites partie de la révolution de la santé connectée à Porto-Novo.</p>
                <div class="d-flex gap-3 justify-content-center flex-wrap">
                    <a href="<?php echo BASE_URL; ?>inscription.php" class="btn-white">
                        <i class="fas fa-user-plus"></i> Créer un compte
                    </a>
                    <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="btn-white" style="background: transparent; color: white; border: 2px solid rgba(255,255,255,0.3);">
                        <i class="fas fa-search"></i> Rechercher un médicament
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="footer-custom">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4">
                    <a href="<?php echo BASE_URL; ?>index.php" class="footer-brand" style="text-decoration: none;">
                        <span class="brand-icon"><i class="fas fa-hospital"></i></span>
                        PharmaGeo
                    </a>
                    <p class="mt-3" style="max-width: 320px; font-size: 0.95rem;">Plateforme intelligente de géolocalisation des pharmacies à Porto-Novo.</p>
                </div>
                <div class="col-lg-2 col-md-4">
                    <h6 class="footer-title">Navigation</h6>
                    <ul class="footer-links">
                        <li><a href="<?php echo BASE_URL; ?>"><i class="fas fa-chevron-right" style="font-size: 0.5rem;"></i> Accueil</a></li>
                        <li><a href="<?php echo BASE_URL; ?>pages/patient/recherche.php"><i class="fas fa-chevron-right" style="font-size: 0.5rem;"></i> Rechercher</a></li>
                        <li><a href="<?php echo BASE_URL; ?>pages/patient/carte.php"><i class="fas fa-chevron-right" style="font-size: 0.5rem;"></i> Carte</a></li>
                        <li><a href="<?php echo BASE_URL; ?>apropos.php"><i class="fas fa-chevron-right" style="font-size: 0.5rem;"></i> À propos</a></li>
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
