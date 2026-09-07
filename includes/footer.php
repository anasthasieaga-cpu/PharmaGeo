        </div> <!-- Fin container -->
    </main>
    
    <!-- ============================================================
         PharmaGeo — Pied de page du site
         Bloc commun affiché en bas de chaque page : coordonnées,
         liens utiles et chargement des scripts globaux.
         ============================================================ -->
    <!-- ===== FOOTER ===== -->
    <footer class="text-white py-4 mt-5" style="background: linear-gradient(135deg, rgb(42, 195, 42), hsl(25, 75%, 47%));">
        <div class="container">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <h5><i class="fas fa-hospital me-2"></i>PharmaGeo</h5>
                    <p class="small opacity-75">
                        Plateforme intelligente de géolocalisation des pharmacies et de recherche de disponibilité des médicaments.
                    </p>
                </div>
                <div class="col-md-4 mb-3">
                    <h5>Liens utiles</h5>
                    <ul class="list-unstyled small">
                        <li><a href="<?php echo BASE_URL; ?>" class="text-white text-decoration-none opacity-75 hover-opacity-100">
                            <i class="fas fa-chevron-right me-1" style="font-size: 0.5rem;"></i> Accueil
                        </a></li>
                        <li><a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="text-white text-decoration-none opacity-75 hover-opacity-100">
                            <i class="fas fa-chevron-right me-1" style="font-size: 0.5rem;"></i> Rechercher
                        </a></li>
                        <li><a href="<?php echo BASE_URL; ?>pages/patient/carte.php" class="text-white text-decoration-none opacity-75 hover-opacity-100">
                            <i class="fas fa-chevron-right me-1" style="font-size: 0.5rem;"></i> Carte interactive
                        </a></li>
                        <li><a href="#" class="text-white text-decoration-none opacity-75 hover-opacity-100">
                            <i class="fas fa-chevron-right me-1" style="font-size: 0.5rem;"></i> À propos
                        </a></li>
                        <li><a href="#" class="text-white text-decoration-none opacity-75 hover-opacity-100">
                            <i class="fas fa-chevron-right me-1" style="font-size: 0.5rem;"></i> Contact
                        </a></li>
                    </ul>
                </div>
                <div class="col-md-4 mb-3">
                    <h5>Contact</h5>
                    <ul class="list-unstyled small">
                        <li><i class="fas fa-map-marker-alt me-2"></i> Porto-Novo, Bénin</li>
                        <li><i class="fas fa-envelope me-2"></i> contact@pharmageo.bj</li>
                        <li><i class="fas fa-phone me-2"></i> +229 XX XX XX XX</li>
                    </ul>
                    <div class="mt-2">
                        <a href="#" class="text-white me-2 opacity-75 hover-opacity-100"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" class="text-white me-2 opacity-75 hover-opacity-100"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="text-white me-2 opacity-75 hover-opacity-100"><i class="fab fa-linkedin-in"></i></a>
                        <a href="#" class="text-white opacity-75 hover-opacity-100"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
            </div>
            <hr class="border-light opacity-25">
            <div class="text-center">
                <p class="small opacity-75 mb-0">
                    &copy; <?php echo date('Y'); ?> PharmaGeo - Tous droits réservés. 
                    <span class="d-none d-md-inline">|</span> 
                    <span class="d-block d-md-inline">Conçu avec <i class="fas fa-heart text-danger"></i> pour la santé à Porto-Novo</span>
                </p>
            </div>
        </div>
    </footer>
    
    <!-- ===== SCRIPTS ===== -->
    <!-- URL de base injectée pour les requêtes JS -->
    <script>
        window.BASE_URL = <?php echo json_encode(BASE_URL); ?>;
    </script>
    <!-- Bootstrap 5 Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Leaflet JS pour la cartographie -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.js"></script>
    
    <!-- jQuery (optionnel, pour certaines fonctionnalités) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Scripts personnalisés -->
    <script src="<?php echo BASE_URL; ?>assets/js/app.js"></script>
    
    <!-- Responsivité globale -->
    <script src="<?php echo BASE_URL; ?>assets/js/responsive.js"></script>
    
    <?php if (isset($extra_js)): ?>
        <?php echo $extra_js; ?>
    <?php endif; ?>
    
    <!-- Script de gestion du footer -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Ajouter la classe hover-opacity-100
        document.querySelectorAll('.hover-opacity-100').forEach(el => {
            el.addEventListener('mouseenter', function() {
                this.style.opacity = '1';
            });
            el.addEventListener('mouseleave', function() {
                this.style.opacity = '0.75';
            });
        });
    });
    </script>
</body>
</html>