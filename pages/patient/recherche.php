<?php
// ============================================================
// PharmaGeo — Espace patient : Recherche de médicaments
// Recherche par nom commercial ou DCI avec calcul de distance.
// ============================================================

$page_title = 'Recherche de médicaments - PharmaGeo';
require_once '../../includes/config.php';
require_once '../../includes/session.php';

// ===== VARIABLES INITIALES =====
$resultats = [];
$recherche_effectuee = false;

// ===== RECHERCHE DE MÉDICAMENTS =====
if (isset($_GET['q']) && !empty($_GET['q'])) {
    $recherche_effectuee = true;
    $terme_recherche = cleanInput($_GET['q']);
    
    try {
        // Recherche intelligente
        $sql = "SELECT 
                    m.Id_medicament, m.Nom_commercial, m.DCI, m.Forme, m.Dosage,
                    p.Id_pharmacie, p.Nom as Pharmacie, p.Adresse, p.Telephone, p.Quartier, p.Commune,
                    p.Latitude, p.Longitude, p.Horaire,
                    s.Quantite, s.Prix, s.Statut as Statut_stock,
                    (6371 * acos(cos(radians(:lat)) * cos(radians(p.Latitude)) * 
                    cos(radians(p.Longitude) - radians(:lng)) + 
                    sin(radians(:lat)) * sin(radians(p.Latitude)))) as Distance
                FROM medicament m
                JOIN stock s ON m.Id_medicament = s.Id_medicament
                JOIN pharmacie p ON s.Id_pharmacie = p.Id_pharmacie
                WHERE (m.Nom_commercial LIKE :terme 
                    OR m.DCI LIKE :terme 
                    OR m.Id_medicament IN (
                        SELECT Id_medicament FROM medicament_synonyme 
                        WHERE Libelle LIKE :terme
                    ))
                AND s.Statut = 'disponible'
                AND s.Quantite > 0
                AND p.Statut = 'active'
                ORDER BY Distance ASC, s.Statut ASC";
        
        $stmt = $pdo->prepare($sql);
        $term = '%' . $terme_recherche . '%';
        $lat = $_SESSION['latitude'] ?? 6.4970;
        $lng = $_SESSION['longitude'] ?? 2.6050;
        $stmt->execute([':terme' => $term, ':lat' => $lat, ':lng' => $lng]);
        $resultats = $stmt->fetchAll();
        
        // Enregistrer la recherche
        if (!empty($resultats)) {
            $sqlRecherche = "INSERT INTO recherche (Mot_recherche, Latitude, Longitude, Id_utilisateur) 
                            VALUES (:mot, :lat, :lng, :user)";
            $stmtRecherche = $pdo->prepare($sqlRecherche);
            $stmtRecherche->execute([
                ':mot' => $terme_recherche,
                ':lat' => $lat,
                ':lng' => $lng,
                ':user' => getIdUtilisateur()
            ]);
        }
    } catch(PDOException $e) {
        $error = "Erreur lors de la recherche : " . $e->getMessage();
    }
}

