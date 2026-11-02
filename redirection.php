<?php
// ============================================================
// PharmaGeo — Redirection du menu du compte
//
// La balise <select> du compte envoie son choix ici (?vers=...).
// On ne construit jamais une URL à partir de la requête : chaque
// choix correspond à une page autorisée, vérifiée selon le rôle.
// ============================================================

require_once 'includes/config.php';
require_once 'includes/session.php';

// Chaque entrée est une destination interne au projet. Rien d'autre
// n'est accepté, donc impossible de détourner la redirection.
$destinations = array(
    'dashboard'     => lienDashboard(),
    'profil'        => lienProfil(),
    'historique'    => lienHistorique(),
    'notifications' => lienNotifications(),
    'deconnexion'   => 'deconnexion.php',
);

// Ces deux entrées sont réservées aux rôles concernés.
if (aRole('pharmacien')) {
    $destinations['officine'] = 'pharmacien/dashboard.php';
}
if (aRole('administrateur')) {
    $destinations['administration'] = 'admin/dashboard.php';
}

$choix = isset($_GET['vers']) ? (string)$_GET['vers'] : '';

if (!isset($destinations[$choix])) {
    // Choix inconnu ou absent : on renvoie vers l'accueil.
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

header('Location: ' . BASE_URL . $destinations[$choix]);
exit;