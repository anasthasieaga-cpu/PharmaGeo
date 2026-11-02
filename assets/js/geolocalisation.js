// ============================================================
// PharmaGeo — Géolocalisation
// Gère l'affichage de la carte Leaflet, la position de
// l'utilisateur et le marquage des pharmacies.
// ============================================================
// ===== Géolocalisation et carte Leaflet =====

document.addEventListener('DOMContentLoaded', function() {
    const mapContainer = document.getElementById('pharmacy-map');
    if (!mapContainer) return;
    
    // Initialisation de la carte
    const map = L.map('pharmacy-map').setView([6.4970, 2.6050], 13);
    
    // Couche de tuiles OpenStreetMap
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 19
    }).addTo(map);
    
    // Icône personnalisée pour les pharmacies
    const pharmacyIcon = L.divIcon({
        className: 'pharmacy-marker',
        html: '<i class="fas fa-hospital" style="font-size: 2rem; color: #16A34A;"></i>',
        iconSize: [30, 30],
        iconAnchor: [15, 30]
    });
    
    const pharmacyIconSelected = L.divIcon({
        className: 'pharmacy-marker-selected',
        html: '<i class="fas fa-hospital" style="font-size: 2.5rem; color: #166534;"></i>',
        iconSize: [35, 35],
        iconAnchor: [17, 35]
    });
    
    let userMarker = null;
    let userCircle = null;
    
    // Récupérer la position de l'utilisateur
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            function(position) {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                
                // Centrer la carte sur l'utilisateur
                map.setView([lat, lng], 15);
                
                // Marqueur utilisateur
                if (userMarker) {
                    map.removeLayer(userMarker);
                }
                userMarker = L.marker([lat, lng], {
                    icon: L.divIcon({
                        className: 'user-marker',
                        html: '<i class="fas fa-user-circle" style="font-size: 2.5rem; color: #166534;"></i>',
                        iconSize: [35, 35],
                        iconAnchor: [17, 35]
                    })
                }).addTo(map).bindPopup('Votre position');
                
                // Cercle de précision
                if (userCircle) {
                    map.removeLayer(userCircle);
                }
                userCircle = L.circle([lat, lng], {
                    radius: 100,
                    color: '#166534',
                    fillColor: '#166534',
                    fillOpacity: 0.1
                }).addTo(map);
                
                // Envoyer la position au serveur
                fetch(window.BASE_URL + 'api/geoloc_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ latitude: lat, longitude: lng })
                });
                
                // Charger les pharmacies proches
                loadNearbyPharmacies(lat, lng);
            },
            function(error) {
                console.warn('Géolocalisation non disponible:', error);
                // Charger toutes les pharmacies
                loadAllPharmacies();
            }
        );
    } else {
        // Charger toutes les pharmacies
        loadAllPharmacies();
    }
    
    // Fonction pour charger les pharmacies proches
    function loadNearbyPharmacies(lat, lng) {
        fetch(window.BASE_URL + 'api/geoloc_api.php?lat=' + lat + '&lng=' + lng + '&radius=10')
            .then(response => response.json())
            .then(data => {
                addPharmaciesToMap(data);
            })
            .catch(error => console.error('Erreur chargement pharmacies:', error));
    }
    
    // Fonction pour charger toutes les pharmacies
    function loadAllPharmacies() {
        fetch(window.BASE_URL + 'api/geoloc_api.php?all=1')
            .then(response => response.json())
            .then(data => {
                addPharmaciesToMap(data);
            })
            .catch(error => console.error('Erreur chargement pharmacies:', error));
    }
    
    // Ajouter les pharmacies sur la carte
    function addPharmaciesToMap(pharmacies) {
        pharmacies.forEach(pharmacy => {
            const isSelected = window.location.search.includes(`id=${pharmacy.Id_pharmacie}`);
            const icon = isSelected ? pharmacyIconSelected : pharmacyIcon;
            
            const marker = L.marker([pharmacy.Latitude, pharmacy.Longitude], {
                icon: icon
            }).addTo(map);
            
            const popupContent = `
                <div class="pharmacy-popup">
                    <h5 class="fw-bold" style="color: #16A34A;">
                        <i class="fas fa-store"></i> ${pharmacy.Nom}
                    </h5>
                    <p class="mb-1">
                        <i class="fas fa-map-marker-alt" style="color: #166534;"></i>
                        ${pharmacy.Quartier || ''}, ${pharmacy.Commune || 'Porto-Novo'}
                    </p>
                    <p class="mb-1">
                        <i class="fas fa-phone"></i> ${pharmacy.Telephone || 'Non disponible'}
                    </p>
                    <p class="mb-1">
                        <i class="fas fa-clock"></i> ${pharmacy.Horaire || 'Horaires non renseignés'}
                    </p>
                    ${pharmacy.Distance ? `<p class="mb-1"><i class="fas fa-route" style="color: #16A34A;"></i> ${formatDistance(pharmacy.Distance)}</p>` : ''}
                    <div class="mt-2">
                        <a href="${window.BASE_URL}pages/patient/pharmacie.php?id=${pharmacy.Id_pharmacie}" 
                           class="btn btn-primary-custom btn-sm">
                            <i class="fas fa-info-circle"></i> Voir détails
                        </a>
                        <a href="https://www.google.com/maps/dir/?api=1&destination=${pharmacy.Latitude},${pharmacy.Longitude}" 
                           target="_blank" class="btn btn-secondary-custom btn-sm">
                            <i class="fas fa-directions"></i> Itinéraire
                        </a>
                    </div>
                </div>
            `;
            
            marker.bindPopup(popupContent, {
                maxWidth: 300,
                className: 'custom-popup'
            });
            
            // Si c'est la pharmacie sélectionnée, ouvrir le popup
            if (isSelected) {
                setTimeout(() => marker.openPopup(), 500);
            }
        });
    }
    
    // Bouton pour recentrer sur la position de l'utilisateur
    const recenterBtn = document.getElementById('recenter-map');
    if (recenterBtn) {
        recenterBtn.addEventListener('click', function() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        map.setView([position.coords.latitude, position.coords.longitude], 15);
                    },
                    function(error) {
                        alert('Impossible de récupérer votre position.');
                    }
                );
            }
        });
    }
});