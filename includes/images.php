<?php
// ============================================================
// PharmaGeo — Gestion des photos (utilisateur et pharmacie)
// ------------------------------------------------------------
// Centralise le téléversement, la validation, la suppression et la
// construction d'URL des images de profil. Utilisé par l'espace
// pharmacien (profil.php, pharmacie.php) et par les pages publiques.
//
// Les fichiers sont stockés dans uploads/ (dossier protégé par
// uploads/.htaccess qui interdit l'exécution de tout script). Les
// chemins enregistrés en base sont RELATIFS à la racine du projet
// (ex : "uploads/logos/pharmacie_12_ab12cd.jpg") afin d'être
// résolus de façon fiable via BASE_URL, quelle que soit la page.
// ============================================================

// Taille maximale d'une image (2 Mo) et formats autorisés.
if (!defined('PHARMA_IMAGE_MAX_SIZE')) {
    define('PHARMA_IMAGE_MAX_SIZE', 2 * 1024 * 1024);
}
if (!defined('PHARMA_IMAGE_EXTENSIONS')) {
    define('PHARMA_IMAGE_EXTENSIONS', 'jpg,jpeg,png,webp');
}
if (!defined('PHARMA_IMAGE_MIMES')) {
    define('PHARMA_IMAGE_MIMES', 'image/jpeg,image/png,image/webp');
}

/**
 * Racine physique du projet (dossier parent de includes/), en
 * séparateurs "/", sans dépendre de config.php.
 */
function pharma_projet_racine() {
    return rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
}

/**
 * Nom du dossier projet (utile pour normaliser les anciens chemins
 * absolus du type "/PharmaGeo/uploads/...").
 */
function pharma_projet_dossier() {
    return basename(pharma_projet_racine());
}

/**
 * Dossier racine des téléversements.
 */
function pharma_uploads_racine() {
    return pharma_projet_racine() . '/uploads';
}

/**
 * Normalise une valeur de chemin d'image stockée en base :
 *  - les URL absolues (http/https) sont conservées telles quelles ;
 *  - l'ancien préfixe projet ("/PharmaGeo/") est retiré ;
 *  - le slash initial est supprimé.
 * Retourne un chemin relatif à la racine du projet, ou ''.
 */
function pharma_normaliser_chemin_image($valeur) {
    $valeur = trim((string) $valeur);
    if ($valeur === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $valeur)) {
        return $valeur;
    }
    $valeur = str_replace('\\', '/', $valeur);
    $valeur = ltrim($valeur, '/');
    $projet = pharma_projet_dossier();
    if ($projet !== '' && stripos($valeur, $projet . '/') === 0) {
        $valeur = substr($valeur, strlen($projet) + 1);
    }
    return $valeur;
}

/**
 * URL publique d'une image à partir de sa valeur en base.
 * Retourne '' si aucune image n'est renseignée.
 */
function pharma_image_url($valeur) {
    $chemin = pharma_normaliser_chemin_image($valeur);
    if ($chemin === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $chemin)) {
        return $chemin;
    }
    $base = defined('BASE_URL') ? BASE_URL : '/';
    return rtrim($base, '/') . '/' . ltrim($chemin, '/');
}

/**
 * Chemin physique d'une image de téléversement, ou '' si la valeur
 * ne correspond pas à un fichier géré par l'application.
 */
function pharma_image_fichier($valeur) {
    $chemin = pharma_normaliser_chemin_image($valeur);
    if ($chemin === '' || preg_match('#^https?://#i', $chemin)) {
        return '';
    }
    if (strpos($chemin, 'uploads/') !== 0) {
        return '';
    }
    return pharma_projet_racine() . '/' . $chemin;
}

/**
 * Indique si le fichier correspondant existe réellement sur le disque.
 */
function pharma_image_disponible($valeur) {
    $fichier = pharma_image_fichier($valeur);
    return $fichier !== '' && is_file($fichier);
}

/**
 * URL de l'image par défaut selon le type : 'pharmacien' ou 'pharmacie'.
 */
function pharma_image_defaut($type = 'pharmacien') {
    $nom = ($type === 'pharmacie') ? 'pharmacie-defaut.svg' : 'pharmacien-defaut.svg';
    $base = defined('BASE_URL') ? BASE_URL : '/';
    return rtrim($base, '/') . '/assets/images/' . $nom;
}

