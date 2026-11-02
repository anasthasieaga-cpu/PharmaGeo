/**
 * PharmaGeo — Menus déroulants de la barre de navigation.
 *
 * Ces menus (bouton profil, cloche des notifications) sont pilotés
 * par ce script et non par Bootstrap. Motif : le bouton profil doit
 * toujours donner accès au tableau de bord et à la déconnexion,
 * même si la bibliothèque JavaScript de Bootstrap n'est pas chargée
 * — CDN inaccessible, coupure réseau, proxy, cache. Seule la mise
 * en forme continue d'être assurée par la feuille de style Bootstrap.
 */
(function () {
    'use strict';

    function menuAssocie(bouton) {
        var parId = bouton.getAttribute('aria-labelledby');
        if (parId) {
            var id = parId.replace(/^#/, '');
            if (id) {
                var menu = document.querySelector('#' + id + ' + .dropdown-menu');
                if (menu) return menu;
            }
        }
        if (!bouton.parentElement) return null;
        return bouton.parentElement.querySelector('.dropdown-menu');
    }

    function majEtat(bouton, ouvert) {
        bouton.setAttribute('aria-expanded', ouvert ? 'true' : 'false');
        bouton.classList.toggle('show', ouvert);
    }

    // Bootstrap positionne normalement ces menus avec Popper. Comme ce
    // script s'en charge seul, on ancre le menu sur son bouton pour
    // qu'il apparaisse bien dessous, et on gère l'affichage.
    function preparer(bouton, menu) {
        var parent = bouton.parentElement;
        if (parent && window.getComputedStyle(parent).position === 'static') {
            parent.style.position = 'relative';
        }
        menu.style.position = 'absolute';
        menu.style.top = '100%';
        menu.style.right = '0';
        menu.style.left = 'auto';
        menu.style.transform = 'none';
        menu.style.marginTop = '0.125rem';
        menu.style.display = 'none';
    }

    function ouvrir(entree) {
        entree.menu.style.display = 'block';
        entree.menu.classList.add('show');
        majEtat(entree.bouton, true);
    }

    function fermer(entree) {
        entree.menu.classList.remove('show');
        entree.menu.style.display = 'none';
        majEtat(entree.bouton, false);
    }

    function installer() {
        var boutons = document.querySelectorAll('[data-navbar-dropdown]');
        if (!boutons.length) return;

        var ouverts = [];

        function tousFermer() {
            ouverts.forEach(fermer);
            ouverts = [];
        }

        boutons.forEach(function (bouton) {
            var menu = menuAssocie(bouton);
            if (!menu) return;

            preparer(bouton, menu);

            bouton.addEventListener('click', function (event) {
                // Empêche la navigation vers le lien de repli : c'est
                // le menu qui doit s'ouvrir au clic.
                event.preventDefault();
                event.stopPropagation();

                var etaitOuvert = menu.classList.contains('show');

                tousFermer();

                if (etaitOuvert) return;

                ouvrir({ menu: menu, bouton: bouton });
                ouverts.push({ menu: menu, bouton: bouton });
            });

            // La touche Entrée doit aussi ouvrir le menu.
            bouton.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                if (event.target !== bouton) return;
                event.preventDefault();
                bouton.click();
            });

            // Un clic sur un lien du menu doit le laisser jouer son rôle,
            // tout en refermant le menu.
            menu.addEventListener('click', function (event) {
                if (event.target.closest('a')) tousFermer();
            });
        });

        // Clic en dehors : ferme le menu ouvert.
        document.addEventListener('click', function (event) {
            if (!ouverts.length) return;
            var dedans = ouverts.some(function (entree) {
                return entree.menu.contains(event.target) || entree.bouton.contains(event.target);
            });
            if (!dedans) tousFermer();
        });

        // Touche Échap : ferme et rend le focus au bouton.
        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape' || !ouverts.length) return;
            var dernier = ouverts[ouverts.length - 1];
            tousFermer();
            dernier.bouton.focus();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', installer);
    } else {
        installer();
    }
})();