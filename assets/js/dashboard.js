/**
 * PharmaGeo — Interactions des tableaux de bord
 * -----------------------------------------------------------------
 * Chargé sur toutes les pages (assets/js/dashboard.js).
 * Ne dépend que du DOM : aucune logique métier.
 */
(function () {
    'use strict';

    var DOC = document;
    var BODY = DOC.body;

    /* ---------------------------------------------------------
       Menu latéral (tiroir mobile)
       --------------------------------------------------------- */
    function sidebar() {
        var panneau = DOC.getElementById('sidebar') || DOC.querySelector('.pgd-sidebar, .sidebar, .sidebar-admin');
        var bouton = DOC.getElementById('toggleSidebar') || DOC.querySelector('.toggle-sidebar');
        var voile = DOC.getElementById('sidebarOverlay') || DOC.querySelector('.pgd-overlay, .sidebar-overlay');

        if (!panneau || !bouton) {
            return;
        }

        function estOuvert() {
            return panneau.classList.contains('is-open') || panneau.classList.contains('open');
        }

        function ouvrir() {
            panneau.classList.add('is-open', 'open');
            if (voile) {
                voile.hidden = false;
                // Force un reflow pour que la transition CSS s'applique.
                void voile.offsetWidth;
                voile.classList.add('active');
            }
            bouton.setAttribute('aria-expanded', 'true');
            BODY.classList.add('pgd-sidebar-open');
            BODY.style.overflow = 'hidden';
        }

        function fermer() {
            panneau.classList.remove('is-open', 'open');
            if (voile) {
                voile.classList.remove('active');
                var fin = function () { voile.hidden = true; };
                if (voile.addEventListener) {
                    voile.addEventListener('transitionend', fin, { once: true });
                } else {
                    fin();
                }
            }
            bouton.setAttribute('aria-expanded', 'false');
            BODY.classList.remove('pgd-sidebar-open');
            BODY.style.overflow = '';
        }

        function basculer() {
            estOuvert() ? fermer() : ouvrir();
        }

        bouton.addEventListener('click', basculer);

        if (voile) {
            voile.addEventListener('click', fermer);
        }

        DOC.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && estOuvert()) {
                fermer();
            }
        });

        // Refermet le tiroir si l'on repasse en grand écran.
        window.addEventListener('resize', function () {
            if (window.innerWidth >= 1200 && estOuvert()) {
                fermer();
            }
        });

        panneau.dataset.pgdReady = '1';
    }

    /* ---------------------------------------------------------
       Menu profil
       --------------------------------------------------------- */
    function profil() {
        var bloc = DOC.getElementById('pgdProfile');
        if (!bloc) {
            return;
        }

        var bouton = DOC.getElementById('pgdProfileToggle');
        if (!bouton) {
            return;
        }

        function basculer() {
            var ouvert = bloc.classList.toggle('is-open');
            bouton.setAttribute('aria-expanded', ouvert ? 'true' : 'false');
        }

        bouton.addEventListener('click', function (e) {
            e.stopPropagation();
            basculer();
        });

        DOC.addEventListener('click', function (e) {
            if (bloc.classList.contains('is-open') && !bloc.contains(e.target)) {
                bloc.classList.remove('is-open');
                bouton.setAttribute('aria-expanded', 'false');
            }
        });

        DOC.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                bloc.classList.remove('is-open');
                bouton.setAttribute('aria-expanded', 'false');
            }
        });
    }

    /* ---------------------------------------------------------
       Confirmation avant action destructive
       Usage : <a class="pgd-confirm" data-pgd-confirm="Message ?">
       --------------------------------------------------------- */
    function confirmations() {
        DOC.addEventListener('click', function (e) {
            var cible = e.target.closest ? e.target.closest('[data-pgd-confirm], .pgd-confirm') : null;
            if (!cible) {
                return;
            }

            var message = cible.getAttribute('data-pgd-confirm')
                || cible.getAttribute('data-confirm')
                || 'Confirmer cette action ?';

            if (!window.confirm(message)) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    }

    /* ---------------------------------------------------------
       Soumission automatique des formulaires de filtre
       --------------------------------------------------------- */
    function filtresAuto() {
        DOC.addEventListener('change', function (e) {
            var champ = e.target;
            if (champ && champ.matches && champ.matches('[data-pgd-autosubmit]')) {
                var form = champ.form || champ.closest('form');
                if (form) {
                    var page = form.querySelector('input[name="page"]');
                    if (page) {
                        page.value = '1';
                    }
                    form.submit();
                }
            }
        });
    }

    /* ---------------------------------------------------------
       Info-bulles : titre natif pour les attributs data-tip
       --------------------------------------------------------- */
    function infoBulles() {
        var elements = DOC.querySelectorAll('[data-tip]');
        for (var i = 0; i < elements.length; i++) {
            if (!elements[i].getAttribute('title')) {
                elements[i].setAttribute('title', elements[i].getAttribute('data-tip'));
            }
        }
    }

    /* ---------------------------------------------------------
       Mise en évidence de la ligne survolée via le clavier
       --------------------------------------------------------- */
    function lignesCliquables() {
        DOC.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') {
                return;
            }
            var actif = DOC.activeElement;
            if (actif && actif.classList && actif.classList.contains('pgd-row-link')) {
                e.preventDefault();
                actif.click();
            }
        });
    }

    /* ---------------------------------------------------------
       Animation d'apparition des cartes (au chargement)
       --------------------------------------------------------- */
    function apparitions() {
        var blocs = DOC.querySelectorAll('.pgd-stats > *, .pgd-card, .section-card, .pgd-stat, .stat-card');
        if (!blocs.length) {
            return;
        }

        for (var i = 0; i < blocs.length; i++) {
            blocs[i].classList.add('pgd-fade-up');
            blocs[i].style.animationDelay = Math.min(i * 0.03, 0.3) + 's';
        }
    }

    function init() {
        sidebar();
        profil();
        confirmations();
        filtresAuto();
        infoBulles();
        lignesCliquables();
        apparitions();
    }

    if (DOC.readyState === 'loading') {
        DOC.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();