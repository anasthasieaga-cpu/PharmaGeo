<?php
// ============================================================
// PharmaGeo — Résolution d'une saisie libre vers des médicaments
//
// Pourquoi ce fichier existe :
// une simple recherche par « LIKE %terme% » est trop fragile et
// produit des résultats incohérents :
//
//   1) « paracetamol » est le DCI de 6 produits (Doliprane, Efferalgan,
//      Dafalgan, Paracétamol, Paracetamol Vitamine C, Albendazole).
//      Les 6 devenaient 6 médicaments distincts à réserver, donc
//      aucune pharmacie n'était jamais complète et le bouton
//      « Tout réserver » ne pouvait pas s'afficher.
//
//   2) « eferalgan » (faute de frappe) ne trouvait rien : le bouton
//      disparaissait silencieusement, sans explication.
//
// On applique donc deux règles :
//   - UN terme saisi = UNE demande (pas une famille de produits) ;
//   - la correspondance ignore les accents et tolère les fautes
//     de frappe, en indiquant la correction proposée.
//
// Chaque demande est de type :
//   'produit'  -> un médicament précis (Doliprane, Efferalgan…) ;
//   'molecule' -> « n'importe quel produit de cette molécule »,
//                 satisfait dès qu'une pharmacie en détient un seul.
// ============================================================

/**
 * Minuscules, sans accent ni séparateur : « Efferalgan » => « efferalgan ».
 *
 * Point d'attention : `\p{Mn}` ne suffit pas. Un « é » précomposé
 * (U+00E9) n'est PAS une marque de combinaison, il fait partie de
 * `\p{L}` : `preg_replace('/\p{Mn}/u', ...)` le laisse intact et
 * « Paracétamol » restait donc différent de « paracetamol ».
 * On translittère donc explicitement les caractères accentués.
 */
function medNormaliser($texte)
{
    if ($texte === null || $texte === '') return '';
    $s = function_exists('mb_strtolower') ? mb_strtolower($texte, 'UTF-8') : strtolower($texte);

    static $accents = null;
    if ($accents === null) {
        $accents = array();
        $lettres = 'aàáâãäåāăąeèéêëēĕėęěiìíîïĩīĭįıoòóôõöøōŏőuùúûüũūŭůűųyýÿŷỳñńņňšśşžźżćĉċčţťďđğĝģħłĺļŀńņřśŕşšţťůűźżż';
        $simples = 'aaaaaaaaaeeeeeeeeeiiiiiiiiiooooooooouuuuuuuuuuyyyyynnnsssszzzccccc';
        $nb = min(mb_strlen($lettres, 'UTF-8'), mb_strlen($simples, 'UTF-8'));
        for ($i = 0; $i < $nb; $i++) {
            $accents[mb_substr($lettres, $i, 1, 'UTF-8')] = mb_substr($simples, $i, 1, 'UTF-8');
        }
    }

    if (is_array($accents) && !empty($accents)) {
        $s = strtr($s, $accents);
    }
    // Les éventuelles marques de combinaison restantes sont supprimées ici.
    $s = preg_replace('/\p{Mn}/u', '', $s);

    $s = str_replace(array(' ', '-', "'", '.', '/'), '', $s);
    return $s;
}

/** Distance de Levenshtein tolérante aux accents. */
function medDistance($a, $b)
{
    if (!function_exists('levenshtein')) return 99;
    return levenshtein($a, $b);
}

/** Tolérance autorisée selon la longueur du mot (1 lettre pour un mot court). */
function medTolerer($mot)
{
    $n = function_exists('mb_strlen') ? mb_strlen($mot, 'UTF-8') : strlen($mot);
    if ($n <= 4) return 1;
    if ($n <= 8) return 2;
    return 3;
}

