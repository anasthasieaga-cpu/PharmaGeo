<?php
/**
 * PharmaGeo — Composants partagés des tableaux de bord
 * -----------------------------------------------------------------
 * Rendu commun (sidebar, en-tête, profil, notifications, boutons) utilisé
 * par TOUTES les pages de l'application : administrateur, pharmacien et
 * patient. Remplace les blocs HTML/CSS dupliqués dans chaque page.
 *
 * Principes :
 *  - aucune modification de la logique métier ni de la base de données ;
 *  - fonctionne y compris sur les pages "standalone" qui ne chargent pas
 *    includes/config.php (BASE_URL est recalculé si besoin) ;
 *  - les helpers de config.php sont utilisés uniquement s'ils existent.
 *
 * API principale
 *   pgd_debut([...])  : <head>, body, sidebar, topbar, ouverture du contenu
 *   pgd_pied([...])   : fermeture du contenu, scripts
 *   pgd_stat_carte()  : carte de statistique
 *   pgd_badge_statut(): badge de statut normalisé
 *   pgd_cellule_user(): cellule « avatar + nom »
 *   pgd_vide()        : état vide
 *   pgd_alerte()      : bandeau d'état (succès/erreur/info)
 */

if (!defined('PGD_INC_CHARGEE')) {
    define('PGD_INC_CHARGEE', true);
}

/* ============================================================
   OUTILS
   ============================================================ */

/**
 * URL de base du projet, utilisable même si config.php n'est pas chargée.
 */
function pgd_base_url()
{
    if (defined('BASE_URL')) {
        return BASE_URL;
    }

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $racine = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');

    // BASE_URL historique du projet
    $prefixe = '/PharmaGeo';

    if ($script !== '' && strpos($script, $prefixe . '/') === 0) {
        return $prefixe . '/';
    }

    return $prefixe . '/';
}

/**
 * Construit une URL absolue à partir d'un chemin relatif au projet.
 */
function pgd_url($chemin = '')
{
    $base = pgd_base_url();
    $chemin = ltrim((string) $chemin, '/');

    return $chemin === '' ? $base : $base . $chemin;
}

/**
 * Rôle de la personne connectée ('administrateur', 'pharmacien', 'patient').
 * Retombe sur le rôle déduit du script courant si la session est absente.
 */
function pgd_role()
{
    if (function_exists('getRoleUtilisateur')) {
        $role = getRoleUtilisateur();
        if (!empty($role)) {
            return $role;
        }
    }

    if (!empty($_SESSION['role'])) {
        return $_SESSION['role'];
    }

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    if (strpos($script, '/admin/') !== false) {
        return 'administrateur';
    }
    if (strpos($script, '/pharmacien/') !== false) {
        return 'pharmacien';
    }
    return 'patient';
}

/**
 * Libellé lisible du rôle.
 */
function pgd_libelle_role($role = null)
{
    $role = $role ?: pgd_role();

    switch ($role) {
        case 'administrateur':
            return 'Administrateur';
        case 'pharmacien':
            return 'Pharmacien';
        default:
            return 'Patient';
    }
}

/**
 * Initiales affichées quand aucune photo n'est disponible.
 */
function pgd_initiales($texte)
{
    $mots = preg_split('/\s+/u', trim((string) $texte), -1, PREG_SPLIT_NO_EMPTY);
    if (!$mots) {
        return '?';
    }

    if (count($mots) === 1) {
        return mb_strtoupper(mb_substr($mots[0], 0, 2));
    }

    return mb_strtoupper(mb_substr($mots[0], 0, 1) . mb_substr($mots[count($mots) - 1], 0, 1));
}

/**
 * Échappe une valeur pour un affichage HTML sûr.
 */
function pgd_e($valeur)
{
    return htmlspecialchars((string) $valeur, ENT_QUOTES, 'UTF-8');
}

/**
 * Chemin du fichier PHP courant, relatif au projet (ex: « admin/utilisateurs.php »).
 */
function pgd_page_courante()
{
    $racine = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    $fichier = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');

    if ($fichier !== '' && strpos($fichier, $racine) === 0) {
        return ltrim(substr($fichier, strlen($racine)), '/');
    }

    return basename($_SERVER['SCRIPT_NAME'] ?? '');
}

