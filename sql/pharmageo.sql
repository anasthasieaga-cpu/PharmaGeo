-- ============================================================
-- PharmaGeo - Schéma de la base de données
-- Base : gestion_soutenance
-- Moteur : MySQL 5.7+ / MariaDB 10+
-- Charset : utf8mb4
-- ============================================================
-- Schéma reconstruit à partir de la structure réelle de la base
-- (SHOW CREATE TABLE). Il reproduit fidèlement les tables,
-- colonnes, types, index et clés étrangères utilisées par
-- l'application.
-- ============================================================

CREATE DATABASE IF NOT EXISTS gestion_soutenance
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_general_ci;

USE gestion_soutenance;

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- TABLE : utilisateur
-- ============================================================
DROP TABLE IF EXISTS utilisateur;
CREATE TABLE utilisateur (
    Id_utilisateur INT UNSIGNED NOT NULL AUTO_INCREMENT,
    Nom            VARCHAR(100) NOT NULL,
    Prenom         VARCHAR(100) NOT NULL,
    Email          VARCHAR(150) NOT NULL,
    Mot_de_passe   VARCHAR(255) NOT NULL,
    Telephone      VARCHAR(20) DEFAULT NULL,
    -- Dernière position connue de la personne : sert de référence au calcul
    -- des distances lorsque le navigateur n'a pas encore fourni de GPS.
    -- Renseignée par api/geoloc_api.php via includes/geo.php.
    Latitude       DECIMAL(10,7) DEFAULT NULL,
    Longitude      DECIMAL(10,7) DEFAULT NULL,
    -- Photo de profil de l'utilisateur (chemin relatif au projet,
    -- ex : "uploads/photos/pharmacien_3_ab12.jpg"). NULL = image par défaut.
    Photo          VARCHAR(255) DEFAULT NULL,
    Role           ENUM('administrateur','pharmacien','patient') NOT NULL DEFAULT 'patient',
    Statut         ENUM('actif','suspendu','desactive') NOT NULL DEFAULT 'actif',
    Date_creation  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (Id_utilisateur),
    UNIQUE KEY uq_utilisateur_email (Email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE : password_reset
-- Jetons de réinitialisation du mot de passe (mot de passe oublié)
-- ============================================================
DROP TABLE IF EXISTS password_reset;
CREATE TABLE password_reset (
    Id_reset       INT NOT NULL AUTO_INCREMENT,
    Token          VARCHAR(64) NOT NULL,
    Expiration     DATETIME NOT NULL,
    Utilise        TINYINT(1) NOT NULL DEFAULT 0,
    Date_creation  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Id_utilisateur INT NOT NULL,
    PRIMARY KEY (Id_reset),
    UNIQUE KEY uq_pwdreset_user (Id_utilisateur),
    KEY idx_pwdreset_token (Token),
    CONSTRAINT fk_pwdreset_utilisateur
        FOREIGN KEY (Id_utilisateur)
        REFERENCES utilisateur (Id_utilisateur)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE : pharmacien
-- ============================================================
DROP TABLE IF EXISTS pharmacien;
CREATE TABLE pharmacien (
    Id_pharmacien     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    Numero_ordre      VARCHAR(50) NOT NULL,
    Date_validation   DATETIME DEFAULT NULL,
    Statut_validation ENUM('en_attente','valide','rejete') NOT NULL DEFAULT 'en_attente',
    Id_utilisateur    INT UNSIGNED NOT NULL,
    PRIMARY KEY (Id_pharmacien),
    UNIQUE KEY uq_pharmacien_numero_ordre (Numero_ordre),
    UNIQUE KEY uq_pharmacien_utilisateur (Id_utilisateur),
    CONSTRAINT fk_pharmacien_utilisateur
        FOREIGN KEY (Id_utilisateur)
        REFERENCES utilisateur (Id_utilisateur)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE : pharmacie
-- ============================================================
DROP TABLE IF EXISTS pharmacie;
CREATE TABLE pharmacie (
    Id_pharmacie  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    Nom           VARCHAR(150) NOT NULL,
    Adresse       VARCHAR(255) DEFAULT NULL,
    Quartier      VARCHAR(100) DEFAULT NULL,
    Commune       VARCHAR(100) DEFAULT NULL,
    Latitude      DECIMAL(10,7) NOT NULL,
    Longitude     DECIMAL(10,7) NOT NULL,
    Telephone     VARCHAR(20) DEFAULT NULL,
    Email         VARCHAR(150) DEFAULT NULL,
    Logo          VARCHAR(255) DEFAULT NULL,
    Horaire       VARCHAR(255) DEFAULT NULL,
    Statut        ENUM('en_attente','active','suspendue') NOT NULL DEFAULT 'en_attente',
    Id_pharmacien INT UNSIGNED NOT NULL,
    PRIMARY KEY (Id_pharmacie),
    UNIQUE KEY uq_pharmacie_pharmacien (Id_pharmacien),
    CONSTRAINT fk_pharmacie_pharmacien
        FOREIGN KEY (Id_pharmacien)
        REFERENCES pharmacien (Id_pharmacien)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE : jour_permanence
-- ============================================================
DROP TABLE IF EXISTS jour_permanence;
CREATE TABLE jour_permanence (
    Id_permanence INT UNSIGNED NOT NULL AUTO_INCREMENT,
    Jour_semaine  ENUM('lundi','mardi','mercredi','jeudi','vendredi','samedi','dimanche') NOT NULL,
    Date_debut    DATE DEFAULT NULL,
    Date_fin      DATE DEFAULT NULL,
    Id_pharmacie  INT UNSIGNED NOT NULL,
    PRIMARY KEY (Id_permanence),
    KEY fk_permanence_pharmacie (Id_pharmacie),
    CONSTRAINT fk_permanence_pharmacie
        FOREIGN KEY (Id_pharmacie)
        REFERENCES pharmacie (Id_pharmacie)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE : categorie
-- ============================================================
DROP TABLE IF EXISTS categorie;
CREATE TABLE categorie (
    Id_categorie INT UNSIGNED NOT NULL AUTO_INCREMENT,
    Libelle      VARCHAR(100) NOT NULL,
    Description  TEXT DEFAULT NULL,
    PRIMARY KEY (Id_categorie),
    UNIQUE KEY uq_categorie_libelle (Libelle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE : medicament
-- ============================================================
DROP TABLE IF EXISTS medicament;
CREATE TABLE medicament (
    Id_medicament  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    Nom_commercial VARCHAR(150) NOT NULL,
    DCI            VARCHAR(150) DEFAULT NULL,
    Forme          VARCHAR(50) DEFAULT NULL,
    Dosage         VARCHAR(50) DEFAULT NULL,
    Description    TEXT DEFAULT NULL,
    Id_categorie   INT UNSIGNED NOT NULL,
    PRIMARY KEY (Id_medicament),
    KEY fk_medicament_categorie (Id_categorie),
    KEY idx_medicament_nom (Nom_commercial),
    KEY idx_medicament_dci (DCI),
    CONSTRAINT fk_medicament_categorie
        FOREIGN KEY (Id_categorie)
        REFERENCES categorie (Id_categorie)
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE : medicament_synonyme
-- ============================================================
DROP TABLE IF EXISTS medicament_synonyme;
CREATE TABLE medicament_synonyme (
    Id_medicament INT UNSIGNED NOT NULL,
    Libelle       VARCHAR(150) NOT NULL,
    PRIMARY KEY (Id_medicament, Libelle),
    KEY idx_synonyme_libelle (Libelle),
    CONSTRAINT fk_synonyme_medicament
        FOREIGN KEY (Id_medicament)
        REFERENCES medicament (Id_medicament)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE : stock
-- ============================================================
DROP TABLE IF EXISTS stock;
CREATE TABLE stock (
    Id_stock         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    Quantite         INT NOT NULL DEFAULT 0,
    Prix             DECIMAL(10,2) DEFAULT NULL,
    Seuil_alerte     INT NOT NULL DEFAULT 5,
    Statut           ENUM('disponible','stock_faible','rupture','en_approvisionnement','desactive')
                     NOT NULL DEFAULT 'disponible',
    Date_mise_a_jour DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                     ON UPDATE CURRENT_TIMESTAMP,
    Id_pharmacie     INT UNSIGNED NOT NULL,
    Id_medicament    INT UNSIGNED NOT NULL,
    PRIMARY KEY (Id_stock),
    UNIQUE KEY uq_stock_pharmacie_medicament (Id_pharmacie, Id_medicament),
    KEY fk_stock_medicament (Id_medicament),
    CONSTRAINT fk_stock_medicament
        FOREIGN KEY (Id_medicament)
        REFERENCES medicament (Id_medicament)
        ON UPDATE CASCADE,
    CONSTRAINT fk_stock_pharmacie
        FOREIGN KEY (Id_pharmacie)
        REFERENCES pharmacie (Id_pharmacie)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_stock_quantite CHECK (Quantite >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE : historique_stock
-- ============================================================
DROP TABLE IF EXISTS historique_stock;
CREATE TABLE historique_stock (
    Id_historique     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    Ancienne_quantite INT NOT NULL,
    Nouvelle_quantite INT NOT NULL,
    Type_operation    VARCHAR(50) NOT NULL,
    Date_operation    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Id_stock          INT UNSIGNED NOT NULL,
    Id_utilisateur    INT UNSIGNED NOT NULL,
    PRIMARY KEY (Id_historique),
    KEY fk_historique_stock (Id_stock),
    KEY fk_historique_utilisateur (Id_utilisateur),
    CONSTRAINT fk_historique_stock
        FOREIGN KEY (Id_stock)
        REFERENCES stock (Id_stock)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_historique_utilisateur
        FOREIGN KEY (Id_utilisateur)
        REFERENCES utilisateur (Id_utilisateur)
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE : reservation
-- ============================================================
DROP TABLE IF EXISTS reservation;
CREATE TABLE reservation (
    Id_reservation   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    Quantite         INT NOT NULL,
    Statut           ENUM('en_attente','confirmee','refusee','expiree','retiree','annulee')
                     NOT NULL DEFAULT 'en_attente',
    Date_reservation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Date_expiration  DATETIME DEFAULT NULL,
    Id_utilisateur   INT UNSIGNED NOT NULL,
    Id_stock         INT UNSIGNED NOT NULL,
    PRIMARY KEY (Id_reservation),
    KEY fk_reservation_utilisateur (Id_utilisateur),
    KEY fk_reservation_stock (Id_stock),
    CONSTRAINT fk_reservation_stock
        FOREIGN KEY (Id_stock)
        REFERENCES stock (Id_stock)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_reservation_utilisateur
        FOREIGN KEY (Id_utilisateur)
        REFERENCES utilisateur (Id_utilisateur)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_reservation_quantite CHECK (Quantite > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE : recherche
-- ============================================================
DROP TABLE IF EXISTS recherche;
CREATE TABLE recherche (
    Id_recherche   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    Mot_recherche  VARCHAR(150) NOT NULL,
    Latitude       DECIMAL(10,7) DEFAULT NULL,
    Longitude      DECIMAL(10,7) DEFAULT NULL,
    Date_recherche DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Id_utilisateur INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (Id_recherche),
    KEY fk_recherche_utilisateur (Id_utilisateur),
    KEY idx_recherche_mot (Mot_recherche),
    CONSTRAINT fk_recherche_utilisateur
        FOREIGN KEY (Id_utilisateur)
        REFERENCES utilisateur (Id_utilisateur)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE : notification
-- ============================================================
DROP TABLE IF EXISTS notification;
CREATE TABLE notification (
    Id_notification    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    Titre              VARCHAR(150) NOT NULL,
    Message            TEXT NOT NULL,
    Type               VARCHAR(50) DEFAULT NULL,
    Lu                 TINYINT(1) NOT NULL DEFAULT 0,
    Date_notification  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Id_utilisateur     INT UNSIGNED NOT NULL,
    PRIMARY KEY (Id_notification),
    KEY fk_notification_utilisateur (Id_utilisateur),
    CONSTRAINT fk_notification_utilisateur
        FOREIGN KEY (Id_utilisateur)
        REFERENCES utilisateur (Id_utilisateur)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE : log (journalisation des activités)
-- ============================================================
DROP TABLE IF EXISTS log;
CREATE TABLE log (
    Id_log         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    Action         VARCHAR(150) NOT NULL,
    Date_action    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Adresse_ip     VARCHAR(45) DEFAULT NULL,
    Navigateur     VARCHAR(255) DEFAULT NULL,
    Resultat       ENUM('succes','echec') NOT NULL DEFAULT 'succes',
    Id_utilisateur INT UNSIGNED NOT NULL,
    PRIMARY KEY (Id_log),
    KEY fk_log_utilisateur (Id_utilisateur),
    CONSTRAINT fk_log_utilisateur
        FOREIGN KEY (Id_utilisateur)
        REFERENCES utilisateur (Id_utilisateur)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- TABLE : settings
-- Clé/valeur pour les paramètres globaux de la plateforme
-- (ex : maintenance_mode = '1' ou '0')
-- ============================================================
DROP TABLE IF EXISTS settings;
CREATE TABLE settings (
    Cle              VARCHAR(50) NOT NULL,
    Valeur           TEXT DEFAULT NULL,
    Date_mise_a_jour TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                     ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (Cle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- DONNÉES INITIALES (optionnelles)
-- ============================================================

-- Administrateur par défaut
-- À personnaliser : remplacez le hash par
--   php -r "echo password_hash('VOTRE_MDP', PASSWORD_DEFAULT);"
INSERT INTO utilisateur (Nom, Prenom, Email, Mot_de_passe, Telephone, Role, Statut)
VALUES ('Admin', 'PharmaGeo', 'admin@pharmageo.bj',
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        '+22900000000', 'administrateur', 'actif');

-- Catégories initiales
INSERT INTO categorie (Libelle, Description) VALUES
('Analgésiques', 'Médicaments contre la douleur'),
('Antibiotiques', 'Médicaments contre les infections bactériennes'),
('Antipaludiques', 'Médicaments contre le paludisme'),
('Antihypertenseurs', 'Médicaments contre l''hypertension'),
('Vitamines', 'Compléments vitaminiques'),
('Anti-inflammatoires', 'Médicaments contre l''inflammation');
