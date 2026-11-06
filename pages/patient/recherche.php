<?php
// ============================================================
// PharmaGeo — Recherche de médicaments (multi-médicaments)
// Permet de saisir plusieurs médicaments à la fois (ordonnance),
// de cocher ceux que l'on souhaite, puis d'afficher les
// pharmacies qui en possèdent.
// ============================================================

$page_title = 'Recherche de médicaments - PharmaGeo';
$geo_reload = true; // recalcul des distances si la position change
require_once '../../includes/config.php';
require_once '../../includes/session.php';
require_once '../../includes/geo.php';
require_once '../../includes/medicament_resolve.php';

// ===== POSITION DE RÉFÉRENCE POUR LES DISTANCES =====
// $geo_precise indique si la position est réellement connue (GPS sufficiently
// précis). Tant qu'elle est fausse, AUCUNE distance n'est calculée ni affichée :
// la plateforme affiche le bandeau invitant à autoriser la localisation.
$position = getPositionUtilisateur($pdo);
$geo_precise = !empty($position['precise']);

// ===== VARIABLES INITIALES =====
$requetes = array();             // demandes reconnues, une par terme saisi
$requetes_introuvables = array(); // termes sans correspondance
$medicaments_trouves = array();   // id => fiche médicament
$total_meds = 0;                  // nombre de demandes reconnues
$recherche_effectuee = false;
$raw_query = isset($_GET['q']) ? cleanInput($_GET['q']) : '';

// Médicaments retenus par le visiteur.
// C'est toujours une LISTE d'identifiants : les clés ne doivent jamais
// être réindexées, sinon les identifiants de médicaments sont perdus.
$selected_meds = [];
if (isset($_GET['med']) && is_array($_GET['med'])) {
    foreach ($_GET['med'] as $m) {
        $i = (int)$m;
        if ($i > 0 && !in_array($i, $selected_meds, true)) $selected_meds[] = $i;
    }
}

$pharmacies_result = [];

// ===== ÉTAPE 1 : RÉSOLUTION DE LA SAISIE =====
// Chaque terme devient UNE demande (et non une famille de produits) :
// « paracetamol » ne doit pas créer 6 médicaments à réserver, sinon
// aucune pharmacie n'est jamais complète et le bouton « Tout réserver »
// ne peut pas s'afficher. Les fautes de frappe sont tolérées.
if ($raw_query !== '') {
    $recherche_effectuee = true;

    try {
        $catalogue = medChargerCatalogue($pdo);

        foreach (medDecouper($raw_query) as $terme) {
            $r = medResoudreTerme($terme, $catalogue);
            if ($r['trouve']) {
                $requetes[] = $r;
            } else {
                $requetes_introuvables[] = $r;
            }
        }

        $total_meds = count($requetes);

        // Fiches des médicaments concernés, et nombre de pharmacies qui les détiennent
        $ids_requetes = array();
        foreach ($requetes as $req) {
            foreach ($req['ids'] as $id) $ids_requetes[(int)$id] = (int)$id;
        }
        $ids_requetes = array_values($ids_requetes);

        if (!empty($ids_requetes)) {
            $inM = implode(',', array_fill(0, count($ids_requetes), '?'));
            $stmt = $pdo->prepare("SELECT * FROM medicament WHERE Id_medicament IN ($inM)");
            $stmt->execute($ids_requetes);
            foreach ($stmt->fetchAll() as $row) {
                $medicaments_trouves[(int)$row['Id_medicament']] = $row;
            }

            $stmt = $pdo->prepare("
                SELECT s.Id_medicament, COUNT(DISTINCT s.Id_pharmacie) as nb
                FROM stock s
                JOIN pharmacie p ON s.Id_pharmacie = p.Id_pharmacie
                WHERE s.Id_medicament IN ($inM)
                  AND s.Statut = 'disponible' AND s.Quantite > 0
                  AND p.Statut = 'active'
                GROUP BY s.Id_medicament
            ");
            $stmt->execute($ids_requetes);
            foreach ($stmt->fetchAll() as $row) {
                $mid = (int)$row['Id_medicament'];
                if (isset($medicaments_trouves[$mid])) {
                    $medicaments_trouves[$mid]['nb_pharmacies'] = (int)$row['nb'];
                }
            }
        }
        foreach ($medicaments_trouves as &$mt) {
            if (!isset($mt['nb_pharmacies'])) $mt['nb_pharmacies'] = 0;
        }
        unset($mt);
    } catch(PDOException $e) {
        $error = "Erreur lors de la recherche : " . $e->getMessage();
    }
}

// ===== ÉTAPE 1 bis : SÉLECTION DU PATIENT =====
// En dehors du cas unique traité juste après, aucune sélection automatique :
// c'est le patient qui coche les médicaments qu'il souhaite réserver. Le
// nombre affiché entre parenthèses devant « Tout réserver » correspond donc
// exactement à ce qu'il a coché, jamais à une supposition du site.
// « Modifier ma sélection » (?selection=1) rouvre le formulaire de cochage.
$mode_selection = isset($_GET['selection']);

// ===== ACCÈS DIRECT QUAND LA RECHERCHE EST UNIQUE =====
// Unique exception : lorsque la saisie ne correspond qu'à un seul médicament,
// il n'y a rien à cocher, donc on ouvre directement la liste des pharmacies qui
// le détiennent. Le formulaire de cochage reste affiché dès qu'il y a plusieurs
// médicaments (ordonnance), car c'est alors le patient qui décide.
$acces_direct = false;
if ($recherche_effectuee && !$mode_selection && empty($selected_meds) && count($medicaments_trouves) === 1) {
    $seul_id = (int)array_keys($medicaments_trouves)[0];
    $selected_meds = array($seul_id);
    $acces_direct = true;
}
// Les liens qui doivent rouvrir la liste de cochage (décocher, tout décocher)
// portent ce paramètre : sans lui, l'accès direct sélectionnerait de nouveau
// le médicament et le patient ne pourrait jamais revenir en arrière.
$retour_selection = $acces_direct ? '&selection=1' : '';

// ===== ÉTAPE 2 : PHARMACIES POSSÉDANT LES MÉDICAMENTS COCHÉS =====
if (!empty($selected_meds)) {
    $ids = array_values($selected_meds);
    $inM = implode(',', array_fill(0, count($ids), '?'));
    $lat = $position['lat'];
    $lng = $position['lng'];

    // Fragment SQL de la distance : uniquement si la position est connue.
    // Sans cela on trierait les pharmacies selon une position inventée.
    $sql_distance = $geo_precise
        ? "(6371 * ACOS(LEAST(1, GREATEST(-1,
                COS(RADIANS(?)) * COS(RADIANS(p.Latitude)) *
                COS(RADIANS(p.Longitude) - RADIANS(?)) +
                SIN(RADIANS(?)) * SIN(RADIANS(p.Latitude))
           )))) as Distance"
        : '0 as Distance';
    $sql_tri = $geo_precise ? 'Distance ASC' : 'p.Nom ASC';

    try {
        $sql = "SELECT
                    p.Id_pharmacie, p.Nom, p.Adresse, p.Quartier, p.Commune,
                    p.Telephone, p.Horaire, p.Latitude, p.Longitude, p.Logo,
                    COUNT(DISTINCT s.Id_medicament) as Nb_medicaments,
                    SUM(s.Prix) as Total_prix,
                    $sql_distance
                FROM pharmacie p
                JOIN stock s ON s.Id_pharmacie = p.Id_pharmacie
                WHERE p.Statut = 'active'
                  AND s.Statut = 'disponible' AND s.Quantite > 0
                  AND s.Id_medicament IN ($inM)
                GROUP BY p.Id_pharmacie
                ORDER BY Nb_medicaments DESC, $sql_tri";

        $params = $geo_precise ? [$lat, $lng, $lat] : [];
        foreach ($ids as $id) { $params[] = $id; }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $pharmacies_result = $stmt->fetchAll();

        // Détail des médicaments disponibles par pharmacie
        $sql2 = "SELECT s.Id_stock, s.Id_medicament, s.Prix, s.Quantite,
                        m.Nom_commercial, m.DCI, m.Forme, m.Dosage,
                        c.Libelle AS Categorie
                 FROM stock s
                 JOIN medicament m ON s.Id_medicament = m.Id_medicament
                 LEFT JOIN categorie c ON c.Id_categorie = m.Id_categorie
                 WHERE s.Id_pharmacie = ? AND s.Id_medicament IN ($inM)";
        $stmt2 = $pdo->prepare($sql2);

        foreach ($pharmacies_result as $k => $ph) {
            $stmt2->execute(array_merge([$ph['Id_pharmacie']], $ids));
            $pharmacies_result[$k]['medicaments'] = $stmt2->fetchAll();

            // Ce que cette pharmacie couvre réellement parmi les médicaments
            // QUE LE PATIENT A COCHÉS. Ni la recherche, ni le site ne
            // décident à sa place : le compteur est le sien.
            $stockes = array();
            foreach ($pharmacies_result[$k]['medicaments'] as $mm) {
                $stockes[] = (int)$mm['Id_medicament'];
            }

            $couvertes = 0;
            $manques = array();
            $groupe_ids = array();
            foreach ($selected_meds as $mid) {
                $mid = (int)$mid;
                if (in_array($mid, $stockes, true)) {
                    $couvertes++;
                    $groupe_ids[] = $mid;
                } else {
                    $manques[] = isset($medicaments_trouves[$mid])
                        ? $medicaments_trouves[$mid]['Nom_commercial']
                        : 'médicament #' . $mid;
                }
            }

$nb_selection = count($selected_meds);
            $pharmacies_result[$k]['nb_couvertes'] = $couvertes;
            $pharmacies_result[$k]['manques'] = $manques;
            $pharmacies_result[$k]['complete'] = ($nb_selection > 0 && $couvertes >= $nb_selection);
            $pharmacies_result[$k]['groupe_ids'] = $groupe_ids;
            $pharmacies_result[$k]['Total_medicaments'] = $nb_selection;

            // Distance en mètres, recalculée côté PHP avec geoDistanceMetres()
            // (la même formule que celle utilisée par le navigateur).
            // Elle vaut null si la position n'est pas connue : dans ce cas
            // aucune distance ne doit être affichée.
            $pharmacies_result[$k]['distance_metres'] = $geo_precise
                ? geoDistanceMetres($lat, $lng, $ph['Latitude'], $ph['Longitude'])
                : null;
        }
    } catch(PDOException $e) {
        $error = "Erreur lors de la recherche des pharmacies.";
    }
}

