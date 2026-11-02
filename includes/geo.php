<?php
// ============================================================
// PharmaGeo — Geolocalisation de la personne
//
// Source unique de verite pour la position servant de reference au
// calcul des distances. Ordre de resolution :
//   1. session : position obtenue recemment par le navigateur (GPS)
//   2. base    : derniere position connue de l'utilisateur connecte
//   3. defaut  : centre de Porto-Novo (valeur de repli)
//
// Point essentiel : une position qui n'est pas reellement connue de la
// personne ne doit JAMAIS servir a afficher une distance. Les points 2 et 3
// servent uniquement a centrer la carte. C'est pourquoi chaque position
// est accompagnee d'une fiabilite (voir geoPositionEstPrecise) et que les
// appelants doivent passer par geoDistanceAffichee().
//
// Ce fichier s'auto-charge : il inclut config.php et session.php.
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';

// Centre de la zone couverte par la plateforme. Utilise uniquement
// lorsqu'aucune position reelle n'a pu etre obtenue.
if (!defined('GEO_LAT_DEFAUT')) { define('GEO_LAT_DEFAUT', 6.4970); }
if (!defined('GEO_LNG_DEFAUT')) { define('GEO_LNG_DEFAUT', 2.6050); }

// Duree de validite d'une position en session, en secondes (30 minutes).
if (!defined('GEO_DUREE_VALIDITE')) { define('GEO_DUREE_VALIDITE', 1800); }

// Ecart maximal (en km) en dessous duquel deux positions sont jugees
// identiques : evite de recharger la page pour un deplacement negligeable.
if (!defined('GEO_SEUIL_EGALITE')) { define('GEO_SEUIL_EGALITE', 0.05); }

// Precision GPS maximale (en metres) acceptee pourConsiderer une distance
// comme exploitable. Au-dela, le signal est trop flou : mieux vaut demander
// une nouvelle mesure que d'affirmer une distance au metre pres.
if (!defined('GEO_PRECISION_MAX')) { define('GEO_PRECISION_MAX', 150); }

// Au-dela de cette incertitude (en metres), la position reste utilisee mais
// elle est signalee comme approximative : la distance affichee n'est alors
// qu'indicative et peut se tromper de plusieurs centaines de metres.
if (!defined('GEO_PRECISION_APPROX')) { define('GEO_PRECISION_APPROX', 1000); }

// Memorise si les colonnes Latitude/Longitude existent sur la table
// utilisateur, afin de ne pas retenter la requete a chaque appel.
$GEO_COLONNES_UTILISATEUR = null;

// ============================================================
// COORDONNEES
// ============================================================

/**
 * Verifier qu'un couple latitude/longitude est exploitable.
 * Le couple (0, 0) est rejete : c'est le "point nul" situe en pleine
 * mer, symptome classique d'une geolocalisation echouee.
 */
function geoCoordonneesValides($lat, $lng) {
    if (!is_numeric($lat) || !is_numeric($lng)) return false;
    $lat = (float)$lat;
    $lng = (float)$lng;
    if (!is_finite($lat) || !is_finite($lng)) return false;
    if ($lat < -90 || $lat > 90) return false;
    if ($lng < -180 || $lng > 180) return false;
    if ($lat == 0.0 && $lng == 0.0) return false;
    return true;
}

/**
 * Normaliser une position renvoyee par le navigateur en metres.
 * Retourne null si la valeur n'est pas un nombre fini positif.
 */
function geoNormaliserPrecision($metres) {
    if ($metres === null || $metres === '' || !is_numeric($metres)) return null;
    $m = (float)$metres;
    if (!is_finite($m) || $m < 0) return null;
    // Au-dela de 50 km le signal n'a aucun rapport avec une position
    // humaine : on abandonne la mesure plutot que de la transmettre.
    if ($m > 50000) return null;
    return $m;
}

/**
 * Distance en kilometres entre deux points (formule de la haversine).
 * C'est le meme calcul que la version SQL utilisee dans les requetes,
 * ce qui garantit des resultats identiques cote serveur et navigateur.
 *
 * Ordre des arguments : (latitude 1, longitude 1, latitude 2, longitude 2).
 */
