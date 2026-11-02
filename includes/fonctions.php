<?php
// ============================================================
// PharmaGeo — Fonctions utilitaires
// ------------------------------------------------------------
// Fonctions PHP réutilisables à travers l'ensemble du projet
// (formatage, validation, helpers métier, etc.).
//
// Contenu actuel :
//   - normalisation d'une chaîne (minuscules sans accents)
//   - détection du genre probable d'un prénom
//   - libellé accordé d'un rôle (Pharmacien / Pharmacienne...)
//
// La détection est approximative : elle se base uniquement sur le
// prénom (aucun champ "Genre" n'existe en base). Voir
// pharma_prenom_feminin() pour le détail.
// ============================================================

// ===== GESTION DES PHOTOS (profil / pharmacie) =====
require_once __DIR__ . '/images.php';

/**
 * Minuscules sans accents, pour comparer des prénoms.
 */
function pharma_normaliser($texte) {
    $texte = mb_strtolower(trim((string) $texte), 'UTF-8');
    $accents = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ñ' => 'n', 'ç' => 'c'
    ];
    return strtr($texte, $accents);
}

/**
 * Prénoms féminins courants (français et noms d'usage courants au Bénin).
 */
function pharma_prenoms_feminins() {
    return [
        'aicha', 'aichatou', 'aissata', 'aissatou', 'akissi', 'amelie', 'aminata',
        'angele', 'anjouan', 'antoinette', 'arnelle', 'audrey',
        'beatrice', 'benedicte', 'bernadette', 'bernice', 'betty', 'bibiane',
        'brigitte',
        'carine', 'carla', 'carmen', 'carole', 'caroline', 'cecilia', 'celine',
        'chantal', 'chantale', 'charlotte', 'chiara', 'chloe', 'christelle',
        'christiane', 'christine', 'claire', 'clara', 'colette', 'consolata',
        'danielle', 'delphine', 'denise', 'deniz', 'diane', 'divine', 'domitille',
        'edo', 'eliane', 'elisabeth', 'elodie', 'emilie', 'emma', 'estelle',
        'esther', 'eugenie', 'evelyne',
        'fadimatou', 'fatou', 'fatoumata', 'fleur', 'flore', 'francoise',
        'gaelle', 'gertrude', 'grace', 'helene', 'henriette',
        'imelda', 'irene', 'isabelle',
        'jade', 'jeanne', 'jolie', 'judith', 'julia', 'julie', 'juliette', 'justine',
        'kadiatou', 'karine', 'kathleen', 'khadidjatou', 'khadija', 'khadijatou',
        'laetitia', 'laure', 'laurence', 'lea', 'leila', 'liliane', 'lina', 'line',
        'lisa', 'louise', 'lucette', 'lucie', 'lucienne', 'lydie',
        'madeleine', 'maelle', 'maguette', 'mantenou', 'marceline', 'marguerite',
        'marie', 'marilyne', 'marine', 'martine', 'marion', 'maryam', 'maryse',
        'mathilde', 'maud', 'maureen', 'melanie', 'mireille', 'monique', 'morgane',
        'nadege', 'nadine', 'nancy', 'nathalie', 'nawal', 'nazaire', 'nicole',
        'ninon', 'noelle', 'noemie', 'nounours',
        'odette', 'oceane', 'olivia', 'olorunmilola', 'olorunbosun', 'omoniyi', 'ouria',
        'paola', 'patricia', 'patrine', 'pauline', 'perrine', 'priscille',
        'rachel', 'rachelle', 'raymonde', 'reine', 'renee', 'romaine', 'rosine',
        'sabrina', 'safiath', 'safiatou', 'salimata', 'sandra', 'sandrine', 'sarah',
        'satcha', 'severine', 'silvie', 'simone', 'solange', 'sonia', 'stephanie',
        'sylvie', 'sylviane',
        'tania', 'tanya', 'tchin', 'tchinita', 'tessy', 'therese', 'tina',
        'valerie', 'veronique', 'victoire', 'viviane',
        'yasmine', 'yetunde', 'yveline', 'zita', 'zoulika'
    ];
}