// ===== AFFICHAGE UNIQUEMENT : ENRICHISSEMENT DES CARTES =====
// Ce bloc ne décide RIEN. Il ne sert qu'à afficher sur chaque carte les
// informations demandées par l'interface (catégorie, prix mini, quantité
// en stock, pharmacie la plus proche, distance).
// La sélection, la couverture et la réservation restent pilotées
// exclusivement par le code ci-dessus : aucune de ces valeurs n'entre
// dans un calcul de décision.
// Lecture seule : aucune écriture, aucun changement de schéma ni de données.
$infos_carte = array();       // id_medicament => informations d'affichage
$options_filtres = array('categorie' => array(), 'forme' => array(), 'dosage' => array(), 'localisation' => array());
$suggestions = array();       // noms proposés pendant la saisie

if (!empty($medicaments_trouves)) {
    try {
        $idsAffiches = array_values(array_map('intval', array_keys($medicaments_trouves)));
        $inAffiches = implode(',', array_fill(0, count($idsAffiches), '?'));
        $latRef = $position['lat'];
        $lngRef = $position['lng'];

// Une ligne par couple (médicament, pharmacie qui le détient).
// La distance est calculée en PHP : c'est geoDistanceMetres() qui fait
// référence, ce qui évite d'afficher un nombre calculé avec une autre
// référence que celle réellement utilisée pour le tri.
$sqlAffichage = "SELECT
                m.Id_medicament,
                c.Libelle AS Categorie,
                s.Prix, s.Quantite,
                p.Id_pharmacie, p.Nom, p.Quartier, p.Commune, p.Latitude, p.Longitude
            FROM medicament m
            JOIN stock s ON s.Id_medicament = m.Id_medicament
            JOIN pharmacie p ON p.Id_pharmacie = s.Id_pharmacie
            LEFT JOIN categorie c ON c.Id_categorie = m.Id_categorie
            WHERE m.Id_medicament IN ($inAffiches)
              AND s.Statut = 'disponible' AND s.Quantite > 0
              AND p.Statut = 'active'
            ORDER BY p.Nom ASC";

        $stmtAffichage = $pdo->prepare($sqlAffichage);
        $stmtAffichage->execute($idsAffiches);

        $lignesAffichage = $stmtAffichage->fetchAll();

        // Tri par distance croissante quand la position est connue, afin que
        // la première pharmacie rencontrée soit bien la plus proche.
        if ($geo_precise) {
            foreach ($lignesAffichage as $i => $ligne) {
                $lignesAffichage[$i]['__distance'] = geoDistanceMetres(
                    $latRef,
                    $lngRef,
                    $ligne['Latitude'],
                    $ligne['Longitude']
                );
            }
            usort($lignesAffichage, function ($a, $b) {
                return $a['__distance'] <=> $b['__distance'];
            });
        }

        foreach ($lignesAffichage as $ligne) {
            $mid = (int)$ligne['Id_medicament'];

            if (!isset($infos_carte[$mid])) {
                // Première pharmacie rencontrée pour ce médicament : la plus proche.
                $infos_carte[$mid] = array(
                    'categorie'      => $ligne['Categorie'],
                    'prix_min'       => null,
                    'quantite'       => 0,
                    'pharmacie'      => $ligne['Nom'],
                    'pharmacie_id'   => (int)$ligne['Id_pharmacie'],
                    'quartier'       => $ligne['Quartier'],
                    'commune'        => $ligne['Commune'],
                    'latitude'       => (float)$ligne['Latitude'],
                    'longitude'      => (float)$ligne['Longitude'],
                    'distance_metres'=> $geo_precise ? $ligne['__distance'] : null,
                );
            }

            $prix = ($ligne['Prix'] === null) ? null : (float)$ligne['Prix'];
            if ($prix !== null && ($infos_carte[$mid]['prix_min'] === null || $prix < $infos_carte[$mid]['prix_min'])) {
                $infos_carte[$mid]['prix_min'] = $prix;
            }
            $infos_carte[$mid]['quantite'] += (int)$ligne['Quantite'];
        }
    } catch(PDOException $e) {
        // L'affichage reste utilisable même si cet enrichissement échoue.
        $infos_carte = array();
    }
}

// Listes déroulantes des filtres : lectures simples, sans impact métier.
try {
    $options_filtres['categorie'] = $pdo->query(
        "SELECT DISTINCT c.Libelle FROM categorie c
         JOIN medicament m ON m.Id_categorie = c.Id_categorie
         WHERE c.Libelle IS NOT NULL AND c.Libelle <> ''
         ORDER BY c.Libelle"
    )->fetchAll(PDO::FETCH_COLUMN);

    $options_filtres['forme'] = $pdo->query(
        "SELECT DISTINCT Forme FROM medicament
         WHERE Forme IS NOT NULL AND Forme <> '' ORDER BY Forme"
    )->fetchAll(PDO::FETCH_COLUMN);

    $options_filtres['dosage'] = $pdo->query(
        "SELECT DISTINCT Dosage FROM medicament
         WHERE Dosage IS NOT NULL AND Dosage <> '' ORDER BY Dosage LIMIT 40"
    )->fetchAll(PDO::FETCH_COLUMN);

    $options_filtres['localisation'] = $pdo->query(
        "SELECT DISTINCT Quartier FROM pharmacie
         WHERE Statut = 'active' AND Quartier IS NOT NULL AND Quartier <> ''
         ORDER BY Quartier"
    )->fetchAll(PDO::FETCH_COLUMN);

    // Suggestions de saisie : noms des médicaments actifs du catalogue.
    $suggestions = $pdo->query(
        "SELECT Nom_commercial FROM medicament
         WHERE Statut = 'actif' AND Nom_commercial IS NOT NULL AND Nom_commercial <> ''
         ORDER BY Nom_commercial LIMIT 400"
    )->fetchAll(PDO::FETCH_COLUMN);

    // Compteurs affichés dans la partie haute de la page (lecture seule).
    $stats_plateforme = array(
        'pharmacies'   => (int)$pdo->query("SELECT COUNT(*) FROM pharmacie WHERE Statut = 'active'")->fetchColumn(),
        'medicaments'  => (int)$pdo->query("SELECT COUNT(*) FROM medicament WHERE Statut = 'actif'")->fetchColumn(),
        'en_stock'     => (int)$pdo->query("SELECT COUNT(DISTINCT Id_medicament) FROM stock WHERE Statut = 'disponible' AND Quantite > 0")->fetchColumn(),
    );
} catch(PDOException $e) {
    $options_filtres = array('categorie' => array(), 'forme' => array(), 'dosage' => array(), 'localisation' => array());
    $suggestions = array();
    $stats_plateforme = array('pharmacies' => 0, 'medicaments' => 0, 'en_stock' => 0);
}

// Points à afficher sur la carte : pharmacies correspondant aux résultats.
// On réutilise les pharmacies déjà calculées (étape 2) et, à défaut, la
// pharmacie la plus proche de chaque médicament trouvé (étape 1).
$points_carte = array();
$ids_deja_vus = array();
if (!empty($pharmacies_result)) {
    foreach ($pharmacies_result as $ph) {
        $pid = (int)$ph['Id_pharmacie'];
        if (isset($ids_deja_vus[$pid])) { continue; }
        $ids_deja_vus[$pid] = true;
        $points_carte[] = array(
            'id'       => $pid,
            'nom'      => $ph['Nom'],
            'quartier' => $ph['Quartier'] ?? '',
            'commune'  => $ph['Commune'] ?? '',
            'latitude' => (float)$ph['Latitude'],
            'longitude'=> (float)$ph['Longitude'],
            'distance' => $ph['distance_metres'] ?? null,
            'couvertes'=> (int)$ph['nb_couvertes'],
            'total'    => (int)$ph['Total_medicaments'],
        );
    }
} else {
    foreach ($infos_carte as $infos) {
        $pid = (int)$infos['pharmacie_id'];
        if (isset($ids_deja_vus[$pid])) { continue; }
        $ids_deja_vus[$pid] = true;
        $points_carte[] = array(
            'id'       => $pid,
            'nom'      => $infos['pharmacie'],
            'quartier' => $infos['quartier'] ?? '',
            'commune'  => $infos['commune'] ?? '',
            'latitude' => $infos['latitude'],
            'longitude'=> $infos['longitude'],
            'distance' => $infos['distance_metres'],
            'couvertes'=> 1,
            'total'    => 1,
        );
    }
}
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
    <?php require_once '../../includes/geo_style.php'; ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/palette.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/recherche.css">
    <!-- Favicon & identité visuelle PharmaGéo -->
    <link rel="icon" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon.ico" sizes="any">
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo BASE_URL; ?>assets/identite/favicons/apple-touch-icon.png">
    <link rel="manifest" href="<?php echo BASE_URL; ?>assets/identite/favicons/site.webmanifest">
    <meta property="og:image" content="<?php echo BASE_URL; ?>assets/identite/reseaux-sociaux/og-image-1200x630.png">