/** Charge tout le catalogue actif une seule fois (base de 61 lignes). */
function medChargerCatalogue(PDO $pdo)
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $catalogue = array();
    $stmt = $pdo->query("SELECT Id_medicament, Nom_commercial, DCI, Dosage FROM medicament WHERE Statut = 'actif'");
    foreach ($stmt->fetchAll() as $row) {
        $id = (int)$row['Id_medicament'];
        $dci = isset($row['DCI']) ? $row['DCI'] : '';
        $molecules = array();
        foreach (explode('/', $dci) as $piece) {
            $n = medNormaliser($piece);
            if ($n !== '') $molecules[] = $n;
        }
        $catalogue[$id] = array(
            'id'       => $id,
            'nom'      => $row['Nom_commercial'],
            'dci'      => $dci,
            'norm_nom' => medNormaliser($row['Nom_commercial']),
            'norm_dosage' => medNormaliser(isset($row['Dosage']) ? $row['Dosage'] : ''),
            'molecules' => $molecules,
            'synonymes' => array(),
        );
    }

    // Synonymes éventuels
    try {
        $syn = $pdo->query("SELECT Id_medicament, Libelle FROM medicament_synonyme");
        foreach ($syn->fetchAll() as $row) {
            $id = (int)$row['Id_medicament'];
            if (isset($catalogue[$id])) {
                $n = medNormaliser($row['Libelle']);
                if ($n !== '') $catalogue[$id]['synonymes'][] = $n;
            }
        }
    } catch (PDOException $e) {
        // Table absente : la recherche fonctionne quand même.
    }

    return $catalogue;
}

/**
 * Chiffres d'un dosage : « 1g/125 mg » => « 1125 », « 0,5 % » => « 05 ».
 */
function medChiffres($texte)
{
    if ($texte === null) return '';
    return preg_replace('/[^0-9]+/', '', (string)$texte);
}

/**
 * Sépare le dosage saisi après le nom : « doliprane 1000 » =>
 * nom « doliprane » + dosage « 1000 ».
 *
 * Pourquoi c'est nécessaire : medNormaliser() supprime les espaces, donc
 * « Doliprane 1000 » devenait « doliprane1000 » et ne correspondait plus
 * à « Doliprane ». Résultat : les saisies les plus naturelles
 * (« paracétamol 500 », « vitamine c 1000 ») ne renvoyaient rien.
 *
 * Le dosage n'est retiré que s'il est séparé du nom par un espace ou un
 * tiret, placé en fin de saisie, et qu'il reste un nom d'au moins
 * 3 caractères. Cela évite de casser un nom qui contient un chiffre
 * (par exemple « vitamine b12 »).
 */
function medSeparerDosage($terme)
{
    $s = trim((string)$terme);
    $motif = '/[\s\-]+\d+(?:[.,]\d+)?\s*(?:%|mg|ml|gr|g|mcg|µg|ug)?(?:\s*\/\s*\d+(?:[.,]\d+)?\s*(?:%|mg|ml|gr|g|mcg|µg|ug)?)?\s*$/iu';
    if (!preg_match($motif, $s, $m)) {
        return array('nom' => $s, 'dosage' => '');
    }
    $reste = rtrim(substr($s, 0, -strlen($m[0])));
    if (mb_strlen(medNormaliser($reste), 'UTF-8') < 3) {
        return array('nom' => $s, 'dosage' => '');
    }
    return array('nom' => $reste, 'dosage' => trim($m[0]));
}

/**
 * Les produits proposés sont-ils compatibles avec le dosage saisi ?
 *
 * Sert à ne pas retenir un produit dont le dosage contredit la demande :
 * « amoxicilline 1000 » ne doit pas s'arrêter sur le produit nommé
 * « Amoxicilline 500 mg », mais continuer vers les autres produits de la
 * même molécule.
 */
function medDosageCompatible(array $ids, $dosage, array $catalogue)
{
    if ($dosage === '') return true;
    $nb_cherche = medChiffres($dosage);
    if ($nb_cherche === '') return true;
    foreach ($ids as $id) {
        if (!isset($catalogue[$id])) continue;
        $nb_dosage = medChiffres($catalogue[$id]['norm_dosage']);
        if ($nb_dosage !== '' && strpos($nb_dosage, $nb_cherche) !== false) return true;
    }
    return false;
}

/**
 * Résout un terme saisi en une demande.
 *
 * @return array {
 *   'terme'      => terme tel que saisi,
 *   'trouve'     => bool,
 *   'type'       => 'produit' | 'molecule' | 'inconnu',
 *   'ids'        => array<int> médicaments possibles,
 *   'libelle'    => ce qu'il faut afficher,
 *   'correction' => nom corrigé si une faute a été détectée,
 *   'suggestion' => meilleur candidat si rien n'a été trouvé,
 *   'dosage'     => dosage éventuellement saisi après le nom
 * }
 */
