<?php
// ============================================================
// PharmaGeo — Page d'accueil
// Page d'atterrissage proposant la recherche de médicaments et
// la présentation de la plateforme de géolocalisation.
// ============================================================
// ===== ACTIVATION DES ERREURS POUR LE DÉVELOPPEMENT =====
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ===== DÉFINITION DES CONSTANTES =====
define('BASE_URL', 'http://localhost/pharmageo/');

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

// ===== FONCTIONS UTILITAIRES =====
function estConnecte() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function aRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

function lienDashboard() {
    switch ($_SESSION['role'] ?? '') {
        case 'administrateur': return 'admin/dashboard.php';
        case 'pharmacien': return 'pharmacien/dashboard.php';
        default: return 'pages/patient/dashboard.php';
    }
}

// ===== RÉCUPÉRATION DES STATISTIQUES =====
try {
    $stats = [
        'pharmacies' => $pdo->query("SELECT COUNT(*) as total FROM pharmacie WHERE Statut = 'active'")->fetch(),
        'medicaments' => $pdo->query("SELECT COUNT(DISTINCT m.Id_medicament) as total FROM medicament m JOIN stock s ON m.Id_medicament = s.Id_medicament WHERE m.Statut = 'actif' AND s.Quantite > 0 AND s.Statut = 'disponible'")->fetch(),
        'utilisateurs' => $pdo->query("SELECT COUNT(*) as total FROM utilisateur WHERE Role = 'patient' AND Statut = 'actif'")->fetch(),
        'pharmaciens' => $pdo->query("SELECT COUNT(*) as total FROM pharmacien WHERE Statut_validation = 'valide'")->fetch(),
        'reservations' => $pdo->query("SELECT COUNT(*) as total FROM reservation WHERE Statut = 'confirmee'")->fetch()
    ];
} catch(PDOException $e) {
    $stats = [
        'pharmacies' => ['total' => 0],
        'medicaments' => ['total' => 0],
        'utilisateurs' => ['total' => 0],
        'pharmaciens' => ['total' => 0],
        'reservations' => ['total' => 0]
    ];
}