function geoDistanceKm($lat1, $lng1, $lat2, $lng2) {
    // Sans coordonnees exploitables, aucune distance n'est calculable :
    // renvoyer null evite d'afficher un nombre faux (ex. 0).
    if (!geoCoordonneesValides($lat1, $lng1) || !geoCoordonneesValides($lat2, $lng2)) {
        return null;
    }

    $rayonTerre = 6371.0;
    $dLat = deg2rad((float)$lat2 - (float)$lat1);
    $dLng = deg2rad((float)$lng2 - (float)$lng1);
    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad((float)$lat1)) * cos(deg2rad((float)$lat2))
       * sin($dLng / 2) * sin($dLng / 2);

    // Protection contre les depassements dus aux arrondis flottants.
    $a = max(0.0, min(1.0, $a));

    return $rayonTerre * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Distance en METRES entre deux points (haversine).
 * Fonction de reference pour tout affichage : le formatage se fait
 * ensuite avec geoFormaterDistance().
 */
function geoDistanceMetres($lat1, $lng1, $lat2, $lng2) {
    $km = geoDistanceKm($lat1, $lng1, $lat2, $lng2);
    return ($km === null) ? null : $km * 1000.0;
}

/**
 * Formater une distance exprimée en metres pour l'affichage.
 *   - en dessous de 1 km  : « 250 m »
 *   - au-dela de 1 km    : « 2,40 km »
 * Renvoie null si la valeur n'est pas exploitable.
 */
function geoFormaterDistance($metres) {
    if ($metres === null || !is_numeric($metres)) return null;
    $m = (float)$metres;
    if (!is_finite($m) || $m < 0) return null;

    if ($m < 1000) {
        // Sous 10 m on arrondit au metre ; au-dela on arrondit a la
        // dizaine, une distance de rue n'a pas besoin d'une precision
        // decimale et « 250 m » se lit mieux que « 253 m ».
        if ($m < 10) return round($m) . ' m';
        return round($m, -1) . ' m';
    }

    // Au-dela du kilometre : deux decimales, comme demande.
    return str_replace('.', ',', number_format($m / 1000, 2, '.', '')) . ' km';
}

/**
 * Deux positions sont-elles considerees comme equivalentes ?
 * Permet d'eviter un rechargement de page inutile quand la personne
 * n'a pas bouge de facon perceptible.
 */
function geoPositionsEquivalentes($lat1, $lng1, $lat2, $lng2) {
    if (!geoCoordonneesValides($lat1, $lng1) || !geoCoordonneesValides($lat2, $lng2)) {
        return false;
    }
    $km = geoDistanceKm($lat1, $lng1, $lat2, $lng2);
    return ($km !== null && $km <= GEO_SEUIL_EGALITE);
}

// ============================================================
// POSITION DE L'UTILISATEUR
// ============================================================

/**
 * La position obtenue permet-elle d'afficher une distance ?
 *
 * Des que le navigateur a fourni une position (source « gps ») avec des
 * coordonnees valides, la distance est calculee : une mesure peu precise
 * (reseau Wi-Fi, plusieurs centaines de metres) reste infiniment plus
 * utile qu'un « distance inconnue ». La precision annoncee n'est donc
 * plus un critere de rejet, elle sert uniquement a informer la personne.
 */
function geoPositionEstPrecise(array $position) {
    if (empty($position) || !isset($position['source'])) return false;
    if ($position['source'] !== 'gps') return false;
    if (!geoCoordonneesValides(
        isset($position['lat']) ? $position['lat'] : null,
        isset($position['lng']) ? $position['lng'] : null
    )) return false;

    return true;
}

/**
 * Position memorisee en session, si elle existe et n'est pas perimee.
 * Retourne null sinon.
 */
function geoPositionSession() {
    if (!isset($_SESSION['latitude'], $_SESSION['longitude'])) return null;
    if (!geoCoordonneesValides($_SESSION['latitude'], $_SESSION['longitude'])) return null;

    $horodatage = isset($_SESSION['position_ts']) ? (int)$_SESSION['position_ts'] : 0;
    if ($horodatage > 0 && (time() - $horodatage) > GEO_DUREE_VALIDITE) return null;

    $position = array(
        'lat'        => (float)$_SESSION['latitude'],
        'lng'        => (float)$_SESSION['longitude'],
        'source'     => isset($_SESSION['position_source']) ? $_SESSION['position_source'] : 'session',
        'precision'  => isset($_SESSION['position_precision']) ? $_SESSION['position_precision'] : null,
        'horodatage' => $horodatage
    );

    return $position;
}