/* ============================================================
   NAVIGATION PARTAGÉE
   ============================================================ */

/**
 * Menu latéral du rôle connecté. Retourne un tableau de sections,
 * chaque section contenant des entrées {libelle, icone, url, actif, badge}.
 */
function pgd_menu($role = null)
{
    $role = $role ?: pgd_role();
    $courante = pgd_page_courante();

    $actif = function ($chemin) use ($courante) {
        $cible = ltrim(str_replace('\\', '/', $chemin), '/');
        return $courante === $cible;
    };

    $lien = function ($chemin) {
        return pgd_url($chemin);
    };

    $menu = [];

    if ($role === 'administrateur') {
        $menu['Pilotage'] = [
            ['libelle' => 'Tableau de bord', 'icone' => 'fa-gauge-high', 'url' => $lien('admin/dashboard.php'), 'actif' => $actif('admin/dashboard.php')],
            ['libelle' => 'Statistiques', 'icone' => 'fa-chart-column', 'url' => $lien('admin/statistiques.php'), 'actif' => $actif('admin/statistiques.php')],
            ['libelle' => 'Rapports', 'icone' => 'fa-file-lines', 'url' => $lien('admin/rapports.php'), 'actif' => $actif('admin/rapports.php')],
        ];
        $menu['Référentiel'] = [
            ['libelle' => 'Pharmacies', 'icone' => 'fa-store', 'url' => $lien('admin/pharmacies.php'), 'actif' => $actif('admin/pharmacies.php')],
            ['libelle' => 'Validation', 'icone' => 'fa-clipboard-check', 'url' => $lien('admin/validation.php'), 'actif' => $actif('admin/validation.php')],
            ['libelle' => 'Médicaments', 'icone' => 'fa-pills', 'url' => $lien('admin/medicaments.php'), 'actif' => $actif('admin/medicaments.php')],
            ['libelle' => 'Catégories', 'icone' => 'fa-tags', 'url' => $lien('admin/categories.php'), 'actif' => $actif('admin/categories.php')],
            ['libelle' => 'Utilisateurs', 'icone' => 'fa-users', 'url' => $lien('admin/utilisateurs.php'), 'actif' => $actif('admin/utilisateurs.php')],
        ];
        $menu['Activité'] = [
            ['libelle' => 'Réservations', 'icone' => 'fa-calendar-check', 'url' => $lien('admin/reservations.php'), 'actif' => $actif('admin/reservations.php')],
            ['libelle' => 'Journal', 'icone' => 'fa-clock-rotate-left', 'url' => $lien('admin/logs.php'), 'actif' => $actif('admin/logs.php')],
            ['libelle' => 'Notifications', 'icone' => 'fa-bell', 'url' => $lien('notifications.php'), 'actif' => $actif('notifications.php')],
        ];
        $menu['Compte'] = [
            ['libelle' => 'Paramètres', 'icone' => 'fa-gear', 'url' => $lien('admin/parametres.php'), 'actif' => $actif('admin/parametres.php')],
            ['libelle' => 'Mon profil', 'icone' => 'fa-user', 'url' => $lien('admin/profil.php'), 'actif' => $actif('admin/profil.php')],
        ];
    } elseif ($role === 'pharmacien') {
        $menu['Pilotage'] = [
            ['libelle' => 'Tableau de bord', 'icone' => 'fa-gauge-high', 'url' => $lien('pharmacien/dashboard.php'), 'actif' => $actif('pharmacien/dashboard.php')],
            ['libelle' => 'Statistiques', 'icone' => 'fa-chart-column', 'url' => $lien('pharmacien/statistiques.php'), 'actif' => $actif('pharmacien/statistiques.php')],
        ];
        $menu['Gestion'] = [
            ['libelle' => 'Ma pharmacie', 'icone' => 'fa-store', 'url' => $lien('pharmacien/pharmacie.php'), 'actif' => $actif('pharmacien/pharmacie.php')],
            ['libelle' => 'Médicaments', 'icone' => 'fa-pills', 'url' => $lien('pharmacien/medicaments.php'), 'actif' => $actif('pharmacien/medicaments.php')],
            ['libelle' => 'Stocks', 'icone' => 'fa-boxes-stacked', 'url' => $lien('pharmacien/stocks.php'), 'actif' => $actif('pharmacien/stocks.php')],
            ['libelle' => 'Réservations', 'icone' => 'fa-calendar-check', 'url' => $lien('pharmacien/reservations.php'), 'actif' => $actif('pharmacien/reservations.php')],
        ];
        $menu['Activité'] = [
            ['libelle' => 'Historique', 'icone' => 'fa-clock-rotate-left', 'url' => $lien('pharmacien/historique_stocks.php'), 'actif' => $actif('pharmacien/historique_stocks.php')],
            ['libelle' => 'Notifications', 'icone' => 'fa-bell', 'url' => $lien('notifications.php'), 'actif' => $actif('notifications.php')],
        ];
        $menu['Compte'] = [
            ['libelle' => 'Mon profil', 'icone' => 'fa-user', 'url' => $lien('pharmacien/profil.php'), 'actif' => $actif('pharmacien/profil.php')],
        ];
    } else {
        $menu['Navigation'] = [
            ['libelle' => 'Tableau de bord', 'icone' => 'fa-gauge-high', 'url' => $lien('pages/patient/dashboard.php'), 'actif' => $actif('pages/patient/dashboard.php')],
            ['libelle' => 'Rechercher', 'icone' => 'fa-magnifying-glass', 'url' => $lien('pages/patient/recherche.php'), 'actif' => $actif('pages/patient/recherche.php')],
            ['libelle' => 'Carte', 'icone' => 'fa-map-location-dot', 'url' => $lien('pages/patient/carte.php'), 'actif' => $actif('pages/patient/carte.php')],
            ['libelle' => 'Réserver', 'icone' => 'fa-calendar-plus', 'url' => $lien('pages/patient/reservation.php'), 'actif' => $actif('pages/patient/reservation.php')],
        ];
        $menu['Activité'] = [
            ['libelle' => 'Historique', 'icone' => 'fa-clock-rotate-left', 'url' => $lien('pages/patient/historique.php'), 'actif' => $actif('pages/patient/historique.php')],
            ['libelle' => 'Notifications', 'icone' => 'fa-bell', 'url' => $lien('notifications.php'), 'actif' => $actif('notifications.php')],
        ];
        $menu['Compte'] = [
            ['libelle' => 'Mon profil', 'icone' => 'fa-user', 'url' => $lien('pages/patient/profil.php'), 'actif' => $actif('pages/patient/profil.php')],
        ];
    }

    return $menu;
}

