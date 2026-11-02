<?php
// ============================================================
// PharmaGeo — Aides à la réinitialisation de la base de données
// ------------------------------------------------------------
// Ce fichier définit les fonctions d'aide utilisées par le
// point d'entrée api/reinitialiser.php et par le bloc affiché
// dans les sidebars des zones admin et pharmacien.
//
// Deux niveaux :
//   donnees → vide les données métier (médicaments, stocks,
//             réservations, notifications, historique, journaux)
//             en conservant les comptes.
//   total   → vide toute la base (données métier ET comptes).
//
// Toute réinitialisation est précédée d'une confirmation par
// saisie du mot de sécurité (REINIT_CODE_CONFIRMATION).
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('REINIT_CODE_CONFIRMATION')) {
    define('REINIT_CODE_CONFIRMATION', 'RESET');
}

// Base URL (attendues par le projet : '.../pharmageo/')
function reinitialiser_base_url() {
    if (defined('BASE_URL')) {
        return BASE_URL;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $racine = rtrim(dirname(dirname($script)), '/');
    return $scheme . '://' . $host . $racine . '/';
}

// Vide les tables ciblées selon le niveau demandé
function reinitialiser_executer($niveau) {
    $pdo = new PDO("mysql:host=localhost;dbname=gestion_soutenance;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $tables = [
        'reservation',
        'notification',
        'recherche',
        'historique_stock',
        'stock',
        'medicament_synonyme',
        'medicament',
        'jour_permanence',
        'log',
        'password_reset'
    ];

    if ($niveau === 'total') {
        $tables = array_merge($tables, ['pharmacie', 'pharmacien', 'categorie', 'utilisateur']);
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ($tables as $table) {
            $pdo->exec('TRUNCATE TABLE `' . $table . '`');
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}

// Bloc affiché dans les sidebars (deux boutons + confirmations)
function html_bloc_reinitialiser() {
    $base = reinitialiser_base_url();
    $endpoint = $base . 'api/reinitialiser.php';

    return '
        <style>
        .nav-item.reinit-nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            box-sizing: border-box;
            width: 80%;
            margin: 6px auto;
            padding: 11px 14px;
            border-radius: 10px;
            font-size: 0.88rem;
            font-weight: 600;
            line-height: 1.3;
            color: #fff;
            text-align: left;
            text-decoration: none !important;
            cursor: pointer;
            transition: all 0.25s ease;
        }
        .nav-label.reinit-nav-label {
            box-sizing: border-box;
            width: 80%;
            margin: 20px auto 6px;
            padding: 12px 0 6px;
            color: rgba(255,255,255,0.4);
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-align: center;
        }
        .nav-item.reinit-nav-item i {
            width: 20px;
            flex: 0 0 20px;
            text-align: center;
        }
        .nav-item.reinit-nav-item span { flex: 1; }
        .nav-item.reinit-nav-item:hover {
            color: #fff;
            transform: translateX(3px);
        }
        .nav-item.reinit-nav-item.reinit-donnees {
            background: rgba(200,150,0,0.16) !important;
            border: 1px solid rgba(200,150,0,0.40);
        }
        .nav-item.reinit-nav-item.reinit-donnees:hover {
            background: rgba(200,150,0,0.28) !important;
        }
        .nav-item.reinit-nav-item.reinit-total {
            background: rgba(220,38,38,0.16) !important;
            border: 1px solid rgba(220,38,38,0.45);
        }
        .nav-item.reinit-nav-item.reinit-total:hover {
            background: rgba(220,38,38,0.30) !important;
        }
        </style>
        <div class="nav-label reinit-nav-label">Base de données</div>
        <a href="#" class="nav-item reinit-nav-item reinit-donnees"
           onclick="return confirmerReinitialiser(\'donnees\')"
           title="Vider les données métier (médicaments, stocks, réservations, notifications, historique, journaux). Les comptes sont conservés.">
            <i class="fas fa-broom"></i><span>Réinitialiser les données</span>
        </a>
        <a href="#" class="nav-item reinit-nav-item reinit-total"
           onclick="return confirmerReinitialiser(\'total\')"
           title="Vider TOUTE la base de données (données métier ET comptes : utilisateurs, pharmacies, pharmaciens). Vous serez déconnecté(e).">
            <i class="fas fa-database"></i><span>Tout réinitialiser</span>
        </a>
        <script>
        function confirmerReinitialiser(niveau) {
            if (event) event.preventDefault();
            var estTotal = niveau === "total";
            var codeAttendu = "' . REINIT_CODE_CONFIRMATION . '";
            var description = estTotal
                ? "TOUTE la base de données sera vidée : données métier ET comptes (utilisateurs, pharmacies, pharmaciens).\nVous serez déconnecté(e)."
                : "Les données métier seront vidées : médicaments, stocks, réservations, notifications, historique et journaux.\nLes comptes seront conservés.";
            var saisi = window.prompt("Avertissement - " + (estTotal ? "Tout réinitialiser" : "Réinitialiser les données") + "\n\n" + description + "\n\nTapez le mot de confirmation : " + codeAttendu, "");
            if (saisi === null) return false;
            if (saisi.trim().toUpperCase() !== codeAttendu.toUpperCase()) {
                window.alert("Mot de confirmation incorrect. Réinitialisation annulée.");
                return false;
            }
            window.location.href = "' . $endpoint . '?niveau=" + niveau + "&code=" + encodeURIComponent(saisi.trim());
            return false;
        }
        document.addEventListener("DOMContentLoaded", function() {
            var reset = new URLSearchParams(window.location.search).get("reset");
            if (!reset) return;
            var messages = {
                "donnees": "Base de données réinitialisée : données métier vidées, comptes conservés.",
                "code_incorrect": "Réinitialisation annulée : mot de confirmation incorrect.",
                "erreur": "Erreur lors de la réinitialisation."
            };
            if (!messages[reset]) return;
            var b = document.createElement("div");
            b.style.cssText = "position:fixed;top:16px;left:50%;transform:translateX(-50%);z-index:99999;padding:14px 24px;border-radius:10px;font-weight:600;font-size:0.95rem;color:#fff;box-shadow:0 8px 30px rgba(0,0,0,0.25);" + (reset === "donnees" ? "background:#16A34A;" : "background:#DC2626;");
            b.textContent = messages[reset];
            document.body.appendChild(b);
            setTimeout(function() { if (b.parentNode) b.parentNode.removeChild(b); }, 6000);
        });
        </script>';
}