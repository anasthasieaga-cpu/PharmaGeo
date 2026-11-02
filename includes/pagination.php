<?php
/**
 * PharmaGeo — Pagination serveur reutilisable
 * -----------------------------------------------------------------
 * A charger apres includes/config.php (qui fournit BASE_URL) :
 *     require_once __DIR__ . '/../includes/pagination.php';
 *
 * Principe : la pagination se fait en base (LIMIT / OFFSET), jamais en
 * PHP, afin de ne pas charger inutilement toutes les lignes quand la
 * table contient beaucoup de donnees.
 *
 * Les liens de pagination conservent les filtres et la recherche
 * courants. Inversement, les URL de filtre n'incluent jamais « page »
 * afin qu'un nouveau filtre ou une nouvelle recherche revienne
 * automatiquement a la page 1.
 */

/** Nombre d'elements affiches par page (10 par defaut). */
if (!defined('PAGINATION_PAR_PAGE')) {
    define('PAGINATION_PAR_PAGE', 10);
}

/**
 * Calcule toutes les informations necessaires a l'affichage.
 *
 * @param int         $total    Nombre total de resultats filtres
 * @param int         $par_page Elements par page
 * @param int|null    $page     Page courante (null = $_GET['page'])
 * @param int         $fenetre  Nombre maximum de numeros de pages affiches
 * @return array
 */
function pagination_calculer($total, $par_page = PAGINATION_PAR_PAGE, $page = null, $fenetre = 7)
{
    $total       = max(0, (int) $total);
    $par_page    = max(1, (int) $par_page);
    $total_pages = $total > 0 ? (int) ceil($total / $par_page) : 1;

    if ($page === null) {
        $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    }
    // La page est bornee : un ?page=999 ou un ?page=-3 ne casse jamais l'affichage.
    $page = max(1, min((int) $page, $total_pages));

    return [
        'total'       => $total,
        'par_page'    => $par_page,
        'page'        => $page,
        'offset'      => ($page - 1) * $par_page,
        'total_pages' => $total_pages,
        'debut'       => $total > 0 ? ($page - 1) * $par_page + 1 : 0,
        'fin'         => $total > 0 ? min($page * $par_page, $total) : 0,
        'fenetre'     => max(3, (int) $fenetre),
        'precedent'   => $page > 1 ? $page - 1 : null,
        'suivant'     => $page < $total_pages ? $page + 1 : null,
        'a_afficher'  => $total > 0,
    ];
}

/**
 * Fragment SQL « LIMIT x OFFSET y » de la page courante.
 *
 * Les valeurs sont forcées a l'entier et injectees directement :
 * cette version de MariaDB refuse les placeholders prepares dans LIMIT
 * (les trois formes nommées ou positionnelles echouent).
 *
 * @param array $pagination
 * @return string
 */
function pagination_sql_limite($pagination)
{
    return ' LIMIT ' . (int) $pagination['par_page']
         . ' OFFSET ' . (int) $pagination['offset'];
}

/**
 * Parametres d'URL a conserver dans les liens de pagination.
 *
 * - « page » est toujours retire : un changement de filtre ou de
 *   recherche ramene donc automatiquement a la page 1 ;
 * - « action » et « id » sont retires par defaut : ce sont des
 *   commandes a effet de bord (supprimer, valider, changer un statut…).
 *   Les reconduire dans un lien de pagination ferait rejouer l'action
 *   a chaque changement de page. Les pages ou « action » est un vrai
 *   filtre (journaux, onglet « a valider ») le repassent explicitement.
 *
 * @param array $params  Valeurs ajoutees/ecrasees (filtres courants)
 * @param array $exclus  Parametres d'URL a ne pas reconduire
 * @return array
 */
function pagination_filtres($params = [], $exclus = ['page', 'action', 'id'])
{
    $courants = $_GET;
    foreach ($exclus as $cle) {
        unset($courants[$cle]);
    }

    return array_merge($courants, $params);
}

/**
 * Numeros de pages a afficher, avec ellipses pour les grandes listes.
 * Exemple : 1 … 4 5 [6] 7 8 … 20
 *
 * @param array $pagination
 * @return array
 */