/* ============================================================
   COMPOSANTS VISUELS
   ============================================================ */

/**
 * Avatar (photo si disponible, sinon initiales).
 */
function pgd_avatar($photo, $nom, $type = 'pharmacien', $classes = '')
{
    $url = function_exists('pharma_image_url_ou_defaut')
        ? pharma_image_url_ou_defaut($photo, $type)
        : pgd_url('assets/images/' . ($type === 'pharmacie' ? 'pharmacie-defaut.svg' : 'pharmacien-defaut.svg'));

    if (function_exists('pharma_image_disponible') && pharma_image_disponible($photo)) {
        return '<span class="' . pgd_e($classes) . '"><img src="' . pgd_e($url) . '" alt="' . pgd_e($nom) . '"></span>';
    }

    return '<span class="' . pgd_e($classes) . '">' . pgd_e(pgd_initiales($nom)) . '</span>';
}

/**
 * Badge de statut normalisé à partir des libellés de la base.
 */
function pgd_badge_statut($statut)
{
    $statut = (string) $statut;
    $config = [
        'active' => ['Actif', 'success'],
        'actif' => ['Actif', 'success'],
        'valide' => ['Validé', 'success'],
        'validee' => ['Validée', 'success'],
        'confirmee' => ['Confirmée', 'success'],
        'confirme' => ['Confirmé', 'success'],
        'retiree' => ['Retirée', 'info'],
        'retire' => ['Retiré', 'info'],
        'en_attente' => ['En attente', 'warning'],
        'pending' => ['En attente', 'warning'],
        'suspendue' => ['Suspendue', 'danger'],
        'suspendu' => ['Suspendu', 'danger'],
        'refusee' => ['Refusée', 'danger'],
        'refuse' => ['Refusé', 'danger'],
        'rejete' => ['Rejeté', 'danger'],
        'annulee' => ['Annulée', 'neutral'],
        'annule' => ['Annulé', 'neutral'],
        'expiree' => ['Expirée', 'neutral'],
        'expire' => ['Expiré', 'neutral'],
        'terminee' => ['Terminée', 'neutral'],
    ];

    $cle = strtolower($statut);
    [$libelle, $ton] = $config[$cle] ?? [ucfirst(str_replace('_', ' ', $statut)), 'neutral'];

    return '<span class="pgd-badge pgd-badge--' . $ton . '">' . pgd_e($libelle) . '</span>';
}

