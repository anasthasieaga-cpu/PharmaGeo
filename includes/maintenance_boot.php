<?php
// ============================================================
// PharmaGeo — Bootstrap du mode maintenance
// Fichier exécuté automatiquement avant chaque page PHP grâce à
// la directive `php_value auto_prepend_file` du .htaccess racine.
// ============================================================

require_once __DIR__ . '/maintenance.php';

bloquer_si_maintenance();