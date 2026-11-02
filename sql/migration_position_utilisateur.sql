-- ============================================================
-- PharmaGeo — Migration : position de la personne
--
-- Ajoute Latitude / Longitude sur la table `utilisateur` afin que
-- la derniere position connue survive a la fermeture du navigateur.
-- Sans ces colonnes, le calcul des distances ne peut s'appuyer que
-- sur la session PHP (voir includes/geo.php, qui degrade proprement
-- en mode session seul si les colonnes sont absentes).
--
-- Import : mysql -u root gestion_soutenance < migration_position_utilisateur.sql
-- ============================================================

ALTER TABLE `utilisateur`
    ADD COLUMN `Latitude`  DECIMAL(10,7) DEFAULT NULL AFTER `Telephone`,
    ADD COLUMN `Longitude` DECIMAL(10,7) DEFAULT NULL AFTER `Latitude`;