/**
 * Cellule « avatar + nom (+ détail) » réutilisée dans tous les tableaux.
 */
function pgd_cellule_user($photo, $nom, $detail = '', $type = 'pharmacien')
{
    $avatar = pgd_avatar($photo, $nom, $type, 'pgd-cell-user__avatar');
    $detailHtml = ($detail !== '' && $detail !== null)
        ? '<small>' . pgd_e($detail) . '</small>'
        : '';

    return '<div class="pgd-cell-user">' . $avatar
        . '<div class="pgd-cell-user__text"><strong>' . pgd_e($nom) . '</strong>' . $detailHtml . '</div></div>';
}

/**
 * Carte de statistique.
 * Options : libelle, valeur, icone, ton, delta, delta_ton, lien, extra.
 */
function pgd_stat_carte(array $o = [])
{
    $libelle = $o['libelle'] ?? '';
    $valeur = $o['valeur'] ?? '—';
    $icone = $o['icone'] ?? 'fa-chart-simple';
    $ton = $o['ton'] ?? 'green';
    $delta = $o['delta'] ?? '';
    $deltaTon = $o['delta_ton'] ?? '';
    $lien = $o['lien'] ?? '';

    $html = '<div class="pgd-stat' . (isset($o['classes']) ? ' ' . pgd_e($o['classes']) : '') . '">';
    $html .= '<div class="pgd-stat__head"><div class="pgd-stat__label">' . pgd_e($libelle) . '</div>';
    $html .= '<div class="pgd-stat__icon pgd-stat__icon--' . pgd_e($ton) . '"><i class="fas ' . pgd_e($icone) . '"></i></div></div>';
    $html .= '<div class="pgd-stat__value">' . $valeur . '</div>';

    if ($delta !== '') {
        $html .= '<span class="pgd-stat__delta' . ($deltaTon ? ' pgd-stat__delta--' . pgd_e($deltaTon) : '') . '">' . $delta . '</span>';
    }

    if (isset($o['extra']) && $o['extra'] !== '') {
        $html .= $o['extra'];
    }

    $html .= '</div>';

    if ($lien !== '') {
        $html = '<a href="' . pgd_e($lien) . '" style="display:block;color:inherit">' . $html . '</a>';
    }

    return $html;
}

/**
 * État vide illustré.
 */
function pgd_vide($titre, $message = '', $icone = 'fa-inbox', $action = '')
{
    $html = '<div class="pgd-empty">';
    $html .= '<div class="pgd-empty__icon"><i class="fas ' . pgd_e($icone) . '"></i></div>';
    $html .= '<h5>' . pgd_e($titre) . '</h5>';
    if ($message !== '') {
        $html .= '<p>' . pgd_e($message) . '</p>';
    }
    if ($action !== '') {
        $html .= $action;
    }
    $html .= '</div>';

    return $html;
}

/**
 * Bandeau d'état.
 */
function pgd_alerte($message, $ton = 'info', $icone = '')
{
    $icones = ['success' => 'fa-circle-check', 'warning' => 'fa-triangle-exclamation', 'danger' => 'fa-circle-exclamation', 'info' => 'fa-circle-info'];
    $icone = $icone ?: ($icones[$ton] ?? 'fa-circle-info');

    return '<div class="pgd-alert pgd-alert--' . pgd_e($ton) . '"><i class="fas ' . pgd_e($icone) . '"></i><div>' . $message . '</div></div>';
}

/* ============================================================
   STRUCTURE DE PAGE
   ============================================================ */