</head>
<body>
    <!-- ===== HEADER ===== -->
    <?php include_once '../../includes/header.php'; ?>

    <!-- ============================================================
         HERO — ACCROCHE + ZONE DE RECHERCHE
         ============================================================ -->
    <section class="rg-hero">
        <div class="rg-hero__bg" aria-hidden="true"></div>
        <div class="container position-relative">
            <div class="rg-hero__inner">
                <span class="rg-pill">
                    <i class="fas fa-heart-pulse"></i>
                    Réseau de pharmacies partenaires à votre écoute
                </span>

                <h1 class="rg-hero__title">Trouvez votre médicament rapidement</h1>
                <p class="rg-hero__sub">
                    Saisissez un nom commercial, une DCI ou une catégorie. PharmaGeo recherche
                    pour vous la pharmacie la plus proche qui dispose du traitement,
                    et vous indique immédiatement la distance et la disponibilité.
                </p>

                <?php if (empty($selected_meds)): ?>
                    <!-- ===== RECHERCHE ===== -->
                    <!-- Le fragment "#resultats" est conserve par le navigateur lors d'un envoi
                         GET : meme sans JavaScript, la page ramene directement aux resultats. -->
                    <form method="GET" action="<?php echo BASE_URL; ?>pages/patient/recherche.php#resultats" class="rg-search" id="formRecherche" autocomplete="off">
                        <div class="rg-search__tabs" role="tablist" aria-label="Mode de saisie">
                            <button type="button" class="rg-search__tab is-active" data-rg-mode="simple" role="tab">
                                <i class="fas fa-magnifying-glass me-1"></i> Recherche simple
                            </button>
                            <button type="button" class="rg-search__tab" data-rg-mode="liste" role="tab">
                                <i class="fas fa-list-check me-1"></i> Liste d'ordonnance
                            </button>
                        </div>

                        <!-- Sans JavaScript, on affiche directement la saisie multiple,
                             qui reste ainsi accessible a tous. -->
                        <noscript>
                            <style>
                                #rgqListe { display: block !important; }
                                #rgqSimple { display: none !important; }
                                .rg-search__tabs { display: none !important; }
                            </style>
                        </noscript>

                        <div class="rg-search__field">
                            <i class="fas fa-search rg-search__icon" aria-hidden="true"></i>

                            <input type="text" name="q" id="rgqSimple" class="rg-search__input"
                                   value="<?php echo htmlspecialchars($raw_query); ?>"
                                   placeholder="Ex : paracétamol, amoxicilline…"
                                   aria-label="Rechercher un médicament par nom, DCI ou catégorie"
                                   autocomplete="off" required>

                            <textarea name="q" id="rgqListe" class="rg-search__input rg-search__textarea is-hidden"
                                      rows="2"
                                      placeholder="Ex : Paracétamol&#10;Amoxicilline&#10;Vitamine C"
                                      aria-label="Saisir une liste de médicaments"><?php echo htmlspecialchars($raw_query); ?></textarea>

                            <ul class="rg-suggest" id="rgSuggest" role="listbox" aria-label="Suggestions" hidden></ul>

                            <button type="submit" class="rg-search__submit" id="btnRechercher">
                                <span class="rg-search__submit-label">
                                    <i class="fas fa-search"></i> Rechercher
                                </span>
                                <span class="rg-spinner" aria-hidden="true"></span>
                            </button>
                        </div>

                        <p class="rg-search__hint">
                            <i class="fas fa-circle-info mt-1"></i>
                            <span>
                                Recherche par nom du médicament, DCI (substance active) ou catégorie.
                                Séparez plusieurs médicaments par une virgule ou une nouvelle ligne :
                                les fautes de frappe sont corrigées automatiquement.
                            </span>
                        </p>
                    </form>
                <?php else: ?>
                    <!-- Recherche déjà effectuée : on montre l'état courant -->
                    <div class="rg-search text-center">
                        <div class="d-flex flex-wrap justify-content-center gap-2 align-items-center">
                            <?php if ($acces_direct): ?>
                                <span class="rg-badge rg-badge--ok">
                                    <i class="fas fa-circle-check"></i>
                                    Médicament trouvé — fiche et pharmacies qui en disposent
                                </span>
                            <?php else: ?>
                                <span class="rg-badge rg-badge--ok">
                                    <i class="fas fa-layer-group"></i>
                                    <?php echo count($selected_meds); ?> médicament(s) dans votre sélection
                                </span>
                            <?php endif; ?>
                            <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php<?php echo $raw_query !== '' ? '?q=' . urlencode($raw_query) . '&selection=1' : '?selection=1'; ?>"
                               class="rg-btn rg-btn--ghost">
                                <i class="fas fa-arrow-left"></i> Modifier ma sélection
                            </a>
                            <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="rg-btn rg-btn--primary">
                                <i class="fas fa-rotate"></i> Nouvelle recherche
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="rg-stats">
                    <div class="rg-stat">
                        <span class="rg-stat__value"><?php echo (int)$stats_plateforme['pharmacies']; ?></span>
                        <span class="rg-stat__label">pharmacies partenaires</span>
                    </div>
                    <div class="rg-stat">
                        <span class="rg-stat__value"><?php echo (int)$stats_plateforme['medicaments']; ?></span>
                        <span class="rg-stat__label">médicaments référencés</span>
                    </div>
                    <div class="rg-stat">
                        <span class="rg-stat__value"><?php echo (int)$stats_plateforme['en_stock']; ?></span>
                        <span class="rg-stat__label">médicaments en stock</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         ZONE DE RÉSULTATS
         ============================================================ -->
    <main class="rg-zone" id="resultats" tabindex="-1" data-rg-scroll="<?php echo $recherche_effectuee ? '1' : '0'; ?>">
        <div class="container">

            <?php if (isset($error)): ?>
                <div class="rg-alert rg-alert--danger">
                    <i class="fas fa-exclamation-circle rg-alert__ico"></i>
                    <div class="rg-alert__body"><?php echo htmlspecialchars($error); ?></div>
                </div>
            <?php endif; ?>

            <!-- Position de référence pour les distances -->
            <div class="mb-3"><?php echo geoAfficherPosition($position); ?></div>

            <!-- Bandeau invitant à autoriser la localisation.
                 Tant qu'il est affiché, aucune distance n'est publiée :
                 mieux vaut une information absente qu'un chiffre faux. -->
            <?php echo geoBandeauPosition($position); ?>

            <?php
            // Y a-t-il des résultats à filtrer ? (étape 1 : médicaments,
            // étape 2 : pharmacies)
            $afficher_filtres = ($recherche_effectuee && !empty($medicaments_trouves)) || !empty($pharmacies_result);
            ?>

            <!-- ===== FILTRES ===== -->
            <?php if ($afficher_filtres): ?>
                <section class="rg-filters" id="rgFiltres" aria-label="Filtres de recherche">
                    <div class="rg-filters__head">
                        <h2 class="rg-filters__title">
                            <i class="fas fa-sliders"></i> Affiner les résultats
                        </h2>
                        <button type="button" class="rg-reset" id="rgResetFiltres">
                            <i class="fas fa-rotate-left"></i> Réinitialiser les filtres
                        </button>
                    </div>

                    <div class="rg-filters__grid">
                        <div class="rg-field">
                            <label class="rg-field__label" for="rgFiltreCategorie">
                                <i class="fas fa-layer-group"></i> Catégorie
                            </label>
                            <select class="rg-select" id="rgFiltreCategorie" data-rg-filtre="categorie">
                                <option value="">Toutes les catégories</option>
                                <?php foreach ($options_filtres['categorie'] as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="rg-field">
                            <label class="rg-field__label" for="rgFiltreForme">
                                <i class="fas fa-capsules"></i> Forme
                            </label>
                            <select class="rg-select" id="rgFiltreForme" data-rg-filtre="forme">
                                <option value="">Toutes les formes</option>
                                <?php foreach ($options_filtres['forme'] as $forme): ?>
                                    <option value="<?php echo htmlspecialchars($forme); ?>"><?php echo htmlspecialchars($forme); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="rg-field">
                            <label class="rg-field__label" for="rgFiltreDosage">
                                <i class="fas fa-weight-hanging"></i> Dosage
                            </label>
                            <select class="rg-select" id="rgFiltreDosage" data-rg-filtre="dosage">
                                <option value="">Tous les dosages</option>
                                <?php foreach ($options_filtres['dosage'] as $dosage): ?>
                                    <option value="<?php echo htmlspecialchars($dosage); ?>"><?php echo htmlspecialchars($dosage); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="rg-field">
                            <label class="rg-field__label" for="rgFiltreDispo">
                                <i class="fas fa-circle-check"></i> Disponibilité
                            </label>
                            <select class="rg-select" id="rgFiltreDispo" data-rg-filtre="dispo">
                                <option value="">Toutes</option>
                                <option value="1">Disponible</option>
                                <option value="0">Indisponible</option>
                            </select>
                        </div>

                        <div class="rg-field">
                            <label class="rg-field__label" for="rgFiltreLieu">
                                <i class="fas fa-map-location-dot"></i> Localisation
                            </label>
                            <select class="rg-select" id="rgFiltreLieu" data-rg-filtre="localisation">
                                <option value="">Tous les quartiers</option>
                                <?php foreach ($options_filtres['localisation'] as $quartier): ?>
                                    <option value="<?php echo htmlspecialchars($quartier); ?>"><?php echo htmlspecialchars($quartier); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="rg-field">
                            <label class="rg-field__label" for="rgFiltreTexte">
                                <i class="fas fa-filter"></i> Recherche rapide
                            </label>
                            <input type="search" class="rg-searchmin" id="rgFiltreTexte"
                                   placeholder="Nom, DCI, pharmacie…"
                                   aria-label="Recherche rapide dans les résultats">
                        </div>
                    </div>

                    <div class="rg-chips" id="rgChips" style="display:none"></div>
                </section>
            <?php endif; ?>

            <!-- ===== ÉTAPE 2 : PHARMACIES (une sélection existe déjà) ===== -->
            <?php if (!empty($selected_meds)): ?>

                <?php if ($acces_direct && count($selected_meds) === 1): ?>
                    <?php
                    // ===== FICHE DU MÉDICAMENT TROUVÉ =====
                    // C'est la destination du clic sur « Rechercher » : la fiche
                    // du médicament s'affiche en premier, et les pharmacies qui
                    // en disposent suivent juste en dessous. Il n'y a donc rien à
                    // cocher : on ne demande rien au patient pour voir le résultat.
                    $mid_direct = (int)reset($selected_meds);
                    $med_direct = isset($medicaments_trouves[$mid_direct]) ? $medicaments_trouves[$mid_direct] : null;
                    ?>
                    <?php if ($med_direct !== null):
                        $infos_d = isset($infos_carte[$mid_direct]) ? $infos_carte[$mid_direct] : null;
                        $dispo_d  = ((int)$med_direct['nb_pharmacies'] > 0);
                        $lieu_d   = '';
                        if ($infos_d) {
                            $lieu_d = (string)$infos_d['pharmacie'];
                            if (!empty($infos_d['quartier'])) $lieu_d .= ' — ' . $infos_d['quartier'];
                            if (!empty($infos_d['commune']))  $lieu_d .= ', ' . $infos_d['commune'];
                        }
                        $url_pharma_d = $infos_d
                            ? BASE_URL . 'pages/patient/pharmacie.php?id=' . (int)$infos_d['pharmacie_id'] . '&med[]=' . $mid_direct
                            : '';
                        $url_resa_d = ($infos_d && $dispo_d)
                            ? BASE_URL . 'pages/patient/reservation.php?stock=' . $mid_direct . '&pharmacie=' . (int)$infos_d['pharmacie_id']
                            : '';
                        ?>
                        <section class="rg-direct" data-rg-direct-med data-rg-ancre aria-label="Médicament trouvé">
                            <h2 class="rg-filters__title mb-3">
                                <i class="fas fa-capsules"></i> Médicament trouvé
                            </h2>

                            <article class="rg-card">
                                <div class="rg-card__band<?php echo $dispo_d ? '' : ' rg-card__band--off'; ?>"></div>
                                <div class="rg-card__body">
                                    <div class="rg-card__head">
                                        <div style="min-width: 0;">
                                            <h3 class="rg-card__title"><?php echo htmlspecialchars($med_direct['Nom_commercial']); ?></h3>
                                            <?php if (!empty($med_direct['DCI'])): ?>
                                                <p class="rg-card__sub">
                                                    <i class="fas fa-flask me-1"></i> DCI : <?php echo htmlspecialchars($med_direct['DCI']); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php if ($dispo_d): ?>
                                                <span class="rg-badge rg-badge--ok">
                                                    <i class="fas fa-circle-check"></i> Disponible
                                                </span>
                                            <?php else: ?>
                                                <span class="rg-badge rg-badge--off">
                                                    <i class="fas fa-circle-xmark"></i> Indisponible
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($infos_d['categorie'])): ?>
                                                <span class="rg-badge rg-badge--info">
                                                    <i class="fas fa-layer-group"></i><?php echo htmlspecialchars($infos_d['categorie']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="rg-specs">
                                        <div class="rg-spec">
                                            <span class="rg-spec__label">Forme</span>
                                            <span class="rg-spec__value"><?php echo htmlspecialchars($med_direct['Forme'] ?: '—'); ?></span>
                                        </div>
                                        <div class="rg-spec">
                                            <span class="rg-spec__label">Dosage</span>
                                            <span class="rg-spec__value"><?php echo htmlspecialchars($med_direct['Dosage'] ?: '—'); ?></span>
                                        </div>
                                        <div class="rg-spec">
                                            <span class="rg-spec__label">Prix minimum</span>
                                            <span class="rg-spec__value rg-spec__value--price">
                                                <?php echo ($infos_d && $infos_d['prix_min'] !== null)
                                                    ? number_format($infos_d['prix_min'], 0, ',', ' ') . ' FCFA'
                                                    : '—'; ?>
                                            </span>
                                        </div>
                                        <div class="rg-spec">
                                            <span class="rg-spec__label">Quantité en stock</span>
                                            <span class="rg-spec__value"><?php echo $infos_d ? (int)$infos_d['quantite'] : 0; ?></span>
                                        </div>
                                    </div>

<?php
                                        // La distance n'est affichée que si la position
                                        // GPS est réellement connue.
                                        $distance_d = $infos_d ? geoFormaterDistance($infos_d['distance_metres']) : null;
                                        ?>
                                        <?php if ($lieu_d !== ''): ?>
                                            <p class="rg-card__sub">
                                                <i class="fas fa-location-dot me-1"></i>
                                                <?php echo htmlspecialchars($lieu_d); ?>
                                                <?php if ($distance_d !== null): ?>
                                                    <span class="rg-badge rg-badge--info ms-1" title="Distance à vol d'oiseau">
                                                        à <?php echo $distance_d; ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="rg-badge rg-badge--muted ms-1"
                                                          title="Activez la localisation pour connaître la distance">
                                                        <i class="fas fa-location-arrow me-1"></i> distance inconnue
                                                    </span>
                                                <?php endif; ?>
                                            </p>
                                        <?php elseif ($distance_d !== null): ?>
                                            <p class="rg-card__sub">
                                                <i class="fas fa-location-dot me-1"></i>
                                                <span class="rg-badge rg-badge--info" title="Distance à vol d'oiseau">
                                                    à <?php echo $distance_d; ?>
                                                </span>
                                            </p>
                                        <?php endif; ?>

                                    <div class="d-flex flex-wrap gap-2 mt-3">
                                        <?php if ($url_resa_d !== ''): ?>
                                            <a href="<?php echo htmlspecialchars($url_resa_d); ?>" class="rg-btn rg-btn--primary">
                                                <i class="fas fa-calendar-check"></i> Réserver
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($url_pharma_d !== ''): ?>
                                            <a href="<?php echo htmlspecialchars($url_pharma_d); ?>" class="rg-btn rg-btn--ghost">
                                                <i class="fas fa-store"></i> Voir la pharmacie
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($infos_d): ?>
                                            <a href="<?php echo BASE_URL; ?>pages/patient/carte.php?id=<?php echo (int)$infos_d['pharmacie_id']; ?>" class="rg-btn rg-btn--ghost">
                                                <i class="fas fa-map-location-dot"></i> Sur la carte
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        </section>
                    <?php endif; ?>
                <?php endif; ?>

                <?php
                // Rappel de ce que la recherche a compris : sans cette information,
                // une faute de frappe ferait croire à tort que rien n'a été trouvé.
                if ($recherche_effectuee): ?>
                    <div class="rg-alert rg-alert--info">
                        <i class="fas fa-list-check rg-alert__ico"></i>
                        <div class="rg-alert__body">
                            <span class="rg-alert__title">Recherche comprise comme :</span>
                            <?php foreach ($requetes as $req): ?>
                                <span class="rg-badge rg-badge--ok me-1">
                                    <?php echo htmlspecialchars($req['libelle']); ?>
                                    <?php if ($req['type'] === 'molecule'): ?>
                                        <i class="fas fa-layer-group" title="N'importe quel produit de cette molécule"></i>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                            <?php foreach ($requetes_introuvables as $t): ?>
                                <span class="rg-badge rg-badge--off">
                                    <?php echo htmlspecialchars($t['terme']); ?> — introuvable, ignoré
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php
                    $corrigees = array();
                    foreach ($requetes as $req) {
                        if (!empty($req['correction'])) $corrigees[] = $req['terme'] . ' → ' . $req['correction'];
                    }
                    if (!empty($corrigees)): ?>
                        <div class="rg-alert rg-alert--ok">
                            <i class="fas fa-spell-check rg-alert__ico"></i>
                            <div class="rg-alert__body">
                                <span class="rg-alert__title">Orthographe corrigée automatiquement</span>
                                <?php foreach ($corrigees as $c): ?>
                                    <span class="rg-badge rg-badge--info"><?php echo htmlspecialchars($c); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- ===== LISTE DE SÉLECTION DU PATIENT ===== -->
                <div class="carte-selection mb-3">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <strong><i class="fas fa-check-square me-2"></i>Médicaments que vous souhaitez réserver</strong>
                        <span class="rg-badge rg-badge--ok">
                            <i class="fas fa-check-double"></i><?php echo count($selected_meds); ?> sélectionné(s)
                        </span>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($medicaments_trouves as $mid => $med): ?>
                            <?php $coche = in_array((int)$mid, $selected_meds, true); ?>
                            <?php
                            // On conserve les autres termes de la recherche pour ne pas
                            // perdre le reste de la liste.
                            $lien = BASE_URL . 'pages/patient/recherche.php?q=' . urlencode($raw_query);
                            if ($coche) $lien .= '&med%5B%5D=' . (int)$mid;
                            else {
                                foreach ($selected_meds as $autre) {
                                    if ((int)$autre !== (int)$mid) $lien .= '&med%5B%5D=' . (int)$autre;
                                }
                                $lien .= $retour_selection;
                            }
                            ?>
                            <a href="<?php echo htmlspecialchars($lien); ?>"
                               class="sel-pill <?php echo $coche ? 'sel-pill-on' : 'sel-pill-off'; ?>">
                                <i class="fas <?php echo $coche ? 'fa-check-circle' : 'fa-plus-circle'; ?> me-1"></i>
                                <?php echo htmlspecialchars($med['Nom_commercial']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="small mt-2">
                        <?php if (count($selected_meds) === count($medicaments_trouves) && !empty($medicaments_trouves)): ?>
                            <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php?q=<?php echo urlencode($raw_query) . $retour_selection; ?>" class="me-2">
                                Tout décocher
                            </a>
                        <?php else: ?>
                            <a href="<?php
                                $tout = BASE_URL . 'pages/patient/recherche.php?q=' . urlencode($raw_query);
                                foreach (array_keys($medicaments_trouves) as $mid) $tout .= '&med%5B%5D=' . (int)$mid;
                                echo htmlspecialchars($tout); ?>" class="me-2">
                                Tout cocher
                            </a>
                        <?php endif; ?>
                        <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php?q=<?php echo urlencode($raw_query); ?>&selection=1">
                            Voir la liste complète
                        </a>
                    </div>
                </div>

                <?php
                // ===== BOUTON GLOBAL « TOUT RÉSERVER » =====
                // Standard de la plateforme : toujours affiché dès qu'une
                // sélection existe, quel que soit le nombre de pharmacies.
                $urlGlobal = BASE_URL . 'pages/patient/reservation_groupe.php?';
                foreach ($ids as $mid) $urlGlobal .= 'med[]=' . (int)$mid . '&';
                $urlGlobal = rtrim($urlGlobal, '&');
                ?>
                <div class="carte-tout-reserver mb-3">
                    <div>
                        <h5 class="mb-1"><i class="fas fa-layer-group me-2"></i>Réserver toute ma liste en un clic</h5>
                        <p class="mb-0">
                            <?php echo count($ids); ?> médicament(s) sélectionné(s).
                            Choisissez la pharmacie qui convient, ou réservez tout d'un coup dans celle de votre choix.
                        </p>
                    </div>
                    <a href="<?php echo htmlspecialchars($urlGlobal); ?>" class="btn btn-tout-reserver-inline">
                        <i class="fas fa-calendar-check me-2"></i>Tout réserver (<?php echo count($ids); ?>)
                    </a>
                </div>

                <div class="rg-results-head">
                    <div>
                        <h2 class="rg-filters__title mb-2">
                            <i class="fas fa-store"></i> Pharmacies disponibles
                        </h2>
                        <div class="d-flex flex-wrap gap-2">
                            <?php
                            foreach ($selected_meds as $mid) {
                                $nom = 'Médicament';
                                if (isset($medicaments_trouves[$mid])) $nom = $medicaments_trouves[$mid]['Nom_commercial'];
                                echo '<span class="med-chip"><i class="fas fa-capsules"></i>' . htmlspecialchars($nom) . '</span>';
                            }
                            ?>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php<?php echo $raw_query !== '' ? '?q=' . urlencode($raw_query) . '&selection=1' : '?selection=1'; ?>" class="btn btn-outline-custom btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>Modifier la sélection
                        </a>
                        <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-sync-alt me-1"></i>Nouvelle recherche
                        </a>
                    </div>
                </div>

                <?php if (!$recherche_effectuee && empty($medicaments_trouves)): ?>
                    <?php
                    // Arrivée directe avec med[] sans q : on charge les noms.
                    $idsM = array_values($selected_meds);
                    $inMm = implode(',', array_fill(0, count($idsM), '?'));
                    $stmt = $pdo->prepare("SELECT * FROM medicament WHERE Id_medicament IN ($inMm) AND Statut = 'actif'");
                    $stmt->execute($idsM);
                    foreach ($stmt->fetchAll() as $row) {
                        $medicaments_trouves[(int)$row['Id_medicament']] = $row;
                    }
                    ?>
                <?php endif; ?>

                <?php if (!empty($pharmacies_result)): ?>
                    <?php
                    // Le dénominateur des badges est TOUJOURS le nombre de
                    // médicaments cochés par le patient.
                    $completes = array_filter($pharmacies_result, function($ph) {
                        return !empty($ph['complete']);
                    });
                    ?>
                    <div class="rg-alert rg-alert--ok">
                        <i class="fas fa-map-marked-alt rg-alert__ico"></i>
                        <div class="rg-alert__body">
                            <strong><?php echo count($pharmacies_result); ?></strong> pharmacie(s) possèdent au moins un
                            de vos médicaments.
                            <?php if (count($completes) > 0): ?>
                                <strong><?php echo count($completes); ?></strong> d'entre elles disposent de
                                <strong>tous</strong> vos médicaments : réservez-les en un seul clic avec « Tout réserver ».
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ===== CARTE INTERACTIVE ===== -->
                    <?php if (!empty($points_carte)): ?>
                        <section class="rg-map" id="rgMap">
                            <div class="rg-map__head">
                                <h2 class="rg-map__title">
                                    <i class="fas fa-map-location-dot"></i> Carte des pharmacies
                                </h2>
                                <button type="button" class="rg-map__toggle" id="rgMapToggle">
                                    <i class="fas fa-map"></i> Masquer la carte
                                </button>
                            </div>
                            <div class="rg-map__wrap">
                                <div class="rg-map__canvas" id="rgMapCanvas" role="region" aria-label="Carte des pharmacies"></div>
                                <div class="rg-map__loading" id="rgMapLoading">
                                    <div class="rg-spinner" style="display:block;border-color:rgba(22,163,74,0.25);border-top-color:#16A34A;"></div>
                                    <span>Chargement de la carte…</span>
                                </div>
                            </div>
                            <div class="rg-map__legend">
                                <span><i class="fas fa-store"></i> Pharmacie ayant un médicament trouvé</span>
                                <span class="lg-user"><i class="fas fa-user"></i> Votre position</span>
                            </div>
                        </section>
                    <?php endif; ?>

                    <div class="rg-results-head">
                        <span class="rg-count">
                            <i class="fas fa-list-check"></i>
                            <strong id="rgNombreAffiche"><?php echo count($pharmacies_result); ?></strong>
                            pharmacie(s) affichée(s)
                        </span>
                    </div>

                    <!-- Squelettes de chargement -->
                    <div class="rg-loading" id="rgChargement" aria-hidden="true">
                        <?php for ($i = 0; $i < 4; $i++): ?>
                            <div class="rg-skeleton">
                                <div class="rg-skeleton__line rg-skeleton__line--mid"></div>
                                <div class="rg-skeleton__line rg-skeleton__line--short"></div>
                                <div class="rg-skeleton__block"></div>
                                <div class="rg-skeleton__line"></div>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <div class="rg-grid rg-grid--wide" id="rgGrille" data-rg-ancre>
                        <?php foreach ($pharmacies_result as $ph): ?>
                            <?php $complete = !empty($ph['complete']); ?>
                            <?php
                            // Attributs utilisés par les filtres (affichage uniquement).
                            // Une pharmacie porte plusieurs médicaments : chaque
                            // caractéristique est donc une liste de valeurs
                            // séparées par « | ». Sans cela, les filtres
                            // Catégorie / Forme / Dosage ne pourraient jamais
                            // correspondre et masqueraient toutes les pharmacies.
                            $cats_ph = array();
                            $formes_ph = array();
                            $doss_ph = array();
                            foreach ($ph['medicaments'] as $mm) {
                                if (!empty($mm['Categorie'])) $cats_ph[$mm['Categorie']] = 1;
                                if (!empty($mm['Forme']))     $formes_ph[$mm['Forme']] = 1;
                                if (!empty($mm['Dosage']))    $doss_ph[$mm['Dosage']] = 1;
                            }
                            $attrs_filtre = ' data-rg-card="pharma"'
                                . ' data-nom="' . htmlspecialchars($ph['Nom']) . '"'
                                . ' data-categorie="' . htmlspecialchars(implode('|', array_keys($cats_ph))) . '"'
                                . ' data-forme="' . htmlspecialchars(implode('|', array_keys($formes_ph))) . '"'
                                . ' data-dosage="' . htmlspecialchars(implode('|', array_keys($doss_ph))) . '"'
                                . ' data-quartier="' . htmlspecialchars($ph['Quartier'] ?? '') . '"'
                                . ' data-commune="' . htmlspecialchars($ph['Commune'] ?? '') . '"'
                                . ' data-distance="' . (isset($ph['distance_metres']) && $ph['distance_metres'] !== null ? (float)$ph['distance_metres'] : '') . '"'
                                . ' data-dispo="' . ($complete ? '1' : '0') . '"';
                            ?>
                            <article class="rg-card pharmacy-card"<?php echo $attrs_filtre; ?>>
                                <div class="rg-card__band<?php echo $complete ? '' : ' rg-card__band--off'; ?>"></div>
                                <div class="rg-card__body">
                                    <div class="rg-card__head">
                                        <div class="d-flex align-items-center gap-3 flex-grow-1">
                                            <img src="<?php echo htmlspecialchars(pharma_image_url_ou_defaut($ph['Logo'] ?? null, 'pharmacie')); ?>"
                                                 alt="" class="rounded-circle" style="width: 50px; height: 50px; object-fit: cover; flex-shrink: 0;">
                                            <div style="min-width: 0;">
                                                <?php
                                                // La page de la pharmacie reçoit la sélection
                                                // courante pour proposer « Tout réserver ».
                                                $urlPagePharmacie = BASE_URL . 'pages/patient/pharmacie.php?id=' . (int)$ph['Id_pharmacie'];
                                                foreach ($ids as $id) {
                                                    $urlPagePharmacie .= '&med[]=' . (int)$id;
                                                }
                                                ?>
                                                <h3 class="rg-card__title">
                                                    <a href="<?php echo htmlspecialchars($urlPagePharmacie); ?>" class="lien-pharmacie">
                                                        <?php echo htmlspecialchars($ph['Nom']); ?>
                                                    </a>
                                                </h3>
                                                <p class="rg-card__sub">
                                                    <?php echo htmlspecialchars($ph['Quartier'] ?? ''); ?>,
                                                    <?php echo htmlspecialchars($ph['Commune'] ?? ''); ?>
                                                </p>
                                            </div>
                                        </div>
                                        <span class="rg-badge <?php echo $complete ? 'rg-badge--ok' : 'rg-badge--info'; ?>">
                                            <i class="fas <?php echo $complete ? 'fa-check-circle' : 'fa-layer-group'; ?>"></i>
                                            <?php echo (int)$ph['nb_couvertes']; ?>/<?php echo count($selected_meds); ?>
                                        </span>
                                    </div>

                                    <div class="d-flex flex-wrap gap-2 mb-3">
                                        <?php $distance_ph = geoFormaterDistance($ph['distance_metres'] ?? null); ?>
                                        <?php if ($distance_ph !== null): ?>
                                            <span class="rg-badge rg-badge--info" title="Distance à vol d'oiseau (ligne droite, non routière)">
                                                <i class="fas fa-route"></i><?php echo $distance_ph; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="rg-badge rg-badge--muted"
                                                  title="Activez la localisation pour connaître la distance">
                                                <i class="fas fa-location-arrow"></i> distance inconnue
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($ph['Telephone']): ?>
                                            <span class="rg-badge rg-badge--off">
                                                <i class="fas fa-phone"></i><?php echo htmlspecialchars($ph['Telephone']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($ph['Horaire']): ?>
                                            <span class="rg-badge rg-badge--off">
                                                <i class="fas fa-clock"></i><?php echo htmlspecialchars($ph['Horaire']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="rg-specs">
                                        <div class="rg-spec">
                                            <span class="rg-spec__label">Médicaments couverts</span>
                                            <span class="rg-spec__value"><?php echo (int)$ph['nb_couvertes']; ?> sur <?php echo count($selected_meds); ?></span>
                                        </div>
                                        <?php if ($ph['Total_prix']): ?>
                                            <div class="rg-spec">
                                                <span class="rg-spec__label">Total estimé</span>
                                                <span class="rg-spec__value rg-spec__value--price">
                                                    <?php echo number_format($ph['Total_prix'], 0, ',', ' '); ?> FCFA
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Médicaments disponibles dans cette pharmacie -->
                                    <div class="mb-2">
                                        <h4 class="rg-field__label mb-2">
                                            <i class="fas fa-capsules"></i> Médicaments disponibles
                                        </h4>
                                        <?php foreach ($ph['medicaments'] as $med): ?>
                                            <?php $urlReservation = BASE_URL . 'pages/patient/reservation.php?stock=' . $med['Id_medicament'] . '&pharmacie=' . $ph['Id_pharmacie']; ?>
                                            <div class="d-flex justify-content-between align-items-center p-2 mb-2 med-row rounded-3"
                                                 style="background: var(--rg-soft);">
                                                <div style="min-width: 0;">
                                                    <span class="fw-semibold"><?php echo htmlspecialchars($med['Nom_commercial']); ?></span>
                                                    <?php if ($med['Dosage']): ?>
                                                        <span class="rg-badge rg-badge--info ms-1"><?php echo htmlspecialchars($med['Dosage']); ?></span>
                                                    <?php endif; ?>
                                                    <div class="text-muted small">
                                                        <?php echo $med['Prix'] ? number_format($med['Prix'], 0, ',', ' ') . ' FCFA' : 'Prix non indiqué'; ?>
                                                        · <?php echo (int)$med['Quantite']; ?> en stock
                                                    </div>
                                                </div>
                                                <a href="<?php echo $urlReservation; ?>" class="rg-btn rg-btn--ghost btn-sm">
                                                    <i class="fas fa-calendar-check"></i> Réserver
                                                </a>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <?php if (!empty($ph['groupe_ids'])): ?>
                                        <?php
                                        // Le bouton groupé est TOUJOURS présent sur chaque
                                        // pharmacie : c'est un standard de la plateforme.
                                        // S'il manque un médicament, il réserve ce qui est
                                        // disponible et le manque est indiqué juste au-dessus.
                                        $urlToutReserver = BASE_URL . 'pages/patient/reservation_groupe.php?pharmacie=' . (int)$ph['Id_pharmacie'];
                                        foreach ($ph['groupe_ids'] as $id) {
                                            $urlToutReserver .= '&med[]=' . (int)$id;
                                        }
                                        ?>
                                        <a href="<?php echo htmlspecialchars($urlToutReserver); ?>" class="btn btn-tout-reserver w-100 mt-2">
                                            <i class="fas fa-layer-group me-1"></i>
                                            Tout réserver (<?php echo count($ph['groupe_ids']); ?>)
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($complete): ?>
                                        <div class="rg-alert rg-alert--ok py-2 mt-2 mb-0">
                                            <i class="fas fa-thumbs-up rg-alert__ico"></i>
                                            <div class="rg-alert__body">
                                                Cette pharmacie possède tous vos médicaments :
                                                réservez-les en un seul clic.
                                            </div>
                                        </div>
                                    <?php elseif (!empty($ph['manques'])): ?>
                                        <div class="rg-alert rg-alert--warn py-2 mt-2 mb-0">
                                            <i class="fas fa-exclamation-triangle rg-alert__ico"></i>
                                            <div class="rg-alert__body">
                                                Il manque ici :
                                                <strong><?php echo htmlspecialchars(implode(', ', $ph['manques'])); ?></strong>.
                                                Réservez ce qui est disponible ci-dessus, ou retirez ces médicaments de votre liste.
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="rg-card__actions mt-3">
                                        <a href="carte.php?id=<?php echo $ph['Id_pharmacie']; ?>" class="rg-btn rg-btn--ghost">
                                            <i class="fas fa-map-marker-alt"></i> Voir sur la carte
                                        </a>
                                        <a href="<?php echo htmlspecialchars($urlPagePharmacie); ?>" class="rg-btn rg-btn--primary">
                                            <i class="fas fa-store"></i> Voir les détails
                                        </a>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <div class="rg-empty" id="rgAucunResultat" style="display:none;">
                        <div class="rg-empty__ico"><i class="fas fa-filter-circle-xmark"></i></div>
                        <h3>Aucune pharmacie ne correspond à ces filtres</h3>
                        <p>Élargissez votre recherche en réinitialisant les filtres, ou retirez certains médicaments de votre liste.</p>
                        <button type="button" class="rg-btn rg-btn--primary" onclick="document.getElementById('rgResetFiltres').click()">
                            <i class="fas fa-rotate-left"></i> Réinitialiser les filtres
                        </button>
                    </div>

                <?php else: ?>
                    <div class="rg-empty">
                        <div class="rg-empty__ico"><i class="fas fa-store-slash"></i></div>
                        <h3>Aucune pharmacie ne possède actuellement ces médicaments</h3>
                        <p>
                            Essayez de retirer un médicament de votre sélection ou revenez plus tard :
                            les pharmacies réapprovisionnent leur stock régulièrement.
                        </p>
                        <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="rg-btn rg-btn--primary">
                            <i class="fas fa-rotate"></i> Nouvelle recherche
                        </a>
                    </div>
                <?php endif; ?>

            <?php elseif ($recherche_effectuee): ?>
                <!-- ===== ÉTAPE 1 : RÉSULTATS DE MÉDICAMENTS + COCHAGE ===== -->
                <?php if (!empty($medicaments_trouves) || !empty($requetes_introuvables)): ?>
                    <?php if (empty($medicaments_trouves)): ?>
                        <!-- Aucun médicament trouvé : message d'absence dedicated -->
                        <div class="rg-empty" data-rg-ancre>
                            <div class="rg-empty__ico"><i class="fas fa-magnifying-glass"></i></div>
                            <h3>Aucun médicament trouvé pour votre recherche.</h3>
                            <?php if (!empty($requetes)): ?>
                                <p>
                                    Nous n'avons trouvé aucun médicament correspondant à :
                                    <?php foreach ($requetes as $t): ?>
                                        <span class="rg-badge rg-badge--info"><?php echo htmlspecialchars($t['terme']); ?></span>
                                    <?php endforeach; ?>
                                </p>
                            <?php endif; ?>
                            <?php $suggeres = []; ?>
                            <?php foreach ($requetes_introuvables as $t): ?>
                                <?php if (!empty($t['suggestion'])) { $suggeres[$t['suggestion']] = $t['suggestion']; } ?>
                            <?php endforeach; ?>
                            <?php if (!empty($suggeres)): ?>
                                <p class="small">Vouliez-vous peut-être rechercher :</p>
                                <div class="d-flex flex-wrap justify-content-center gap-2 mb-3">
                                    <?php foreach ($suggeres as $s): ?>
                                        <a class="rg-chip"
                                           href="<?php echo BASE_URL; ?>pages/patient/recherche.php?q=<?php echo urlencode($s); ?>#resultats">
                                            <i class="fas fa-pills"></i><?php echo htmlspecialchars($s); ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <p class="small">
                                Vérifiez l'orthographe, essayez le nom de la molécule (DCI)
                                ou consultez la liste des médicaments populaires ci-dessus.
                            </p>
                            <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="rg-btn rg-btn--primary">
                                <i class="fas fa-magnifying-glass"></i> Lancer une nouvelle recherche
                            </a>
                        </div>
                    <?php endif; ?>

                    <div class="rg-alert rg-alert--ok">
                        <i class="fas fa-check-circle rg-alert__ico"></i>
                        <div class="rg-alert__body">
                            <strong><?php echo $total_meds; ?></strong> médicament(s) reconnu(s) sur
                            <strong><?php echo count($requetes) + count($requetes_introuvables); ?></strong> terme(s) saisi(s)
                            <?php if (!empty($requetes_introuvables)): ?>
                                , <?php echo count($requetes_introuvables); ?> introuvable(s)
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($requetes_introuvables)): ?>
                        <div class="rg-alert rg-alert--warn">
                            <i class="fas fa-exclamation-triangle rg-alert__ico"></i>
                            <div class="rg-alert__body">
                                <span class="rg-alert__title">Aucun médicament ne correspond à :</span>
                                <?php foreach ($requetes_introuvables as $t): ?>
                                    <span class="rg-badge rg-badge--info">
                                        <?php echo htmlspecialchars($t['terme']); ?>
                                        <?php if (!empty($t['suggestion'])): ?>
                                            — vouliez-vous dire <strong><?php echo htmlspecialchars($t['suggestion']); ?></strong> ?
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                                <div class="small mt-2">
                                    Ces termes sont ignorés pour la recherche de « Tout réserver ».
                                    Corrigez l'orthographe puis relancez la recherche.
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($medicaments_trouves)): ?>
                        <!-- ===== CARTE INTERACTIVE (étape 1) ===== -->
                        <?php if (!empty($points_carte)): ?>
                            <section class="rg-map" id="rgMap">
                                <div class="rg-map__head">
                                    <h2 class="rg-map__title">
                                        <i class="fas fa-map-location-dot"></i>
                                        <?php echo $geo_precise
                                            ? 'Pharmacies les plus proches'
                                            : 'Pharmacies qui proposent vos médicaments'; ?>
                                    </h2>
                                    <button type="button" class="rg-map__toggle" id="rgMapToggle">
                                        <i class="fas fa-map"></i> Masquer la carte
                                    </button>
                                </div>
                                <div class="rg-map__wrap">
                                    <div class="rg-map__canvas" id="rgMapCanvas" role="region" aria-label="Carte des pharmacies"></div>
                                    <div class="rg-map__loading" id="rgMapLoading">
                                        <div class="rg-spinner" style="display:block;border-color:rgba(22,163,74,0.25);border-top-color:#16A34A;"></div>
                                        <span>Chargement de la carte…</span>
                                    </div>
                                </div>
                                <div class="rg-map__legend">
                                    <span><i class="fas fa-store"></i> <?php echo $geo_precise
                                                ? 'Pharmacie la plus proche pour un médicament trouvé'
                                                : 'Pharmacie proposant un médicament trouvé'; ?></span>
                                    <span class="lg-user"><i class="fas fa-user"></i> Votre position</span>
                                </div>
                            </section>
                        <?php endif; ?>

                        <div class="rg-results-head">
                            <span class="rg-count">
                                <i class="fas fa-pills"></i>
                                <strong id="rgNombreAffiche"><?php echo count($medicaments_trouves); ?></strong>
                                médicament(s) trouvé(s)
                            </span>
                            <span class="text-muted small">
                                Cochez les médicaments qui vous intéressent, puis affichez les pharmacies.
                            </span>
                        </div>

                        <!-- Squelettes de chargement -->
                        <div class="rg-loading" id="rgChargement" aria-hidden="true">
                            <?php for ($i = 0; $i < 4; $i++): ?>
                                <div class="rg-skeleton">
                                    <div class="rg-skeleton__line rg-skeleton__line--mid"></div>
                                    <div class="rg-skeleton__line rg-skeleton__line--short"></div>
                                    <div class="rg-skeleton__block"></div>
                                    <div class="rg-skeleton__line"></div>
                                </div>
                            <?php endfor; ?>
                        </div>

                        <form method="GET" action="" id="formPharmacies">
                            <input type="hidden" name="q" value="<?php echo htmlspecialchars($raw_query); ?>">

                            <div class="rg-grid" id="rgGrille" data-rg-ancre>
                                <?php foreach ($medicaments_trouves as $med): ?>
                                    <?php
                                    $mid = (int)$med['Id_medicament'];
                                    $infos = isset($infos_carte[$mid]) ? $infos_carte[$mid] : null;
                                    $disponible = ((int)$med['nb_pharmacies'] > 0);
                                    $coche = $mode_selection || in_array($mid, $selected_meds, true);

                                    // Attributs utilisés par les filtres (affichage uniquement).
                                    $attrs_filtre = ' data-rg-card="med"'
                                        . ' data-nom="' . htmlspecialchars($med['Nom_commercial']) . '"'
                                        . ' data-dci="' . htmlspecialchars($med['DCI'] ?? '') . '"'
                                        . ' data-categorie="' . htmlspecialchars($infos['categorie'] ?? '') . '"'
                                        . ' data-forme="' . htmlspecialchars($med['Forme'] ?? '') . '"'
                                        . ' data-dosage="' . htmlspecialchars($med['Dosage'] ?? '') . '"'
                                        . ' data-pharmacie="' . htmlspecialchars($infos['pharmacie'] ?? '') . '"'
                                        . ' data-quartier="' . htmlspecialchars($infos['quartier'] ?? '') . '"'
                                        . ' data-commune="' . htmlspecialchars($infos['commune'] ?? '') . '"'
                                        . ' data-distance="' . (isset($infos['distance_metres']) && $infos['distance_metres'] !== null ? (float)$infos['distance_metres'] : '') . '"'
                                        . ' data-dispo="' . ($disponible ? '1' : '0') . '"';

                                    // Liens de la carte (mêmes conventions que le reste du site).
                                    $urlPharmaCarte = $infos
                                        ? BASE_URL . 'pages/patient/pharmacie.php?id=' . (int)$infos['pharmacie_id'] . '&med[]=' . $mid
                                        : BASE_URL . 'pages/patient/recherche.php';
                                    $urlReserverCarte = ($infos && $disponible)
                                        ? BASE_URL . 'pages/patient/reservation.php?stock=' . $mid . '&pharmacie=' . (int)$infos['pharmacie_id']
                                        : '';
                                    ?>
                                    <article class="rg-card"<?php echo $attrs_filtre; ?>>
                                        <div class="rg-card__band<?php echo $disponible ? '' : ' rg-card__band--off'; ?>"></div>
                                        <div class="rg-card__body">
                                            <div class="rg-card__head">
                                                <div style="min-width: 0;">
                                                    <h3 class="rg-card__title"><?php echo htmlspecialchars($med['Nom_commercial']); ?></h3>
                                                    <?php if ($med['DCI']): ?>
                                                        <p class="rg-card__sub">
                                                            <i class="fas fa-flask me-1"></i> DCI : <?php echo htmlspecialchars($med['DCI']); ?>
                                                        </p>
                                                    <?php else: ?>
                                                        <p class="rg-card__sub">&nbsp;</p>
                                                    <?php endif; ?>
                                                </div>

                                                <!-- Sélection : case à cocher (fonctionnement existant) -->
                                                <label class="checkbox-card rg-check<?php echo $coche ? ' selected' : ''; ?>"
                                                       data-med-check title="Ajouter à ma sélection">
                                                    <input type="checkbox" name="med[]" value="<?php echo $mid; ?>"
                                                           class="d-none"<?php echo $coche ? ' checked' : ''; ?>>
                                                    <span class="custom-check"><i class="fas fa-check"></i></span>
                                                </label>
                                            </div>

                                            <div class="d-flex flex-wrap gap-2 mb-3">
                                                <?php if ($disponible): ?>
                                                    <span class="rg-badge rg-badge--ok">
                                                        <i class="fas fa-circle-check"></i> Disponible
                                                    </span>
                                                <?php else: ?>
                                                    <span class="rg-badge rg-badge--off">
                                                        <i class="fas fa-circle-xmark"></i> Indisponible
                                                    </span>
                                                <?php endif; ?>
                                                <?php if (!empty($infos['categorie'])): ?>
                                                    <span class="rg-badge rg-badge--info">
                                                        <i class="fas fa-layer-group"></i><?php echo htmlspecialchars($infos['categorie']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>

                                            <div class="rg-specs">
                                                <div class="rg-spec">
                                                    <span class="rg-spec__label">Forme</span>
                                                    <span class="rg-spec__value"><?php echo htmlspecialchars($med['Forme'] ?: '—'); ?></span>
                                                </div>
                                                <div class="rg-spec">
                                                    <span class="rg-spec__label">Dosage</span>
                                                    <span class="rg-spec__value"><?php echo htmlspecialchars($med['Dosage'] ?: '—'); ?></span>
                                                </div>
                                                <div class="rg-spec">
                                                    <span class="rg-spec__label">Prix minimum</span>
                                                    <span class="rg-spec__value rg-spec__value--price">
                                                        <?php echo ($infos && $infos['prix_min'] !== null)
                                                            ? number_format($infos['prix_min'], 0, ',', ' ') . ' FCFA'
                                                            : '—'; ?>
                                                    </span>
                                                </div>
                                                <div class="rg-spec">
                                                    <span class="rg-spec__label">Quantité disponible</span>
                                                    <span class="rg-spec__value">
                                                        <?php echo ($infos && $infos['quantite'] > 0)
                                                            ? (int)$infos['quantite'] . ' unité(s)'
                                                            : '—'; ?>
                                                    </span>
                                                </div>
                                            </div>

                                            <!-- Pharmacie de référence (affichage) -->
                                            <?php if ($infos): ?>
                                                <div class="rg-card__pharma">
                                                    <div class="rg-card__pharma-ico"><i class="fas fa-store"></i></div>
                                                    <div style="min-width: 0;">
                                                        <p class="rg-card__pharma-name">
                                                            <?php echo htmlspecialchars($infos['pharmacie']); ?>
                                                            <?php if (!$geo_precise): ?>
                                                                <?php /* Sans position connue, aucune pharmacie
                                                                         n'est « la plus proche » : on ne
                                                                         l'affirme pas. */ ?>
                                                                <span class="rg-badge rg-badge--muted ms-1">pharmacie disponible</span>
                                                            <?php endif; ?>
                                                        </p>
                                                        <p class="rg-card__pharma-meta">
                                                            <i class="fas fa-map-marker-alt"></i>
                                                            <?php echo htmlspecialchars(trim(($infos['quartier'] ?? '') . ' ' . ($infos['commune'] ?? '')) ?: 'Quartier non renseigné'); ?>
                                                            <?php
                                                            $distance_med = geoFormaterDistance($infos['distance_metres'] ?? null);
                                                            if ($distance_med !== null) {
                                                                echo ' · <span title="Distance à vol d\'oiseau (ligne droite, non routière)"><i class="fas fa-route"></i> ' . $distance_med . '</span>';
                                                            } else {
                                                                echo ' · <i class="fas fa-location-arrow"></i> distance inconnue';
                                                            }
                                                            ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($med['Description'])): ?>
                                                <details class="rg-details mb-2">
                                                    <summary><i class="fas fa-chevron-down"></i> Description du médicament</summary>
                                                    <div class="rg-details__body"><?php echo htmlspecialchars($med['Description']); ?></div>
                                                </details>
                                            <?php endif; ?>

                                            <div class="rg-card__actions">
                                                <a href="<?php echo htmlspecialchars($urlPharmaCarte); ?>" class="rg-btn rg-btn--ghost">
                                                    <i class="fas fa-eye"></i> Voir les détails
                                                </a>
                                                <?php if ($disponible && $urlReserverCarte): ?>
                                                    <a href="<?php echo htmlspecialchars($urlReserverCarte); ?>" class="rg-btn rg-btn--primary">
                                                        <i class="fas fa-calendar-check"></i> Réserver
                                                    </a>
                                                <?php else: ?>
                                                    <span class="rg-btn rg-btn--disabled" aria-disabled="true">
                                                        <i class="fas fa-ban"></i> Indisponible
                                                    </span>
                                                <?php endif; ?>
                                            </div>

                                            <div class="rg-card__pharma-meta mt-2">
                                                <i class="fas fa-store"></i>
                                                Disponible dans <strong><?php echo (int)$med['nb_pharmacies']; ?></strong> pharmacie(s)
                                            </div>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>

                            <div class="rg-empty" id="rgAucunResultat" style="display:none;">
                                <div class="rg-empty__ico"><i class="fas fa-filter-circle-xmark"></i></div>
                                <h3>Aucun médicament ne correspond à ces filtres</h3>
                                <p>Modifiez ou réinitialisez vos critères pour revoir les médicaments trouvés.</p>
                                <button type="button" class="rg-btn rg-btn--primary" onclick="document.getElementById('rgResetFiltres').click()">
                                    <i class="fas fa-rotate-left"></i> Réinitialiser les filtres
                                </button>
                            </div>

                            <div class="text-center mt-4">
                                <button type="submit" class="btn btn-primary-custom btn-lg" id="btnVoirPharmacies">
                                    <i class="fas fa-store me-2"></i>Voir les pharmacies qui possèdent ces médicaments
                                    (<span id="rgCompteurSelection">0</span>)
                                </button>
                                <p class="text-muted small mt-2">
                                    <i class="fas fa-info-circle me-1"></i>Cochez au moins un médicament
                                </p>
                            </div>
                        </form>
                    <?php endif; ?>

                    <div class="text-center mt-3">
                        <a href="<?php echo BASE_URL; ?>pages/patient/recherche.php" class="rg-btn rg-btn--ghost">
                            <i class="fas fa-sync-alt"></i> Nouvelle recherche
                        </a>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- ===== ÉTAT INITIAL ===== -->
                <div class="rg-empty">
                    <div class="rg-empty__ico"><i class="fas fa-prescription-bottle-medical"></i></div>
                    <h3>Commencez votre recherche</h3>
                    <p>
                        Saisissez un ou plusieurs médicaments dans la barre ci-dessus.
                        Vous pouvez indiquer un nom commercial, une DCI ou une catégorie.
                    </p>
                    <?php
                    $populaires = $pdo->query("SELECT Nom_commercial FROM medicament WHERE Statut = 'actif' ORDER BY Id_medicament LIMIT 8")->fetchAll();
                    if (!empty($populaires)): ?>
                        <h4 class="rg-field__label justify-content-center mb-3">Médicaments populaires</h4>
                        <div class="d-flex flex-wrap justify-content-center gap-2">
                            <?php foreach ($populaires as $p): ?>
                                <a href="?q=<?php echo urlencode($p['Nom_commercial']); ?>" class="rg-chip">
                                    <i class="fas fa-pills"></i><?php echo htmlspecialchars($p['Nom_commercial']); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- ===== FOOTER ===== -->
    <?php include_once '../../includes/footer.php'; ?>

    <!-- Données utilisées par l'interface (lecture seule) -->
    <script>
        window.RG_SUGGESTIONS = <?php echo json_encode($suggestions, JSON_UNESCAPED_UNICODE); ?>;
        window.RG_MAP = <?php echo json_encode(array('pharmacies' => $points_carte), JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <!-- Script historique de la page (autocomplétion, géolocalisation) : conservé tel quel -->
    <script src="<?php echo BASE_URL; ?>assets/js/recherche.js"></script>
    <!-- Interface de la page de recherche : modes de saisie, suggestions, filtres, carte -->
    <script src="<?php echo BASE_URL; ?>assets/js/recherche_ui.js"></script>
</body>
</html>