function medResoudreTerme($terme, array $catalogue)
{
    $detail = medSeparerDosage($terme);
    $nom_recheche = $detail['nom'];
    $dosage_recheche = $detail['dosage'];

    $t = medNormaliser($nom_recheche);
    $res = array(
        'terme'      => $terme,
        'trouve'     => false,
        'type'       => 'inconnu',
        'ids'        => array(),
        'libelle'    => $terme,
        'correction' => null,
        'suggestion' => null,
        'dosage'     => $dosage_recheche,
    );
    if ($t === '') return $res;

    // Un dosage saisi (« Doliprane 1000 ») ne doit pas être perdu : on garde
    // les produits dont le dosage correspond. Si aucun ne correspond, on
    // retombe sur tous les candidats plutôt que d'afficher « aucun résultat ».
    $filtrer = function (array $ids) use ($dosage_recheche, $catalogue) {
        if ($dosage_recheche === '' || empty($ids)) return $ids;
        $nb_cherche = medChiffres($dosage_recheche);
        if ($nb_cherche === '') return $ids;
        $gardes = array();
        foreach ($ids as $id) {
            if (!isset($catalogue[$id])) { $gardes[] = $id; continue; }
            $nb_dosage = medChiffres($catalogue[$id]['norm_dosage']);
            if ($nb_dosage !== '' && strpos($nb_dosage, $nb_cherche) !== false) $gardes[] = $id;
        }
        return !empty($gardes) ? $gardes : $ids;
    };

    $parNom    = array();
    $parDci    = array();
    $parSyn    = array();
    foreach ($catalogue as $med) {
        if ($med['norm_nom'] === $t)                       $parNom[$med['id']] = 1;
        if (in_array($t, $med['molecules'], true))          $parDci[$med['id']] = 1;
        if (in_array($t, $med['synonymes'], true))          $parSyn[$med['id']] = 1;
    }

    // 1. Nom commercial exact : c'est ce que le patient a écrit, on ne
    //    touche à rien. « paracetamol » désigne donc le produit
    //    « Paracétamol », et non « n'importe quel paracétamol ».
    //    Exception : si un dosage a été saisi et qu'il contredit ce
    //    produit, on ne s'arrête pas dessus (voir étape 3).
    if (!empty($parNom) && medDosageCompatible(array_keys($parNom), $dosage_recheche, $catalogue)) {
        $ids = $filtrer(array_keys($parNom));
        $id = $ids[0];
        $res['trouve'] = true;
        $res['type'] = 'produit';
        $res['ids'] = $ids;
        $res['libelle'] = $catalogue[$id]['nom'];
        return $res;
    }

    // 2. Synonyme exact
    if (!empty($parSyn) && medDosageCompatible(array_keys($parSyn), $dosage_recheche, $catalogue)) {
        $ids = $filtrer(array_keys($parSyn));
        $id = $ids[0];
        $res['trouve'] = true;
        $res['type'] = 'produit';
        $res['ids'] = $ids;
        $res['libelle'] = $catalogue[$id]['nom'];
        return $res;
    }

    // 3. Le terme ne correspond à aucun produit : c'est une MOLÉCULE
    //    (DCI). Le patient demande la substance, pas une marque : la
    //    demande est alors satisfaite par n'importe quel produit de
    //    cette molécule. Un seul produit sera retenu pour la réservation.
    if (!empty($parDci)) {
        $ids = $filtrer(array_keys($parDci));
        $demande = $dosage_recheche !== '' ? $nom_recheche . ' ' . $dosage_recheche : $terme;
        $res['trouve'] = true;
        $res['type'] = 'molecule';
        $res['ids'] = $ids;
        $res['libelle'] = $demande . ' (n\'importe quel produit)';
        return $res;
    }

    // 4. Début ou partie du nom commercial — on garde le nom le plus court
    //    (« doliprane » doit ramener Doliprane, pas « DolipraneInjectable »).
    $prefix = array();
    $contains = array();
    foreach ($catalogue as $med) {
        if (strpos($med['norm_nom'], $t) === 0)      $prefix[$med['id']] = 1;
        elseif (strpos($med['norm_nom'], $t) !== false) $contains[$med['id']] = 1;
    }
    foreach (array($prefix, $contains) as $groupe) {
        if (!empty($groupe)) {
            $ids = $filtrer(array_keys($groupe));
            usort($ids, function ($a, $b) use ($catalogue) {
                return mb_strlen($catalogue[$a]['nom'], 'UTF-8') - mb_strlen($catalogue[$b]['nom'], 'UTF-8');
            });
            $res['trouve'] = true;
            $res['type'] = 'produit';
            $res['ids'] = array($ids[0]);
            $res['libelle'] = $catalogue[$ids[0]]['nom'];
            return $res;
        }
    }

    // 5. Faute de frappe : on cherche le nom le plus proche.
    $tol = medTolerer($t);
    $meilleur = null;
    $meilleureDist = 99;
    foreach ($catalogue as $med) {
        $candidats = array($med['norm_nom']);
        foreach ($med['molecules'] as $m) $candidats[] = $m;
        foreach ($med['synonymes'] as $s) $candidats[] = $s;
        foreach ($candidats as $c) {
            $d = medDistance($t, $c);
            if ($d < $meilleureDist) {
                $meilleureDist = $d;
                $meilleur = $med;
            }
        }
    }

    if ($meilleur !== null && $meilleureDist <= $tol) {
        $ids = $filtrer(array($meilleur['id']));
        $res['trouve'] = true;
        $res['type'] = 'produit';
        $res['ids'] = $ids;
        $res['libelle'] = $catalogue[$ids[0]]['nom'];
        $res['correction'] = $res['libelle'];
        return $res;
    }

    // 6. Rien. On propose quand même le plus proche pour aider.
    if ($meilleur !== null) $res['suggestion'] = $meilleur['nom'];
    return $res;
}

