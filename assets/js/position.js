// ============================================================
// PharmaGeo — Détection de la position de la personne
//
// Chargé sur toutes les pages (via includes/footer.php). Objectif :
// que les distances affichées soient calculées depuis l'endroit où
// la personne se trouve réellement, et non depuis un point figé.
//
// Principe : on ne publie JAMAIS une distance calculée depuis une
// position inventée. Seul navigator.geolocation fournit une position
// dont on connaît la précision (coords.accuracy), et cette précision
// accompagne la mesure jusqu'au serveur.
//
// Si le GPS est refusé ou trop imprécis, aucune distance n'est affichée :
// la page invite alors la personne à autoriser la localisation.
// ============================================================

(function () {
    'use strict';

    var config = window.PHARMA_GEO || {};
    var SEUIL = typeof config.seuil === 'number' ? config.seuil : 0.05;
    var PRECISION_MAX = typeof config.precisionMax === 'number' ? config.precisionMax : 150;
    var URL_API = (config.endpoint || ((window.BASE_URL || '') + 'api/geoloc_api.php'));

    // Position de référence utilisée pour le rendu actuel de la page.
    var positionCourante = {
        lat: typeof config.lat === 'number' ? config.lat : null,
        lng: typeof config.lng === 'number' ? config.lng : null,
        source: config.source || 'defaut',
        precision: (typeof config.precision === 'number' ? config.precision : null),
        // Age de la position en secondes (null si inconnu) : sert à décider
        // s'il faut redétecter pour suivre un déplacement.
        age: (typeof config.age === 'number' ? config.age : null)
    };

    // Au-delà de ce délai, une position mémorisée est jugée trop vieille :
    // on la rafraîchit auprès du navigateur au lieu de l'afficher telle quoi.
    var AGE_MAX_POSITION = 30;

    var detectionEnCours = false;
    // >true lorsqu'une détection a été déclenchée manuellement : dans ce cas
    // la page est rechargée même si la position n'a pas changé de façon
    // perceptible, pour que le serveur refasse le calcul.
    var forcer = false;

    // ===== DISTANCE (haversine, identique à includes/geo.php) =====
    function distanceKm(lat1, lng1, lat2, lng2) {
        var R = 6371;
        var dLat = (lat2 - lat1) * Math.PI / 180;
        var dLng = (lng2 - lng1) * Math.PI / 180;
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                Math.sin(dLng / 2) * Math.sin(dLng / 2);
        if (a < 0) a = 0;
        if (a > 1) a = 1;
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function coordonneesValides(lat, lng) {
        return typeof lat === 'number' && typeof lng === 'number' &&
               isFinite(lat) && isFinite(lng) &&
               lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180 &&
               !(lat === 0 && lng === 0);
    }

    // ===== ENVOI AU SERVEUR =====
    function envoyerPosition(lat, lng, source, precision) {
        var corps = {
            latitude: Number(lat.toFixed(6)),
            longitude: Number(lng.toFixed(6)),
            source: source
        };
        if (typeof precision === 'number' && isFinite(precision)) {
            corps.precision = Math.round(precision);
        }

        return fetch(URL_API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(corps)
        }).then(function (res) {
            return res.json().catch(function () { return null; })
                .then(function (data) {
                    if (!res.ok || !data || data.succes !== true) {
                        // Position refusée (précision absente, trop grande,
                        // coordonnées invalides) ou erreur serveur : la page
                        // garde la référence du serveur, bandeau compris.
                        throw new Error((data && data.error) || ('Erreur ' + res.status));
                    }
                    return data;
                });
        });
    }

    // ===== MISE À JOUR DE L'AFFICHAGE =====
    function majBadges(lat, lng, source, precision) {
        var badge = document.querySelector('.geo-position');
        var chips = document.querySelectorAll('[data-geo-refresh]');

        Array.prototype.forEach.call(chips, function (b) {
            b.classList.remove('geo-attente');
        });

        if (!badge) return;

        var fort = badge.querySelector('.geo-badge strong');
        var icone = badge.querySelector('.geo-badge i');
        var libelle = badge.querySelector('.geo-refresh__libelle');
        var classe = badge.querySelector('.geo-badge');

        if (source === 'gps') {
            if (classe) classe.className = 'geo-badge geo-badge-ok';
            if (icone) icone.className = 'fas fa-location-crosshairs me-1';
            if (fort) {
                fort.textContent = (typeof precision === 'number')
                    ? 'GPS (± ' + Math.round(precision) + ' m)'
                    : 'GPS';
            }
            if (libelle) libelle.textContent = 'Actualiser';
        }

        // Retire le bandeau d'avertissement : la position est désormais connue.
        var alerte = document.querySelector('.geo-alerte');
        if (alerte && alerte.parentNode) alerte.parentNode.removeChild(alerte);

        document.dispatchEvent(new CustomEvent('pharmageo:position', {
            detail: { lat: lat, lng: lng, source: source, precision: precision }
        }));
    }

    function marquerEnAttente() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-geo-refresh]'), function (badge) {
            badge.classList.add('geo-attente');
        });
    }

    /**
     * Applique une position fraichement detectee.
     * @param {boolean} forcer >true pour le bouton « Activer la localisation »
     */
    function appliquerPosition(lat, lng, source, precision, forcer) {
        // La comparaison avec la référence de la page est faite AVANT de
        // remplacer positionCourante. Sinon les deux côtés de la
        // comparaison seraient la nouvelle position et aucun déplacement
        // ne serait jamais détecté.
        var deplacement = positionPerimee(lat, lng);

        envoyerPosition(lat, lng, source, precision).then(function (reponse) {
            // Le serveur vient d'enregistrer la position : le rendu courant
            // (distances calculées par le serveur) n'est plus valide.
            positionCourante = { lat: lat, lng: lng, source: source, precision: precision, age: 0 };
            window.PHARMA_GEO = Object.assign({}, window.PHARMA_GEO || {}, {
                lat: lat, lng: lng, source: source, precision: precision, age: 0
            });

            majBadges(lat, lng, source, precision);

            // On ne recharge que si la position a bougé de façon perceptible,
            // ou si l'utilisateur l'a demandé explicitement.
            if (forcer || (config.reload && deplacement)) {
                window.location.reload();
            }
        }).catch(function () {
            // Échec réseau ou position refusée : rien n'a changé côté
            // serveur, on laisse la page en l'état.
        });
    }

    // La position utilisée pour le rendu de la page diffère-t-elle
    // suffisamment de celle que l'on vient de détecter ?
    function positionPerimee(lat, lng) {
        if (!coordonneesValides(positionCourante.lat, positionCourante.lng)) return true;
        if (positionCourante.source !== 'gps') return true;
        return distanceKm(positionCourante.lat, positionCourante.lng, lat, lng) > SEUIL;
    }

    // ===== CHAÎNE DE DÉTECTION =====
    // Le repli par IP est volontairement supprimé : une ville estimée depuis
    // une adresse IP peut se trouver à plusieurs kilomètres de la position
    // réelle, ce qui produirait exactement le genre de distance fausse que
    // la plateforme cherche à éviter. Sans GPS, aucune distance n'est affichée.

    function demanderPosition(options) {
        return new Promise(function (resolve) {
            navigator.geolocation.getCurrentPosition(
                function (position) { resolve(position); },
                function () { resolve(null); },
                options
            );
        });
    }

    function detecterParGPS() {
        if (!navigator.geolocation) { return Promise.resolve(false); }

        // 1re tentative : haute precision (GPS materiel). Si elle echoue ou
        // expire (frequent sur ordinateur sans puce GPS), on retente en
        // precision normale (position Wi-Fi/reseau), largement suffisante
        // pour situer la personne a l'echelle d'une ville.
        var optionsHaute = { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 };
        var optionsNormale = { enableHighAccuracy: false, timeout: 15000, maximumAge: 0 };

        return demanderPosition(optionsHaute).then(function (position) {
            if (!position) { return demanderPosition(optionsNormale); }
            return position;
        }).then(function (position) {
            if (!position || !position.coords) { return false; }

            var lat = position.coords.latitude;
            var lng = position.coords.longitude;

            // On ne calcule rien depuis des coordonnees invalides.
            if (!coordonneesValides(lat, lng)) { return false; }

            var precision = position.coords.accuracy;

            // La precision (coords.accuracy) accompagne la mesure
            // lorsqu'elle est disponible. Son absence ou une valeur elevee
            // (Wi-Fi, reseau) ne doit plus empecher l'envoi : une position
            // approximative vaut mieux que rien.
            if (typeof precision !== 'number' || !isFinite(precision) || precision < 0) {
                precision = null;
            }

            appliquerPosition(lat, lng, 'gps', precision, forcer);
            return true;
        });
    }

    function detecterPosition() {
        if (detectionEnCours) return Promise.resolve();
        detectionEnCours = true;
        marquerEnAttente();

        return detecterParGPS()
            .catch(function () { /* position du serveur conservee */ })
            .then(function () { detectionEnCours = false; });
    }

    // ===== API PUBLIQUE =====
    window.PharmaGeoPosition = {
        detecter: function () { forcer = true; return detecterPosition(); },
        distanceKm: distanceKm,
        obtenir: function () { return positionCourante; }
    };

    /**
     * La permission GPS est-elle déjà accordée ?
     * Permet de mettre à jour silencieusement la position sans provoquer
     * de fenêtre de confirmation à chaque visite.
     */
    function permissionDejaAccordee() {
        if (!navigator.permissions || !navigator.permissions.query) {
            return Promise.resolve(false);
        }
        return navigator.permissions.query({ name: 'geolocation' })
            .then(function (statut) { return statut.state === 'granted'; })
            .catch(function () { return false; });
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Bouton « Activer la localisation » présent sur les pages concernées.
        document.addEventListener('click', function (e) {
            var bouton = e.target.closest ? e.target.closest('[data-geo-refresh]') : null;
            if (!bouton) { return; }
            e.preventDefault();
            forcer = true;
            detecterPosition();
        });

        // Détection automatique : on rafraîchit la position si elle n'est pas
        // connue, si elle n'est pas de type GPS, ou si elle a été mémorisée il
        // y a trop longtemps (la personne a pu se déplacer). On évite en
        // revanche de solliciter le GPS inutilement quand la position est
        // récente et fiable.
        var positionFraiche = positionCourante.source === 'gps'
            && typeof positionCourante.age === 'number'
            && positionCourante.age < AGE_MAX_POSITION;

        if (positionFraiche) { return; }

        permissionDejaAccordee().then(function (accordee) {
            if (accordee || config.reload) { detecterPosition(); }
        });
    });
})();