/**
 * Derniere position connue de l'utilisateur connecte, lue en base.
 * Retourne null si l'utilisateur n'est pas connecte, si les colonnes
 * n'existent pas encore, ou si aucune position n'a ete enregistree.
 */
function geoPositionBase(PDO $pdo, $userId) {
    global $GEO_COLONNES_UTILISATEUR;

    if (empty($userId)) return null;
    if ($GEO_COLONNES_UTILISATEUR === false) return null;

    try {
        $stmt = $pdo->prepare("SELECT Latitude, Longitude FROM utilisateur WHERE Id_utilisateur = ?");
        $stmt->execute([$userId]);
        $ligne = $stmt->fetch();
        $GEO_COLONNES_UTILISATEUR = true;
    } catch (PDOException $e) {
        // Colonnes absentes : on bascule definitivement en mode session seul.
        $GEO_COLONNES_UTILISATEUR = false;
        return null;
    }

    if (!$ligne || !geoCoordonneesValides($ligne['Latitude'], $ligne['Longitude'])) return null;

    // Aucune date n'etant stockee en base, cette position peut dater de
    // plusieurs mois : elle sert donc uniquement de centre de carte,
    // jamais de reference pour une distance affichee.
    return array(
        'lat'        => (float)$ligne['Latitude'],
        'lng'        => (float)$ligne['Longitude'],
        'source'     => 'base',
        'precision'  => null,
        'horodatage' => 0
    );
}

/**
 * Position de reference utilisee pour tous les calculs de distance.
 *
 * @return array{lat:float,lng:float,source:string,precision:?float,horodatage:int,precise:bool}
 */
function getPositionUtilisateur(PDO $pdo = null) {
    if ($pdo === null) {
        global $pdo;
        if (!($pdo instanceof PDO)) $pdo = null;
    }

    $position = null;

    // 1. Session : position la plus recente.
    $position = geoPositionSession();
    if ($position === null && $pdo instanceof PDO) {
        // 2. Base : derniere position connue de l'utilisateur connecte.
        $position = geoPositionBase($pdo, getIdUtilisateur());
    }

    // 3. Repli : centre de Porto-Novo, uniquement pour centrer la carte.
    if ($position === null) {
        $position = array(
            'lat'        => GEO_LAT_DEFAUT,
            'lng'        => GEO_LNG_DEFAUT,
            'source'     => 'defaut',
            'precision'  => null,
            'horodatage' => 0
        );
    }

    $position['precise'] = geoPositionEstPrecise($position);
    return $position;
}

/**
 * Enregistrer la position en session et, si l'utilisateur est connecte,
 * en base afin qu'elle survive a la fermeture du navigateur.
 *
 * @param string $source    'gps' ou 'ip'
 * @param bool   $persister false pour ne toucher qu'a la session
 * @param float  $precision rayon d'erreur annonce par le navigateur, en metres
 * @return bool true si la position a ete acceptee
 */
function geoEnregistrerPosition($lat, $lng, $source = 'gps', $persister = true, $precision = null) {
    if (!geoCoordonneesValides($lat, $lng)) return false;

    $lat = round((float)$lat, 7);
    $lng = round((float)$lng, 7);
    $source = ($source === 'ip') ? 'ip' : 'gps';
    $precision = geoNormaliserPrecision($precision);

    $_SESSION['latitude'] = $lat;
    $_SESSION['longitude'] = $lng;
    $_SESSION['position_source'] = $source;
    $_SESSION['position_precision'] = $precision;
    $_SESSION['position_ts'] = time();

    if (!$persister) return true;

    $userId = getIdUtilisateur();
    if (empty($userId)) return true;

    global $GEO_COLONNES_UTILISATEUR;
    if ($GEO_COLONNES_UTILISATEUR === false) return true;

    global $pdo;
    if (!($pdo instanceof PDO)) return true;

    try {
        $stmt = $pdo->prepare("UPDATE utilisateur SET Latitude = ?, Longitude = ? WHERE Id_utilisateur = ?");
        $stmt->execute([$lat, $lng, $userId]);
        $GEO_COLONNES_UTILISATEUR = true;
    } catch (PDOException $e) {
        // Colonnes absentes : la session reste la seule source, ce qui
        // suffit pour la duree de la visite.
        $GEO_COLONNES_UTILISATEUR = false;
    }
    return true;
}