function pagination_numeros($pagination)
{
    $total_pages = $pagination['total_pages'];
    $page        = $pagination['page'];
    $fenetre     = $pagination['fenetre'];

    if ($total_pages <= $fenetre) {
        return range(1, $total_pages);
    }

    $numeros = [1];
    $debut   = max(2, $page - (int) floor(($fenetre - 3) / 2));
    $fin     = min($total_pages - 1, $debut + $fenetre - 3);

    if ($debut > 2) {
        $numeros[] = '...';
    }
    for ($i = $debut; $i <= $fin; $i++) {
        $numeros[] = $i;
    }
    if ($fin < $total_pages - 1) {
        $numeros[] = '...';
    }
    $numeros[] = $total_pages;

    return $numeros;
}

/**
 * URL d'une page, en conservant les filtres courants de l'URL.
 * Les valeurs passees dans $filtres ecrasent les filtres en cours.
 *
 * @param int   $page
 * @param array $filtres
 * @param string|null $base Fichier cible (defaut : page courante)
 * @return string
 */
function pagination_url($page, $filtres = [], $base = null)
{
    if ($base === null) {
        $base = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '';
    }

    $filtres = pagination_filtres($filtres);
    $filtres['page'] = (int) $page;

    return $base . '?' . http_build_query($filtres);
}

/**
 * Bloc de pagination complet (infos + navigation).
 * Ne produit aucun HTML si la liste est vide ou tient sur une page.
 *
 * @param array  $pagination
 * @param array  $filtres
 * @param string $libelle   « résultats », « médicaments »…
 * @return string
 */
function pagination_html($pagination, $filtres = [], $libelle = 'résultats')
{
    if (!$pagination['a_afficher']) {
        return '';
    }

    $filtres = pagination_filtres($filtres);
    $page    = $pagination['page'];

    $html = '<div class="pagination-wrapper">';
    $html .= '<div class="pagination-info">';
    $html .= '<i class="fas fa-list-ul"></i> Affichage de <strong>'
        . $pagination['debut'] . '</strong> à <strong>' . $pagination['fin'] . '</strong> sur <strong>'
        . $pagination['total'] . '</strong> ' . htmlspecialchars($libelle, ENT_QUOTES, 'UTF-8');
    $html .= '</div>';

    if ($pagination['total_pages'] > 1) {
        $html .= '<nav class="pagination-custom" aria-label="Pagination">';

        // Precedent (desactive sur la premiere page)
        if ($pagination['precedent']) {
            $html .= '<a class="page-link-custom" rel="prev" href="'
                . htmlspecialchars(pagination_url($pagination['precedent'], $filtres), ENT_QUOTES, 'UTF-8') . '">'
                . '<i class="fas fa-chevron-left"></i><span class="page-link-texte"> Précédent</span></a>';
        } else {
            $html .= '<span class="page-link-custom disabled" aria-disabled="true">'
                . '<i class="fas fa-chevron-left"></i><span class="page-link-texte"> Précédent</span></span>';
        }

        foreach (pagination_numeros($pagination) as $numero) {
            if ($numero === '...') {
                $html .= '<span class="page-link-custom ellipsis" aria-hidden="true">&hellip;</span>';
                continue;
            }
            if ((int) $numero === (int) $page) {
                $html .= '<span class="page-link-custom active" aria-current="page">' . (int) $numero . '</span>';
            } else {
                $html .= '<a class="page-link-custom" href="'
                    . htmlspecialchars(pagination_url($numero, $filtres), ENT_QUOTES, 'UTF-8') . '">' . (int) $numero . '</a>';
            }
        }

        // Suivant (desactive sur la derniere page)
        if ($pagination['suivant']) {
            $html .= '<a class="page-link-custom" rel="next" href="'
                . htmlspecialchars(pagination_url($pagination['suivant'], $filtres), ENT_QUOTES, 'UTF-8') . '">'
                . '<span class="page-link-texte">Suivant </span><i class="fas fa-chevron-right"></i></a>';
        } else {
            $html .= '<span class="page-link-custom disabled" aria-disabled="true">'
                . '<span class="page-link-texte">Suivant </span><i class="fas fa-chevron-right"></i></span>';
        }

        $html .= '</nav>';
    }

    $html .= '</div>';

    return $html;
}

/**
 * Affiche directement le bloc de pagination.
 *
 * @param array  $pagination
 * @param array  $filtres
 * @param string $libelle
 */
function pagination_afficher($pagination, $filtres = [], $libelle = 'résultats')
{
    echo pagination_html($pagination, $filtres, $libelle);
}