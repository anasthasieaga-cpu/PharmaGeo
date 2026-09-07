// ============================================================
// PharmaGeo — Recherche intelligente
// Autocomplétion des médicaments, filtres par catégorie /
// distance et envoi de la position utilisateur au serveur.
// ============================================================
// ===== Recherche intelligente =====

document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('recherche-rapide');
    const searchResults = document.getElementById('resultats-rapides');
    
    if (searchInput && searchResults) {
        // Recherche en temps réel (autocomplétion)
        const debouncedSearch = debounce(function(query) {
            if (query.length < 2) {
                searchResults.classList.add('d-none');
                return;
            }
            
            fetch(window.BASE_URL + 'api/recherche_api.php?q=' + encodeURIComponent(query))
                .then(response => response.json())
                .then(data => {
                    if (data.length > 0) {
                        searchResults.innerHTML = data.map(item => `
                            <a href="${window.BASE_URL}pages/patient/recherche.php?q=${encodeURIComponent(item.Nom_commercial)}" 
                               class="list-group-item list-group-item-action">
                                <div class="d-flex justify-content-between">
                                    <span><strong>${item.Nom_commercial}</strong></span>
                                    <span class="badge bg-success">${item.disponible || 0} disponible(s)</span>
                                </div>
                                ${item.DCI ? `<small class="text-muted">DCI: ${item.DCI}</small>` : ''}
                            </a>
                        `).join('');
                        searchResults.classList.remove('d-none');
                    } else {
                        searchResults.innerHTML = `
                            <div class="list-group-item text-muted text-center">
                                <i class="fas fa-search me-2"></i>Aucun résultat trouvé
                            </div>
                        `;
                        searchResults.classList.remove('d-none');
                    }
                })
                .catch(error => {
                    console.error('Erreur recherche:', error);
                });
        }, 300);
        
        searchInput.addEventListener('input', function() {
            debouncedSearch(this.value);
        });
        
        // Fermer les résultats en cliquant ailleurs
        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.classList.add('d-none');
            }
        });
    }
    
    // Filtre par catégorie
    const filtreCategorie = document.getElementById('filtre-categorie');
    if (filtreCategorie) {
        filtreCategorie.addEventListener('change', function() {
            const url = new URL(window.location.href);
            if (this.value) {
                url.searchParams.set('categorie', this.value);
            } else {
                url.searchParams.delete('categorie');
            }
            window.location.href = url.toString();
        });
    }
    
    // Filtre par localisation
    const filtreDistance = document.getElementById('filtre-distance');
    if (filtreDistance) {
        filtreDistance.addEventListener('change', function() {
            const url = new URL(window.location.href);
            if (this.value) {
                url.searchParams.set('distance', this.value);
            } else {
                url.searchParams.delete('distance');
            }
            window.location.href = url.toString();
        });
    }
});

// Fonction de géolocalisation pour la recherche
function getLocationForSearch() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            function(position) {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                // Envoyer la position au serveur
                fetch(window.BASE_URL + 'api/geoloc_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ latitude: lat, longitude: lng })
                });
            },
            function(error) {
                console.warn('Géolocalisation non disponible:', error);
            }
        );
    }
}

// Appeler la géolocalisation au chargement
getLocationForSearch();