/**
 * Libelle lisible de la source d'une position, pour l'affichage.
 */
function geoLibelleSource($source) {
    switch ($source) {
        case 'gps':    return 'GPS';
        case 'ip':     return 'estimation par IP';
        case 'base':   return ' derniere position enregistree';
        case 'session':return ' derniere position enregistree';
        default:       return 'position par defaut (Porto-Novo)';
    }
}

/**
 * Raison pour laquelle aucune distance fiable ne peut etre affichee.
 * Retourne null si la position est exploitable.
 */
function geoMotifPositionNonPrecise(array $position) {
    if (empty($position['source'])) return 'inconnue';

    switch ($position['source']) {
        case 'gps':
            // Des que le navigateur a fourni une position, la distance est
            // calculee : la precision n'est plus un motif de rejet.
            return null;

        case 'ip':
            return 'estimation par IP';

        case 'base':
        case 'session':
            return 'position non confirmee';

        default:
            return 'aucune position';
    }
}

/**
 * Texte du bandeau demandant d'autoriser la localisation.
 * Retourne null si la position est deja exploitable.
 */
function geoMessagePosition(array $position) {
    $motif = geoMotifPositionNonPrecise($position);
    if ($motif === null) return null;

    switch ($motif) {
        case 'precision insuffisante':
            return 'Votre position GPS est trop imprécise pour calculer une distance fiable '
                 . '(incertitude supérieure à ' . (int)GEO_PRECISION_MAX . ' m). '
                 . 'Déplacez-vous à l\'extérieur ou réessayez.';

        case 'estimation par IP':
            return 'Votre position est estimée depuis votre adresse IP : cette précision est '
                 . 'insuffisante pour indiquer une distance fiable. Autorisez la localisation '
                 . 'pour obtenir la distance exacte jusqu\'à chaque pharmacie.';

        case 'position non confirmee':
            return 'Votre dernière position enregistrée n\'a pas été confirmée par le GPS. '
                 . 'Autorisez la localisation pour obtenir la distance exacte jusqu\'à chaque pharmacie.';

        case 'precision inconnue':
            return 'La précision de votre position GPS n\'a pas pu être mesurée. '
                 . 'Réessayez la détection pour obtenir une distance fiable.';

        default:
            return 'Activez la localisation pour afficher la distance réelle entre vous '
                 . 'et chaque pharmacie.';
    }
}

/**
 * Bloc d'affichage de la position courante, avec un bouton permettant
 * de forcer une nouvelle detection. A placer dans les pages ou les
 * distances sont affichees.
 *
 * @param array $position  resultat de getPositionUtilisateur()
 */