/**
 * Ouverture complète d'une page de tableau de bord.
 *
 * Options :
 *   titre, sous_titre  : en-tête de page
 *   actions            : HTML des boutons à droite de l'en-tête
 *   recherche          : '' (aucune) ou array{url, placeholder, valeur}
 *   notifications      : nombre de notifications non lues (null = calculé)
 *   extra_css          : tableau de feuilles de style supplémentaires
 *   role               : force le rôle (détection automatique sinon)
 *   datetime           : booléen, affiche la date du jour
 */
function pgd_debut(array $o = [])
{
    $role = $o['role'] ?? pgd_role();
    $titre = $o['titre'] ?? 'Tableau de bord';
    $sousTitre = $o['sous_titre'] ?? '';
    $base = pgd_base_url();

    echo '<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . pgd_e($titre) . ' | PharmaGeo</title>
<meta name="theme-color" content="#16A34A">
<meta property="og:image" content="' . pgd_e($base) . 'assets/identite/reseaux-sociaux/og-image-1200x630.png">
<link rel="icon" href="' . pgd_e($base) . 'assets/identite/favicons/favicon.ico" sizes="any">
<link rel="icon" type="image/svg+xml" href="' . pgd_e($base) . 'assets/identite/favicons/favicon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="' . pgd_e($base) . 'assets/identite/favicons/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="' . pgd_e($base) . 'assets/identite/favicons/favicon-16x16.png">
<link rel="apple-touch-icon" sizes="180x180" href="' . pgd_e($base) . 'assets/identite/favicons/apple-touch-icon.png">
<link rel="manifest" href="' . pgd_e($base) . 'assets/identite/favicons/site.webmanifest">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="' . pgd_e($base) . 'assets/css/palette.css">
<link rel="stylesheet" href="' . pgd_e($base) . 'assets/css/dashboard.css">';

    foreach (($o['extra_css'] ?? []) as $feuille) {
        echo "\n<link rel=\"stylesheet\" href=\"" . pgd_e($feuille) . '">';
    }

    if (!empty($o['chart'])) {
        echo "\n<script src=\"https://cdn.jsdelivr.net/npm/chart.js\"></script>";
    }

    echo '
</head>
<body class="pgd-body">';

    $sidebar = $o['sidebar'] ?? [];
    if (!empty($o['sidebar_badges'])) {
        $sidebar['badges'] = $o['sidebar_badges'];
    }
    if (!empty($o['sidebar_extra'])) {
        $sidebar['extra'] = $o['sidebar_extra'];
    }

    pgd_sidebar(['role' => $role] + $sidebar);

    echo '<div class="pgd-overlay" id="sidebarOverlay" hidden-aria="true"></div>';

    echo '<div class="pgd-main">';

    pgd_topbar($o);
}

/**
 * Menu latéral + carte utilisateur.
 */
