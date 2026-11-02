<?php
// ============================================================
// PharmaGeo — Logique de réservation (partagée)
//
// Fonctions utilisées par reservation.php (réservation d'un seul
// médicament) et reservation_groupe.php (réservation de tous les
// médicaments d'une pharmacie en une seule fois).
// ============================================================

// ============================================================
// IDENTITÉ DU PATIENT
// ============================================================

/**
 * Valider les informations fournies par un visiteur sans compte.
 *
 * @return array Liste des messages d'erreur (vide si tout est valide)
 */
function reservationValiderInvite($invite, $motDePasse, $confirmation) {
    $erreurs = [];

    if (empty($invite['nom'])) {
        $erreurs[] = "Le nom est obligatoire.";
    } elseif (strlen($invite['nom']) < 2) {
        $erreurs[] = "Le nom doit contenir au moins 2 caractères.";
    }

    if (empty($invite['prenom'])) {
        $erreurs[] = "Le prénom est obligatoire.";
    } elseif (strlen($invite['prenom']) < 2) {
        $erreurs[] = "Le prénom doit contenir au moins 2 caractères.";
    }

    if (empty($invite['email'])) {
        $erreurs[] = "L'adresse email est obligatoire.";
    } elseif (!filter_var($invite['email'], FILTER_VALIDATE_EMAIL)) {
        $erreurs[] = "L'adresse email n'est pas valide.";
    }

    if (!empty($invite['telephone']) && !preg_match('/^[0-9+\-\s]{8,20}$/', $invite['telephone'])) {
        $erreurs[] = "Le numéro de téléphone n'est pas valide.";
    }

    if (empty($motDePasse)) {
        $erreurs[] = "Le mot de passe est obligatoire.";
    } elseif (strlen($motDePasse) < 8) {
        $erreurs[] = "Le mot de passe doit contenir au moins 8 caractères.";
    } elseif (!preg_match('/[A-Z]/', $motDePasse)) {
        $erreurs[] = "Le mot de passe doit contenir au moins une majuscule.";
    } elseif (!preg_match('/[a-z]/', $motDePasse)) {
        $erreurs[] = "Le mot de passe doit contenir au moins une minuscule.";
    } elseif (!preg_match('/[0-9]/', $motDePasse)) {
        $erreurs[] = "Le mot de passe doit contenir au moins un chiffre.";
    }

    if ($motDePasse !== $confirmation) {
        $erreurs[] = "Les mots de passe ne correspondent pas.";
    }

    return $erreurs;
}

/**
 * Déterminer le patient concerné par une réservation.
 *
 * Si la personne est déjà connectée, son identifiant est renvoyé tel quel.
 * Sinon, les informations du formulaire servent soit à ouvrir une session sur
 * un compte existant, soit à créer un compte patient.
 *
 * @return array{
 *     user_id:int|null, erreurs:string[], compte_creer:bool,
 *     session_ouverte:bool, utilisateur:array|null
 * }
 */