/** Découpe une saisie multi-lignes en termes. */
function medDecouper($saisie)
{
    $parts = preg_split('/[\r\n,;]+/', $saisie);
    $termes = array();
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && !in_array($p, $termes, true)) $termes[] = $p;
    }
    return $termes;
}

/**
 * Choisit UN médicament par demande, pour la réservation.
 *
 * Principe : le client reçoit exactement ce qu'il a demandé.
 * Une demande = un médicament dans le panier. On ne fusionne jamais
 * deux demandes et on n'en supprime aucune, même si deux termes
 * désignent la même molécule (« Amoxicilline » puis « Augmentin »
 * donnent bien deux médicaments).
 *
 * Seul choix possible : lorsqu'une demande porte sur une molécule,
 * on prend le produit que la pharmacie détient réellement — le
 * patient a demandé la substance, pas une marque.
 *
 * @param array      $requetes  demandes renvoyées par medResoudreTerme
 * @param array      $catalogue catalogue medChargerCatalogue
 * @param array|null $stockes   Id_medicament réellement disponibles (optionnel)
 * @return array<int> un identifiant par demande, dans l'ordre des demandes
 */
function medChoisirIds(array $requetes, array $catalogue, $stockes = null)
{
    $choisis = array();

    foreach ($requetes as $req) {
        $candidats = $req['ids'];
        if (empty($candidats)) continue;

        if ($stockes !== null) {
            $pref = array();
            foreach ($candidats as $id) {
                if (in_array($id, $stockes, true)) $pref[] = $id;
            }
            if (!empty($pref)) $candidats = $pref;
        }

        // Demande nominative : le produit est imposé.
        if ($req['type'] !== 'molecule') {
            $choisis[] = (int)$candidats[0];
            continue;
        }

        // Demande de molécule : on prend le produit le plus simple
        // (nom le plus court = probablement le générique).
        $premier = (int)$candidats[0];
        foreach ($candidats as $id) {
            if (isset($catalogue[$id]) && isset($catalogue[$premier])
                && mb_strlen($catalogue[$id]['nom'], 'UTF-8') < mb_strlen($catalogue[$premier]['nom'], 'UTF-8')) {
                $premier = (int)$id;
            }
        }
        $choisis[] = $premier;
    }

    return $choisis;
}