function geoAfficherPosition($position) {
    $precise = !empty($position['precise']) || geoPositionEstPrecise($position);

    if ($precise) {
        $precision = geoNormaliserPrecision($position['precision'] ?? null);
        $detail = ($precision !== null)
            ? 'GPS (± ' . (int)round($precision) . ' m)'
            : 'GPS';
        $html = '<span class="geo-badge geo-badge-ok">'
              . '<i class="fas fa-location-crosshairs me-1"></i>'
              . 'Distances calculées depuis votre position GPS : '
              . '<strong>' . $detail . '</strong></span>';

        // Position exploitable mais floue : on le dit clairement pour que la
        // personne ne prenne pas la distance affichée pour une mesure exacte.
        if ($precision !== null && $precision > GEO_PRECISION_APPROX) {
            $html .= '<span class="geo-badge geo-badge-attention ms-2" title="La précision annoncée par le navigateur limite la justesse de la distance">'
                   . '<i class="fas fa-triangle-exclamation me-1"></i>'
                   . 'Position approximative (± ' . (int)round($precision / 1000) . ' km) : distances indicatives'
                   . '</span>';
        }
    } else {
        // On nomme la raison : sans cela, la personne ne sait pas si le
        // navigateur a été refusé, s'il n'y a pas de GPS, ou si la mesure
        // était trop floue.
        $motifs = array(
            'estimation par IP'    => 'Position estimée par IP',
            'position non confirmee' => 'Position non confirmée',
            'precision insuffisante' => 'GPS trop imprécis',
            'precision inconnue'   => 'Précision GPS inconnue',
            'aucune position'      => 'Localisation inactive',
            'inconnue'             => 'Localisation inactive'
        );
        $motif = geoMotifPositionNonPrecise($position);
        $libelle = isset($motifs[$motif]) ? $motifs[$motif] : 'Localisation inactive';

        $html = '<span class="geo-badge geo-badge-attention">'
              . '<i class="fas fa-location-dot me-1"></i>'
              . '<strong>' . htmlspecialchars($libelle, ENT_QUOTES, 'UTF-8') . '</strong>'
              . ' — les distances ne sont pas affichées'
              . '</span>';
    }

    return '<div class="geo-position">'
         . $html
         . '<button type="button" class="geo-refresh" data-geo-refresh '
         . 'title="' . ($precise ? 'Actualiser ma position' : 'Activer la localisation') . '">'
         . '<i class="fas fa-location-crosshairs"></i>'
         . '<span class="geo-refresh__libelle">'
         . ($precise ? 'Actualiser' : 'Activer la localisation')
         . '</span>'
         . '</button>'
         . '</div>';
}

/**
 * Bandeau d'avertissement a afficher en tete de page lorsque la position
 * n'est pas exploitable. Retourne une chaine vide si tout est correct :
 * l'appelant peut donc l'imprimer sans condition.
 */
function geoBandeauPosition(array $position) {
    $message = geoMessagePosition($position);
    if ($message === null) return '';

    return '<div class="geo-alerte" role="alert">'
         . '<i class="fas fa-location-crosshairs geo-alerte__ico"></i>'
         . '<div class="geo-alerte__body">'
         . '<strong>Distance indisponible</strong>'
         . '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
         . '<button type="button" class="geo-alerte__btn" data-geo-refresh>'
         . '<i class="fas fa-location-crosshairs"></i> Activer la localisation'
         . '</button>'
         . '</div>'
         . '</div>';
}

/**
 * Distance a afficher pour une pharmacie, ou null si aucune distance
 * fiable ne peut etre publiee.
 *
 * Cette fonction est le point de passage obligatoire : c'est elle qui
 * empeche d'afficher une distance calculee depuis une position par defaut
 * ou estimee, source du principal ecart constate.
 *
 * @param array $position  resultat de getPositionUtilisateur()
 * @param float $lat, $lng coordonnees GPS de la pharmacie
 * @return float|null distance en metres
 */
function geoDistanceAffichee(array $position, $lat, $lng) {
    if (empty($position['precise']) && !geoPositionEstPrecise($position)) return null;
    return geoDistanceMetres($position['lat'], $position['lng'], $lat, $lng);
}

/**
 * Configuration transmise au navigateur par includes/footer.php.
 */
function geoConfigJS($position, $reload = null) {
    if ($reload === null) {
        // Les pages qui affichent des distances posent $geo_reload avant
        // l'inclusion : c'est le signal « cette page a besoin d'être
        // re-rendue quand la position change ».
        $reload = !empty($GLOBALS['geo_reload']);
    }
    $precise = !empty($position['precise']) || geoPositionEstPrecise($position);
    $horodatage = isset($position['horodatage']) ? (int)$position['horodatage'] : 0;
    // Age de la position en secondes (null si inconnu). Le navigateur s'en
    // sert pour decider s'il doit redetecter : une position memorisee n'est
    // reutilisee que si elle est recente, sinon elle peut etre obsolette
    // (l'utilisateur a bouge) et afficher une distance fausse.
    $age = ($horodatage > 0) ? max(0, time() - $horodatage) : null;
    return json_encode(array(
        'lat'        => $position['lat'],
        'lng'        => $position['lng'],
        'source'     => $position['source'],
        'precision'  => isset($position['precision']) ? $position['precision'] : null,
        'age'        => $age,
        'precise'    => $precise,
        'precisionMax' => GEO_PRECISION_MAX,
        'seuil'      => GEO_SEUIL_EGALITE,
        'reload'     => (bool)$reload
    ));
}
