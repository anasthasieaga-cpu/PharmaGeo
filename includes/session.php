<?php
// ============================================================
// PharmaGeo — Gestion des sessions
// Démarre la session PHP une seule fois et prépare la logique
// liée à la session (expiration, régénération d'ID, etc.).
// ============================================================

// ===== GESTION DES SESSIONS =====
// Ce fichier doit toujours être inclus APRÈS includes/config.php
// (config.php doit être chargé en premier pour définir les constantes/fonctions utilitaires).

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Espace disponible pour toute logique supplémentaire liée à la session
// (ex: régénération d'ID de session, expiration automatique, etc.)