// ===== RÉCUPÉRATION DES MÉDICAMENTS POPULAIRES =====
try {
    $populaires = $pdo->query("
        SELECT m.Nom_commercial, m.DCI, COUNT(r.Id_reservation) as total_reservations
        FROM medicament m
        JOIN stock s ON m.Id_medicament = s.Id_medicament
        JOIN reservation r ON s.Id_stock = r.Id_stock
        WHERE m.Statut = 'actif' AND r.Statut = 'confirmee'
        GROUP BY m.Id_medicament
        ORDER BY total_reservations DESC
        LIMIT 6
    ")->fetchAll();
} catch(PDOException $e) {
    $populaires = [];
}

// ===== RÉCUPÉRATION DES DERNIÈRES PHARMACIES =====
try {
    $dernieres_pharmacies = $pdo->query("
        SELECT p.*, u.Nom, u.Prenom
        FROM pharmacie p
        JOIN pharmacien ph ON p.Id_pharmacien = ph.Id_pharmacien
        JOIN utilisateur u ON ph.Id_utilisateur = u.Id_utilisateur
        WHERE p.Statut = 'active'
        ORDER BY p.Id_pharmacie DESC
        LIMIT 4
    ")->fetchAll();
} catch(PDOException $e) {
    $dernieres_pharmacies = [];
}

$page_title = 'PharmaGeo - Plateforme de géolocalisation des pharmacies à Porto-Novo';
$page_description = 'Trouvez rapidement vos médicaments dans les pharmacies de Porto-Novo. Géolocalisation en temps réel, disponibilité des stocks et réservation en ligne.';
?>
<!DOCTYPE html>
<html lang="fr">
<!-- ===== EN-TÊTE DE PAGE (META ET RESSOURCES) ===== -->
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($page_description); ?>">
    <meta name="robots" content="index, follow">
    <meta name="theme-color" content="#16A34A">
    <link rel="canonical" href="<?php echo BASE_URL; ?>">

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="PharmaGeo - Pharmacies à Porto-Novo">
    <meta property="og:description" content="Recherche de médicaments et géolocalisation des pharmacies à Porto-Novo">
    <meta property="og:url" content="<?php echo BASE_URL; ?>">

    <!-- Préconnexions -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://images.unsplash.com" crossorigin>
    <link rel="preload" as="image" href="https://images.unsplash.com/photo-1696861286643-341a8d7a79e9?q=80&amp;w=1600&amp;auto=format&amp;fit=crop">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- AOS Animation Library -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">

    <!-- Swiper JS pour le carousel -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css">

    <style>
        /* ============================================================
           VARIABLES & RESET
           ============================================================ */
        :root {
            --primary-green: #16A34A;
            --primary-green-dark: #166534;
            --primary-orange: #B91C1C;
            --primary-orange-dark: #B91C1C;
            --white: #ffffff;
            --light-bg: #F0FDF4;
            --dark-text: #0F2E19;
            --gray-text: #64748b;
            --shadow-sm: 0 2px 12px rgba(0,0,0,0.06);
            --shadow-md: 0 8px 30px rgba(0,0,0,0.08);
            --shadow-lg: 0 20px 60px rgba(0,0,0,0.12);
            --shadow-xl: 0 30px 80px rgba(0,0,0,0.18);
            --shadow-glow-green: 0 8px 40px rgba(22,163,74,0.30);
            --shadow-glow-orange: 0 8px 40px rgba(212,136,15,0.25);
            --transition: all 0.5s cubic-bezier(0.23,1,0.32,1);
            --radius: 20px;
            --radius-lg: 28px;
            --radius-sm: 12px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
                scroll-behavior: auto !important;
            }
        }

        .skip-link {
            position: absolute;
            top: -60px;
            left: 16px;
            background: var(--dark-text);
            color: white;
            padding: 12px 22px;
            border-radius: var(--radius-sm);
            z-index: 10000;
            font-weight: 600;
            transition: top 0.3s ease;
        }
        .skip-link:focus { top: 16px; color: white; }

        a:focus-visible, button:focus-visible, input:focus-visible {
            outline: 3px solid var(--primary-green);
            outline-offset: 2px;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--light-bg);
            color: var(--dark-text);
            overflow-x: hidden;
            line-height: 1.7;
            -webkit-font-smoothing: antialiased;
        }

        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: var(--light-bg); }
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            border-radius: 10px;
        }

        /* ============================================================
           ANIMATIONS
           ============================================================ */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(50px) scale(0.97); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-40px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-70px); }
            to { opacity: 1; transform: translateX(0); }
        }
        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(70px); }
            to { opacity: 1; transform: translateX(0); }
        }
        @keyframes scaleIn {
            from { opacity: 0; transform: scale(0.8); }
            to { opacity: 1; transform: scale(1); }
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-18px) rotate(1deg); }
        }
        @keyframes floatSlow {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-25px); }
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }
        @keyframes zoomIn {
            from { opacity: 0; transform: scale(1.1); }
            to { opacity: 1; transform: scale(1); }
        }
        @keyframes gradientMove {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        @keyframes typewriter {
            from { width: 0; }
            to { width: 100%; }
        }
        @keyframes blink {
            0%, 100% { border-color: transparent; }
            50% { border-color: white; }
        }
        @keyframes particleFloat {
            0%, 100% { transform: translateY(0) rotate(0deg); opacity: 0.6; }
            50% { transform: translateY(-100px) rotate(180deg); opacity: 1; }
        }

        .animate-fade-up { animation: fadeInUp 0.9s cubic-bezier(0.23,1,0.32,1) forwards; }
        .animate-fade-down { animation: fadeInDown 0.7s ease-out forwards; }
        .animate-slide-left { animation: slideInLeft 0.8s ease-out forwards; }
        .animate-slide-right { animation: slideInRight 0.8s ease-out forwards; }
        .animate-scale { animation: scaleIn 0.7s ease-out forwards; }
        .animate-float { animation: float 5s ease-in-out infinite; }
        .animate-float-slow { animation: floatSlow 8s ease-in-out infinite; }
        .animate-pulse { animation: pulse 2.5s ease-in-out infinite; }
        .animate-zoom { animation: zoomIn 1s ease-out forwards; }
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }
        .delay-5 { animation-delay: 0.5s; }
        .delay-6 { animation-delay: 0.6s; }
        .opacity-0 { opacity: 0; }

        /* ============================================================
           SCROLL PROGRESS
           ============================================================ */
        .scroll-progress {
            position: fixed;
            top: 0;
            left: 0;
            height: 3px;
            width: 0%;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            z-index: 10001;
            transition: width 0.1s linear;
        }

        /* ============================================================
           BACK TO TOP
           ============================================================ */
        .back-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 54px;
            height: 54px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            box-shadow: var(--shadow-glow-green);
            cursor: pointer;
            z-index: 998;
            opacity: 0;
            visibility: hidden;
            transform: translateY(30px);
            transition: var(--transition);
        }
        .back-to-top.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        .back-to-top:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 45px rgba(22,163,74,0.45);
        }
        @media (max-width: 768px) {
            .back-to-top { bottom: 90px; right: 18px; width: 46px; height: 46px; font-size: 1rem; }
        }

        /* ============================================================
           NAVIGATION
           ============================================================ */
        .navbar-custom {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            box-shadow: 0 1px 20px rgba(0,0,0,0.04);
            padding: 14px 0;
            transition: var(--transition);
            border-bottom: 1px solid rgba(255,255,255,0.3);
        }
        .navbar-custom.scrolled {
            box-shadow: 0 4px 40px rgba(0,0,0,0.08);
            padding: 8px 0;
            background: rgba(255,255,255,0.98);
        }
        .navbar-brand-custom {
            font-weight: 800;
            font-size: 1.7rem;
            color: var(--dark-text);
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .navbar-brand-custom .pg-brand-wordmark {
            display: block;
            width: 188px;
            height: 64px;
            flex: 0 0 188px;
            object-fit: contain;
            object-position: left center;
        }
        .navbar-brand-custom .brand-icon {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            padding: 8px 12px;
            border-radius: var(--radius-sm);
            color: white;
            box-shadow: var(--shadow-glow-green);
            font-size: 1.2rem;
        }
        .navbar-brand-custom .brand-text {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .navbar-toggler {
            padding: 6px 10px !important;
            box-shadow: none !important;
            border-color: var(--primary-green) !important;
        }
        .navbar-toggler-icon {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3E%3Cpath stroke='#16A34A' stroke-width='2.5' stroke-linecap='round' stroke-miterlimit='10' d='M4 7h22M4 15h22M4 23h22'/%3E%3C/svg%3E") !important;
        }
        .nav-link-custom {
            font-weight: 500;
            color: var(--dark-text);
            padding: 8px 20px !important;
            border-radius: var(--radius-sm);
            transition: var(--transition);
            position: relative;
            font-size: 0.95rem;
        }
        .nav-link-custom:hover, .nav-link-custom.active {
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
        .nav-link-custom:hover::after, .nav-link-custom.active::after {
            width: 50%;
        }
        .btn-nav-connect {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white !important;
            padding: 10px 30px !important;
            border-radius: 50px !important;
            font-weight: 600;
            transition: var(--transition);
            border: none;
            box-shadow: var(--shadow-glow-green);
        }
        .btn-nav-connect:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 40px rgba(22,163,74,0.45);
            color: white !important;
        }
        .btn-nav-register {
            border: 2px solid var(--primary-green);
            color: var(--primary-green) !important;
            padding: 10px 30px !important;
            border-radius: 50px !important;
            font-weight: 600;
            transition: var(--transition);
            background: transparent;
        }
        .btn-nav-register:hover {
            background: var(--primary-green);
            color: white !important;
            transform: translateY(-3px);
            box-shadow: var(--shadow-glow-green);
        }

        /* ============================================================
           HERO SECTION AVEC SLIDER
           ============================================================ */
        .hero-section {
            position: relative;
            min-height: 100vh;
            display: flex;
            align-items: center;
            overflow: hidden;
            padding: 130px 0 80px;
            isolation: isolate;
        }

        /* Swiper Slider */
        .hero-slider {
            position: absolute;
            inset: 0;
            z-index: 0;
        }
        .hero-slider .swiper-slide {
            position: relative;
            width: 100%;
            height: 100%;
        }
        .hero-slider .swiper-slide .slide-bg {
            position: absolute;
            inset: 0;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            transform: scale(1.12);
            transition: transform 7s cubic-bezier(0.16,1,0.3,1);
            will-change: transform;
        }
        .hero-slider .swiper-slide-active .slide-bg {
            transform: scale(1);
            animation: heroKenBurns 16s ease-in-out infinite alternate;
        }
        @keyframes heroKenBurns {
            0%   { transform: scale(1) translate3d(0,0,0); }
            100% { transform: scale(1.08) translate3d(-1.5%,-1%,0); }
        }
        @media (prefers-reduced-motion: reduce) {
            .hero-slider .swiper-slide-active .slide-bg { animation: none; }
        }
        .hero-slider .swiper-slide .slide-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(100deg, rgba(6,14,26,0.90) 0%, rgba(8,20,18,0.72) 32%, rgba(22,163,74,0.55) 68%, rgba(212,136,15,0.55) 100%);
            z-index: 1;
        }
        .hero-slider .swiper-slide .slide-pattern {
            position: absolute;
            inset: 0;
            background-image: 
                radial-gradient(circle at 20% 50%, rgba(255,255,255,0.08) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255,255,255,0.06) 0%, transparent 50%),
                radial-gradient(circle at 40% 80%, rgba(255,255,255,0.05) 0%, transparent 50%),
                url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            z-index: 1;
            pointer-events: none;
        }

        /* Effet de particules */
        .hero-particles {
            position: absolute;
            inset: 0;
            z-index: 1;
            pointer-events: none;
            overflow: hidden;
        }
        .hero-particles .particle {
            position: absolute;
            width: 6px;
            height: 6px;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
            animation: particleFloat 12s ease-in-out infinite;
        }
        .hero-particles .particle:nth-child(1) { left: 10%; animation-delay: 0s; width: 8px; height: 8px; }
        .hero-particles .particle:nth-child(2) { left: 25%; animation-delay: 2s; width: 4px; height: 4px; }
        .hero-particles .particle:nth-child(3) { left: 45%; animation-delay: 4s; width: 10px; height: 10px; }
        .hero-particles .particle:nth-child(4) { left: 65%; animation-delay: 1s; width: 5px; height: 5px; }
        .hero-particles .particle:nth-child(5) { left: 80%; animation-delay: 3s; width: 7px; height: 7px; }
        .hero-particles .particle:nth-child(6) { left: 90%; animation-delay: 5s; width: 4px; height: 4px; }

        .hero-content {
            position: relative;
            z-index: 2;
            color: white;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: rgba(255,255,255,0.12);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            padding: 10px 28px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 500;
            letter-spacing: 0.3px;
            margin-bottom: 28px;
            border: 1px solid rgba(255,255,255,0.12);
            box-shadow: 0 4px 20px rgba(0,0,0,0.04);
        }
        .hero-badge i { font-size: 1rem; }

        .hero-title {
            font-size: clamp(2.1rem, 5.2vw + 1rem, 5rem);
            font-weight: 900;
            line-height: 1.05;
            letter-spacing: -2.5px;
            margin-bottom: 20px;
            text-shadow: 0 4px 40px rgba(0,0,0,0.08);
        }
        .hero-title .highlight {
            background: linear-gradient(135deg, #ffffff, rgba(255,255,255,0.75));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-subtitle {
            font-size: 1.3rem;
            opacity: 0.92;
            max-width: 560px;
            margin-bottom: 36px;
            font-weight: 300;
            line-height: 1.8;
            text-shadow: 0 2px 20px rgba(0,0,0,0.04);
        }

        .hero-search {
            background: rgba(255,255,255,0.12);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border-radius: 80px;
            padding: 6px 6px 6px 30px;
            display: flex;
            align-items: center;
            max-width: 640px;
            border: 1px solid rgba(255,255,255,0.15);
            transition: var(--transition);
            box-shadow: 0 8px 40px rgba(0,0,0,0.06);
        }
        .hero-search:focus-within {
            background: rgba(255,255,255,0.20);
            border-color: rgba(255,255,255,0.30);
            box-shadow: 0 8px 50px rgba(0,0,0,0.10);
            transform: scale(1.02);
        }
        .hero-search .form-control {
            background: transparent;
            border: none;
            color: white;
            padding: 14px 10px;
            font-size: 1rem;
            flex: 1;
        }
        .hero-search .form-control::placeholder {
            color: rgba(255,255,255,0.55);
        }
        .hero-search .form-control:focus {
            box-shadow: none;
        }
        .hero-search .btn-search {
            background: white;
            color: var(--primary-green);
            border: none;
            padding: 14px 38px;
            border-radius: 50px;
            font-weight: 700;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            white-space: nowrap;
        }
        .hero-search .btn-search:hover {
            transform: scale(1.04);
            box-shadow: 0 8px 35px rgba(0,0,0,0.12);
        }

        .hero-stats-mini {
            display: flex;
            gap: 50px;
            margin-top: 44px;
            padding-top: 32px;
            border-top: 1px solid rgba(255,255,255,0.08);
        }
        .hero-stats-mini .stat-item { text-align: center; }
        .hero-stats-mini .stat-number {
            font-size: 2.1rem;
            font-weight: 800;
            display: block;
            text-shadow: 0 2px 20px rgba(0,0,0,0.04);
        }
        .hero-stats-mini .stat-label {
            font-size: 0.85rem;
            opacity: 0.7;
            font-weight: 400;
        }

        /* Contrôles du slider */
        .hero-slider-controls {
            position: absolute;
            bottom: 40px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 5;
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .hero-slider-controls .swiper-pagination-bullet {
            width: 12px;
            height: 12px;
            background: rgba(255,255,255,0.3);
            opacity: 1;
            border-radius: 50%;
            transition: var(--transition);
            cursor: pointer;
        }
        .hero-slider-controls .swiper-pagination-bullet-active {
            background: white;
            transform: scale(1.3);
            box-shadow: 0 0 20px rgba(255,255,255,0.3);
        }
        .hero-slider-controls .swiper-button-prev,
        .hero-slider-controls .swiper-button-next {
            position: relative;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: rgba(255,255,255,0.10);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,0.10);
            color: white;
            font-size: 1.2rem;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .hero-slider-controls .swiper-button-prev:hover,
        .hero-slider-controls .swiper-button-next:hover {
            background: rgba(255,255,255,0.20);
            transform: scale(1.1);
        }
        .hero-slider-controls .swiper-button-prev::after,
        .hero-slider-controls .swiper-button-next::after {
            display: none;
        }

        /* ============================================================
           STATISTIQUES
           ============================================================ */
        .stats-section {
            padding: 50px 0;
            background: transparent;
            margin-top: -40px;
            position: relative;
            z-index: 5;
        }
        .stat-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 32px 28px;
            text-align: center;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-md);
            border: 1px solid rgba(255,255,255,0.5);
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            opacity: 0;
            transition: var(--transition);
        }
        .stat-card:hover::before { opacity: 1; }
        .stat-card:hover {
            transform: translateY(-12px);
            box-shadow: var(--shadow-xl);
            border-color: transparent;
        }
        .stat-card .icon-wrapper {
            width: 68px;
            height: 68px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 2rem;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
        }
        .stat-card:hover .icon-wrapper { transform: scale(1.08) rotate(-4deg); }
        .stat-card .icon-wrapper.green {
            background: linear-gradient(135deg, rgba(22,163,74,0.10), rgba(22,163,74,0.04));
            color: var(--primary-green);
        }
        .stat-card .icon-wrapper.orange {
            background: linear-gradient(135deg, rgba(212,136,15,0.10), rgba(212,136,15,0.04));
            color: var(--primary-orange);
        }
        .stat-card .icon-wrapper.blue {
            background: linear-gradient(135deg, rgba(22,83,52,0.10), rgba(22,83,52,0.04));
            color: #166534;
        }
        .stat-card .icon-wrapper.purple {
            background: linear-gradient(135deg, rgba(155,89,182,0.10), rgba(155,89,182,0.04));
            color: #1F2937;
        }
        .stat-card .stat-number {
            font-size: 2.8rem;
            font-weight: 900;
            color: var(--dark-text);
            line-height: 1.1;
            background: linear-gradient(135deg, var(--dark-text), #1F2937);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .stat-card .stat-label {
            color: var(--gray-text);
            font-weight: 500;
            font-size: 0.95rem;
        }
        .stat-card .stat-trend {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 4px 16px;
            border-radius: 50px;
            margin-top: 10px;
            box-shadow: var(--shadow-sm);
        }
        .stat-card .stat-trend.up {
            color: var(--primary-green);
            background: rgba(22,163,74,0.08);
        }
        .stat-card .stat-trend.down {
            color: #DC2626;
            background: rgba(220,38,38,0.08);
        }

        /* ============================================================
           SECTIONS COMMUNES
           ============================================================ */
        .section-title {
            font-size: 2.8rem;
            font-weight: 800;
            letter-spacing: -1.5px;
            margin-bottom: 10px;
        }
        .section-title .highlight {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .section-subtitle {
            color: var(--gray-text);
            font-size: 1.1rem;
            max-width: 600px;
            margin: 0 auto;
        }
        .section-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 24px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.85rem;
            letter-spacing: 0.3px;
            box-shadow: var(--shadow-sm);
        }
        .section-badge.green {
            background: rgba(22,163,74,0.10);
            color: var(--primary-green);
        }
        .section-badge.orange {
            background: rgba(212,136,15,0.10);
            color: var(--primary-orange);
        }

        /* ============================================================
           MÉDICAMENTS CARDS
           ============================================================ */
        .medicament-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 30px 26px;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-md);
            border: 1px solid rgba(255,255,255,0.5);
            height: 100%;
            cursor: default;
        }
        .medicament-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            opacity: 0;
            transition: var(--transition);
            z-index: 0;
        }
        .medicament-card:hover::before { opacity: 1; }
        .medicament-card:hover {
            transform: translateY(-12px);
            box-shadow: var(--shadow-xl);
        }
        .medicament-card .card-content {
            position: relative;
            z-index: 1;
            transition: var(--transition);
        }
        .medicament-card:hover .card-content { color: white; }
        .medicament-card .medicament-icon {
            width: 56px;
            height: 56px;
            border-radius: var(--radius-sm);
            background: rgba(22,163,74,0.08);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            color: var(--primary-green);
            margin-bottom: 14px;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
        }
        .medicament-card:hover .medicament-icon {
            background: rgba(255,255,255,0.18);
            color: white;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        .medicament-card .medicament-name {
            font-weight: 700;
            font-size: 1.15rem;
            margin-bottom: 4px;
        }
        .medicament-card .medicament-dci {
            font-size: 0.85rem;
            color: var(--gray-text);
            transition: var(--transition);
        }
        .medicament-card:hover .medicament-dci {
            color: rgba(255,255,255,0.80);
        }
        .medicament-card .medicament-count {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--primary-green);
            margin-top: 12px;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 16px;
            background: rgba(22,163,74,0.08);
            border-radius: 50px;
        }
        .medicament-card:hover .medicament-count {
            color: rgba(255,255,255,0.90);
            background: rgba(255,255,255,0.14);
        }

        /* ============================================================
           PHARMACIES CARDS
           ============================================================ */
        .pharmacy-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            overflow: hidden;
            transition: var(--transition);
            box-shadow: var(--shadow-md);
            border: 1px solid rgba(255,255,255,0.5);
            height: 100%;
        }
        .pharmacy-card:hover {
            transform: translateY(-12px);
            box-shadow: var(--shadow-xl);
            border-color: transparent;
        }
        .pharmacy-card .card-body { padding: 28px; }
        .pharmacy-card .pharmacy-avatar {
            width: 60px;
            height: 60px;
            border-radius: var(--radius-sm);
            background: linear-gradient(135deg, rgba(22,163,74,0.08), rgba(212,136,15,0.08));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: var(--primary-green);
            flex-shrink: 0;
            box-shadow: var(--shadow-sm);
        }
        .pharmacy-card .pharmacy-name {
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 2px;
        }
        .pharmacy-card .pharmacy-address {
            font-size: 0.85rem;
            color: var(--gray-text);
        }
        .pharmacy-card .pharmacy-badge {
            background: rgba(22,163,74,0.08);
            color: var(--primary-green);
            padding: 4px 16px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            box-shadow: var(--shadow-sm);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .pharmacy-card .pharmacy-stats {
            display: flex;
            gap: 24px;
            padding-top: 18px;
            margin-top: 18px;
            border-top: 1px solid rgba(0,0,0,0.04);
        }
        .pharmacy-card .pharmacy-stats .stat { text-align: center; flex: 1; }
        .pharmacy-card .pharmacy-stats .stat-number {
            font-weight: 700;
            font-size: 1.1rem;
            display: block;
        }
        .pharmacy-card .pharmacy-stats .stat-label {
            font-size: 0.7rem;
            color: var(--gray-text);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-outline-custom {
            border: 2px solid var(--primary-green);
            color: var(--primary-green);
            background: transparent;
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            font-weight: 600;
            transition: var(--transition);
        }
        .btn-outline-custom:hover {
            background: var(--primary-green);
            color: white;
            transform: translateY(-3px);
            box-shadow: var(--shadow-glow-green);
        }

        /* ============================================================
           FEATURES SECTION
           ============================================================ */
        .features-section {
            padding: 90px 0;
            background: var(--light-bg);
            position: relative;
        }
        .features-section::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: 
                url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%2342c942' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            pointer-events: none;
        }

        .feature-card {
            text-align: center;
            padding: 44px 32px;
            border-radius: var(--radius-lg);
            transition: var(--transition);
            background: var(--white);
            border: 1px solid rgba(255,255,255,0.5);
            height: 100%;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-md);
        }
        .feature-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            transform: scaleX(0);
            transition: var(--transition);
            transform-origin: left;
        }
        .feature-card:hover::after { transform: scaleX(1); }
        .feature-card:hover {
            transform: translateY(-12px);
            box-shadow: var(--shadow-xl);
            border-color: transparent;
        }
        .feature-card .icon-wrapper {
            width: 80px;
            height: 80px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 18px;
            font-size: 2.2rem;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
        }
        .feature-card:hover .icon-wrapper { transform: scale(1.08) rotate(-4deg); }
        .feature-card .icon-wrapper.green {
            background: linear-gradient(135deg, rgba(22,163,74,0.10), rgba(22,163,74,0.04));
            color: var(--primary-green);
        }
        .feature-card .icon-wrapper.orange {
            background: linear-gradient(135deg, rgba(212,136,15,0.10), rgba(212,136,15,0.04));
            color: var(--primary-orange);
        }
        .feature-card .icon-wrapper.blue {
            background: linear-gradient(135deg, rgba(22,83,52,0.10), rgba(22,83,52,0.04));
            color: #166534;
        }
        .feature-card .icon-wrapper.purple {
            background: linear-gradient(135deg, rgba(155,89,182,0.10), rgba(155,89,182,0.04));
            color: #1F2937;
        }
        .feature-card .feature-title {
            font-weight: 700;
            font-size: 1.2rem;
            margin-bottom: 8px;
        }
        .feature-card .feature-description {
            color: var(--gray-text);
            font-size: 0.95rem;
            line-height: 1.7;
        }

        /* ============================================================
           TESTIMONIALS SECTION
           ============================================================ */
        .testimonials-section {
            padding: 90px 0;
            background: var(--white);
        }
        .testimonial-card {
            background: var(--light-bg);
            border-radius: var(--radius-lg);
            padding: 32px 30px;
            transition: var(--transition);
            border: 1px solid rgba(255,255,255,0.5);
            height: 100%;
        }
        .testimonial-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-lg);
        }
        .testimonial-card .testimonial-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            font-weight: 700;
            flex-shrink: 0;
        }
        .testimonial-card .testimonial-text {
            font-size: 1rem;
            color: var(--dark-text);
            line-height: 1.8;
            font-style: italic;
        }
        .testimonial-card .testimonial-name {
            font-weight: 700;
            font-size: 1.05rem;
        }
        .testimonial-card .testimonial-role {
            font-size: 0.85rem;
            color: var(--gray-text);
        }
        .testimonial-card .testimonial-stars {
            color: #DC2626;
            font-size: 0.9rem;
            letter-spacing: 2px;
        }

        /* ============================================================
           CTA SECTION
           ============================================================ */
        .cta-section {
            padding: 100px 0;
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
        }
        .cta-section .cta-bg-image {
            position: absolute;
            inset: 0;
            background-image: 
                url('data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 600"%3E%3Cdefs%3E%3ClinearGradient id="b" x1="0%25" y1="0%25" x2="100%25" y2="100%25"%3E%3Cstop offset="0%25" style="stop-color:%23ffffff;stop-opacity:0.05"/%3E%3Cstop offset="100%25" style="stop-color:%23ffffff;stop-opacity:0.02"/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect fill="url(%23b)" width="1440" height="600"/%3E%3Ccircle cx="200" cy="300" r="250" fill="rgba(255,255,255,0.03)"/%3E%3Ccircle cx="1200" cy="100" r="300" fill="rgba(255,255,255,0.02)"/%3E%3Ccircle cx="800" cy="500" r="200" fill="rgba(255,255,255,0.02)"/%3E%3C/svg%3E');
            background-size: cover;
            background-position: center;
            z-index: 0;
        }
        .cta-section .cta-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(22,163,74,0.92), rgba(212,136,15,0.88));
            z-index: 1;
        }
        .cta-section .cta-pattern {
            position: absolute;
            inset: 0;
            background-image: 
                radial-gradient(circle at 20% 50%, rgba(255,255,255,0.05) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255,255,255,0.04) 0%, transparent 50%);
            z-index: 1;
            pointer-events: none;
        }
        .cta-section .cta-content {
            position: relative;
            z-index: 2;
            color: white;
        }
        .cta-section .cta-title {
            font-size: 3.2rem;
            font-weight: 800;
            letter-spacing: -1.5px;
            text-shadow: 0 4px 40px rgba(0,0,0,0.05);
        }
        .cta-section .cta-subtitle {
            font-size: 1.1rem;
            opacity: 0.85;
            max-width: 520px;
            text-shadow: 0 2px 20px rgba(0,0,0,0.04);
        }
        .btn-cta {
            background: white;
            color: var(--primary-green);
            padding: 16px 44px;
            border-radius: 50px;
            font-weight: 700;
            border: none;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 8px 40px rgba(0,0,0,0.12);
            text-decoration: none;
        }
        .btn-cta:hover {
            transform: translateY(-4px);
            box-shadow: 0 16px 60px rgba(0,0,0,0.18);
            color: var(--primary-green);
        }
        .btn-cta-outline {
            background: transparent;
            color: white;
            border: 2px solid rgba(255,255,255,0.25);
            padding: 14px 40px;
            border-radius: 50px;
            font-weight: 600;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }
        .btn-cta-outline:hover {
            background: white;
            color: var(--primary-green);
            border-color: white;
            transform: translateY(-4px);
            box-shadow: 0 8px 40px rgba(0,0,0,0.12);
        }

        /* ============================================================
           FOOTER
           ============================================================ */
        .footer-custom {
            background: #0F2E19;
            color: rgba(255,255,255,0.70);
            padding: 70px 0 30px;
            position: relative;
        }
        .footer-custom::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
        }
        .footer-custom .footer-brand {
            font-size: 1.8rem;
            font-weight: 800;
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .footer-custom .footer-brand .brand-icon {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            padding: 6px 10px;
            border-radius: var(--radius-sm);
            color: white;
        }
        .footer-custom .footer-description {
            max-width: 320px;
            font-size: 0.95rem;
            line-height: 1.8;
        }
        .footer-custom .footer-title {
            color: white;
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 20px;
        }
        .footer-custom .footer-links {
            list-style: none;
            padding: 0;
        }
        .footer-custom .footer-links li { margin-bottom: 10px; }
        .footer-custom .footer-links a {
            color: rgba(255,255,255,0.55);
            text-decoration: none;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .footer-custom .footer-links a:hover {
            color: var(--primary-green);
            transform: translateX(4px);
        }
        .footer-custom .social-links {
            display: flex;
            gap: 10px;
        }
        .footer-custom .social-links a {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-sm);
            background: rgba(255,255,255,0.04);
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255,255,255,0.50);
            transition: var(--transition);
            text-decoration: none;
            font-size: 1.1rem;
            border: 1px solid rgba(255,255,255,0.04);
        }
        .footer-custom .social-links a:hover {
            background: var(--primary-green);
            color: white;
            transform: translateY(-4px);
            box-shadow: var(--shadow-glow-green);
        }
        .footer-custom .footer-bottom {
            border-top: 1px solid rgba(255,255,255,0.04);
            padding-top: 24px;
            margin-top: 40px;
            font-size: 0.9rem;
        }

        /* ============================================================
           TOAST
           ============================================================ */
        .toast-container-custom {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 400px;
            width: 100%;
        }
        .toast-custom {
            background: white;
            border-radius: var(--radius-sm);
            padding: 18px 24px;
            box-shadow: var(--shadow-xl);
            display: flex;
            align-items: center;
            gap: 14px;
            animation: slideInRight 0.5s ease-out;
            border-left: 5px solid var(--primary-green);
            transition: var(--transition);
        }
        .toast-custom .toast-icon { font-size: 1.4rem; flex-shrink: 0; }
        .toast-custom .toast-content { flex: 1; }
        .toast-custom .toast-title { font-weight: 700; font-size: 0.95rem; }
        .toast-custom .toast-message { color: var(--gray-text); font-size: 0.85rem; }
        .toast-custom .btn-close { font-size: 0.7rem; opacity: 0.5; }

        /* ============================================================
           KEYBOARD SHORTCUT
           ============================================================ */
        .shortcut-hint {
            position: fixed;
            bottom: 30px;
            left: 30px;
            background: rgba(0,0,0,0.70);
            backdrop-filter: blur(12px);
            color: white;
            padding: 8px 18px;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 500;
            z-index: 99;
            border: 1px solid rgba(255,255,255,0.05);
            display: none;
        }
        .shortcut-hint kbd {
            background: rgba(255,255,255,0.12);
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            color: white;
        }
        @media (min-width: 992px) { .shortcut-hint { display: block; } }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 1199px) {
            .section-title { font-size: 2.4rem; }
        }
        @media (max-width: 991px) {
            .hero-stats-mini { gap: 30px; flex-wrap: wrap; }
            .section-title { font-size: 2.2rem; }
            .cta-section .cta-title { font-size: 2.6rem; }
            .stat-card .stat-number { font-size: 2.4rem; }
            .navbar-custom { padding: 12px 0; }
        }
        @media (max-width: 768px) {
            .hero-section { padding: 100px 0 50px; min-height: auto; }
            .hero-title { letter-spacing: -1px; }
            .hero-subtitle { font-size: 1rem; }
            .hero-search {
                flex-direction: column;
                background: transparent;
                padding: 0;
                border: none;
                backdrop-filter: none;
                box-shadow: none;
                transform: none !important;
            }
            .hero-search .form-control {
                background: rgba(255,255,255,0.12);
                border-radius: 50px;
                padding: 14px 20px;
                margin-bottom: 10px;
                border: 1px solid rgba(255,255,255,0.10);
                width: 100%;
            }
            .hero-search .btn-search {
                width: 100%;
                justify-content: center;
                padding: 14px;
            }
            .hero-stats-mini {
                gap: 20px;
                flex-wrap: wrap;
                justify-content: center;
            }
            .hero-stats-mini .stat-number { font-size: 1.6rem; }
            .stats-section { padding: 30px 0; }
            .section-title { font-size: 1.8rem; }
            .section-subtitle { font-size: 1rem; }
            .stat-card { padding: 24px 20px; }
            .stat-card .stat-number { font-size: 2rem; }
            .feature-card { padding: 30px 20px; }
            .cta-section { padding: 60px 0; }
            .cta-section .cta-title { font-size: 2rem; }
            .btn-cta, .btn-cta-outline {
                width: 100%;
                justify-content: center;
            }
            .pharmacy-card .card-body { padding: 20px; }
            .medicament-card { padding: 20px; }
            .footer-custom .footer-description { max-width: 100%; }
            .toast-container-custom {
                bottom: 15px;
                right: 15px;
                left: 15px;
                max-width: none;
            }
            .toast-custom {
                min-width: auto;
                width: 100%;
                padding: 14px 18px;
            }
            .hero-slider-controls .swiper-button-prev,
            .hero-slider-controls .swiper-button-next {
                width: 36px;
                height: 36px;
                font-size: 0.9rem;
            }
            .hero-slider-controls .swiper-pagination-bullet {
                width: 10px;
                height: 10px;
            }
        }
        @media (max-width: 576px) {
            .hero-badge { font-size: 0.75rem; padding: 6px 16px; }
            .hero-stats-mini .stat-number { font-size: 1.3rem; }
            .stat-card .stat-number { font-size: 1.6rem; }
            .stat-card { padding: 18px 16px; }
            .stat-card .icon-wrapper {
                width: 50px;
                height: 50px;
                font-size: 1.4rem;
            }
            .section-title { font-size: 1.5rem; }
            .feature-card .icon-wrapper {
                width: 60px;
                height: 60px;
                font-size: 1.6rem;
            }
            .pharmacy-card .pharmacy-avatar {
                width: 44px;
                height: 44px;
                font-size: 1.4rem;
            }
            .medicament-card .medicament-icon {
                width: 44px;
                height: 44px;
                font-size: 1.2rem;
            }
            .btn-nav-connect, .btn-nav-register {
                padding: 6px 16px !important;
                font-size: 0.9rem;
            }
            .navbar-brand-custom { font-size: 1.3rem; }
            .navbar-brand-custom .brand-icon { padding: 6px 10px; font-size: 1rem; }
            .pharmacy-card .pharmacy-stats { gap: 12px; }
            .pharmacy-card .pharmacy-stats .stat-number { font-size: 0.95rem; }
            .testimonial-card { padding: 24px 20px; }
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

    <a href="#main-content" class="skip-link">Aller au contenu principal</a>
    <div class="scroll-progress" id="scrollProgress"></div>

    <!-- ============================================================
    NAVIGATION
    ============================================================ -->
    <nav class="navbar navbar-expand-lg navbar-custom sticky-top" id="mainNav" aria-label="Navigation principale">
        <div class="container">
            <a class="navbar-brand navbar-brand-custom" href="<?php echo BASE_URL; ?>">
                <img class="pg-brand-wordmark" src="<?php echo BASE_URL; ?>assets/identite/logos/svg/pharmageo-logo-horizontal.svg" alt="PharmaGeo" onerror="this.onerror=null;this.src='<?php echo BASE_URL; ?>assets/identite/icones/pharmageo-icone-vert.svg';this.style.width='48px';this.style.flexBasis='48px';">
            </a>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Ouvrir le menu de navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-lg-center gap-1">
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom active" href="<?php echo BASE_URL; ?>" aria-current="page">
                            <i class="fas fa-home me-1"></i>Accueil
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom" href="<?php echo BASE_URL; ?>pages/patient/recherche.php">
                            <i class="fas fa-search me-1"></i>Rechercher
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom" href="<?php echo BASE_URL; ?>pages/patient/carte.php">
                            <i class="fas fa-map-marker-alt me-1"></i>Carte
                        </a>
                    </li>
                    <?php if (estConnecte()): ?>
                        <li class="nav-item">
                            <a class="nav-link nav-link-custom" href="<?php echo BASE_URL . lienDashboard(); ?>">
                                <i class="fas fa-user me-1"></i>Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link btn-nav-connect" href="<?php echo BASE_URL; ?>deconnexion.php">
                                <i class="fas fa-sign-out-alt me-1"></i>Déconnexion
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link btn-nav-connect" href="<?php echo BASE_URL; ?>connexion.php">
                                <i class="fas fa-sign-in-alt me-1"></i>Connexion
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link btn-nav-register" href="<?php echo BASE_URL; ?>inscription.php">
                                <i class="fas fa-user-plus me-1"></i>Inscription
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- ============================================================
    HERO SECTION AVEC SLIDER
    ============================================================ -->
    <section class="hero-section" id="main-content">
        <!-- Slider -->
        <div class="hero-slider swiper" id="heroSlider">
            <div class="swiper-wrapper">
                <!-- Slide 1 - Pharmacie -->
                <div class="swiper-slide">
                    <div class="slide-bg" style="background-image: url('https://images.unsplash.com/photo-1696861286643-341a8d7a79e9?q=80&amp;w=1600&amp;auto=format&amp;fit=crop');"></div>
                    <div class="slide-overlay"></div>
                    <div class="slide-pattern"></div>
                </div>
                <!-- Slide 2 - Médicaments -->
                <div class="swiper-slide">
                    <div class="slide-bg" style="background-image: url('https://images.unsplash.com/photo-1577401132921-cb39bb0adcff?q=80&amp;w=1600&amp;auto=format&amp;fit=crop');"></div>
                    <div class="slide-overlay"></div>
                    <div class="slide-pattern"></div>
                </div>
                <!-- Slide 3 - Carte -->
                <div class="swiper-slide">
                    <div class="slide-bg" style="background-image: url('https://images.unsplash.com/photo-1529775768124-fb874e73ac7e?q=80&amp;w=1600&amp;auto=format&amp;fit=crop');"></div>
                    <div class="slide-overlay"></div>
                    <div class="slide-pattern"></div>
                </div>
            </div>
            <!-- Contrôles -->
            <div class="hero-slider-controls">
                <div class="swiper-button-prev">
                    <i class="fas fa-chevron-left"></i>
                </div>
                <div class="swiper-pagination"></div>
                <div class="swiper-button-next">
                    <i class="fas fa-chevron-right"></i>
                </div>
            </div>
        </div>

        <!-- Particules -->
        <div class="hero-particles">
            <div class="particle"></div>
            <div class="particle"></div>
            <div class="particle"></div>
            <div class="particle"></div>
            <div class="particle"></div>
            <div class="particle"></div>
        </div>

        <div class="container position-relative" style="z-index:2;">
            <div class="row align-items-center">
                <div class="col-lg-6 hero-content">
                    <div class="hero-badge animate-fade-down">
                        <i class="fas fa-check-circle"></i>
                        Plateforme officielle de santé à Porto-Novo
                    </div>
                    <h1 class="hero-title animate-fade-up delay-1">
                        Trouvez vos<br>
                        <span class="highlight">médicaments</span><br>
                        en un clic
                    </h1>
                    <p class="hero-subtitle animate-fade-up delay-2">
                        Géolocalisez les pharmacies proches de vous, consultez la disponibilité 
                        des médicaments en temps réel et réservez en quelques secondes.
                    </p>
                    
                    <form action="<?php echo BASE_URL; ?>pages/patient/recherche.php" method="GET" class="hero-search animate-fade-up delay-3" id="heroSearchForm">
                        <input type="text" name="q" class="form-control" placeholder="Paracétamol, Amoxicilline... (plusieurs médicaments possible)" aria-label="Rechercher un ou plusieurs médicaments (séparés par des virgules)" required>
                        <button type="submit" class="btn-search" id="heroSearchBtn">
                            <i class="fas fa-search"></i>
                            <span class="btn-search-label">Rechercher</span>
                        </button>
                    </form>
                    
                    <div class="hero-stats-mini animate-fade-up delay-4">
                        <div class="stat-item">
                            <span class="stat-number"><?php echo number_format($stats['pharmacies']['total'] ?? 0); ?></span>
                            <span class="stat-label">Pharmacies</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number"><?php echo number_format($stats['medicaments']['total'] ?? 0); ?></span>
                            <span class="stat-label">Médicaments</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number"><?php echo number_format($stats['utilisateurs']['total'] ?? 0); ?></span>
                            <span class="stat-label">Patients</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-6 d-none d-lg-block">
                    <!-- Espace réservé pour l'image ou la carte -->
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
    STATISTIQUES SECTION
    ============================================================ -->
    <section class="stats-section">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="0">
                    <div class="stat-card">
                        <div class="icon-wrapper green">
                            <i class="fas fa-store"></i>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['pharmacies']['total'] ?? 0); ?></div>
                        <div class="stat-label">Pharmacies partenaires</div>
                        <div class="stat-trend up">
                            <i class="fas fa-arrow-up"></i> +12% ce mois
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="100">
                    <div class="stat-card">
                        <div class="icon-wrapper orange">
                            <i class="fas fa-capsules"></i>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['medicaments']['total'] ?? 0); ?></div>
                        <div class="stat-label">Médicaments disponibles</div>
                        <div class="stat-trend up">
                            <i class="fas fa-arrow-up"></i> +8% ce mois
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="200">
                    <div class="stat-card">
                        <div class="icon-wrapper blue">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['utilisateurs']['total'] ?? 0); ?></div>
                        <div class="stat-label">Patients inscrits</div>
                        <div class="stat-trend up">
                            <i class="fas fa-arrow-up"></i> +23% ce mois
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="300">
                    <div class="stat-card">
                        <div class="icon-wrapper purple">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['reservations']['total'] ?? 0); ?></div>
                        <div class="stat-label">Réservations confirmées</div>
                        <div class="stat-trend up">
                            <i class="fas fa-arrow-up"></i> +5% ce mois
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
    FEATURES SECTION
    ============================================================ -->
    <section class="features-section" id="features">
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <span class="section-badge green">
                    <i class="fas fa-star me-1"></i> Pourquoi PharmaGeo ?
                </span>
                <h2 class="section-title mt-3">
                    Une plateforme <span class="highlight">intelligente</span><br>
                    au service de votre santé
                </h2>
                <p class="section-subtitle">
                    Découvrez comment PharmaGeo révolutionne l'accès aux médicaments à Porto-Novo
                </p>
            </div>
            
            <div class="row g-4">
                <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="0">
                    <div class="feature-card">
                        <div class="icon-wrapper green">
                            <i class="fas fa-search"></i>
                        </div>
                        <h5 class="feature-title">Recherche intelligente</h5>
                        <p class="feature-description">
                            Trouvez vos médicaments par nom, DCI ou catégorie avec des suggestions automatiques
                        </p>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="100">
                    <div class="feature-card">
                        <div class="icon-wrapper orange">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <h5 class="feature-title">Géolocalisation précise</h5>
                        <p class="feature-description">
                            Visualisez toutes les pharmacies sur une carte interactive avec itinéraire
                        </p>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="200">
                    <div class="feature-card">
                        <div class="icon-wrapper blue">
                            <i class="fas fa-clock"></i>
                        </div>
                        <h5 class="feature-title">Temps réel</h5>
                        <p class="feature-description">
                            Consultez la disponibilité des médicaments actualisée en temps réel par les pharmaciens
                        </p>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="300">
                    <div class="feature-card">
                        <div class="icon-wrapper purple">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <h5 class="feature-title">Réservation simplifiée</h5>
                        <p class="feature-description">
                            Réservez vos médicaments en quelques clics et recevez une confirmation immédiate
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
    MÉDICAMENTS POPULAIRES
    ============================================================ -->
    <?php if (!empty($populaires)): ?>
    <section class="py-5" style="background: var(--white);">
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <span class="section-badge orange">
                    <i class="fas fa-fire me-1"></i> Les plus demandés
                </span>
                <h2 class="section-title mt-3">
                    Médicaments <span class="highlight">populaires</span>
                </h2>
                <p class="section-subtitle">
                    Les médicaments les plus recherchés par les patients à Porto-Novo
                </p>
            </div>
            
            <div class="row g-4">
                <?php foreach ($populaires as $index => $medicament): ?>
                <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="<?php echo $index * 100; ?>">
                    <div class="medicament-card">
                        <div class="card-content">
                            <div class="medicament-icon">
                                <i class="fas fa-pills"></i>
                            </div>
                            <div class="medicament-name"><?php echo htmlspecialchars($medicament['Nom_commercial']); ?></div>
                            <?php if ($medicament['DCI']): ?>
                                <div class="medicament-dci"><?php echo htmlspecialchars($medicament['DCI']); ?></div>
                            <?php endif; ?>
                            <div class="medicament-count">
                                <i class="fas fa-shopping-cart me-1"></i>
                                <?php echo $medicament['total_reservations']; ?> réservations
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ============================================================
    DERNIÈRES PHARMACIES
    ============================================================ -->
    <?php if (!empty($dernieres_pharmacies)): ?>
    <section class="py-5" style="background: var(--light-bg);">
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <span class="section-badge orange">
                    <i class="fas fa-store me-1"></i> Nouvelles officines
                </span>
                <h2 class="section-title mt-3">
                    Pharmacies <span class="highlight">partenaires</span>
                </h2>
                <p class="section-subtitle">
                    Découvrez les dernières pharmacies qui rejoignent la plateforme
                </p>
            </div>
            
            <div class="row g-4">
                <?php foreach ($dernieres_pharmacies as $index => $pharmacie): ?>
                <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="<?php echo $index * 100; ?>">
                    <div class="pharmacy-card">
                        <div class="card-body">
                            <div class="d-flex align-items-start gap-3">
                                <div class="pharmacy-avatar">
                                    <i class="fas fa-store"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="pharmacy-name"><?php echo htmlspecialchars($pharmacie['Nom']); ?></div>
                                    <div class="pharmacy-address">
                                        <i class="fas fa-map-pin me-1" style="font-size: 0.7rem;"></i>
                                        <?php echo htmlspecialchars($pharmacie['Quartier'] ?? 'Porto-Novo'); ?>
                                    </div>
                                </div>
                                <span class="pharmacy-badge">
                                    <i class="fas fa-check-circle me-1"></i>Active
                                </span>
                            </div>
                            
                            <div class="pharmacy-stats">
                                <div class="stat">
                                    <!-- Statistiques de démonstration générées aléatoirement -->
                                    <span class="stat-number"><?php echo rand(50, 200); ?></span>
                                    <span class="stat-label">Médicaments</span>
                                </div>
                                <div class="stat">
                                    <span class="stat-number"><?php echo rand(10, 50); ?></span>
                                    <span class="stat-label">Réservations</span>
                                </div>
                                <div class="stat">
                                    <span class="stat-number"><?php echo rand(4, 9); ?>/10</span>
                                    <span class="stat-label">Note</span>
                                </div>
                            </div>
                            
                            <a href="<?php echo BASE_URL; ?>pages/patient/pharmacie.php?id=<?php echo $pharmacie['Id_pharmacie']; ?>" 
                               class="btn btn-outline-custom w-100 mt-3">
                                <i class="fas fa-arrow-right me-1"></i> Voir la pharmacie
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>
                    <div class="col-lg-4 text-lg-end mt-4 mt-lg-0" data-aos="fade-left">
                        <?php if (estConnecte()): ?>
                            <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="btn-cta">
                                <i class="fas fa-search"></i> Rechercher maintenant
                            </a>
                        <?php else: ?>
                            <a href="<?php echo BASE_URL; ?>inscription.php" class="btn-cta me-2 mb-2 mb-lg-0">
                                <i class="fas fa-user-plus"></i> Créer un compte
                            </a>
                            <a href="<?php echo BASE_URL; ?>connexion.php" class="btn-cta-outline">
                                <i class="fas fa-sign-in-alt"></i> Se connecter
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
    FOOTER
    ============================================================ -->
    <footer class="footer-custom">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4">
                    <a href="<?php echo BASE_URL; ?>index.php" class="footer-brand" style="text-decoration: none;"><img class="pg-footer-logo" src="<?php echo BASE_URL; ?>assets/identite/logos/svg/pharmageo-logo-horizontal-blanc.svg" alt="PharmaGeo"></a>
                    <p class="footer-description mt-3">
                        Plateforme intelligente de géolocalisation des pharmacies et de recherche de disponibilité des médicaments à Porto-Novo.
                    </p>
                    <div class="social-links mt-3">
                        <a href="#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                        <a href="#" aria-label="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                        <a href="#" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
                        <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                    </div>
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
                <p class="mb-0">
                    &copy; <?php echo date('Y'); ?> PharmaGeo. Tous droits réservés. 
                    Conçu avec <i class="fas fa-heart" style="color: #DC2626;"></i> pour la santé à Porto-Novo.
                    <span class="d-block d-md-inline mt-1 mt-md-0">
                        <i class="fas fa-code"></i> Version 2.0
                    </span>
                </p>
            </div>
        </div>
    </footer>

    <!-- ============================================================
    BOUTON RETOUR EN HAUT
    ============================================================ -->
    <button class="back-to-top" id="backToTop" aria-label="Retourner en haut de la page">
        <i class="fas fa-arrow-up"></i>
    </button>

    <!-- ============================================================
    TOAST CONTAINER
    ============================================================ -->
    <div class="toast-container-custom" id="toastContainer" aria-live="polite"></div>

    <!-- ============================================================
    SHORTCUT HINT
    ============================================================ -->
    <div class="shortcut-hint">
        <kbd>Ctrl</kbd> + <kbd>K</kbd> Rechercher
    </div>

    <!-- ============================================================
    SCRIPTS
    ============================================================ -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
    
    <script>
        // ============================================================
        // INITIALISATION AOS
        // ============================================================
        const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        AOS.init({
            duration: prefersReducedMotion ? 0 : 800,
            once: true,
            offset: 40,
            easing: 'ease-out-cubic',
            disable: prefersReducedMotion
        });

        // ============================================================
        // INITIALISATION SWIPER - HERO SLIDER
        // ============================================================
        const heroSlider = new Swiper('#heroSlider', {
            loop: true,
            autoplay: {
                delay: 5000,
                disableOnInteraction: false,
                pauseOnMouseEnter: true
            },
            effect: 'fade',
            fadeEffect: {
                crossFade: true
            },
            speed: 1200,
            pagination: {
                el: '.swiper-pagination',
                clickable: true,
                bulletClass: 'swiper-pagination-bullet',
                bulletActiveClass: 'swiper-pagination-bullet-active'
            },
            navigation: {
                nextEl: '.swiper-button-next',
                prevEl: '.swiper-button-prev'
            },
            grabCursor: true,
            keyboard: {
                enabled: true,
                onlyInViewport: true
            },
            a11y: {
                prevSlideMessage: 'Diapositive précédente',
                nextSlideMessage: 'Diapositive suivante',
                firstSlideMessage: 'Première diapositive',
                lastSlideMessage: 'Dernière diapositive'
            }
        });

        // ============================================================
        // PARALLAXE FLUIDE DU HERO AU SCROLL
        // ============================================================
        const heroSliderEl = document.getElementById('heroSlider');
        const isCoarsePointer = window.matchMedia('(pointer: coarse)').matches;
        if (heroSliderEl && !prefersReducedMotion && !isCoarsePointer) {
            let ticking = false;
            window.addEventListener('scroll', () => {
                if (!ticking) {
                    requestAnimationFrame(() => {
                        const offset = window.pageYOffset;
                        if (offset < window.innerHeight * 1.2) {
                            heroSliderEl.style.transform = `translate3d(0, ${offset * 0.25}px, 0)`;
                        }
                        ticking = false;
                    });
                    ticking = true;
                }
            }, { passive: true });
        }

        // ============================================================
        // NAVIGATION SCROLL EFFECT
        // ============================================================
        const nav = document.getElementById('mainNav');
        const scrollProgress = document.getElementById('scrollProgress');
        const backToTop = document.getElementById('backToTop');
        let lastScroll = 0;

        window.addEventListener('scroll', () => {
            const currentScroll = window.pageYOffset;

            if (currentScroll > 50) {
                nav.classList.add('scrolled');
            } else {
                nav.classList.remove('scrolled');
            }

            const docHeight = document.documentElement.scrollHeight - window.innerHeight;
            const progress = docHeight > 0 ? (currentScroll / docHeight) * 100 : 0;
            scrollProgress.style.width = progress + '%';

            if (currentScroll > 500) {
                backToTop.classList.add('show');
            } else {
                backToTop.classList.remove('show');
            }

            lastScroll = currentScroll;
        }, { passive: true });

        backToTop.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: prefersReducedMotion ? 'auto' : 'smooth' });
        });

        // ============================================================
        // FEEDBACK RECHERCHE
        // ============================================================
        const heroSearchForm = document.getElementById('heroSearchForm');
        const heroSearchBtn = document.getElementById('heroSearchBtn');
        if (heroSearchForm && heroSearchBtn) {
            heroSearchForm.addEventListener('submit', () => {
                heroSearchBtn.querySelector('.btn-search-label').textContent = 'Recherche...';
                heroSearchBtn.querySelector('i').className = 'fas fa-spinner fa-spin';
                heroSearchBtn.style.pointerEvents = 'none';
            });
        }

        // ============================================================
        // TOAST SYSTEM
        // ============================================================
        function showToast(title, message, icon = 'fa-check-circle', color = 'var(--primary-green)') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = 'toast-custom';
            toast.style.borderLeftColor = color;
            toast.innerHTML = `
                <div class="toast-icon" style="color: ${color}">
                    <i class="fas ${icon}"></i>
                </div>
                <div class="toast-content">
                    <div class="toast-title">${title}</div>
                    <div class="toast-message">${message}</div>
                </div>
                <button class="btn-close" style="font-size: 0.7rem;" onclick="this.parentElement.remove()"></button>
            `;
            container.appendChild(toast);
            
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateX(40px)';
                    setTimeout(() => toast.remove(), 300);
                }
            }, 5000);
        }

        // ============================================================
        // SMOOTH SCROLL
        // ============================================================
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({
                        behavior: prefersReducedMotion ? 'auto' : 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // ============================================================
        // COUNTER ANIMATION
        // ============================================================
        function animateCounter(element, target, duration = 2000) {
            const startTime = performance.now();
            
            function updateCounter(currentTime) {
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3);
                const current = Math.round(eased * target);
                
                element.textContent = current.toLocaleString();
                
                if (progress < 1) {
                    requestAnimationFrame(updateCounter);
                } else {
                    element.textContent = target.toLocaleString();
                }
            }
            
            requestAnimationFrame(updateCounter);
        }

        const statNumbers = document.querySelectorAll('.stat-number');
        const statObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const target = parseInt(entry.target.textContent.replace(/\s/g, ''));
                    if (!isNaN(target) && target > 0) {
                        entry.target.textContent = '0';
                        animateCounter(entry.target, target);
                    }
                    statObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.3 });

        statNumbers.forEach(stat => statObserver.observe(stat));

        // ============================================================
        // KEYBOARD SHORTCUTS
        // ============================================================
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                const searchInput = document.querySelector('.hero-search .form-control, .search-container .form-control');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }
            if (e.altKey && e.key === 'r') {
                e.preventDefault();
                window.location.href = '<?php echo BASE_URL; ?>pages/patient/recherche.php';
            }
            if (e.altKey && e.key === 'm') {
                e.preventDefault();
                window.location.href = '<?php echo BASE_URL; ?>pages/patient/carte.php';
            }
            if (e.altKey && e.key === 'h') {
                e.preventDefault();
                window.location.href = '<?php echo BASE_URL; ?>';
            }
        });

        // ============================================================
        // RESPONSIVE TOUCH IMPROVEMENTS
        // ============================================================
        if ('ontouchstart' in window) {
            document.querySelectorAll('.stat-card, .medicament-card, .pharmacy-card, .feature-card, .testimonial-card')
                .forEach(card => {
                    card.addEventListener('touchstart', function() {
                        this.style.transform = 'scale(0.98)';
                    }, { passive: true });
                    card.addEventListener('touchend', function() {
                        this.style.transform = '';
                    }, { passive: true });
                });
        }

        // ============================================================
        // CONSOLE BRANDING
        // ============================================================
        console.log('%c PharmaGeo v2.0 ', 'background: linear-gradient(135deg, #16A34A, #166534); color: white; font-size: 22px; font-weight: bold; padding: 14px 28px; border-radius: 10px;');
        console.log('%c Plateforme de géolocalisation des pharmacies - Porto-Novo ', 'color: #64748b; font-size: 14px;');
    </script>
</body>
</html>
