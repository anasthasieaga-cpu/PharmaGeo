<?php
// ============================================================
// PharmaGeo — Espace pharmacien : Gestion des médicaments
// Permet d'ajouter, modifier ou supprimer les médicaments du
// stock de la pharmacie, avec contrôle des réservations liées.
// ============================================================

// ===== ACTIVATION DES ERREURS =====
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ===== DÉFINITION DES CONSTANTES =====
define('BASE_URL', 'http://localhost/pharmageo/');

// ===== FONCTIONS UTILITAIRES =====
require_once __DIR__ . '/../includes/fonctions.php';

// ===== PAGINATION (10 éléments par page) =====
require_once __DIR__ . '/../includes/pagination.php';

// ===== CONNEXION À LA BASE DE DONNÉES =====
try {
    $pdo = new PDO("mysql:host=localhost;dbname=gestion_soutenance;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Erreur de connexion à la base de données : " . $e->getMessage());
}

// ===== GESTION DES SESSIONS =====
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== MESSAGE FLASH (PRG) : stoque le message puis redirige vers une URL propre ====
function redirectWithMessage(string $message, string $type = 'success'): void {
    $_SESSION['flash_message'] = ['type' => $type, 'text' => $message];
    header('Location: ' . BASE_URL . 'pharmacien/medicaments.php');
    exit;
}

// ===== VÉRIFICATION PHARMACIEN =====
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'pharmacien') {
    header('Location: ' . BASE_URL . 'connexion.php');
    exit();
}

// ===== RÉCUPÉRATION DES INFORMATIONS =====
try {
    // Récupérer les informations du pharmacien et de sa pharmacie
    $stmt = $pdo->prepare("
        SELECT u.*, ph.Id_pharmacien, ph.Numero_ordre, ph.Statut_validation,
               p.Id_pharmacie, p.Nom as Pharmacie_Nom, p.Statut as Pharmacie_Statut
        FROM utilisateur u
        JOIN pharmacien ph ON u.Id_utilisateur = ph.Id_utilisateur
        LEFT JOIN pharmacie p ON ph.Id_pharmacien = p.Id_pharmacien
        WHERE u.Id_utilisateur = :id
    ");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        header('Location: ' . BASE_URL . 'connexion.php');
        exit();
    }

    // Vérifier si le pharmacien a une pharmacie
    $has_pharmacy = !empty($user['Id_pharmacie']);
    $pharmacie_id = $user['Id_pharmacie'] ?? null;

    if (!$has_pharmacy) {
        header('Location: ' . BASE_URL . 'pharmacien/dashboard.php?error=no_pharmacy');
        exit();
    }

} catch(PDOException $e) {
    die("Erreur : " . $e->getMessage());
}

// ===== TRAITEMENT DES ACTIONS =====
$message = '';
$message_type = '';

