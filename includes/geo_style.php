<?php
// ============================================================
// PharmaGeo — Feuille de style de l'affichage de la position
//
// À inclure dans le <head> des pages qui affichent des distances
// (recherche, résultats, carte, tableau de bord patient).
// Les couleurs utilisent var(--primary-green, ...) avec une valeur
// de repli, afin de fonctionner aussi bien avec style.css que avec
// les feuilles de style internes des pages.
// ============================================================
?>
<style>
/* ===== Position de référence des distances ===== */
.geo-position {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.geo-badge {
    display: inline-flex;
    align-items: center;
    font-size: 0.82rem;
    padding: 6px 14px;
    border-radius: 999px;
    border: 1px solid transparent;
}

.geo-badge-ok {
    background: rgba(22,163,74, 0.1);
    border-color: rgba(22,163,74, 0.35);
    color: #166534;
}

.geo-badge-defaut,
.geo-badge-attention {
    background: rgba(255, 193, 7, 0.12);
    border-color: rgba(255, 193, 7, 0.4);
    color: #991B1B;
}

.geo-refresh {
    border: 1px solid rgba(22,163,74, 0.35);
    background: #fff;
    color: var(--primary-green, #16A34A);
    min-width: 32px;
    height: 32px;
    padding: 0 10px;
    border-radius: 999px;
    cursor: pointer;
    font-size: 0.8rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: var(--transition, all 0.3s ease);
}

.geo-refresh__libelle {
    font-size: 0.78rem;
    font-weight: 600;
}

.geo-refresh:hover {
    background: var(--primary-green, #16A34A);
    color: #fff;
}

/* ===== Bandeau : distance indisponible =====
   Affiché lorsque la position GPS n'est pas connue. Aucune distance
   n'est publiée dans ce cas : la plateforme ne doit pas afficher un
   chiffre calculé depuis une position inventée. */
.geo-alerte {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    background: #FFFBEB;
    border: 1px solid rgba(245, 158, 11, 0.45);
    border-left: 4px solid #F59E0B;
    border-radius: 14px;
    padding: 16px 18px;
    margin-bottom: 20px;
    color: #78350F;
}

.geo-alerte__ico {
    font-size: 1.25rem;
    color: #D97706;
    line-height: 1.4;
}

.geo-alerte__body {
    flex: 1;
    min-width: 0;
}

.geo-alerte__body strong {
    display: block;
    font-size: 0.95rem;
    margin-bottom: 4px;
}

.geo-alerte__body p {
    margin: 0 0 12px;
    font-size: 0.86rem;
    line-height: 1.55;
}

.geo-alerte__btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--primary-green, #16A34A);
    color: #fff;
    border: 0;
    border-radius: 999px;
    padding: 9px 18px;
    font-size: 0.83rem;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition, all 0.3s ease);
}

.geo-alerte__btn:hover {
    background: #166534;
    color: #fff;
}

.geo-attente .geo-refresh i {
    animation: geo-spin 1s linear infinite;
}

.geo-attente .geo-badge {
    opacity: 0.65;
}

@keyframes geo-spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* ===== Distance sur une carte de pharmacie ===== */
.geo-distance-chip {
    display: inline-flex;
    align-items: center;
    background: rgba(22,163,74, 0.12);
    color: #166534;
    border-radius: 999px;
    padding: 2px 10px;
    font-size: 0.75rem;
    font-weight: 700;
    white-space: nowrap;
}
</style>