/**
 * Prénoms masculins courants, pour écarter les faux positifs
 * (prénoms mixtes comme Dominique, Claude, Morgan, Stéphane...).
 */
function pharma_prenoms_masculins() {
    return [
        'abdoulaye', 'abraham', 'adam', 'adrien', 'ahmed', 'alain', 'albert',
        'alexandre', 'alexis', 'alfred', 'ali', 'amadou', 'antoine', 'armel',
        'arnaud', 'arthur',
        'bakary', 'barnabas', 'benoit', 'bertrand', 'blaise', 'brice', 'bruno',
        'cyrille',
        'damien', 'daniel', 'dany', 'david', 'denis', 'didier', 'dominic',
        'domonique', 'donatien',
        'edouard', 'elie', 'emile', 'emmanuel', 'enrico', 'eric', 'ernest',
        'etienne',
        'fabien', 'felix', 'fiacre', 'frederic',
        'georges', 'gerard', 'ghislain', 'giovanni', 'godfrey', 'guillaume',
        'henri', 'hippolyte', 'honore',
        'ibrahim', 'ismael',
        'jacques', 'jean', 'jerome', 'joel', 'joseph', 'josue', 'jules', 'julien',
        'karl', 'kodjo',
        'laurent', 'leonard', 'lionel', 'ludovic',
        'marc', 'marcel', 'martial', 'mathieu', 'maurice', 'medard', 'michel',
        'mohamed', 'morgan',
        'narcisse', 'nathan', 'nick', 'norbert',
        'olivier',
        'pablo', 'pascal', 'patrice', 'paul', 'pierre', 'raphael', 'raymond',
        'remy', 'rene', 'ricardo', 'robert', 'roderick', 'rodolphe', 'roger',
        'romain',
        'salomon', 'samson', 'sebastien', 'sergio', 'stanislas', 'stephane',
        'theodore', 'thierry', 'toussaint',
        'valentin', 'victor', 'vincent',
        'yves', 'yvon',
        'zacharie'
    ];
}

/**
 * Genre grammatical probable d'un prénom.
 * Retourne true (féminin) ou false (masculin par défaut).
 *
 * Le prénom est découpé sur les séparateurs usuels (espace,
 * trait d'union, apostrophe) afin que "Marie-Claire" ou
 * "Anne-Marie" soient reconnus. Un prénom inconnu est considéré
 * comme masculin : c'est le libellé historique de l'application.
 */
function pharma_prenom_feminin($prenom) {
    $prenom = pharma_normaliser($prenom);
    if ($prenom === '') {
        return false;
    }

    $mots = preg_split('/[^a-z]+/', $prenom, -1, PREG_SPLIT_NO_EMPTY);
    $feminins = pharma_prenoms_feminins();
    $masculins = pharma_prenoms_masculins();

    // Un prénom composé est classé d'après son premier élément connu.
    foreach ($mots as $mot) {
        if (in_array($mot, $feminins, true)) {
            return true;
        }
        if (in_array($mot, $masculins, true)) {
            return false;
        }
    }

    return false;
}

/**
 * Libellé d'un rôle accordé au genre détecté.
 *
 *   pharma_libelle_role('pharmacien', 'Aminata')  => 'Pharmacienne'
 *   pharma_libelle_role('pharmacien', 'Jean')     => 'Pharmacien'
 *   pharma_libelle_role('patient', 'Aminata')     => 'Patiente'
 */
function pharma_libelle_role($role, $prenom = '') {
    $feminin = pharma_prenom_feminin($prenom);

    $libelles = [
        'administrateur' => ['Administrateur', 'Administratrice'],
        'pharmacien' => ['Pharmacien', 'Pharmacienne'],
        'patient' => ['Patient', 'Patiente']
    ];

    if (!isset($libelles[$role])) {
        return $role;
    }

    return $feminin ? $libelles[$role][1] : $libelles[$role][0];
}