function reservationResoudreUtilisateur(PDO $pdo, $invite, $motDePasse = null, $confirmation = null) {
    $resultat = array(
        'user_id'         => null,
        'erreurs'         => array(),
        'compte_creer'    => false,
        'session_ouverte' => false,
        'utilisateur'     => null
    );

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Déjà connecté : rien à demander au visiteur.
    if (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM utilisateur WHERE Id_utilisateur = ?");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $existant = $stmt->fetch();

        if ($existant && $existant['Statut'] === 'actif') {
            $resultat['user_id'] = (int)$existant['Id_utilisateur'];
            $resultat['utilisateur'] = $existant;
            return $resultat;
        }
        // Compte introuvable ou désactivé : on repart du formulaire invité.
        unset($_SESSION['user_id'], $_SESSION['role']);
    }

    $erreurs = reservationValiderInvite($invite, $motDePasse, $confirmation);
    if (!empty($erreurs)) {
        $resultat['erreurs'] = $erreurs;
        return $resultat;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM utilisateur WHERE LOWER(Email) = LOWER(?)");
        $stmt->execute([$invite['email']]);
        $existant = $stmt->fetch();

        if ($existant) {
            // Email déjà inscrit : connexion directe si le mot de passe est correct.
            if ($existant['Statut'] !== 'actif' || !password_verify((string)$motDePasse, $existant['Mot_de_passe'])) {
                $resultat['erreurs'][] = "Cette adresse email est déjà utilisée. Connectez-vous avec votre mot de passe ou utilisez un autre email.";
                return $resultat;
            }

            $_SESSION['user_id'] = (int)$existant['Id_utilisateur'];
            $_SESSION['nom'] = $existant['Nom'];
            $_SESSION['prenom'] = $existant['Prenom'];
            $_SESSION['role'] = $existant['Role'];
            $_SESSION['email'] = $existant['Email'];

            $resultat['user_id'] = (int)$existant['Id_utilisateur'];
            $resultat['session_ouverte'] = true;
            $resultat['utilisateur'] = $existant;
            return $resultat;
        }

        // Création du compte patient
        $stmt = $pdo->prepare("
            INSERT INTO utilisateur (Nom, Prenom, Email, Mot_de_passe, Telephone, Role, Statut)
            VALUES (?, ?, ?, ?, ?, 'patient', 'actif')
        ");
        $stmt->execute(array(
            $invite['nom'],
            $invite['prenom'],
            $invite['email'],
            password_hash((string)$motDePasse, PASSWORD_DEFAULT),
            $invite['telephone'] !== '' ? $invite['telephone'] : null
        ));
        $userId = (int)$pdo->lastInsertId();

        $_SESSION['user_id'] = $userId;
        $_SESSION['nom'] = $invite['nom'];
        $_SESSION['prenom'] = $invite['prenom'];
        $_SESSION['role'] = 'patient';
        $_SESSION['email'] = $invite['email'];
        $_SESSION['welcome_shown'] = false;

        $resultat['user_id'] = $userId;
        $resultat['compte_creer'] = true;
        $resultat['session_ouverte'] = true;

        reservationJournaliser($pdo, 'Inscription patient (réservation)', $userId);

        return $resultat;
    } catch (PDOException $e) {
        $resultat['erreurs'][] = "Erreur lors de la création du compte.";
        return $resultat;
    }
}

// ============================================================
// DISPONIBILITÉ
// ============================================================

/**
 * Vérifier qu'une quantité peut être réservée pour une ligne de stock.
 *
 * @return string Message d'erreur, ou une chaîne vide si c'est réservable
 */
function reservationVerifierStock($stockInfo, $quantite) {
    if ($stockInfo['Pharmacie_Statut'] !== 'active') {
        return "La pharmacie n'est pas active pour le moment.";
    }
    if (isset($stockInfo['Medicament_Statut']) && $stockInfo['Medicament_Statut'] === 'suspendu') {
        return "Ce médicament est momentanément indisponible.";
    }
    if (in_array($stockInfo['Statut_stock'], array('rupture', 'desactive'))) {
        return "Stock indisponible.";
    }
    if ((int)$stockInfo['Quantite'] <= 0) {
        return "Stock épuisé.";
    }
    if ($quantite > (int)$stockInfo['Quantite']) {
        return "Stock insuffisant : " . (int)$stockInfo['Quantite'] . " unité(s) disponible(s).";
    }
    return '';
}

/**
 * Une réservation en attente existe-t-elle déjà pour ce patient et ce stock ?
 */
function reservationDejaEnAttente(PDO $pdo, $userId, $idStock) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total FROM reservation
        WHERE Id_utilisateur = ? AND Id_stock = ? AND Statut = 'en_attente'
    ");
    $stmt->execute([$userId, $idStock]);
    return ((int)$stmt->fetch()['total']) > 0;
}

/**
 * Montant d'une ligne de réservation (null si le prix n'est pas communiqué).
 */
function reservationMontant($stockInfo, $quantite) {
    if (empty($stockInfo['Prix']) || (float)$stockInfo['Prix'] <= 0) return null;
    return $quantite * (float)$stockInfo['Prix'];
}

// ============================================================
// ÉCRITURE
// ============================================================

/**
 * Insérer une ligne de réservation. L'appelant gère la transaction :
 * cette fonction n'ouvre ni ne valide aucun transaction, afin de
 * pouvoir regrouper plusieurs insertions (réservation groupée).
 *
 * @return float|null Montant de la ligne
 */