function pgd_sidebar(array $o = [])
{
    $role = $o['role'] ?? pgd_role();
    $menu = pgd_menu($role);

    $nom = $o['nom'] ?? ($_SESSION['nom'] ?? $_SESSION['Nom'] ?? '');
    $prenom = $o['prenom'] ?? ($_SESSION['prenom'] ?? $_SESSION['Prenom'] ?? '');
    $photo = $o['photo'] ?? ($_SESSION['photo'] ?? $_SESSION['photo_profil'] ?? ($_SESSION['Photo'] ?? ''));
    $typeAvatar = $role === 'pharmacien' ? 'pharmacien' : 'pharmacien';
    $identite = trim($prenom . ' ' . $nom);
    if ($identite === '') {
        $identite = pgd_libelle_role($role);
    }

    echo '<aside class="pgd-sidebar" id="sidebar" aria-label="Navigation principale">';
    echo '<a href="' . pgd_e(pgd_url(pgd_role() === 'patient' ? 'pages/patient/dashboard.php' : ($role === 'administrateur' ? 'admin/dashboard.php' : 'pharmacien/dashboard.php'))) . '" class="pgd-brand">';
    echo '<span class="pgd-brand__icon"><img class="pg-brand-logo" src="' . pgd_e(pgd_url('assets/identite/icones/pharmageo-icone-blanc.svg')) . '" alt=""></span>';
    echo '<span class="pgd-brand__text"><h4>PharmaGeo</h4><small>' . pgd_e(pgd_libelle_role($role)) . '</small></span>';
    echo '</a>';

    echo '<nav class="pgd-nav">';

    foreach ($menu as $section => $entrees) {
        echo '<div class="pgd-nav__label">' . pgd_e($section) . '</div>';
        foreach ($entrees as $e) {
            $classes = 'pgd-nav__item' . (!empty($e['actif']) ? ' is-active' : '');
            $chemin = preg_replace('#^' . preg_quote(pgd_base_url(), '#') . '#', '', $e['url']);
            $valeur = $o['badges'][$chemin] ?? ($e['badge'] ?? '');
            $badge = '';
            if ($valeur !== '' && (int) $valeur > 0) {
                $badge = '<span class="pgd-nav__badge' . (($e['badge_doux'] ?? false) ? ' pgd-nav__badge--soft' : '') . '">' . (int) $valeur . '</span>';
            }
            echo '<a href="' . pgd_e($e['url']) . '" class="' . $classes . '">'
                . '<i class="fas ' . pgd_e($e['icone']) . '"></i>'
                . '<span>' . pgd_e($e['libelle']) . '</span>' . $badge . '</a>';
        }
        echo '<div class="pgd-nav__divider"></div>';
    }

    if (!empty($o['extra'])) {
        echo $o['extra'];
    }

    $classes = 'pgd-nav__item pgd-nav__item--logout';
    echo '<a href="' . pgd_e(pgd_url('deconnexion.php')) . '" class="' . $classes . '">'
        . '<i class="fas fa-arrow-right-from-bracket"></i><span>Déconnexion</span></a>';

    echo '</nav>';

    echo '<a href="' . pgd_e(pgd_url($role === 'administrateur' ? 'admin/profil.php' : ($role === 'pharmacien' ? 'pharmacien/profil.php' : 'pages/patient/profil.php'))) . '" class="pgd-user">';
    echo pgd_avatar($photo, $identite, $typeAvatar, 'pgd-user__avatar');
    echo '<span class="pgd-user__info"><span class="pgd-user__name">' . pgd_e($identite) . '</span>'
        . '<span class="pgd-user__role"><i class="fas fa-circle" style="font-size:.35rem;color:#4ADE80"></i>'
        . pgd_e(pgd_libelle_role($role)) . '</span></span>';
    echo '<i class="fas fa-chevron-right pgd-user__chevron"></i>';
    echo '</a>';

    echo '</aside>';
}

/**
 * En-tête : titre, recherche, date, notifications, menu profil.
 */
