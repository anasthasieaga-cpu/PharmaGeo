// ============================================================
// PharmaGeo — Placement d'une pharmacie sur la carte
//
// Remplit les champs latitude / longitude a partir d'un marqueur que l'on
// deplace ou d'un clic sur la carte (vue satellite disponible pour reperer
// le batiment). La position du navigateur n'est qu'un point de depart :
// elle est annoncee avec sa precision et peut etre ajustee a la main.
//
// L'ancien repli par adresse IP est supprime : il place le marqueur au
// centre de la ville, a plusieurs kilometres de la pharmacie.
//
// Usage : PharmaCoordPicker.init({ map: 'id', lat: 'id', lng: 'id',
//                                  bouton: 'id', statut: 'id',
//                                  reference: 'url du JSON des pharmacies OSM' });
// ============================================================

(function () {
    'use strict';

    // Centre de Porto-Novo, utilise quand aucune coordonnee n'est saisie.
    var CENTRE = [6.4970, 2.6050];
    // Meme zone que includes/coordonnees.php : Porto-Novo, Seme-Podji, Adjarra,
    // Akpro-Misserete.
    var ZONE = { latMin: 6.33, latMax: 6.67, lngMin: 2.46, lngMax: 2.79 };
    // Au-dela de cette incertitude (en metres), on demande d'ajuster le marqueur.
    var PRECISION_ACCEPTABLE = 100;

    function nombre(valeur) {
        if (valeur === null || valeur === undefined) return null;
        var texte = String(valeur).replace(/\s/g, '').replace(',', '.');
        if (!/^[+-]?\d+(\.\d+)?$/.test(texte)) return null;
        return parseFloat(texte);
    }

    function dansLaZone(lat, lng) {
        return lat >= ZONE.latMin && lat <= ZONE.latMax && lng >= ZONE.lngMin && lng <= ZONE.lngMax;
    }

    function init(options) {
        if (typeof L === 'undefined') return null;

        var conteneur = document.getElementById(options.map);
        var champLat = document.getElementById(options.lat);
        var champLng = document.getElementById(options.lng);
        if (!conteneur || !champLat || !champLng) return null;

        var bouton = options.bouton ? document.getElementById(options.bouton) : null;
        var statut = options.statut ? document.getElementById(options.statut) : null;

        function dire(message, type) {
            if (!statut) return;
            statut.textContent = message;
            statut.className = 'locate-status' + (type ? ' ' + type : '');
        }

        var lat0 = nombre(champLat.value);
        var lng0 = nombre(champLng.value);
        var saisieValide = lat0 !== null && lng0 !== null && dansLaZone(lat0, lng0);
        var depart = saisieValide ? [lat0, lng0] : CENTRE;

        var limites = L.latLngBounds([ZONE.latMin, ZONE.lngMin], [ZONE.latMax, ZONE.lngMax]);
        var carte = L.map(conteneur, { maxBounds: limites.pad(0.1), minZoom: 11 })
            .setView(depart, saisieValide ? 18 : 13);

        var plan = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap'
        });
        var satellite = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                maxZoom: 19,
                attribution: 'Imagery &copy; Esri'
            });
        plan.addTo(carte);
        L.control.layers({ 'Plan': plan, 'Satellite': satellite }).addTo(carte);

        var marqueur = L.marker(depart, { draggable: true });
        if (saisieValide) marqueur.addTo(carte);

        function ecrire(lat, lng) {
            champLat.value = lat.toFixed(6);
            champLng.value = lng.toFixed(6);
            champLat.classList.remove('is-invalid');
            champLng.classList.remove('is-invalid');
        }

        function placer(lat, lng, centrer) {
            if (!carte.hasLayer(marqueur)) marqueur.addTo(carte);
            marqueur.setLatLng([lat, lng]);
            ecrire(lat, lng);
            if (centrer) carte.setView([lat, lng], Math.max(carte.getZoom(), 18));
        }

        marqueur.on('dragend', function () {
            var p = marqueur.getLatLng();
            ecrire(p.lat, p.lng);
            dire('Position ajustée : ' + p.lat.toFixed(6) + ', ' + p.lng.toFixed(6) + '.', 'success');
        });

        carte.on('click', function (e) {
            placer(e.latlng.lat, e.latlng.lng, false);
            dire('Position choisie : ' + e.latlng.lat.toFixed(6) + ', ' + e.latlng.lng.toFixed(6) + '.', 'success');
        });

        // Pharmacies deja repertoriees sur OpenStreetMap : points gris sur
        // lesquels on peut cliquer pour placer le marqueur au meme endroit,
        // a confirmer ensuite sur le batiment.
        if (options.reference && window.fetch) {
            fetch(options.reference).then(function (r) { return r.json(); }).then(function (liste) {
                liste.forEach(function (ph) {
                    L.circleMarker([ph.lat, ph.lng], {
                        radius: 6, color: '#6B7280', weight: 2, fillColor: '#E5E7EB', fillOpacity: 0.9
                    }).addTo(carte)
                      .bindTooltip(ph.nom + ' (repérée sur OpenStreetMap, cliquez pour placer ici)')
                      .on('click', function (e) {
                          L.DomEvent.stopPropagation(e);
                          placer(ph.lat, ph.lng, true);
                          dire('Point de « ' + ph.nom + ' » repris depuis OpenStreetMap : vérifiez qu\'il est bien sur votre pharmacie.', 'success');
                      });
                });
            }).catch(function () { /* repere facultatif */ });
        }

        // Saisie manuelle : le marqueur suit les champs.
        function suivreChamps() {
            var lat = nombre(champLat.value);
            var lng = nombre(champLng.value);
            if (lat === null || lng === null) return;
            if (!dansLaZone(lat, lng)) {
                dire(dansLaZone(lng, lat)
                    ? 'Latitude et longitude semblent inversées.'
                    : 'Ces coordonnées sont en dehors de Porto-Novo et des communes voisines.', 'error');
                return;
            }
            placer(lat, lng, true);
            dire('', '');
        }
        champLat.addEventListener('change', suivreChamps);
        champLng.addEventListener('change', suivreChamps);

        // Position du navigateur : point de depart, a ajuster sur la carte.
        function localiser() {
            if (!navigator.geolocation) {
                dire('Le GPS n\'est pas disponible sur ce navigateur. Placez le marqueur sur la carte.', 'error');
                return;
            }
            if (!window.isSecureContext) {
                dire('Le GPS exige une connexion sécurisée (HTTPS). Placez le marqueur sur la carte.', 'error');
                return;
            }

            if (bouton) bouton.disabled = true;
            dire('Localisation en cours...', '');

            navigator.geolocation.getCurrentPosition(function (pos) {
                var lat = pos.coords.latitude;
                var lng = pos.coords.longitude;
                var precision = Math.round(pos.coords.accuracy);
                if (bouton) bouton.disabled = false;

                if (!dansLaZone(lat, lng)) {
                    dire('La position détectée est en dehors de la zone de Porto-Novo. Placez le marqueur sur la carte.', 'error');
                    return;
                }

                placer(lat, lng, true);
                if (precision > PRECISION_ACCEPTABLE) {
                    dire('Position détectée avec une incertitude de ± ' + precision +
                         ' m : déplacez le marqueur sur le bâtiment de la pharmacie.', 'error');
                } else {
                    dire('Position détectée (± ' + precision + ' m). Vérifiez que le marqueur est sur la pharmacie.', 'success');
                }
            }, function (erreur) {
                if (bouton) bouton.disabled = false;
                var messages = {
                    1: 'Autorisation de géolocalisation refusée.',
                    2: 'Position actuellement indisponible.',
                    3: 'Délai de localisation dépassé.'
                };
                dire((messages[erreur.code] || 'Erreur de localisation.') +
                     ' Placez le marqueur sur la carte.', 'error');
            }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
        }

        if (bouton) bouton.addEventListener('click', localiser);

        // La carte est parfois initialisee dans un bloc masque : on recalcule
        // sa taille des qu'elle devient visible.
        setTimeout(function () { carte.invalidateSize(); }, 300);

        return { carte: carte, marqueur: marqueur, localiser: localiser };
    }

    window.PharmaCoordPicker = { init: init };
})();