function reservationInserer(PDO $pdo, $userId, $stockInfo, $quantite) {
    $montant = reservationMontant($stockInfo, $quantite);

    $stmt = $pdo->prepare("
        INSERT INTO reservation (Id_utilisateur, Id_stock, Quantite, Montant, Statut, Date_reservation)
        VALUES (?, ?, ?, ?, 'en_attente', NOW())
    ");
    $stmt->execute([$userId, (int)$stockInfo['Id_stock'], $quantite, $montant]);

    return $montant;
}

/**
 * Insère une réservation et retourne son identifiant.
 * Utilisé par la réservation groupée, qui doit ensuite envoyer le
 * patient sur la page de ses réservations et non sur l'historique.
 *
 * @return array{montant:float, id:int}
 */
function reservationInsererEtRetournerId(PDO $pdo, $userId, $stockInfo, $quantite) {
    $montant = reservationInserer($pdo, $userId, $stockInfo, $quantite);

    $stmt = $pdo->prepare("SELECT LAST_INSERT_ID() AS id");
    $stmt->execute();
    $id = (int)$stmt->fetchColumn();

    return array('montant' => $montant, 'id' => $id);
}

/**
 * Prévenir le pharmacien qu'une réservation arrive. Ne bloque jamais
 * la réservation en cas d'échec (notification secondary).
 */
function reservationNotifierPharmacien(PDO $pdo, $idPharmacie, $message) {
    try {
        $stmt = $pdo->prepare("
            SELECT ph.Id_utilisateur as Id_pharmacien
            FROM pharmacie p
            JOIN pharmacien ph ON p.Id_pharmacien = ph.Id_pharmacien
            WHERE p.Id_pharmacie = ?
        ");
        $stmt->execute([$idPharmacie]);
        $pharmacien = $stmt->fetch();

        if (!$pharmacien) return;

        $stmt = $pdo->prepare("
            INSERT INTO notification (Titre, Message, Id_utilisateur, Lu)
            VALUES ('Nouvelle réservation', ?, ?, 0)
        ");
        $stmt->execute([$message, $pharmacien['Id_pharmacien']]);
    } catch (PDOException $e) {
        // La notification est secondaire : son échec ne doit pas
        // faire perdre la réservation.
    }
}

/**
 * Journaliser une action dans la table log.
 */
function reservationJournaliser(PDO $pdo, $action, $userId) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur)
            VALUES (?, ?, ?, 'succes', ?)
        ");
        $stmt->execute([
            $action,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $userId
        ]);
    } catch (PDOException $e) {
        // Ignorer
    }
}

/**
 * Réserver un seul médicament : vérifications, écriture, notification
 * et journalisation. Utilisé par reservation.php.
 *
 * @return array{ok:bool, erreur:string, montant:float|null, quantite:int, id:int}
 */
function reservationCreer(PDO $pdo, $userId, $stockInfo, $quantite) {
    $resultat = array('ok' => false, 'erreur' => '', 'montant' => null, 'quantite' => $quantite, 'id' => 0);

    $quantite = max(1, (int)$quantite);

    $erreur = reservationVerifierStock($stockInfo, $quantite);
    if ($erreur !== '') {
        $resultat['erreur'] = $erreur;
        return $resultat;
    }

    if (reservationDejaEnAttente($pdo, $userId, $stockInfo['Id_stock'])) {
        $resultat['erreur'] = "Vous avez déjà une réservation en attente pour ce médicament dans cette pharmacie.";
        return $resultat;
    }

    try {
        $pdo->beginTransaction();

        $montant = reservationInserer($pdo, $userId, $stockInfo, $quantite);

        // On récupère l'identifiant pour renvoyer le patient directement
        // sur la page de sa réservation.
        $stmtId = $pdo->prepare("SELECT LAST_INSERT_ID() AS id");
        $stmtId->execute();
        $idReservation = (int)$stmtId->fetchColumn();

        reservationNotifierPharmacien(
            $pdo,
            $stockInfo['Id_pharmacie'],
            'Nouvelle réservation : ' . $stockInfo['Nom_commercial'] . ' (x' . $quantite . ')'
        );
        reservationJournaliser($pdo, 'Réservation créée', $userId);

        $pdo->commit();

        $resultat['ok'] = true;
        $resultat['montant'] = $montant;
        $resultat['id'] = $idReservation;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $resultat['erreur'] = "Erreur lors de l'enregistrement de la réservation.";
    }

    return $resultat;
}
