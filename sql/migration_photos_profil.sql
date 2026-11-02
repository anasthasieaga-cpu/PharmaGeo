-- ============================================================
-- PharmaGeo — Migration : photos de profil
--
-- Ajoute la colonne `Photo` sur la table `utilisateur` afin de
-- stocker la photo de profil du pharmacien (chemin relatif à la
-- racine du projet, ex : "uploads/photos/pharmacien_3_ab12.jpg").
--
-- La photo de la pharmacie utilise la colonne `pharmacie.Logo`
-- (déjà présente dans le schéma).
--
-- Import : mysql -u root gestion_soutenance < migration_photos_profil.sql
-- ============================================================

ALTER TABLE `utilisateur`
    ADD COLUMN `Photo` VARCHAR(255) DEFAULT NULL AFTER `Longitude`;
