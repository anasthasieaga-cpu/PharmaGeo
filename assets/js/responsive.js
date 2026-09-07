/**
 * PharmaGeo - Améliorations responsives globales
 *
 * - Ajoute automatiquement un tiroir mobile (menu latéral) sur les pages
 *   ".sidebar-admin" qui ne disposent pas de bouton d'ouverture natif.
 * - Gère l'overlay, la fermeture (clic overlay, Échap, lien cliqué).
 */

(function () {
    'use strict';

    var MOBILE_BREAKPOINT = '(max-width: 991px)';

    // Exécute fn dès que le DOM est prêt
    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    // Limite la fréquence d'appel de fn (au plus une fois par "limit" ms)
    function throttle(fn, limit) {
        var last = 0;
        return function () {
            var now = Date.now();
            if (now - last >= limit) {
                last = now;
                fn();
            }
        };
    }

    // Referme le tiroir mobile (sidebar, overlay et bouton)
    function closeDrawer() {
        var sidebar = document.querySelector('.sidebar-admin');
        if (sidebar && sidebar.classList.contains('open')) {
            sidebar.classList.remove('open');
        }
        var overlay = document.getElementById('sidebarOverlay');
        if (overlay) {
            overlay.classList.remove('active');
        }
        var fab = document.getElementById('sidebarToggleFab');
        if (fab) {
            fab.classList.remove('active');
            if (fab.querySelector('.fa-times')) {
                fab.innerHTML = '<i class="fas fa-bars" aria-hidden="true"></i>';
            }
        }
    }

    // Crée le tiroir mobile et son overlay s'ils n'existent pas déjà
    function ensureDrawer() {
        var sidebar = document.querySelector('.sidebar-admin');
        if (!sidebar) {
            return;
        }

        // Une page équipée de son propre bouton/overlay n'a pas besoin d'aide.
        if (document.getElementById('toggleSidebar')) {
            return;
        }

        sidebar.id = sidebar.id || 'sidebar';

        // Overlay
        var overlay = document.getElementById('sidebarOverlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'sidebarOverlay';
            overlay.className = 'sidebar-overlay';
            document.body.appendChild(overlay);
        }

        // Bouton flottant
        var fab = document.getElementById('sidebarToggleFab');
        if (!fab) {
            fab = document.createElement('button');
            fab.id = 'sidebarToggleFab';
            fab.type = 'button';
            fab.setAttribute('aria-label', 'Ouvrir le menu de navigation');
            fab.innerHTML = '<i class="fas fa-bars" aria-hidden="true"></i>';
            document.body.appendChild(fab);
        }

        function setOpen(open) {
            sidebar.classList.toggle('open', open);
            overlay.classList.toggle('active', open);
            fab.classList.toggle('active', open);
            fab.innerHTML = open
                ? '<i class="fas fa-times" aria-hidden="true"></i>'
                : '<i class="fas fa-bars" aria-hidden="true"></i>';
        }

        fab.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            setOpen(!sidebar.classList.contains('open'));
        });

        overlay.addEventListener('click', closeDrawer);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeDrawer();
            }
        });

        // Fermer après une navigation depuis le tiroir
        sidebar.addEventListener('click', function (e) {
            if (e.target.closest('.nav-item')) {
                closeDrawer();
            }
        });
    }

    // Active le tiroir uniquement si la fenêtre est en largeur mobile
    function runForCurrentWidth() {
        if (window.matchMedia && window.matchMedia(MOBILE_BREAKPOINT).matches) {
            ensureDrawer();
        }
    }

    ready(function () {
        runForCurrentWidth();
        window.addEventListener('resize', throttle(runForCurrentWidth, 200));
    });
})();