// ===== AJOUT D'UN MÉDICAMENT AU STOCK =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajouter') {
    $id_medicament = (int)($_POST['id_medicament'] ?? 0);
    $nouveau_medicament = trim($_POST['nouveau_medicament'] ?? '');
    $nouveau_medicament_dci = trim($_POST['nouveau_medicament_dci'] ?? '');
    $nouveau_medicament_forme = trim($_POST['nouveau_medicament_forme'] ?? '');
    $nouveau_medicament_dosage = trim($_POST['nouveau_medicament_dosage'] ?? '');
    $nouveau_medicament_categorie = trim($_POST['nouveau_medicament_categorie'] ?? '');
    $quantite = (int)($_POST['quantite'] ?? 0);
    $prix = !empty($_POST['prix']) ? (float)$_POST['prix'] : null;
    $seuil_alerte = (int)($_POST['seuil_alerte'] ?? 5);
    
    $errors = [];
    if ($id_medicament <= 0) {
        // Aucun médicament existant sélectionné : on autorise la saisie d'un nom
        if ($nouveau_medicament === '') {
            $errors[] = "Veuillez écrire le nom du médicament ou en sélectionner un.";
        } else {
            // Chercher un médicament existant avec le même nom (insensible à la casse)
            $nom_peres = $pdo->prepare("SELECT Id_medicament FROM medicament WHERE LOWER(Nom_commercial) = LOWER(:nom) LIMIT 1");
            $nom_peres->execute([':nom' => $nouveau_medicament]);
            $existant = $nom_peres->fetch();
            if ($existant) {
                $id_medicament = (int)$existant['Id_medicament'];
            } else {
                // Aucun médicament trouvé : création d'un nouveau médicament
                try {
                    // Résoudre / créer la catégorie (obligatoire : NOT NULL)
                    $id_categorie = 0;
                    if ($nouveau_medicament_categorie !== '') {
                        $cat = $pdo->prepare("SELECT Id_categorie FROM categorie WHERE LOWER(Libelle) = LOWER(:lib) LIMIT 1");
                        $cat->execute([':lib' => $nouveau_medicament_categorie]);
                        $catRow = $cat->fetch();
                        if ($catRow) {
                            $id_categorie = (int)$catRow['Id_categorie'];
                        } else {
                            $insCat = $pdo->prepare("INSERT INTO categorie (Libelle) VALUES (:lib)");
                            $insCat->execute([':lib' => $nouveau_medicament_categorie]);
                            $id_categorie = (int)$pdo->lastInsertId();
                        }
                    }
                    if ($id_categorie <= 0) {
                        // Catégorie par défaut
                        $catDef = $pdo->prepare("SELECT Id_categorie FROM categorie WHERE LOWER(Libelle) = 'general' LIMIT 1");
                        $catDef->execute();
                        $catRow = $catDef->fetch();
                        if ($catRow) {
                            $id_categorie = (int)$catRow['Id_categorie'];
                        } else {
                            $insCat = $pdo->prepare("INSERT INTO categorie (Libelle) VALUES ('Général')");
                            $insCat->execute();
                            $id_categorie = (int)$pdo->lastInsertId();
                        }
                    }
                    $insMed = $pdo->prepare("
                        INSERT INTO medicament (Nom_commercial, DCI, Forme, Dosage, Id_categorie)
                        VALUES (:nom, :dci, :forme, :dosage, :categorie)
                    ");
                    $insMed->execute([
                        ':nom' => $nouveau_medicament,
                        ':dci' => $nouveau_medicament_dci !== '' ? $nouveau_medicament_dci : null,
                        ':forme' => $nouveau_medicament_forme !== '' ? $nouveau_medicament_forme : null,
                        ':dosage' => $nouveau_medicament_dosage !== '' ? $nouveau_medicament_dosage : null,
                        ':categorie' => $id_categorie
                    ]);
                    $id_medicament = (int)$pdo->lastInsertId();
                } catch (PDOException $e) {
                    $errors[] = "Impossible de créer le médicament : " . $e->getMessage();
                }
            }
        }
    }
    if ($quantite < 0) $errors[] = "La quantité doit être un nombre positif.";
    
    // Vérifier si le médicament existe déjà dans le stock
    if (empty($errors)) {
        $check = $pdo->prepare("
            SELECT COUNT(*) as total FROM stock 
            WHERE Id_pharmacie = :pharmacie AND Id_medicament = :medicament
        ");
        $check->execute([
            ':pharmacie' => $pharmacie_id,
            ':medicament' => $id_medicament
        ]);
        if ($check->fetch()['total'] > 0) {
            $errors[] = "Ce médicament est déjà dans votre stock. Utilisez la fonction de mise à jour.";
        }
    }
    
    if (empty($errors)) {
        try {
            // Déterminer le statut
            $statut = 'disponible';
            if ($quantite == 0) {
                $statut = 'rupture';
            } elseif ($quantite <= $seuil_alerte) {
                $statut = 'stock_faible';
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO stock (Quantite, Prix, Seuil_alerte, Statut, Id_pharmacie, Id_medicament)
                VALUES (:quantite, :prix, :seuil, :statut, :pharmacie, :medicament)
            ");
            $stmt->execute([
                ':quantite' => $quantite,
                ':prix' => $prix,
                ':seuil' => $seuil_alerte,
                ':statut' => $statut,
                ':pharmacie' => $pharmacie_id,
                ':medicament' => $id_medicament
            ]);
            
            $stock_id = $pdo->lastInsertId();
            
            // Historique du stock
            $hist = $pdo->prepare("
                INSERT INTO historique_stock (Ancienne_quantite, Nouvelle_quantite, Type_operation, Id_stock, Id_utilisateur)
                VALUES (0, :quantite, 'ajout', :stock, :user)
            ");
            $hist->execute([
                ':quantite' => $quantite,
                ':stock' => $stock_id,
                ':user' => $_SESSION['user_id']
            ]);
            
            // Journalisation
            $log = $pdo->prepare("
                INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur)
                VALUES (CONCAT('Ajout médicament au stock ID: ', :medicament), :ip, :ua, 'succes', :user)
            ");
            $log->execute([
                ':medicament' => $id_medicament,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':user' => $_SESSION['user_id']
            ]);
            
            $message = "Médicament ajouté au stock avec succès !";
            $message_type = "success";
            
        } catch(PDOException $e) {
            $message = "Erreur lors de l'ajout : " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = implode('<br>', $errors);
        $message_type = "danger";
    }
}

// ===== MISE À JOUR D'UN STOCK =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'modifier') {
    $id_stock = (int)($_POST['id_stock'] ?? 0);
    $quantite = (int)($_POST['quantite'] ?? 0);
    $prix = !empty($_POST['prix']) ? (float)$_POST['prix'] : null;
    $seuil_alerte = (int)($_POST['seuil_alerte'] ?? 5);
    
    $errors = [];
    if ($id_stock <= 0) $errors[] = "ID de stock invalide.";
    if ($quantite < 0) $errors[] = "La quantité doit être un nombre positif.";
    
    if (empty($errors)) {
        try {
            // Récupérer l'ancienne quantité
            $old = $pdo->prepare("SELECT Quantite FROM stock WHERE Id_stock = :id");
            $old->execute([':id' => $id_stock]);
            $ancienne_quantite = $old->fetch()['Quantite'] ?? 0;
            
            // Déterminer le statut
            $statut = 'disponible';
            if ($quantite == 0) {
                $statut = 'rupture';
            } elseif ($quantite <= $seuil_alerte) {
                $statut = 'stock_faible';
            }
            
            $stmt = $pdo->prepare("
                UPDATE stock 
                SET Quantite = :quantite, Prix = :prix, Seuil_alerte = :seuil, Statut = :statut,
                    Date_mise_a_jour = NOW()
                WHERE Id_stock = :id
            ");
            $stmt->execute([
                ':quantite' => $quantite,
                ':prix' => $prix,
                ':seuil' => $seuil_alerte,
                ':statut' => $statut,
                ':id' => $id_stock
            ]);
            
            // Historique du stock
            $hist = $pdo->prepare("
                INSERT INTO historique_stock (Ancienne_quantite, Nouvelle_quantite, Type_operation, Id_stock, Id_utilisateur)
                VALUES (:ancienne, :nouvelle, 'mise_a_jour', :stock, :user)
            ");
            $hist->execute([
                ':ancienne' => $ancienne_quantite,
                ':nouvelle' => $quantite,
                ':stock' => $id_stock,
                ':user' => $_SESSION['user_id']
            ]);
            
            $message = "Stock mis à jour avec succès !";
            $message_type = "success";
            
        } catch(PDOException $e) {
            $message = "Erreur lors de la mise à jour : " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = implode('<br>', $errors);
        $message_type = "danger";
    }
}

// ===== SUPPRESSION D'UN STOCK =====
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'supprimer' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        // Vérifier si le stock a des réservations
        $check = $pdo->prepare("SELECT COUNT(*) as total FROM reservation WHERE Id_stock = :id");
        $check->execute([':id' => $id]);
        if ($check->fetch()['total'] > 0) {
            $message = "Ce stock a des réservations associées et ne peut pas être supprimé.";
            $message_type = "warning";
        } else {
            $stmt = $pdo->prepare("DELETE FROM stock WHERE Id_stock = :id");
            $stmt->execute([':id' => $id]);
            
            $log = $pdo->prepare("
                INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur)
                VALUES (CONCAT('Suppression stock ID: ', :id), :ip, :ua, 'succes', :user)
            ");
            $log->execute([
                ':id' => $id,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':user' => $_SESSION['user_id']
            ]);
            
            $message = "Stock supprimé avec succès !";
            $message_type = "danger";
        }
    } catch(PDOException $e) {
        $message = "Erreur lors de la suppression : " . $e->getMessage();
        $message_type = "danger";
    }
}

// ===== SUSPENSION / RÉACTIVATION D'UN STOCK =====
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && in_array($_GET['action'], ['suspendre', 'reactiver']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        if ($_GET['action'] === 'suspendre') {
            // Vérifier que le stock appartient bien à cette pharmacie
            $check = $pdo->prepare("
                SELECT s.Id_stock, s.Statut, s.Quantite, s.Id_medicament, m.Nom_commercial
                FROM stock s
                JOIN medicament m ON s.Id_medicament = m.Id_medicament
                WHERE s.Id_stock = :id AND s.Id_pharmacie = :pharmacie
            ");
            $check->execute([':id' => $id, ':pharmacie' => $pharmacie_id]);
            $stock = $check->fetch();

            if (!$stock) {
                $message = "Stock introuvable pour cette pharmacie.";
                $message_type = "danger";
            } elseif ($stock['Statut'] === 'desactive') {
                $message = "Ce médicament est déjà suspendu.";
                $message_type = "warning";
            } else {
                // 1. Désactiver le stock (il ne sera plus proposé aux patients)
                $stmt = $pdo->prepare("UPDATE stock SET Statut = 'desactive', Date_mise_a_jour = NOW() WHERE Id_stock = :id AND Id_pharmacie = :pharmacie");
                $stmt->execute([':id' => $id, ':pharmacie' => $pharmacie_id]);

                // 2. Annuler les réservations en cours (en attente ou confirmées)
                //    liées à ce stock et notifier les patients concernés.
                $nb_annulations = 0;
                $res = $pdo->prepare("
                    SELECT r.Id_reservation, r.Quantite, r.Id_utilisateur
                    FROM reservation r
                    WHERE r.Id_stock = :id AND r.Statut IN ('en_attente', 'confirmee')
                ");
                $res->execute([':id' => $id]);
                $reservations_a_annuler = $res->fetchAll();

                if (!empty($reservations_a_annuler)) {
                    $maj = $pdo->prepare("UPDATE reservation SET Statut = 'annulee' WHERE Id_reservation = :rid");
                    $notif = $pdo->prepare("
                        INSERT INTO notification (Titre, Message, Type, Lu, Id_utilisateur)
                        VALUES ('Réservation annulée', :message, 'annulation', 0, :user)
                    ");
                    foreach ($reservations_a_annuler as $rv) {
                        $maj->execute([':rid' => $rv['Id_reservation']]);
                        $notif->execute([
                            ':message' => 'Votre réservation pour « ' . $stock['Nom_commercial'] . ' » (x' . $rv['Quantite'] . ') '
                                        . 'a été annulée car la pharmacie a suspendu ce médicament.',
                            ':user' => $rv['Id_utilisateur']
                        ]);
                        $nb_annulations++;
                    }
                }

                // 3. Journalisation
                $log = $pdo->prepare("
                    INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur)
                    VALUES (CONCAT('Suspension stock ID: ', :id, ' (', :nb, ' réservation(s) annulée(s))'), :ip, :ua, 'succes', :user)
                ");
                $log->execute([
                    ':id' => $id,
                    ':nb' => $nb_annulations,
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    ':user' => $_SESSION['user_id']
                ]);

                $message = "Médicament « " . $stock['Nom_commercial'] . " » suspendu avec succès !";
                if ($nb_annulations > 0) {
                    $message .= " " . $nb_annulations . " réservation(s) en cours ont été annulées et les patients ont été notifiés.";
                }
                $message_type = "success";
            }
        } else {
            // Réactivation : recompute le statut selon la quantité et le seuil
            $old = $pdo->prepare("SELECT Quantite, Seuil_alerte FROM stock WHERE Id_stock = :id AND Id_pharmacie = :pharmacie");
            $old->execute([':id' => $id, ':pharmacie' => $pharmacie_id]);
            $info = $old->fetch();
            if (!$info) {
                $message = "Stock introuvable.";
                $message_type = "danger";
            } else {
                $statut = 'disponible';
                if ($info['Quantite'] == 0) {
                    $statut = 'rupture';
                } elseif ($info['Quantite'] <= $info['Seuil_alerte']) {
                    $statut = 'stock_faible';
                }
                
                $stmt = $pdo->prepare("UPDATE stock SET Statut = :statut, Date_mise_a_jour = NOW() WHERE Id_stock = :id AND Id_pharmacie = :pharmacie");
                $stmt->execute([':statut' => $statut, ':id' => $id, ':pharmacie' => $pharmacie_id]);
                
                $log = $pdo->prepare("
                    INSERT INTO log (Action, Adresse_ip, Navigateur, Resultat, Id_utilisateur)
                    VALUES (CONCAT('Réactivation stock ID: ', :id), :ip, :ua, 'succes', :user)
                ");
                $log->execute([
                    ':id' => $id,
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    ':user' => $_SESSION['user_id']
                ]);
                
                $message = "Médicament réactivé avec succès !";
                $message_type = "success";
            }
        }
    } catch(PDOException $e) {
        $message = "Erreur lors de la modification du statut : " . $e->getMessage();
        $message_type = "danger";
    }
}

// ===== REDIRECTION PRG : URL propre + message en session =====
// Évite qu'un ?action=supprimer&id=X reste dans l'URL et ré-exécute
// l'action suivante (bug du message qui ne change pas après un ajout).
if ($message !== '') {
    redirectWithMessage($message, $message_type);
}

// ===== RÉCUPÉRATION DES DONNÉES (CORRIGÉ) =====
try {
    // Récupérer tous les médicaments disponibles (pour l'ajout) - CORRECTION ICI
    $medicaments_disponibles = $pdo->prepare("
        SELECT m.*, c.Libelle as Categorie
        FROM medicament m
        LEFT JOIN categorie c ON m.Id_categorie = c.Id_categorie
        WHERE m.Statut = 'actif'
        AND NOT EXISTS (
            SELECT 1 FROM stock s 
            WHERE s.Id_medicament = m.Id_medicament 
            AND s.Id_pharmacie = :pharmacie
        )
        ORDER BY m.Nom_commercial ASC
    ");
    $medicaments_disponibles->execute([':pharmacie' => $pharmacie_id]);
    $medicaments_disponibles = $medicaments_disponibles->fetchAll();

    // Statistiques calculées sur l'INTÉGRALITÉ du stock de la pharmacie
    // (indispensable : elles doivent rester justes malgré la pagination)
    $stmt_stats = $pdo->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN Statut = 'disponible' THEN 1 ELSE 0 END) as disponibles,
               SUM(CASE WHEN Statut = 'stock_faible' THEN 1 ELSE 0 END) as stock_faible,
               SUM(CASE WHEN Statut = 'rupture' THEN 1 ELSE 0 END) as rupture
        FROM stock
        WHERE Id_pharmacie = :pharmacie
    ");
    $stmt_stats->execute([':pharmacie' => $pharmacie_id]);
    $stats = array_map(function($v) { return (int) $v; }, $stmt_stats->fetch() ?: []);

    // Nombre total (sert aussi à la pagination)
    $pagination = pagination_calculer($stats['total']);

    // Récupérer les médicaments en stock de la page courante avec leurs informations
    $medicaments_stock = $pdo->prepare("
        SELECT s.*, m.Nom_commercial, m.DCI, m.Forme, m.Dosage, m.Description,
               c.Libelle as Categorie,
               (SELECT COUNT(*) FROM reservation WHERE Id_stock = s.Id_stock AND Statut = 'en_attente') as reservations_attente
        FROM stock s
        JOIN medicament m ON s.Id_medicament = m.Id_medicament
        LEFT JOIN categorie c ON m.Id_categorie = c.Id_categorie
        WHERE s.Id_pharmacie = :pharmacie
        ORDER BY m.Nom_commercial ASC
    " . pagination_sql_limite($pagination));
    $medicaments_stock->execute([':pharmacie' => $pharmacie_id]);
    $medicaments_stock = $medicaments_stock->fetchAll();

    // Récupérer les catégories pour le filtre
    $categories = $pdo->query("SELECT * FROM categorie ORDER BY Libelle ASC")->fetchAll();

    // Valeurs distinctes existantes en base (pour l'autocomplétion)
    $liste_dci_base = array_filter(array_column($pdo->query("SELECT DISTINCT DCI FROM medicament WHERE DCI IS NOT NULL AND DCI <> ''")->fetchAll(), 'DCI'));
    $liste_formes_base = array_filter(array_column($pdo->query("SELECT DISTINCT Forme FROM medicament WHERE Forme IS NOT NULL AND Forme <> ''")->fetchAll(), 'Forme'));
    $liste_dosages_base = array_filter(array_column($pdo->query("SELECT DISTINCT Dosage FROM medicament WHERE Dosage IS NOT NULL AND Dosage <> ''")->fetchAll(), 'Dosage'));

    // ===== CATALOGUE DE RÉFÉRENCE (valeurs fréquentes) =====
    $liste_categories_ref = ['Analgésiques', 'Antibiotiques', 'Antipaludiques', 'Antihypertenseurs',
        'Anti-inflammatoires', 'Antihistaminiques', 'Antiacides', 'Antiviraux',
        'Antifongiques', 'Vitamines', 'Minéraux', 'Corticostéroïdes',
        'Antidiabétiques', 'Diurétiques', 'Antidépresseurs', 'Anticoagulants',
        'Vaccins', 'Sirops', 'Collyres', 'Dermatologiques', 'Antipyrétiques',
        'Antispasmodiques', 'Expectorants', 'Antitussifs', 'Antidiarrhéiques',
        'Laxatifs', 'Anxiolytiques', 'Anticonvulsivants', 'Antiparasitaires',
        'Antiémétiques', 'Antalgiques', 'Myorelaxants', 'Hémostatiques',
        'Compléments alimentaires', 'Hygiène et soins'];

    $liste_formes_ref = ['Comprimé', 'Comprimé pelliculé', 'Comprimé effervescent', 'Gélule', 'Capsule',
        'Sirop', 'Suspension buvable', 'Solution buvable', 'Sachet', 'Gouttes orales',
        'Collyre (gouttes oculaires)', 'Pommade', 'Crème', 'Gel', 'Lotion',
        'Suppositoire', 'Ovule', 'Injectable (IV/IM)', 'Spray nasal', 'Aérosol',
        'Inhalateur', 'Comprimé à croquer', 'Pastille', 'Teinture',
        'Solution injectable', 'Poudre pour suspension', 'Pansement médicamenteux'];

    $liste_dosages_ref = ['125 mg', '250 mg', '375 mg', '500 mg', '625 mg', '750 mg', '1000 mg', '1,5 g', '2 g',
        '2,5 mg', '5 mg', '10 mg', '20 mg', '25 mg', '40 mg', '50 mg', '75 mg',
        '100 mg', '120 mg', '150 mg', '200 mg', '300 mg', '400 mg', '500 UI',
        '1000 UI', '1 %', '2 %', '5 %', '10 %', '0,5 %', '4 mg/5 ml', '250 mg/5 ml'];

    $liste_dci_ref = ['Paracétamol', 'Ibuprofène', 'Kétoprofène', 'Diclofénac', 'Aspirine (Acide acétylsalicylique)',
        'Acide méfénamique', 'Amoxicilline', 'Amoxicilline/Acide clavulanique', 'Ampicilline',
        'Azithromycine', 'Ciprofloxacine', 'Ceftriaxone', 'Céfixime', 'Doxycycline',
        'Érythromycine', 'Métronidazole', 'Cotrimoxazole (Sulfaméthoxazole/Triméthoprime)',
        'Artéméther/Luméfantrine', 'Sulfadoxine/Pyriméthamine', 'Quinine', 'Artesunate',
        'Oméprazole', 'Esoméprazole', 'Ranitidine', 'Dompéridone', 'Métoclopramide',
        'Lopéramide', 'Phloroglucinol', 'Amlodipine', 'Losartan', 'Captopril',
        'Nifédipine', 'Hydrochlorothiazide', 'Furosémide', 'Spironolactone',
        'Metformine', 'Glibenclamide', 'Insuline', 'Simvastatine', 'Atorvastatine',
        'Loratadine', 'Cétirizine', 'Lévocétirizine', 'Dexaméthasone', 'Prednisone',
        'Bétaïne', 'Vitamine C', 'Vitamine D', 'Complexe B', 'Fer/Folate (Acide folique)',
        'Zinc', 'Calcium', 'Magnésium', 'Bétadine (Povidone iodée)', 'Chlorhexidine',
        'Hydrocortisone', 'Béthaméthasone', 'Miconazole', 'Clotrimazole', 'Fluconazole',
        'Salbutamol', 'Dextrométhorphane', 'Carbocistéine', 'Pseudoéphédrine'];

    // Liste déroulante des médicaments : catalogue fixe + ceux déjà en base
    $liste_medicaments_ref = [
        // Antalgiques / Antipyrétiques
        'Paracétamol', 'Doliprane', 'Efferalgan', 'Paracetamol Vitamine C', 'Ibuprofène', 'Advil',
        'Nurofen', 'Aspirine', 'Diclofénac', 'Voltarène', 'Kétoprofène', 'Acupan', 'Efferalgan Codeine',
        // Antibiotiques
        'Amoxicilline', 'Augmentin', 'Amoxicilline Acide Clavulanique', 'Ospamox', 'Azithromycine', 'Zithromax',
        'Ciprofloxacine', 'Ciflos', 'Ceftriaxone', 'Céfixime', 'Doxycycline', 'Érythromycine',
        'Métronidazole', 'Flagyl', 'Cotrimoxazole', 'Bactrim',
        // Antipaludiques
        'Artéméther Luméfantrine', 'Coartem', 'Artesunate', 'Quinine', 'Fansidar', 'Malarone',
        // Gastro
        'Oméprazole', 'Mopral', 'Esoméprazole', 'Inexium', 'Ranitidine', 'Dompéridone', 'Motilium',
        'Lopéramide', 'Imodium', 'Spasfon', 'Gaviscon',
        // Cardio / Métabolisme
        'Amlodipine', 'Amlor', 'Losartan', 'Cozzar', 'Captopril', 'Metformine', 'Glucophage',
        'Simvastatine', 'Atorvastatine', 'Hydrochlorothiazide', 'Furosémide', 'Lasilix',
        // Allergie
        'Loratadine', 'Clarityne', 'Cétirizine', 'Zyrtec', 'Lévocétirizine', 'Dexaméthasone',
        // Vitamines / Compléments
        'Vitamine C', 'Redoxon', 'Vitamine D', 'Zinc', 'Fer Folate', 'Complexe B', 'Supradyn',
        'Multivitamines', 'Calcium Vitamine D', 'Magnésium',
        // Antiseptiques / Dermatologiques
        'Betadine', 'Bétadine Solution', 'Alcool 70°', 'Eau Oxygénée', 'Chlorhexidine',
        'Hydrocortisone Crème', 'Miconazole', 'Daktarin', 'Clotrimazole', 'Éosine',
        // Voies respiratoires
        'Salbutamol', 'Ventoline', 'Dextrométhorphane', 'Carbocistéine', 'Pseudoéphédrine', 'Actifed',
        'Sirops antitussifs', 'Spray nasal'];

    // Mapping catalogue : nom -> [DCI, Dosage, Forme, Catégorie]
    $catalogue_mapping = [
        // Antalgiques / Antipyrétiques
        'Paracétamol' => ['Paracétamol', '500 mg', 'Comprimé', 'Antipyrétiques'],
        'Doliprane' => ['Paracétamol', '1000 mg', 'Comprimé', 'Antipyrétiques'],
        'Efferalgan' => ['Paracétamol', '500 mg', 'Comprimé effervescent', 'Antipyrétiques'],
        'Paracetamol Vitamine C' => ['Paracétamol', '500 mg', 'Comprimé effervescent', 'Antipyrétiques'],
        'Ibuprofène' => ['Ibuprofène', '200 mg', 'Comprimé', 'Anti-inflammatoires'],
        'Advil' => ['Ibuprofène', '200 mg', 'Gélule', 'Anti-inflammatoires'],
        'Nurofen' => ['Ibuprofène', '400 mg', 'Comprimé', 'Anti-inflammatoires'],
        'Aspirine' => ['Acide acétylsalicylique', '500 mg', 'Comprimé', 'Antalgiques'],
        'Diclofénac' => ['Diclofénac', '50 mg', 'Comprimé', 'Anti-inflammatoires'],
        'Voltarène' => ['Diclofénac', '75 mg', 'Injectable (IV/IM)', 'Anti-inflammatoires'],
        'Kétoprofène' => ['Kétoprofène', '100 mg', 'Gélule', 'Anti-inflammatoires'],
        'Acupan' => ['Néfopam', '20 mg', 'Injectable (IV/IM)', 'Antalgiques'],
        'Efferalgan Codeine' => ['Paracétamol/Codeine', '500/30 mg', 'Comprimé', 'Antalgiques'],
        // Antibiotiques
        'Amoxicilline' => ['Amoxicilline', '500 mg', 'Gélule', 'Antibiotiques'],
        'Augmentin' => ['Amoxicilline/Acide clavulanique', '1g/125 mg', 'Comprimé', 'Antibiotiques'],
        'Amoxicilline Acide Clavulanique' => ['Amoxicilline/Acide clavulanique', '1g/125 mg', 'Comprimé', 'Antibiotiques'],
        'Ospamox' => ['Amoxicilline', '1000 mg', 'Gélule', 'Antibiotiques'],
        'Azithromycine' => ['Azithromycine', '500 mg', 'Comprimé', 'Antibiotiques'],
        'Zithromax' => ['Azithromycine', '250 mg', 'Comprimé', 'Antibiotiques'],
        'Ciprofloxacine' => ['Ciprofloxacine', '500 mg', 'Comprimé', 'Antibiotiques'],
        'Ciflos' => ['Ciprofloxacine', '250 mg', 'Comprimé', 'Antibiotiques'],
        'Ceftriaxone' => ['Ceftriaxone', '1000 mg', 'Injectable (IV/IM)', 'Antibiotiques'],
        'Céfixime' => ['Céfixime', '200 mg', 'Comprimé', 'Antibiotiques'],
        'Doxycycline' => ['Doxycycline', '100 mg', 'Gélule', 'Antibiotiques'],
        'Érythromycine' => ['Érythromycine', '500 mg', 'Comprimé', 'Antibiotiques'],
        'Métronidazole' => ['Métronidazole', '500 mg', 'Comprimé', 'Antibiotiques'],
        'Flagyl' => ['Métronidazole', '250 mg', 'Comprimé', 'Antibiotiques'],
        'Cotrimoxazole' => ['Cotrimoxazole', '480 mg', 'Comprimé', 'Antibiotiques'],
        'Bactrim' => ['Cotrimoxazole', '480 mg', 'Comprimé', 'Antibiotiques'],
        // Antipaludiques
        'Artéméther Luméfantrine' => ['Artéméther/Luméfantrine', '20/120 mg', 'Comprimé', 'Antipaludiques'],
        'Coartem' => ['Artéméther/Luméfantrine', '20/120 mg', 'Comprimé', 'Antipaludiques'],
        'Artesunate' => ['Artesunate', '100 mg', 'Comprimé', 'Antipaludiques'],
        'Quinine' => ['Quinine', '300 mg', 'Comprimé', 'Antipaludiques'],
        'Fansidar' => ['Sulfadoxine/Pyriméthamine', '500/25 mg', 'Comprimé', 'Antipaludiques'],
        'Malarone' => ['Atovaquone/Proguanil', '250/100 mg', 'Comprimé', 'Antipaludiques'],
        // Gastro
        'Oméprazole' => ['Oméprazole', '20 mg', 'Gélule', 'Antiacides'],
        'Mopral' => ['Oméprazole', '20 mg', 'Gélule', 'Antiacides'],
        'Esoméprazole' => ['Esoméprazole', '20 mg', 'Gélule', 'Antiacides'],
        'Inexium' => ['Esoméprazole', '40 mg', 'Comprimé', 'Antiacides'],
        'Ranitidine' => ['Ranitidine', '150 mg', 'Comprimé', 'Antiacides'],
        'Dompéridone' => ['Dompéridone', '10 mg', 'Comprimé', 'Antispasmodiques'],
        'Motilium' => ['Dompéridone', '10 mg', 'Comprimé', 'Antispasmodiques'],
        'Lopéramide' => ['Lopéramide', '2 mg', 'Gélule', 'Antidiarrhéiques'],
        'Imodium' => ['Lopéramide', '2 mg', 'Gélule', 'Antidiarrhéiques'],
        'Spasfon' => ['Phloroglucinol', '80 mg', 'Comprimé', 'Antispasmodiques'],
        'Gaviscon' => ['Alginates de sodium', '500 mg', 'Comprimé à croquer', 'Antiacides'],
        // Cardio / Métabolisme
        'Amlodipine' => ['Amlodipine', '5 mg', 'Comprimé', 'Antihypertenseurs'],
        'Amlor' => ['Amlodipine', '5 mg', 'Comprimé', 'Antihypertenseurs'],
        'Losartan' => ['Losartan', '50 mg', 'Comprimé', 'Antihypertenseurs'],
        'Cozzar' => ['Losartan', '50 mg', 'Comprimé', 'Antihypertenseurs'],
        'Captopril' => ['Captopril', '25 mg', 'Comprimé', 'Antihypertenseurs'],
        'Metformine' => ['Metformine', '850 mg', 'Comprimé', 'Antidiabétiques'],
        'Glucophage' => ['Metformine', '850 mg', 'Comprimé', 'Antidiabétiques'],
        'Simvastatine' => ['Simvastatine', '20 mg', 'Comprimé', 'Cardiovasculaires'],
        'Atorvastatine' => ['Atorvastatine', '20 mg', 'Comprimé', 'Cardiovasculaires'],
        'Hydrochlorothiazide' => ['Hydrochlorothiazide', '25 mg', 'Comprimé', 'Diurétiques'],
        'Furosémide' => ['Furosémide', '40 mg', 'Comprimé', 'Diurétiques'],
        'Lasilix' => ['Furosémide', '40 mg', 'Comprimé', 'Diurétiques'],
        // Allergie
        'Loratadine' => ['Loratadine', '10 mg', 'Comprimé', 'Antihistaminiques'],
        'Clarityne' => ['Loratadine', '10 mg', 'Comprimé', 'Antihistaminiques'],
        'Cétirizine' => ['Cétirizine', '10 mg', 'Comprimé', 'Antihistaminiques'],
        'Zyrtec' => ['Cétirizine', '10 mg', 'Comprimé', 'Antihistaminiques'],
        'Lévocétirizine' => ['Lévocétirizine', '5 mg', 'Comprimé', 'Antihistaminiques'],
        'Dexaméthasone' => ['Dexaméthasone', '0,75 mg', 'Comprimé', 'Corticostéroïdes'],
        // Vitamines / Compléments
        'Vitamine C' => ['Acide ascorbique', '1000 mg', 'Comprimé effervescent', 'Vitamines'],
        'Redoxon' => ['Acide ascorbique', '1000 mg', 'Comprimé effervescent', 'Vitamines'],
        'Vitamine D' => ['Vitamine D', '1000 UI', 'Gélule', 'Vitamines'],
        'Zinc' => ['Zinc', '20 mg', 'Comprimé', 'Minéraux'],
        'Fer Folate' => ['Fer/Folate', '50 mg', 'Comprimé', 'Compléments alimentaires'],
        'Complexe B' => ['Complexe B', '', 'Comprimé', 'Vitamines'],
        'Supradyn' => ['Multivitamines', '', 'Comprimé effervescent', 'Vitamines'],
        'Multivitamines' => ['Multivitamines', '', 'Gélule', 'Vitamines'],
        'Calcium Vitamine D' => ['Calcium/Vitamine D', '', 'Comprimé', 'Minéraux'],
        'Magnésium' => ['Magnésium', '300 mg', 'Comprimé', 'Minéraux'],
        // Antiseptiques / Dermatologiques
        'Betadine' => ['Povidone iodée', '10 %', 'Solution', 'Antiseptiques'],
        'Bétadine Solution' => ['Povidone iodée', '10 %', 'Solution', 'Antiseptiques'],
        'Alcool 70°' => ['Alcool éthylique', '70 %', 'Solution', 'Antiseptiques'],
        'Eau Oxygénée' => ['Eau oxygénée', '10 %', 'Solution', 'Antiseptiques'],
        'Chlorhexidine' => ['Chlorhexidine', '0,5 %', 'Solution', 'Antiseptiques'],
        'Hydrocortisone Crème' => ['Hydrocortisone', '1 %', 'Crème', 'Dermatologiques'],
        'Miconazole' => ['Miconazole', '2 %', 'Crème', 'Antifongiques'],
        'Daktarin' => ['Miconazole', '2 %', 'Gel oral', 'Antifongiques'],
        'Clotrimazole' => ['Clotrimazole', '1 %', 'Crème', 'Antifongiques'],
        'Éosine' => ['Éosine', '2 %', 'Solution', 'Dermatologiques'],
        // Voies respiratoires
        'Salbutamol' => ['Salbutamol', '100 µg', 'Aérosol', 'Bronchodilatateurs'],
        'Ventoline' => ['Salbutamol', '100 µg', 'Aérosol', 'Bronchodilatateurs'],
        'Dextrométhorphane' => ['Dextrométhorphane', '15 mg', 'Sirop', 'Antitussifs'],
        'Carbocistéine' => ['Carbocistéine', '750 mg', 'Sirop', 'Expectorants'],
        'Pseudoéphédrine' => ['Pseudoéphédrine', '60 mg', 'Comprimé', 'Décongestionnants'],
        'Actifed' => ['Pseudoéphédrine/Triprolidine', '', 'Comprimé', 'Décongestionnants'],
        'Sirops antitussifs' => ['Dextrométhorphane', '15 mg/5 ml', 'Sirop', 'Antitussifs'],
        'Spray nasal' => ['Xylométazoline', '0,1 %', 'Spray nasal', 'Décongestionnants'],
    ];

    // Fusionner (sans doublons) les valeurs de base avec celles du catalogue
    $liste_dci = array_values(array_unique(array_merge($liste_dci_ref, $liste_dci_base)));
    $liste_formes = array_values(array_unique(array_merge($liste_formes_ref, $liste_formes_base)));
    $liste_dosages = array_values(array_unique(array_merge($liste_dosages_ref, $liste_dosages_base)));

} catch(PDOException $e) {
    $medicaments_disponibles = [];
    $medicaments_stock = [];
    $categories = [];
    $pagination = pagination_calculer(0);
    $stats = ['total' => 0, 'disponibles' => 0, 'stock_faible' => 0, 'rupture' => 0];
    $message = "Erreur lors du chargement des données : " . $e->getMessage();
    $message_type = "danger";
}

// ===== RÉCUPÉRATION D'UN STOCK POUR MODIFICATION =====
$edit_stock = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("
        SELECT s.*, m.Nom_commercial, m.DCI, m.Forme, m.Dosage
        FROM stock s
        JOIN medicament m ON s.Id_medicament = m.Id_medicament
        WHERE s.Id_stock = :id AND s.Id_pharmacie = :pharmacie
    ");
    $stmt->execute([':id' => $id, ':pharmacie' => $pharmacie_id]);
    $edit_stock = $stmt->fetch();
}

$page_title = 'Mes médicaments - PharmaGeo';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        /* ===== VARIABLES ===== */
        :root {
            --primary-green: #16A34A;
            --primary-orange: #166534;
            --white: #ffffff;
            --light-bg: #F0FDF4;
            --dark-text: #1F2937;
            --gray-text: #64748B;
            --shadow-sm: 0 2px 20px rgba(0,0,0,0.04);
            --shadow-md: 0 8px 40px rgba(0,0,0,0.08);
            --shadow-lg: 0 20px 60px rgba(0,0,0,0.12);
            --shadow-glow-green: 0 8px 40px rgba(22,163,74, 0.20);
            --shadow-glow-orange: 0 8px 40px rgba(21,128,61, 0.20);
            --radius: 16px;
            --radius-lg: 24px;
            --transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        /* ===== BASE ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--light-bg);
            color: var(--dark-text);
            overflow-x: hidden;
        }

        /* ===== SCROLLBAR ===== */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: var(--light-bg); }
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            border-radius: 10px;
        }

        /* ===== ANIMATIONS ===== */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-30px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .animate-fade-up { animation: fadeInUp 0.6s ease-out forwards; }
        .animate-slide-left { animation: slideInLeft 0.5s ease-out forwards; }
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }

        /* ===== NAVBAR ===== */
        .navbar-custom {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 2px 30px rgba(0,0,0,0.06);
            padding: 12px 0;
            transition: var(--transition);
            border-bottom: 1px solid rgba(0,0,0,0.02);
        }

        .navbar-custom.scrolled {
            box-shadow: 0 4px 40px rgba(0,0,0,0.1);
            padding: 8px 0;
        }

        .navbar-brand-custom {
            font-weight: 800;
            font-size: 1.6rem;
            color: var(--dark-text);
            letter-spacing: -0.5px;
        }

        .navbar-brand-custom .brand-icon {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            padding: 8px 12px;
            border-radius: 12px;
            color: white;
            margin-right: 10px;
            box-shadow: var(--shadow-glow-green);
        }

        .navbar-brand-custom .brand-text {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .nav-link-custom {
            font-weight: 500;
            color: var(--dark-text);
            padding: 8px 18px !important;
            border-radius: 12px;
            transition: var(--transition);
            position: relative;
        }

        .nav-link-custom:hover,
        .nav-link-custom.active {
            color: var(--primary-green);
            background: rgba(22,163,74,0.08);
        }

        .nav-link-custom::after {
            content: '';
            position: absolute;
            bottom: 2px;
            left: 50%;
            width: 0;
            height: 3px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            border-radius: 10px;
            transition: var(--transition);
            transform: translateX(-50%);
        }

        .nav-link-custom:hover::after,
        .nav-link-custom.active::after {
            width: 60%;
        }

        .btn-nav-logout {
            background: linear-gradient(135deg, #DC2626, #B91C1C);
            color: white !important;
            padding: 8px 24px !important;
            border-radius: 50px !important;
            font-weight: 600;
            transition: var(--transition);
            border: none;
            box-shadow: 0 4px 15px rgba(220,38,38,0.2);
        }

        .btn-nav-logout:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(220,38,38,0.35);
            color: white !important;
        }

        /* ===== LAYOUT / SIDEBAR ===== */
        .layout { display: flex; min-height: 100vh; }
        .sidebar-admin {
            width: 250px; background: linear-gradient(180deg, #0F2E19, #14532D);
            color: #fff; flex-shrink: 0; display: flex; flex-direction: column;
            position: fixed; top: 0; bottom: 0; left: 0; overflow-y: auto; z-index: 100;
        }
        .brand-admin { padding: 22px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .brand-admin .brand-icon { width: 40px; height: 40px; border-radius: 12px; background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); display: flex; align-items: center; justify-content: center;  color: #fff;}
        .brand-admin h4 { margin: 0; font-weight: 800; font-size: 1.1rem; color: #fff; 
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            color: #fff;
        }
        
        .brand-admin small { color: rgba(255,255,255,0.6); display: block; }
        .nav-menu { padding: 16px 12px; flex: 1; }
        .nav-label { color: rgba(255,255,255,0.4); font-size: 0.72rem; text-transform: uppercase; letter-spacing: 1px; padding: 12px 12px 6px; }
        .nav-item { display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.75); text-decoration: none; padding: 11px 14px; border-radius: 10px; margin-bottom: 2px; font-size: 0.92rem; transition: all 0.25s ease; }
        .nav-item i { width: 20px; text-align: center; }
        .nav-item:hover { background: rgba(255,255,255,0.08); color: #fff; }
        .nav-item.active { background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); color: #fff; font-weight: 600; }
        .user-profile { padding: 16px; border-top: 1px solid rgba(255,255,255,0.08); display: flex; align-items: center; gap: 12px; }
        .user-profile .avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-green), var(--primary-orange)); display: flex; align-items: center; justify-content: center; font-weight: 800; }
        .user-profile .name { font-size: 0.9rem; font-weight: 600; }
        .user-profile .role { font-size: 0.75rem; color: rgba(255,255,255,0.6); }
        a.user-profile { text-decoration: none; color: inherit; transition: background-color 0.2s ease; }
        a.user-profile:hover { background-color: rgba(255,255,255,0.07); }
        a.user-profile .profile-chevron { margin-left: auto; font-size: 0.7rem; color: rgba(255,255,255,0.45); }
        a.user-profile:hover .profile-chevron { color: #fff; }
        @media (max-width: 991px) {
            .sidebar-admin { position: fixed; transform: translateX(-100%); transition: transform 0.3s ease; }
            .sidebar-admin.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
        }

        /* ===== MAIN CONTENT ===== */
        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 24px 30px 30px;
            min-height: 100vh;
        }

        /* ===== TOP BAR ===== */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .top-bar .page-title h1 {
            font-size: 1.6rem;
            font-weight: 800;
            margin: 0;
        }

        .top-bar .page-title h1 .highlight {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .top-bar .page-title p {
            color: var(--gray-text);
            margin: 0;
            font-size: 0.9rem;
        }

        .top-bar .page-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .top-bar .page-actions .btn-action {
            padding: 10px 20px;
            border-radius: 12px;
            border: none;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            text-decoration: none;
        }

        .top-bar .page-actions .btn-action.btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white;
            box-shadow: var(--shadow-glow-green);
        }

        .top-bar .page-actions .btn-action.btn-primary-custom:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 40px rgba(22,163,74,0.35);
        }

        .top-bar .page-actions .btn-action.btn-outline-custom {
            border: 2px solid var(--primary-green);
            color: var(--primary-green);
            background: transparent;
        }

        .top-bar .page-actions .btn-action.btn-outline-custom:hover {
            background: var(--primary-green);
            color: white;
            transform: translateY(-3px);
        }

        /* ===== STATS CARDS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 18px 20px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            border: 1px solid rgba(0,0,0,0.02);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            opacity: 0;
            transition: var(--transition);
        }

        .stat-card:hover::before { opacity: 1; }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
        }

        .stat-card .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .stat-card .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .stat-card .stat-icon.green {
            background: rgba(22,163,74,0.1);
            color: var(--primary-green);
        }

        .stat-card .stat-icon.orange {
            background: rgba(21,128,61,0.1);
            color: var(--primary-orange);
        }

        .stat-card .stat-icon.blue {
            background: rgba(22,83,52,0.1);
            color: #166534;
        }

        .stat-card .stat-icon.red {
            background: rgba(220,38,38,0.1);
            color: #DC2626;
        }

        .stat-card .stat-number {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--dark-text);
            line-height: 1.2;
        }

        .stat-card .stat-label {
            color: var(--gray-text);
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* ===== TABLE ===== */
        .table-container {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 20px;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            border: 1px solid rgba(0,0,0,0.02);
        }

        .table-custom {
            margin: 0;
        }

        .table-custom thead th {
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-text);
            border-bottom: 2px solid #F0FDF4;
            padding: 12px 10px;
        }

        .table-custom tbody td {
            padding: 14px 10px;
            vertical-align: middle;
            border-bottom: 1px solid #F0FDF4;
            font-size: 0.9rem;
        }

        .table-custom tbody tr {
            transition: var(--transition);
        }

        .table-custom tbody tr:hover {
            background: rgba(22,163,74,0.02);
        }

        .table-custom tbody tr:last-child td {
            border-bottom: none;
        }

        /* ===== BADGES ===== */
        .badge-status {
            padding: 4px 14px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-status.disponible {
            background: rgba(22,163,74,0.1);
            color: var(--primary-green);
        }

        .badge-status.stock_faible {
            background: rgba(21,128,61,0.1);
            color: var(--primary-orange);
        }

        .badge-status.rupture {
            background: rgba(220,38,38,0.1);
            color: #DC2626;
        }

        .badge-status.en_approvisionnement {
            background: rgba(22,83,52,0.1);
            color: #166534;
        }

        .badge-status.desactive {
            background: rgba(108,117,125,0.1);
            color: #64748B;
        }

        /* ===== BTN ACTION ===== */
        .btn-action-sm {
            padding: 6px 12px;
            border-radius: 8px;
            border: none;
            font-size: 0.75rem;
            font-weight: 600;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-action-sm.btn-edit {
            background: rgba(21,128,61,0.1);
            color: var(--primary-orange);
        }

        .btn-action-sm.btn-edit:hover {
            background: var(--primary-orange);
            color: white;
        }

        .btn-action-sm.btn-delete {
            background: rgba(220,38,38,0.1);
            color: #DC2626;
        }

        .btn-action-sm.btn-delete:hover {
            background: #DC2626;
            color: white;
        }

        .btn-action-sm.btn-view {
            background: rgba(22,83,52,0.1);
            color: #166534;
        }

        .btn-action-sm.btn-view:hover {
            background: #166534;
            color: white;
        }

        .btn-action-sm.btn-suspend {
            background: rgba(200,150,0,0.12);
            color: #B91C1C;
        }

        .btn-action-sm.btn-suspend:hover {
            background: #B91C1C;
            color: white;
        }

        /* ===== ALERT ===== */
        .alert-custom {
            border-radius: 14px;
            padding: 14px 18px;
            border: none;
            font-weight: 500;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 20px;
        }

        .alert-custom i { font-size: 1.2rem; margin-top: 2px; }

        .alert-custom-success {
            background: rgba(22,163,74,0.08);
            color: var(--primary-green);
            border-left: 4px solid var(--primary-green);
        }

        .alert-custom-danger {
            background: rgba(220,38,38,0.08);
            color: #DC2626;
            border-left: 4px solid #DC2626;
        }

        .alert-custom-warning {
            background: rgba(21,128,61,0.08);
            color: var(--primary-orange);
            border-left: 4px solid var(--primary-orange);
        }

        /* ===== MODAL ===== */
        .modal-content-custom {
            border-radius: var(--radius-lg);
            border: none;
            box-shadow: 0 30px 80px rgba(0,0,0,0.2);
        }

        .modal-header-custom {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            color: white;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            padding: 20px 24px;
        }

        .modal-header-custom .btn-close {
            filter: brightness(0) invert(1);
        }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 16px;
        }

        .empty-state h5 {
            font-weight: 700;
            color: var(--dark-text);
        }

        .empty-state p {
            color: var(--gray-text);
            max-width: 400px;
            margin: 0 auto;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .main-content { padding: 15px; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .top-bar { flex-direction: column; align-items: stretch; }
            .top-bar .page-actions { flex-wrap: wrap; }
            .top-bar .page-actions .btn-action { font-size: 0.8rem; padding: 8px 16px; }
            .table-container { padding: 12px; overflow-x: auto; }
            .table-custom { font-size: 0.8rem; min-width: 700px; }
            .table-custom thead th,
            .table-custom tbody td { padding: 8px 6px; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 10px; }
            .top-bar .page-title h1 { font-size: 1.2rem; }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card { padding: 12px; }
            .stat-card .stat-number { font-size: 1.3rem; }
            .stat-card .stat-label { font-size: 0.7rem; }
            .stat-card .stat-icon { width: 32px; height: 32px; font-size: 0.9rem; }
            .navbar-brand-custom { font-size: 1.3rem; }
        }

        /* ===== FORM ===== */
        .form-modern .form-group { margin-bottom: 18px; }

        .form-modern .form-label {
            font-weight: 600;
            color: var(--dark-text);
            font-size: 0.9rem;
            margin-bottom: 6px;
        }

        .form-modern .form-label .required {
            color: #DC2626;
            margin-left: 2px;
        }

        .form-modern .input-group-custom {
            position: relative;
        }

        .form-modern .input-group-custom .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-text);
            font-size: 1rem;
            transition: var(--transition);
            z-index: 3;
        }

        .form-modern .input-group-custom .form-control,
        .form-modern .input-group-custom .form-select {
            padding: 12px 16px 12px 44px;
            border: 2px solid #D9E9DF;
            border-radius: 14px;
            font-size: 0.95rem;
            transition: var(--transition);
            background: #F0FDF4;
            color: var(--dark-text);
        }

        .form-modern .input-group-custom .form-control:focus,
        .form-modern .input-group-custom .form-select:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 4px rgba(22,163,74,0.1);
            background: var(--white);
        }
    </style>
    <link rel="stylesheet" href="../assets/css/responsive.css">    <!-- Palette verte PharmaGeo -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/palette.css">
    <!-- Favicon & identité visuelle PharmaGéo -->
    <link rel="icon" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon.ico" sizes="any">
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo BASE_URL; ?>assets/identite/favicons/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo BASE_URL; ?>assets/identite/favicons/apple-touch-icon.png">
    <link rel="manifest" href="<?php echo BASE_URL; ?>assets/identite/favicons/site.webmanifest">
    <meta property="og:image" content="<?php echo BASE_URL; ?>assets/identite/reseaux-sociaux/og-image-1200x630.png">
</head>
<body>

    <!-- ===== NAVIGATION ===== -->
    <div class="layout">
    <aside class="sidebar-admin" id="sidebar">
        <a href="<?php echo BASE_URL; ?>index.php" class="brand-admin" style="text-decoration: none;">
            <div class="brand-icon"><img class="pg-brand-logo" src="<?php echo BASE_URL; ?>assets/identite/icones/pharmageo-icone-blanc.svg" alt=""></div>
            <div><h4>PharmaGeo</h4><small><?php echo htmlspecialchars(pharma_libelle_role('pharmacien', $user['Prenom'] ?? '')); ?></small></div>
        </a>
        <nav class="nav-menu">
            <div class="nav-label">Navigation</div>
            <a href="<?php echo BASE_URL; ?>pharmacien/dashboard.php" class="nav-item"><i class="fas fa-tachometer-alt"></i><span>Tableau de bord</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/pharmacie.php" class="nav-item"><i class="fas fa-store"></i><span>Ma pharmacie</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/medicaments.php" class="nav-item active"><i class="fas fa-capsules"></i><span>Médicaments</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/stocks.php" class="nav-item"><i class="fas fa-boxes"></i><span>Stocks</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/historique_stocks.php" class="nav-item"><i class="fas fa-history"></i><span>Historique</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/reservations.php" class="nav-item"><i class="fas fa-calendar-check"></i><span>Réservations</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/statistiques.php" class="nav-item"><i class="fas fa-chart-bar"></i><span>Statistiques</span></a>
            <a href="<?php echo BASE_URL; ?>pharmacien/profil.php" class="nav-item"><i class="fas fa-user"></i><span>Profil</span></a>
            <div class="nav-label" style="margin-top: 20px;">Système</div>
            <?php require_once __DIR__ . '/../includes/reinitialiser.php'; echo html_bloc_reinitialiser(); ?>
            <a href="<?php echo BASE_URL; ?>deconnexion.php" class="nav-item" style="color: #FEF2F2; margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.15); margin-bottom: 12px;"><i class="fas fa-sign-out-alt"></i><span>Déconnexion</span></a>
        </nav>
        <a class="user-profile" href="<?php echo BASE_URL; ?>pharmacien/profil.php" title="Voir mon profil">
            <div class="avatar"><?php if (!empty($_SESSION['photo']) && function_exists('pharma_image_disponible') && pharma_image_disponible($_SESSION['photo'])): ?><img src="<?php echo htmlspecialchars(pharma_image_url($_SESSION['photo'])); ?>" alt=""><?php else: ?><?php echo strtoupper(substr($_SESSION['prenom'] ?? 'A', 0, 1) . substr($_SESSION['nom'] ?? 'D', 0, 1)); ?><?php endif; ?></div>
            <div>
                <div class="name"><?php echo htmlspecialchars(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')); ?></div>
                <div class="role"><i class="fas fa-user-md me-1" style="color:#DC2626;"></i> <?php echo htmlspecialchars(pharma_libelle_role('pharmacien', $user['Prenom'] ?? '')); ?></div>
            </div>
            <i class="fas fa-chevron-right profile-chevron"></i>
        </a>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">

        <!-- ===== TOP BAR ===== -->
        <div class="top-bar animate-fade-up">
            <div class="page-title">
                <h1>Mes <span class="highlight">médicaments</span></h1>
                <p>
                    <i class="fas fa-capsules me-1" style="color: var(--primary-green);"></i>
                    Gérez les médicaments de votre pharmacie
                </p>
            </div>
            <div class="page-actions">
                <button class="btn-action btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="fas fa-plus"></i> Ajouter un médicament
                </button>
                <a href="<?php echo BASE_URL; ?>pharmacien/stocks.php" class="btn-action btn-outline-custom">
                    <i class="fas fa-boxes"></i> Gérer les stocks
                </a>
            </div>
        </div>

        <!-- ===== STATS ===== -->
        <div class="stats-grid animate-fade-up delay-1">
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">Total médicaments</div>
                    </div>
                    <div class="stat-icon blue"><i class="fas fa-capsules"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['disponibles']; ?></div>
                        <div class="stat-label">Disponibles</div>
                    </div>
                    <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['stock_faible']; ?></div>
                        <div class="stat-label">Stock faible</div>
                    </div>
                    <div class="stat-icon orange"><i class="fas fa-exclamation-triangle"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo $stats['rupture']; ?></div>
                        <div class="stat-label">En rupture</div>
                    </div>
                    <div class="stat-icon red"><i class="fas fa-times-circle"></i></div>
                </div>
            </div>
        </div>

        <!-- ===== MESSAGES ===== -->
        <?php
        $flash = $_SESSION['flash_message'] ?? null;
        if ($flash !== null) unset($_SESSION['flash_message']);
        $alerte = $flash ?? (($message !== '') ? ['type' => $message_type, 'text' => $message] : null);
        ?>
        <?php if ($alerte !== null): ?>
            <div class="alert-custom alert-custom-<?php echo $alerte['type']; ?> animate-fade-up">
                <i class="fas fa-<?php echo $alerte['type'] === 'success' ? 'check-circle' : ($alerte['type'] === 'warning' ? 'exclamation-triangle' : 'exclamation-circle'); ?>"></i>
                <div><?php echo $alerte['text']; ?></div>
            </div>
        <?php endif; ?>

        <!-- ===== TABLEAU ===== -->
        <div class="table-container animate-fade-up delay-2">
            <?php if (!empty($medicaments_stock)): ?>
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead>
                            <tr>
                                <th>Médicament</th>
                                <th>DCI</th>
                                <th>Quantité</th>
                                <th>Prix</th>
                                <th>Statut</th>
                                <th>Réservations</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($medicaments_stock as $med): ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($med['Nom_commercial']); ?></strong>
                                            <?php if ($med['Dosage']): ?>
                                                <small class="text-muted d-block"><?php echo htmlspecialchars($med['Dosage']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($med['DCI'] ?? '-'); ?></td>
                                    <td>
                                        <span class="fw-bold <?php echo $med['Quantite'] <= $med['Seuil_alerte'] && $med['Quantite'] > 0 ? 'text-warning' : ($med['Quantite'] == 0 ? 'text-danger' : ''); ?>">
                                            <?php echo $med['Quantite']; ?>
                                        </span>
                                        <small class="text-muted d-block">Seuil: <?php echo $med['Seuil_alerte']; ?></small>
                                    </td>
                                    <td>
                                        <?php echo $med['Prix'] ? number_format($med['Prix'], 0, ',', ' ') . ' FCFA' : '-'; ?>
                                    </td>
                                    <td>
                                        <span class="badge-status <?php echo $med['Statut']; ?>">
                                            <?php 
                                                $statuts = [
                                                    'disponible' => 'Disponible',
                                                    'stock_faible' => 'Stock faible',
                                                    'rupture' => 'Rupture',
                                                    'en_approvisionnement' => 'En approvisionnement',
                                                    'desactive' => 'Désactivé'
                                                ];
                                                echo $statuts[$med['Statut']] ?? $med['Statut'];
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($med['reservations_attente'] > 0): ?>
                                            <span class="badge bg-warning text-dark">
                                                <i class="fas fa-clock me-1"></i>
                                                <?php echo $med['reservations_attente']; ?> en attente
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex gap-1 justify-content-end flex-wrap">
                                            <a href="<?php echo BASE_URL; ?>pharmacien/medicaments.php?action=edit&id=<?php echo $med['Id_stock']; ?>" 
                                               class="btn-action-sm btn-edit" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $med['Id_stock']; ?>"
                                               title="Modifier le stock">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php if ($med['Statut'] === 'desactive'): ?>
                                                <a href="<?php echo BASE_URL; ?>pharmacien/medicaments.php?action=reactiver&id=<?php echo $med['Id_stock']; ?>" 
                                                   class="btn-action-sm btn-view"
                                                   onclick="return confirmReactivate(<?php echo $med['Id_stock']; ?>, '<?php echo addslashes($med['Nom_commercial']); ?>')"
                                                   title="Réactiver ce médicament (le rendre visible pour les patients)">
                                                    <i class="fas fa-play"></i>
                                                </a>
                                            <?php else: ?>
                                                <a href="<?php echo BASE_URL; ?>pharmacien/medicaments.php?action=suspendre&id=<?php echo $med['Id_stock']; ?>" 
                                                   class="btn-action-sm btn-suspend"
                                                   onclick="return confirmSuspend(<?php echo $med['Id_stock']; ?>, '<?php echo addslashes($med['Nom_commercial']); ?>')"
                                                   title="Suspendre ce médicament (le masquer pour les patients de cette pharmacie)">
                                                    <i class="fas fa-pause"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="<?php echo BASE_URL; ?>pharmacien/medicaments.php?action=supprimer&id=<?php echo $med['Id_stock']; ?>" 
                                               class="btn-action-sm btn-delete"
                                               onclick="return confirmDelete(<?php echo $med['Id_stock']; ?>, '<?php echo addslashes($med['Nom_commercial']); ?>', <?php echo $med['reservations_attente']; ?>)"
                                               title="Supprimer du stock">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ===== PAGINATION ===== -->
                <?php pagination_afficher($pagination, [], 'médicaments en stock'); ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-capsules"></i>
                    <h5>Aucun médicament en stock</h5>
                    <p>Commencez par ajouter des médicaments à votre pharmacie.</p>
                    <button class="btn btn-primary-custom mt-3" data-bs-toggle="modal" data-bs-target="#addModal" style="padding: 10px 28px; border-radius: 50px; background: linear-gradient(135deg, #16A34A, #166534); color: white; border: none; font-weight: 600; transition: var(--transition);">
                        <i class="fas fa-plus me-1"></i> Ajouter un médicament
                    </button>
                </div>
            <?php endif; ?>
        </div>

    </main>

    </div>

    <!-- ===== MODAL AJOUT ===== -->
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content modal-content-custom">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-plus me-2"></i>Ajouter un médicament au stock
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" class="p-4 form-modern">
                    <input type="hidden" name="action" value="ajouter">
                    
                    <div class="form-group">
                        <label for="medicament_select" class="form-label">Médicament <span class="required">*</span></label>
                        <div class="input-group-custom">
                            <span class="input-icon"><i class="fas fa-capsules"></i></span>
                            <input type="text" class="form-control" id="medicament_select"
                                   name="nouveau_medicament" list="liste_med"
                                   placeholder="Tapez ou sélectionnez un médicament..." autocomplete="off">
                            <datalist id="liste_med">
                                <?php foreach ($medicaments_disponibles as $med): ?>
                                    <option value="<?php echo htmlspecialchars($med['Nom_commercial']); ?>">
                                <?php endforeach; ?>
                                <?php
                                $noms_en_base = array_column($medicaments_disponibles, 'Nom_commercial');
                                foreach ($liste_medicaments_ref as $nom_ref):
                                    if (in_array($nom_ref, $noms_en_base)) continue;
                                ?>
                                    <option value="<?php echo htmlspecialchars($nom_ref); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <input type="hidden" id="id_medicament" name="id_medicament" value="0">
                        <small class="text-muted">
                            <i class="fas fa-info-circle"></i> Tapez le nom du médicament ou choisissez dans la liste.
                            Les champs DCI, dosage, forme et catégorie se rempliront automatiquement si le médicament est reconnu.
                        </small>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-6 form-group">
                            <label class="form-label">DCI (Dénomination Commune Internationale)</label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-flask"></i></span>
                                <input type="text" class="form-control" id="nouveau_medicament_dci"
                                       name="nouveau_medicament_dci" list="liste_dci"
                                       placeholder="Saisissez ou sélectionnez..." autocomplete="off">
                                <datalist id="liste_dci">
                                    <?php foreach ($liste_dci as $dci): ?>
                                        <option value="<?php echo htmlspecialchars($dci); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="form-label">Dosage</label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-percent"></i></span>
                                <input type="text" class="form-control" id="nouveau_medicament_dosage"
                                       name="nouveau_medicament_dosage" list="liste_dosages"
                                       placeholder="Saisissez ou sélectionnez..." autocomplete="off">
                                <datalist id="liste_dosages">
                                    <?php foreach ($liste_dosages as $dos): ?>
                                        <option value="<?php echo htmlspecialchars($dos); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="form-label">Forme</label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-pills"></i></span>
                                <input type="text" class="form-control" id="nouveau_medicament_forme"
                                       name="nouveau_medicament_forme" list="liste_formes"
                                       placeholder="Saisissez ou sélectionnez..." autocomplete="off">
                                <datalist id="liste_formes">
                                    <?php foreach ($liste_formes as $form): ?>
                                        <option value="<?php echo htmlspecialchars($form); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="form-label">Catégorie</label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-tags"></i></span>
                                <input type="text" class="form-control" id="nouveau_medicament_categorie"
                                       name="nouveau_medicament_categorie" list="liste_categories"
                                       placeholder="Saisissez ou sélectionnez..." autocomplete="off">
                                <datalist id="liste_categories">
                                    <?php
                                    $liste_cat_options = array_values(array_unique(array_merge(
                                        $liste_categories_ref,
                                        array_column($categories, 'Libelle')
                                    )));
                                    foreach ($liste_cat_options as $cat):
                                    ?>
                                        <option value="<?php echo htmlspecialchars($cat); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                        </div>
                    </div>
                    <?php if (empty($medicaments_disponibles)): ?>
                        <small class="text-warning mt-2 d-block">
                            <i class="fas fa-info-circle"></i> Tous les médicaments en base sont déjà dans votre stock.
                            Tapez le nom d'un médicament pour en créer un nouveau.
                        </small>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-md-6 form-group">
                            <label for="quantite" class="form-label">Quantité <span class="required">*</span></label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-box"></i></span>
                                <input type="number" class="form-control" id="quantite" name="quantite" 
                                       placeholder="0" min="0" value="0" required>
                            </div>
                        </div>
                        <div class="col-md-6 form-group">
                            <label for="prix" class="form-label">Prix (FCFA)</label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-money-bill-wave"></i></span>
                                <input type="number" class="form-control" id="prix" name="prix" 
                                       placeholder="0" min="0" step="100">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="seuil_alerte" class="form-label">Seuil d'alerte</label>
                        <div class="input-group-custom">
                            <span class="input-icon"><i class="fas fa-bell"></i></span>
                            <input type="number" class="form-control" id="seuil_alerte" name="seuil_alerte" 
                                   placeholder="5" min="1" value="5">
                        </div>
                        <small class="text-muted">Vous serez alerté lorsque le stock atteindra ce seuil.</small>
                    </div>

                    <div class="mt-4 d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 50px; padding: 10px 28px;">Annuler</button>
                        <button type="submit" class="btn btn-primary-custom" style="border-radius: 50px; padding: 10px 28px;">
                            <i class="fas fa-save me-1"></i> Ajouter au stock
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ===== MODALS ÉDITION ===== -->
    <?php foreach ($medicaments_stock as $med): ?>
    <div class="modal fade" id="editModal<?php echo $med['Id_stock']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content modal-content-custom">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-edit me-2"></i>Modifier le stock
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" class="p-4 form-modern">
                    <input type="hidden" name="action" value="modifier">
                    <input type="hidden" name="id_stock" value="<?php echo $med['Id_stock']; ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Médicament</label>
                        <div class="input-group-custom">
                            <span class="input-icon"><i class="fas fa-capsules"></i></span>
                            <input type="text" class="form-control" 
                                   value="<?php echo htmlspecialchars($med['Nom_commercial']); ?>" disabled>
                        </div>
                        <small class="text-muted"><?php echo htmlspecialchars($med['DCI'] ?? ''); ?> - <?php echo htmlspecialchars($med['Dosage'] ?? ''); ?></small>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6 form-group">
                            <label for="quantite_<?php echo $med['Id_stock']; ?>" class="form-label">Quantité <span class="required">*</span></label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-box"></i></span>
                                <input type="number" class="form-control" id="quantite_<?php echo $med['Id_stock']; ?>" 
                                       name="quantite" value="<?php echo $med['Quantite']; ?>" min="0" required>
                            </div>
                        </div>
                        <div class="col-md-6 form-group">
                            <label for="prix_<?php echo $med['Id_stock']; ?>" class="form-label">Prix (FCFA)</label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fas fa-money-bill-wave"></i></span>
                                <input type="number" class="form-control" id="prix_<?php echo $med['Id_stock']; ?>" 
                                       name="prix" value="<?php echo $med['Prix'] ?? ''; ?>" min="0" step="100">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="seuil_alerte_<?php echo $med['Id_stock']; ?>" class="form-label">Seuil d'alerte</label>
                        <div class="input-group-custom">
                            <span class="input-icon"><i class="fas fa-bell"></i></span>
                            <input type="number" class="form-control" id="seuil_alerte_<?php echo $med['Id_stock']; ?>" 
                                   name="seuil_alerte" value="<?php echo $med['Seuil_alerte']; ?>" min="1">
                        </div>
                    </div>

                    <div class="mt-4 d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 50px; padding: 10px 28px;">Annuler</button>
                        <button type="submit" class="btn btn-primary-custom" style="border-radius: 50px; padding: 10px 28px;">
                            <i class="fas fa-save me-1"></i> Mettre à jour
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- ===== SCRIPTS ===== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {

            // ===== SIDEBAR MOBILE TOGGLE (Ctrl + B) =====
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.key.toLowerCase() === 'b') {
                    document.getElementById('sidebar').classList.toggle('open');
                }
            });

            // ===== CONFIRMATION SUPPRESSION =====
            window.confirmDelete = function(id, nom, reservations) {
                event.preventDefault();
                let message = `Voulez-vous vraiment supprimer le médicament "${nom}" du stock ?`;
                if (reservations > 0) {
                    message = `⚠️ Ce médicament a ${reservations} réservation(s) en attente. La suppression est impossible.`;
                    Swal.fire({
                        title: '⚠️ Suppression impossible',
                        text: message,
                        icon: 'warning',
                        confirmButtonColor: '#64748B',
                        confirmButtonText: 'OK'
                    });
                    return false;
                }
                
                Swal.fire({
                    title: '⚠️ Supprimer du stock',
                    text: message + ' Cette action est irréversible.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#DC2626',
                    cancelButtonColor: '#64748B',
                    confirmButtonText: 'Oui, supprimer',
                    cancelButtonText: 'Annuler'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = `<?php echo BASE_URL; ?>pharmacien/medicaments.php?action=supprimer&id=${id}`;
                    }
                });
                return false;
            };

            // ===== CONFIRMATION SUSPENSION / RÉACTIVATION =====
            window.confirmSuspend = function(id, nom) {
                event.preventDefault();
                Swal.fire({
                    title: '🛑 Suspendre le médicament',
                    text: `Voulez-vous vraiment suspendre "${nom}" ? Il ne sera plus disponible pour les patients de cette pharmacie.`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#B91C1C',
                    cancelButtonColor: '#64748B',
                    confirmButtonText: 'Oui, suspendre',
                    cancelButtonText: 'Annuler'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = `<?php echo BASE_URL; ?>pharmacien/medicaments.php?action=suspendre&id=${id}`;
                    }
                });
                return false;
            };

            window.confirmReactivate = function(id, nom) {
                event.preventDefault();
                Swal.fire({
                    title: 'Réactiver le médicament',
                    text: `Voulez-vous réactiver "${nom}" ? Il redeviendra disponible pour les patients de cette pharmacie.`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#166534',
                    cancelButtonColor: '#64748B',
                    confirmButtonText: 'Oui, réactiver',
                    cancelButtonText: 'Annuler'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = `<?php echo BASE_URL; ?>pharmacien/medicaments.php?action=reactiver&id=${id}`;
                    }
                });
                return false;
            };

            // ===== GESTION DU CHAMP MÉDICAMENT (input + datalist) =====
            const medInput = document.getElementById('medicament_select');
            const medId = document.getElementById('id_medicament');

            // Mapping JS : nom -> {id, dci, dosage, forme, categorie}
            const medicamentsData = {};

            <?php foreach ($medicaments_disponibles as $med): ?>
                medicamentsData[<?php echo json_encode($med['Nom_commercial']); ?>] = {
                    id: <?php echo (int)$med['Id_medicament']; ?>,
                    dci: <?php echo json_encode($med['DCI'] ?? ''); ?>,
                    dosage: <?php echo json_encode($med['Dosage'] ?? ''); ?>,
                    forme: <?php echo json_encode($med['Forme'] ?? ''); ?>,
                    categorie: <?php echo json_encode($med['Categorie'] ?? ''); ?>
                };
            <?php endforeach; ?>

            <?php foreach ($catalogue_mapping as $nom => $props): ?>
                medicamentsData[<?php echo json_encode($nom); ?>] = {
                    id: 0,
                    dci: <?php echo json_encode($props[0]); ?>,
                    dosage: <?php echo json_encode($props[1]); ?>,
                    forme: <?php echo json_encode($props[2]); ?>,
                    categorie: <?php echo json_encode($props[3]); ?>
                };
            <?php endforeach; ?>

            function majMedicament() {
                if (!medInput || !medId) return;
                const nom = medInput.value.trim();
                const data = medicamentsData[nom];

                if (data) {
                    medId.value = data.id;
                    setChamp('nouveau_medicament_dci', data.dci);
                    setChamp('nouveau_medicament_dosage', data.dosage);
                    setChamp('nouveau_medicament_forme', data.forme);
                    setChamp('nouveau_medicament_categorie', data.categorie);
                } else {
                    medId.value = '0';
                    setChamp('nouveau_medicament_dci', '');
                    setChamp('nouveau_medicament_dosage', '');
                    setChamp('nouveau_medicament_forme', '');
                    setChamp('nouveau_medicament_categorie', '');
                }
            }

            function setChamp(id, valeur) {
                const el = document.getElementById(id);
                if (el) el.value = valeur || '';
            }

            if (medInput) {
                medInput.addEventListener('input', majMedicament);
            }

            // Entrée dans un champ de saisie = sélection, jamais envoi au stock
            function selectionnerMedicament() {
                if (!medInput) return;
                let nom = medInput.value.trim();
                if (nom === '') return;

                if (medicamentsData[nom]) {
                    majMedicament();
                    return;
                }
                const proche = Object.keys(medicamentsData).find(
                    cle => cle.toLowerCase().includes(nom.toLowerCase())
                );
                if (proche) {
                    medInput.value = proche;
                    majMedicament();
                }
            }

            const addForm = document.querySelector('#addModal form');
            if (addForm) {
                addForm.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' && e.target && e.target.tagName === 'INPUT') {
                        e.preventDefault();
                        if (e.target.id === 'medicament_select') {
                            selectionnerMedicament();
                            e.target.blur();
                        }
                    }
                });

                addForm.addEventListener('submit', function(e) {
                    const champsRequis = {
                        'medicament_select': 'le nom du médicament',
                        'nouveau_medicament_dci': 'le DCI',
                        'nouveau_medicament_dosage': 'le dosage',
                        'nouveau_medicament_forme': 'la forme',
                        'nouveau_medicament_categorie': 'la catégorie'
                    };
                    for (const [id, libelle] of Object.entries(champsRequis)) {
                        const el = document.getElementById(id);
                        if (!el || el.value.trim() === '') {
                            e.preventDefault();
                            if (el) el.focus();
                            Swal.fire({
                                title: '⚠️ Champs incomplets',
                                text: `Veuillez renseigner ${libelle} avant d'ajouter au stock.`,
                                icon: 'warning',
                                confirmButtonColor: '#16A34A',
                                confirmButtonText: 'OK'
                            });
                            return;
                        }
                    }
                });
            }

            // Réinitialiser le formulaire quand le modal se ferme/rouvre
            const addModal = document.getElementById('addModal');
            if (addModal) {
                addModal.addEventListener('shown.bs.modal', function() {
                    if (medInput) medInput.value = '';
                    if (medId) medId.value = '0';
                    setChamp('nouveau_medicament_dci', '');
                    setChamp('nouveau_medicament_dosage', '');
                    setChamp('nouveau_medicament_forme', '');
                    setChamp('nouveau_medicament_categorie', '');
                });
            }

            // ===== KEYBOARD SHORTCUTS =====
            document.addEventListener('keydown', function(e) {
                // Ctrl + N pour ouvrir le modal d'ajout
                if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                    e.preventDefault();
                    const addBtn = document.querySelector('[data-bs-target="#addModal"]');
                    if (addBtn) addBtn.click();
                }
                // Escape pour fermer
                if (e.key === 'Escape') {
                    const modal = document.querySelector('.modal.show');
                    if (modal) {
                        const closeBtn = modal.querySelector('.btn-close');
                        if (closeBtn) closeBtn.click();
                    }
                }
            });

            // ===== CONSOLE LOG =====
            console.log('%c PharmaGeo - Mes médicaments ', 'background: linear-gradient(135deg, #16A34A, #166534); color: white; font-size: 16px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');
            console.log('%c Raccourci : Ctrl+N pour ajouter un médicament', 'color: #666; font-size: 13px;');

        });
    </script>

</body>
    <script src="../assets/js/responsive.js"></script>
</html>