function pgd_topbar(array $o = [])
{
    $role = $o['role'] ?? pgd_role();
    $titre = $o['titre'] ?? 'Tableau de bord';
    $sousTitre = $o['sous_titre'] ?? '';

    $nom = $o['nom'] ?? ($_SESSION['nom'] ?? $_SESSION['Nom'] ?? '');
    $prenom = $o['prenom'] ?? ($_SESSION['prenom'] ?? $_SESSION['Prenom'] ?? '');
    $photo = $o['photo'] ?? ($_SESSION['photo'] ?? $_SESSION['photo_profil'] ?? ($_SESSION['Photo'] ?? ''));
    $identite = trim($prenom . ' ' . $nom);
    if ($identite === '') {
        $identite = pgd_libelle_role($role);
    }

    $notifications = array_key_exists('notifications', $o) ? (int) $o['notifications'] : pgd_notifications_non_lues();

    echo '<header class="pgd-topbar">';

    echo '<div class="pgd-topbar__lead">';
    echo '<button type="button" class="pgd-burger" id="toggleSidebar" aria-label="Ouvrir le menu" aria-controls="sidebar" aria-expanded="false"><i class="fas fa-bars"></i></button>';
    echo '<div class="pgd-topbar__title"><h1>' . $titre . '</h1>';
    if ($sousTitre !== '') {
        echo '<p>' . pgd_e($sousTitre) . '</p>';
    }
    echo '</div></div>';

    echo '<div class="pgd-topbar__tools">';

    if (!empty($o['recherche']) && is_array($o['recherche'])) {
        $recherche = $o['recherche'];
        echo '<form class="pgd-search" method="get" action="' . pgd_e($recherche['url'] ?? pgd_url('')) . '" role="search">';
        echo '<i class="fas fa-magnifying-glass"></i>';
        echo '<input type="search" name="' . pgd_e($recherche['champ'] ?? 'q') . '" placeholder="'
            . pgd_e($recherche['placeholder'] ?? 'Rechercher…') . '" value="'
            . pgd_e($recherche['valeur'] ?? '') . '" aria-label="Rechercher">';
        echo '</form>';
    }

    if (!empty($o['datetime'])) {
        echo '<div class="pgd-datetime"><i class="far fa-calendar"></i>'
            . date('d/m/Y') . '</div>';
    }

    echo '<a href="' . pgd_e(pgd_url('notifications.php')) . '" class="pgd-bell" aria-label="Notifications">';
    echo '<i class="far fa-bell"></i>';
    if ($notifications > 0) {
        echo '<span class="pgd-bell__count">' . ($notifications > 99 ? '99+' : $notifications) . '</span>';
    }
    echo '</a>';

    if (!empty($o['actions'])) {
        echo $o['actions'];
    }

    echo '<div class="pgd-profile" id="pgdProfile">';
    echo '<button type="button" class="pgd-profile__toggle" id="pgdProfileToggle" aria-haspopup="true" aria-expanded="false">';
    echo pgd_avatar($photo, $identite, 'pharmacien', 'pgd-profile__avatar');
    echo '<span class="pgd-profile__id"><strong>' . pgd_e($identite) . '</strong>'
        . '<span>' . pgd_e(pgd_libelle_role($role)) . '</span></span>';
    echo '<i class="fas fa-chevron-down" style="font-size:.7rem;color:var(--pg-text-muted)"></i>';
    echo '</button>';
    echo '<div class="pgd-profile__menu" role="menu">';
    echo '<div class="pgd-profile__head"><strong>' . pgd_e($identite) . '</strong><span>'
        . pgd_e(pgd_libelle_role($role)) . '</span></div>';
    echo '<a class="pgd-profile__item" role="menuitem" href="' . pgd_e(pgd_url('notifications.php')) . '"><i class="far fa-bell"></i>Notifications</a>';
    echo '<a class="pgd-profile__item" role="menuitem" href="' . pgd_e(pgd_url($role === 'administrateur' ? 'admin/profil.php' : ($role === 'pharmacien' ? 'pharmacien/profil.php' : 'pages/patient/profil.php'))) . '"><i class="fas fa-user"></i>Mon profil</a>';
    echo '<a class="pgd-profile__item" role="menuitem" href="' . pgd_e(pgd_url($role === 'administrateur' ? 'admin/parametres.php' : 'pages/patient/profil.php')) . '"><i class="fas fa-gear"></i>Paramètres</a>';
    echo '<div class="pgd-nav__divider" style="margin:6px 8px;background:var(--pg-border)"></div>';
    echo '<a class="pgd-profile__item pgd-profile__item--danger" role="menuitem" href="' . pgd_e(pgd_url('deconnexion.php')) . '"><i class="fas fa-arrow-right-from-bracket"></i>Déconnexion</a>';
    echo '</div></div>';

    echo '</div></header>';
}

/**
 * Nombre de notifications non lues. Silencieux en cas d'erreur (0).
 */
function pgd_notifications_non_lues()
{
    if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
        return 0;
    }

    $id = function_exists('getIdUtilisateur') ? getIdUtilisateur() : ($_SESSION['user_id'] ?? null);
    if (empty($id)) {
        return 0;
    }

    try {
        $stmt = $GLOBALS['pdo']->prepare('SELECT COUNT(*) FROM notification WHERE Id_utilisateur = ? AND Lu = 0');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Fermeture de la page : scripts partagés puis fin du document.
 */
function pgd_pied(array $o = [])
{
    echo '</div>'; // .pgd-main
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>';
    echo '<script src="' . pgd_e(pgd_base_url()) . 'assets/js/dashboard.js"></script>';

    if (!empty($o['scripts'])) {
        echo $o['scripts'];
    }

    echo '</body></html>';
}