/**
 * URL à afficher pour une photo : la photo réelle si elle existe,
 * sinon l'image par défaut.
 */
function pharma_image_url_ou_defaut($valeur, $type = 'pharmacien') {
    if (pharma_image_disponible($valeur)) {
        return pharma_image_url($valeur);
    }
    return pharma_image_defaut($type);
}

/**
 * Valide un fichier téléversé ($_FILES['champ']) et retourne son
 * extension normalisée. Lève une RuntimeException avec un message
 * en français en cas de problème.
 */
function pharma_valider_image(array $file) {
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException("Fichier invalide.");
    }

    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            throw new RuntimeException("Aucun fichier sélectionné.");
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new RuntimeException("Le fichier est trop volumineux (max 2 Mo).");
        default:
            throw new RuntimeException("Échec du téléversement du fichier.");
    }

    $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $autorisees = array_map('trim', explode(',', PHARMA_IMAGE_EXTENSIONS));
    if (!in_array($extension, $autorisees, true)) {
        throw new RuntimeException("Format non autorisé. Formats acceptés : JPG, JPEG, PNG, WebP.");
    }

    $taille = (int) ($file['size'] ?? 0);
    if ($taille <= 0) {
        throw new RuntimeException("Le fichier est vide.");
    }
    if ($taille > PHARMA_IMAGE_MAX_SIZE) {
        throw new RuntimeException("Le fichier est trop volumineux (max 2 Mo).");
    }

    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException("Fichier invalide.");
    }

    // Vérifie qu'il s'agit d'une vraie image (et non d'un script renommé).
    $infos = @getimagesize($tmp);
    if ($infos === false || empty($infos['mime'])) {
        throw new RuntimeException("Le fichier n'est pas une image valide.");
    }
    $mimes = array_map('trim', explode(',', PHARMA_IMAGE_MIMES));
    if (!in_array(strtolower($infos['mime']), $mimes, true)) {
        throw new RuntimeException("Format d'image non supporté (JPG, PNG ou WebP attendu).");
    }

    return $extension;
}

/**
 * Déplace un fichier téléversé vers uploads/<sous_dossier>/ et
 * retourne le chemin RELATIF à enregistrer en base.
 *
 * @param array  $file         Entrée de $_FILES.
 * @param string $sous_dossier Sous-dossier (ex : 'logos', 'photos').
 * @param string $prefixe      Préfixe du nom de fichier généré.
 */
function pharma_upload_image(array $file, $sous_dossier, $prefixe = 'image') {
    $extension = pharma_valider_image($file);

    $sous_dossier = trim(str_replace('\\', '/', (string) $sous_dossier), '/');
    if ($sous_dossier === '' || strpos($sous_dossier, '..') !== false) {
        throw new RuntimeException("Dossier de destination invalide.");
    }
    $prefixe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) $prefixe);
    if ($prefixe === '') {
        $prefixe = 'image';
    }

    $dossier = pharma_uploads_racine() . '/' . $sous_dossier;
    if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
        throw new RuntimeException("Impossible de créer le dossier de destination.");
    }

    $nom = $prefixe . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $extension;
    $destination = $dossier . '/' . $nom;

    if (!@move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException("Erreur lors de l'enregistrement de l'image.");
    }
    @chmod($destination, 0644);

    return 'uploads/' . $sous_dossier . '/' . $nom;
}

/**
 * Supprime le fichier associé à une valeur stockée en base.
 * Ne supprime jamais les images par défaut ni un fichier hors de
 * uploads/. Retourne true si un fichier a été supprimé.
 */
function pharma_supprimer_image($valeur) {
    if (empty($valeur)) {
        return false;
    }
    $fichier = pharma_image_fichier($valeur);
    if ($fichier === '' || !is_file($fichier)) {
        return false;
    }
    $racine = str_replace('\\', '/', (string) realpath(pharma_uploads_racine()));
    $reel = str_replace('\\', '/', (string) realpath($fichier));
    if ($racine !== '' && $reel !== '' && strpos($reel, rtrim($racine, '/') . '/') === 0) {
        return @unlink($fichier);
    }
    return false;
}
