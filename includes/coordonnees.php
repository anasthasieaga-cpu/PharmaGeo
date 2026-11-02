<?php
// ============================================================
// PharmaGeo — Validation des coordonnees d'une pharmacie
//
// Les distances affichees aux patients sont calculees depuis la latitude
// et la longitude enregistrees pour chaque pharmacie. Une coordonnee
// fausse (virgule decimale, valeurs inversees, point nul, position
// estimee par IP) donne donc une distance fausse, meme si le calcul est
// exact. Toute saisie passe par pharmaValiderCoordonnees() avant d'etre
// ecrite en base.
//
// Fichier autonome : aucune dependance, utilisable avant config.php.
// ============================================================

// Zone couverte par la plateforme : Porto-Novo et les communes voisines
// (Seme-Podji, Adjarra, Akpro-Misserete). Boite englobant ces quatre
// communes, d'apres leurs limites OpenStreetMap. Une coordonnee hors de
// cette zone est presque toujours une erreur de saisie (Cotonou, par
// exemple, se situe a l'ouest de la limite). A modifier ici, et dans
// assets/js/coord_picker.js, pour ouvrir d'autres communes.
if (!defined('PHARMA_ZONE_LAT_MIN')) { define('PHARMA_ZONE_LAT_MIN', 6.33); }
if (!defined('PHARMA_ZONE_LAT_MAX')) { define('PHARMA_ZONE_LAT_MAX', 6.67); }
if (!defined('PHARMA_ZONE_LNG_MIN')) { define('PHARMA_ZONE_LNG_MIN', 2.46); }
if (!defined('PHARMA_ZONE_LNG_MAX')) { define('PHARMA_ZONE_LNG_MAX', 2.79); }

/**
 * Convertir une saisie en nombre decimal.
 * Accepte la virgule decimale (« 6,4970 ») et les espaces.
 * Retourne null si la saisie n'est pas un nombre.
 */
function pharmaNombreDecimal($saisie) {
    if (is_int($saisie) || is_float($saisie)) return is_finite((float)$saisie) ? (float)$saisie : null;
    if (!is_string($saisie)) return null;

    $texte = str_replace(array(' ', "\xC2\xA0"), '', trim($saisie));
    $texte = str_replace(',', '.', $texte);
    if ($texte === '' || !preg_match('/^[+-]?\d+(\.\d+)?$/', $texte)) return null;

    return (float)$texte;
}

/** Le point (lat, lng) se trouve-t-il dans la zone couverte ? */
function pharmaDansLaZone($lat, $lng) {
    return $lat >= PHARMA_ZONE_LAT_MIN && $lat <= PHARMA_ZONE_LAT_MAX
        && $lng >= PHARMA_ZONE_LNG_MIN && $lng <= PHARMA_ZONE_LNG_MAX;
}

/**
 * Valider et normaliser un couple latitude/longitude saisi.
 *
 * @return array{ok:bool,lat:?float,lng:?float,erreur:?string}
 *         lat/lng sont arrondis a 7 decimales (precision de la colonne).
 */
function pharmaValiderCoordonnees($latSaisie, $lngSaisie) {
    $echec = function ($message) {
        return array('ok' => false, 'lat' => null, 'lng' => null, 'erreur' => $message);
    };

    if (trim((string)$latSaisie) === '' || trim((string)$lngSaisie) === '') {
        return $echec("Les coordonnées GPS sont obligatoires pour la géolocalisation.");
    }

    $lat = pharmaNombreDecimal($latSaisie);
    $lng = pharmaNombreDecimal($lngSaisie);
    if ($lat === null || $lng === null) {
        return $echec("Les coordonnées GPS doivent être des nombres (ex. 6.4970 et 2.6050).");
    }

    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return $echec("Les coordonnées GPS sont hors limites (latitude entre -90 et 90, longitude entre -180 et 180).");
    }

    if ($lat == 0.0 && $lng == 0.0) {
        return $echec("Les coordonnées 0, 0 correspondent à un point en pleine mer : la localisation a échoué.");
    }

    if (!pharmaDansLaZone($lat, $lng)) {
        // Cas le plus frequent : latitude et longitude saisies a l'envers.
        if (pharmaDansLaZone($lng, $lat)) {
            return $echec("La latitude et la longitude semblent inversées : dans la zone de Porto-Novo, la latitude est autour de 6,5 et la longitude autour de 2,6.");
        }
        return $echec("Ces coordonnées sont en dehors de Porto-Novo et de ses communes voisines (Sèmè-Podji, Adjarra, Akpro-Missérété). Vérifiez la saisie ou placez le point sur la carte.");
    }

    return array('ok' => true, 'lat' => round($lat, 7), 'lng' => round($lng, 7), 'erreur' => null);
}