// Récupérer les catégories pour le filtre
$categories = $pdo->query("SELECT * FROM categorie ORDER BY Libelle")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/style.css">
</head>
<body>
    <!-- ===== HEADER ===== -->
    <?php include_once '../../includes/header.php'; ?>
    
    <div class="container fade-in">
        <h1 class="mb-4">
            <i class="fas fa-search" style="color: rgb(42, 195, 42);"></i>
            Recherche de médicaments
        </h1>
        
        <!-- ===== BARRE DE RECHERCHE ===== -->
        <div class="row mb-4">
            <div class="col-md-8 mx-auto">
                <form method="GET" action="" class="search-container d-flex">
                    <input type="text" name="q" class="form-control" 
                           placeholder="Entrez le nom d'un médicament..." 
                           value="<?php echo $_GET['q'] ?? ''; ?>" required>
                    <button type="submit" class="btn btn-primary-custom">
                        <i class="fas fa-search me-2"></i>Rechercher
                    </button>
                </form>
                <div class="mt-2 text-muted small">
                    <i class="fas fa-info-circle"></i> Vous pouvez rechercher par nom commercial ou DCI
                </div>
            </div>
        </div>
        
        <!-- ===== FILTRES ===== -->
        <div class="row mb-4">
            <div class="col-md-6">
                <select class="form-select" id="filtre-categorie">
                    <option value="">Toutes les catégories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['Id_categorie']; ?>">
                            <?php echo htmlspecialchars($cat['Libelle']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 text-end">
                <button class="btn btn-outline-custom" onclick="window.location.reload();">
                    <i class="fas fa-sync-alt"></i> Réinitialiser
                </button>
            </div>
        </div>
        
        <!-- ===== RÉSULTATS ===== -->
        <?php if ($recherche_effectuee): ?>
            <?php if (!empty($resultats)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> 
                    <?php echo count($resultats); ?> résultat(s) trouvé(s) pour "<strong><?php echo htmlspecialchars($terme_recherche); ?></strong>"
                </div>
                
                <div class="row">
                    <?php foreach ($resultats as $r): ?>
                        <div class="col-lg-6 col-md-12 mb-4">
                            <div class="card-modern h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h5 class="fw-bold" style="color: rgb(42, 195, 42);">
                                                <?php echo htmlspecialchars($r['Nom_commercial']); ?>
                                            </h5>
                                            <?php if ($r['DCI']): ?>
                                                <span class="badge bg-secondary">DCI: <?php echo htmlspecialchars($r['DCI']); ?></span>
                                            <?php endif; ?>
                                            <span class="badge bg-info"><?php echo htmlspecialchars($r['Forme'] ?? 'Non spécifié'); ?></span>
                                            <span class="badge bg-light text-dark"><?php echo htmlspecialchars($r['Dosage'] ?? ''); ?></span>
                                        </div>
                                        <span class="badge-status <?php echo $r['Statut_stock'] === 'disponible' ? 'badge-disponible' : 'badge-faible'; ?>">
                                            <?php echo $r['Statut_stock']; ?>
                                        </span>
                                    </div>
                                    
                                    <hr>
                                    
                                    <div class="pharmacy-info">
                                        <h6 class="fw-bold">
                                            <i class="fas fa-store" style="color: hsl(25, 75%, 47%);"></i>
                                            <?php echo htmlspecialchars($r['Pharmacie']); ?>
                                        </h6>
                                        <p class="text-muted small mb-1">
                                            <i class="fas fa-map-marker-alt"></i> 
                                            <?php echo htmlspecialchars($r['Quartier'] ?? ''); ?>, 
                                            <?php echo htmlspecialchars($r['Commune'] ?? 'Porto-Novo'); ?>
                                        </p>
                                        <p class="text-muted small mb-1">
                                            <i class="fas fa-phone"></i> 
                                            <?php echo htmlspecialchars($r['Telephone'] ?? 'Non disponible'); ?>
                                        </p>
                                        <p class="text-muted small mb-1">
                                            <i class="fas fa-clock"></i> 
                                            <?php echo htmlspecialchars($r['Horaire'] ?? 'Horaires non renseignés'); ?>
                                        </p>
                                        <p class="mb-2">
                                            <span class="fw-bold">Quantité:</span> 
                                            <?php echo $r['Quantite']; ?> unités
                                            <?php if ($r['Prix']): ?>
                                                - <span class="fw-bold">Prix:</span> 
                                                <?php echo number_format($r['Prix'], 0, ',', ' '); ?> FCFA
                                            <?php endif; ?>
                                        </p>
                                        <p class="mb-2">
                                            <i class="fas fa-route" style="color: rgb(42, 195, 42);"></i>
                                            <span class="fw-bold">Distance:</span> 
                                            <?php echo round($r['Distance'], 2); ?> km
                                        </p>
                                    </div>
                                    
                                    <div class="d-flex gap-2 mt-3">
                                        <a href="carte.php?id=<?php echo $r['Id_pharmacie']; ?>" 
                                           class="btn btn-secondary-custom btn-sm">
                                            <i class="fas fa-map-marker-alt"></i> Voir sur la carte
                                        </a>
                                        <?php $urlReservation = BASE_URL . 'pages/patient/reservation.php?stock=' . $r['Id_medicament'] . '&pharmacie=' . $r['Id_pharmacie']; ?>
                                        <a href="<?php echo estConnecte() ? $urlReservation : BASE_URL . 'connexion.php?redirect=' . urlencode($urlReservation); ?>"
                                           class="btn btn-primary-custom btn-sm">
                                            <i class="fas fa-calendar-check"></i> Réserver
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> 
                    Aucun résultat trouvé pour "<strong><?php echo htmlspecialchars($terme_recherche); ?></strong>"
                    <br><small>Vérifiez l'orthographe ou essayez avec un autre nom.</small>
                </div>
                
                <!-- Suggestions -->
                <div class="mt-4">
                    <h6><i class="fas fa-lightbulb text-warning"></i> Suggestions de recherche :</h6>
                    <div class="d-flex flex-wrap gap-2">
                        <?php
                        $suggestions = $pdo->query("SELECT Nom_commercial FROM medicament LIMIT 10")->fetchAll();
                        foreach ($suggestions as $s):
                        ?>
                            <a href="?q=<?php echo urlencode($s['Nom_commercial']); ?>" 
                               class="btn btn-outline-secondary btn-sm">
                                <?php echo htmlspecialchars($s['Nom_commercial']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="text-center py-5">
                <i class="fas fa-search" style="font-size: 5rem; color: #ddd;"></i>
                <h4 class="mt-3 text-muted">Commencez votre recherche</h4>
                <p>Saisissez le nom d'un médicament dans la barre de recherche ci-dessus</p>
                <div class="mt-4">
                    <h6>Médicaments populaires :</h6>
                    <div class="d-flex flex-wrap justify-content-center gap-2">
                        <?php
                        $populaires = $pdo->query("SELECT Nom_commercial FROM medicament LIMIT 6")->fetchAll();
                        foreach ($populaires as $p):
                        ?>
                            <a href="?q=<?php echo urlencode($p['Nom_commercial']); ?>" 
                               class="btn btn-outline-primary btn-sm">
                                <?php echo htmlspecialchars($p['Nom_commercial']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- ===== FOOTER ===== -->
    <?php include_once '../../includes/footer.php'; ?>
    
    <!-- ===== SCRIPTS ===== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/app.js"></script>
    <script src="../../assets/js/recherche.js"></script>
</body>
</html>