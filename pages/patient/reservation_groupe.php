<?php
// ============================================================
// PharmaGeo — Réservation groupée ("Tout réserver")
//
// Le patient a sélectionné plusieurs médicaments dans la recherche.
// Deux modes :
//   - pharmacie=X : seule cette pharmacie est retenue (bouton sur la
//     carte d'une pharmacie qui possède tous les médicaments) ;
//   - sans pharmacie : toutes les pharmacies proposant au moins un
//     des médicaments sont listées, regroupées, et le patient peut
//     tout réserver en une fois.
//
// Chaque pharmacie reçoit sa propre transaction, sa notification et
// sonjournal : une pharmacie ne doit jamais être informée d'une
// réservation faite chez une autre.
//
// La logique de réservation est partagée avec reservation.php
// (voir includes/reservation_fonctions.php).
// ============================================================

require_once '../../includes/config.php';
require_once '../../includes/session.php';
require_once '../../includes/geo.php';
require_once '../../includes/reservation_fonctions.php';

$position = getPositionUtilisateur($pdo);

$est_connecte = isset($_SESSION['user_id']);
$user = null;
if ($est_connecte) {
    $stmt = $pdo->prepare("SELECT * FROM utilisateur WHERE Id_utilisateur = ?");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch();
}

// ===== PARAMÈTRES DE LA DEMANDE =====
$id_pharmacie = isset($_GET['pharmacie']) ? (int)$_GET['pharmacie'] : 0;

$ids_demandes = array();
$source_ids = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['med'])
    ? $_POST['med']
    : (isset($_GET['med']) ? $_GET['med'] : array());
if (is_array($source_ids)) {
    foreach ($source_ids as $m) {
        $m = (int)$m;
        if ($m > 0 && !in_array($m, $ids_demandes, true)) $ids_demandes[] = $m;
    }
}

// Quantités demandées (par Id_stock)
$quantites = array();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qte']) && is_array($_POST['qte'])) {
    foreach ($_POST['qte'] as $cle => $valeur) {
        $quantites[(int)$cle] = (int)$valeur;
    }
}

// Informations du visiteur (pré-remplissage du formulaire)
$invite = array(
    'nom'       => isset($_POST['nom']) ? trim($_POST['nom']) : '',
    'prenom'    => isset($_POST['prenom']) ? trim($_POST['prenom']) : '',
    'email'     => isset($_POST['email']) ? trim($_POST['email']) : '',
    'telephone' => isset($_POST['telephone']) ? trim($_POST['telephone']) : '',
);

$erreur = '';
$resultat = null;

$medicaments   = array();   // id => fiche médicament (ordre du patient)
$groupes       = array();   // Id_pharmacie => groupe
$introuvables  = array();   // médicaments demandés, absents de toute pharmacie
$total         = 0;
$total_connu   = true;

// ============================================================
// CHARGER LES MÉDICAMENTS ET LES PHARMACIES QUI EN DISPOSENT
// ============================================================
if (empty($ids_demandes)) {
    $erreur = "Aucun médicament sélectionné.";
} else {
    $inM = implode(',', array_fill(0, count($ids_demandes), '?'));

    $stmt = $pdo->prepare("SELECT * FROM medicament WHERE Id_medicament IN ($inM)");
    $stmt->execute($ids_demandes);
    $fiches = array();
    foreach ($stmt->fetchAll() as $row) {
        $fiches[(int)$row['Id_medicament']] = $row;
    }

    // Garder l'ordre demandé par le patient
    foreach ($ids_demandes as $mid) {
        if (isset($fiches[$mid])) $medicaments[$mid] = $fiches[$mid];
        else $introuvables[] = 'médicament #' . $mid;
    }

    // Toutes les pharmacies actives qui stockent au moins un de ces médicaments
    $stmt = $pdo->prepare("
        SELECT
            s.Id_stock, s.Id_medicament, s.Id_pharmacie, s.Quantite, s.Prix,
            s.Statut as Statut_stock,
            p.Nom, p.Adresse, p.Quartier, p.Commune, p.Telephone, p.Horaire,
            p.Latitude, p.Longitude, p.Logo, p.Statut as Pharmacie_Statut
        FROM stock s
        JOIN pharmacie p ON p.Id_pharmacie = s.Id_pharmacie
        WHERE s.Id_medicament IN ($inM)
          AND p.Statut = 'active'
        ORDER BY p.Id_pharmacie, s.Id_stock
    ");
    $stmt->execute($ids_demandes);

    foreach ($stmt->fetchAll() as $row) {
        $pid = (int)$row['Id_pharmacie'];

        if ($id_pharmacie > 0 && $pid !== $id_pharmacie) continue;

        if (!isset($groupes[$pid])) {
            $groupes[$pid] = array(
                'Id_pharmacie' => $pid,
                'Nom'          => $row['Nom'],
                'Adresse'      => $row['Adresse'],
                'Quartier'     => $row['Quartier'],
                'Commune'      => $row['Commune'],
                'Telephone'    => $row['Telephone'],
                'Horaire'      => $row['Horaire'],
                'Latitude'     => $row['Latitude'],
                'Longitude'    => $row['Longitude'],
                'Logo'         => $row['Logo'],
                'distance'     => geoDistanceAffichee($position, $row['Latitude'], $row['Longitude']),
                'lignes'       => array(),
                'manquants'    => array()
            );
        }

        $med = isset($medicaments[(int)$row['Id_medicament']])
            ? $medicaments[(int)$row['Id_medicament']]
            : null;
        if ($med === null) continue;

        $ligne = array(
            'Id_stock'         => (int)$row['Id_stock'],
            'Id_pharmacie'     => $pid,
            'Id_medicament'    => (int)$row['Id_medicament'],
            'Nom_commercial'   => $med['Nom_commercial'],
            'DCI'              => $med['DCI'],
            'Forme'            => $med['Forme'],
            'Dosage'           => $med['Dosage'],
            'Quantite'         => (int)$row['Quantite'],
            'Prix'             => $row['Prix'],
            'Statut_stock'     => $row['Statut_stock'],
            'Medicament_Statut' => $med['Statut'],
            'Pharmacie_Statut' => $row['Pharmacie_Statut']
        );

        // Même contrôle que la réservation unitaire : une ligne non
        // réservable est signalée au lieu d'être proposée.
        $motif = reservationVerifierStock($ligne, 1);
        if ($motif !== '') {
            $groupes[$pid]['manquants'][] = array(
                'Nom_commercial' => $med['Nom_commercial'],
                'raison'         => lcfirst($motif)
            );
            continue;
        }

        $ligne['Quantite_demandee'] = isset($quantites[$ligne['Id_stock']])
            ? max(1, (int)$quantites[$ligne['Id_stock']])
            : 1;

        $groupes[$pid]['lignes'][] = $ligne;
    }

    // Retirer les pharmacies qui n'ont plus aucune ligne réservable
    foreach ($groupes as $pid => $groupe) {
        if (empty($groupe['lignes'])) unset($groupes[$pid]);
    }

    // Médicaments introuvables dans aucune pharmacie proposée
    $couverts = array();
    foreach ($groupes as $groupe) {
        foreach ($groupe['lignes'] as $ligne) $couverts[$ligne['Id_medicament']] = true;
    }
    foreach ($medicaments as $mid => $med) {
        if (!isset($couverts[$mid])) $introuvables[] = $med['Nom_commercial'];
    }

// Tri : les pharmacies ayant le plus de médicaments d'abord, puis les
    // plus proches — comme dans recherche.php. Sans position connue, le
    // critère de distance est neutralisé (null) et le tri s'appuie sur le nom,
    // pour ne pas laisser croire à un classement.
    uasort($groupes, function ($a, $b) {
        $ecart = count($b['lignes']) - count($a['lignes']);
        if ($ecart !== 0) return $ecart;
        $da = $a['distance'];
        $db = $b['distance'];
        if ($da === null && $db === null) return strcmp($a['Nom'], $b['Nom']);
        if ($da === null) return 1;
        if ($db === null) return -1;
        return $da <=> $db;
    });
}

// ============================================================
// TRAITEMENT DU FORMULAIRE
// ============================================================
$pharmacie_unique = (count($groupes) === 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($groupes)) {

    // Pharmacies concernées : toutes, ou une seule via son bouton.
    $action = isset($_POST['pharmacie_action']) ? (string)$_POST['pharmacie_action'] : 'tout';
    if ($action === 'tout') {
        $cibles = $groupes;
    } elseif (isset($groupes[(int)$action])) {
        $cibles = array((int)$action => $groupes[(int)$action]);
    } else {
        $cibles = array();
    }

    // ---- Contrôle des quantités, pharmacie par pharmacie ----
    // Politique « pas de commande à moitié passée » : si une quantité est
    // invalide, rien n'est réservé du tout et le patient corrige. Cela évite
    // qu'une pharmacie soit servie pendant qu'une autre est ignorée sans
    // qu'il s'en aperçoive.
    $erreurs_par_pharmacie = array();
    foreach ($cibles as $pid => $groupe) {
        $erreurs = array();
        foreach ($groupe['lignes'] as $index => $ligne) {
            $demande = isset($quantites[$ligne['Id_stock']]) ? (int)$quantites[$ligne['Id_stock']] : 0;

            if ($demande < 1) {
                $erreurs[] = $ligne['Nom_commercial'] . " : quantité non indiquée.";
                continue;
            }
            if ($demande > (int)$ligne['Quantite']) {
                $erreurs[] = $ligne['Nom_commercial'] . " : " . (int)$ligne['Quantite'] . " unité(s) seulement en stock.";
                continue;
            }
            $cibles[$pid]['lignes'][$index]['Quantite_demandee'] = $demande;
        }
        if (!empty($erreurs)) $erreurs_par_pharmacie[$pid] = $erreurs;
    }

    if (empty($cibles)) {
        $erreur = "Aucune pharmacie sélectionnée.";
    } elseif (!empty($erreurs_par_pharmacie)) {
        $messages = array();
        foreach ($erreurs_par_pharmacie as $pid => $erreurs) {
            $messages[] = "<strong>" . htmlspecialchars($groupes[$pid]['Nom']) . "</strong> : "
                        . implode(' ', $erreurs);
        }
        $erreur = "Réservation impossible :<br>" . implode('<br>', $messages);
        $groupes = $cibles;
    } else {
        // ---- Identité du patient : une seule fois pour toutes ----
        $identity = reservationResoudreUtilisateur(
            $pdo,
            $invite,
            isset($_POST['password']) ? (string)$_POST['password'] : '',
            isset($_POST['confirm_password']) ? (string)$_POST['confirm_password'] : ''
        );

        if (!empty($identity['erreurs'])) {
            $erreur = implode('<br>', $identity['erreurs']);
            $groupes = $cibles;
        } else {
            if ($identity['session_ouverte']) $est_connecte = true;

            $userId = $identity['user_id'];
            $creees_par_pharmacie = array();
            $deja_par_pharmacie   = array();
            $total_global = 0;
            $total_global_connu = true;
            $echecs = array();

            foreach ($cibles as $pid => $groupe) {
                $creees = array();
                $deja   = array();
                $total  = 0;
                $total_connu_groupe = true;

                try {
                    $pdo->beginTransaction();

                    foreach ($groupe['lignes'] as $ligne) {
                        // Déjà en attente : on ne duplique pas la demande.
                        if (reservationDejaEnAttente($pdo, $userId, $ligne['Id_stock'])) {
                            $deja[] = $ligne;
                            continue;
                        }

                        $resultat_insert = reservationInsererEtRetournerId($pdo, $userId, $ligne, $ligne['Quantite_demandee']);
                        $montant = $resultat_insert['montant'];
                        $ligne['Montant'] = $montant;
                        $ligne['Id_reservation'] = $resultat_insert['id'];
                        $creees[] = $ligne;

                        if ($montant === null) {
                            $total_connu_groupe = false;
                        } else {
                            $total += $montant;
                            $total_global += $montant;
                        }
                    }

                    if (!empty($creees)) {
                        $detail = array();
                        foreach ($creees as $ligne) {
                            $detail[] = $ligne['Nom_commercial'] . ' (x' . $ligne['Quantite_demandee'] . ')';
                        }
                        // Une seule notification par pharmacie, même si le
                        // patient a réservé chez plusieursPharmaciens.
                        reservationNotifierPharmacien(
                            $pdo,
                            $pid,
                            'Réservation de ' . count($creees) . ' médicament(s) : ' . implode(', ', $detail)
                        );
                        reservationJournaliser(
                            $pdo,
                            'Réservation groupée (' . count($creees) . ' médicament(s))',
                            $userId
                        );
                    }

                    $pdo->commit();
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    // Cette pharmacie est écartée, les autres sont conservées.
                    $echecs[$pid] = $groupe['Nom'];
                    continue;
                }

                if (!$total_connu_groupe) $total_global_connu = false;

                if (!empty($creees)) $creees_par_pharmacie[$pid] = $creees;
                if (!empty($deja))   $deja_par_pharmacie[$pid]   = $deja;
            }

            if (empty($creees_par_pharmacie) && empty($deja_par_pharmacie)) {
                $erreur = "Aucune réservation n'a pu être enregistrée.";
                $groupes = $cibles;
            } else {
                $resultat = array(
                    'creees'       => $creees_par_pharmacie,
                    'deja'         => $deja_par_pharmacie,
                    'noms'         => array_column($groupes, 'Nom'),
                    'total'        => $total_global,
                    'total_connu'  => $total_global_connu,
                    'compte_creer' => $identity['compte_creer'],
                    'echecs'       => $echecs,
                    // Identifiants des réservations créées : le patient est
                    // redirigé vers la page de SES réservations, pas vers
                    // l'historique général.
                    'ids'          => array_merge(
                        array(),
                        ...array_map(function ($ls) {
                            return array_column($ls, 'Id_reservation');
                        }, $creees_par_pharmacie)
                    )
                );
            }
        }
    }
}

// ============================================================
// TOTAL ESTIMÉ DE L'AFFICHAGE (formulaire)
// ============================================================
if (empty($resultat)) {
    $total = 0;
    $total_connu = true;
    foreach ($groupes as $pid => $groupe) {
        foreach ($groupe['lignes'] as $index => $ligne) {
            $montant = reservationMontant($ligne, $ligne['Quantite_demandee']);
            if ($montant === null) {
                $total_connu = false;
            } else {
                $total += $montant;
            }
        }
    }
}

$nb_total_lignes = 0;
foreach ($groupes as $groupe) $nb_total_lignes += count($groupe['lignes']);

$url_recherche = BASE_URL . 'pages/patient/recherche.php';

$page_title = 'Tout réserver - PharmaGeo';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        /* ===== VARIABLES ===== */
        :root {
            --primary-green: #16A34A;
            --primary-orange: #166534;
            --gray-text: #64748B;
        }
        /* ===== MISE EN PAGE ===== */
        body { background: #F0FDF4; }
        .reservation-wrap { padding: 30px 0; }
        .reservation-card {
            background: #fff; border-radius: 20px; overflow: hidden;
            box-shadow: 0 10px 40px rgba(10,22,40,0.07);
            max-width: 1000px; margin: 0 auto;
        }
        .reservation-header {
            background: linear-gradient(135deg, var(--primary-green), #166534);
            color: #fff; padding: 28px 32px; text-align: center;
        }
        .reservation-header h1 { font-size: 1.7rem; font-weight: 700; margin: 0; }
        .reservation-body { padding: 32px; }
        .pharmacy-name { color: var(--primary-orange); font-weight: 700; }
        .info-line { color: var(--gray-text); font-size: 0.95rem; }
        .qty-input {
            width: 80px; text-align: center; font-weight: 700;
            border: 2px solid #D9E9DF; border-radius: 8px; padding: 8px;
        }
        .btn-submit {
            background: linear-gradient(135deg, var(--primary-green), #166534);
            border: none; color: #fff; font-weight: 700;
            border-radius: 12px; padding: 13px 30px;
        }
        .btn-submit:hover { color: #fff; transform: translateY(-2px); box-shadow: 0 10px 34px rgba(22,163,74,0.28); }
        .btn-submit:disabled { opacity: 0.6; transform: none; box-shadow: none; }
        .price-badge {
            background: rgba(22,163,74,0.1); color: var(--primary-green);
            font-weight: 700; padding: 6px 14px; border-radius: 20px;
        }

        /* ===== GROUPES DE PHARMACIES ===== */
        .groupe-pharmacie {
            border: 1px solid #D9E9DF; border-radius: 16px; padding: 20px;
            margin-bottom: 20px; background: #fff;
        }
        .groupe-pharmacie.complet { border-color: rgba(22,163,74,0.45); background: rgba(22,163,74,0.03); }
        .pharma-complet { background: rgba(22,163,74,0.12); color: #16A34A; font-weight: 700; }
        .pharma-partiel { background: rgba(212,136,15,0.12); color: rgb(180,110,10); font-weight: 700; }
        .med-row {
            padding: 10px 12px; border-radius: 10px; background: #F0FDF4;
        }
        .manquant-chip {
            display: inline-flex; align-items: center; gap: 5px;
            background: #F0FDF4; color: #64748B; text-decoration: line-through;
            border-radius: 50px; padding: 3px 12px; font-size: 0.82rem; font-weight: 600;
        }
        .btn-tout {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            border: none; color: #fff; font-weight: 700;
            border-radius: 14px; padding: 15px 30px; font-size: 1.05rem;
        }
        .btn-tout:hover { color: #fff; transform: translateY(-2px); box-shadow: 0 12px 30px rgba(22,163,74,0.3); }
        @media (max-width: 576px) {
            .reservation-body { padding: 20px; }
            .reservation-header { padding: 20px; }
        }
    </style>
    <link rel="stylesheet" href="../../assets/css/responsive.css">    <!-- Palette verte PharmaGeo -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/palette.css">
</head>
<body>
    <!-- ===== NAVIGATION ===== -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand fw-bold" href="<?php echo BASE_URL; ?>">
                <i class="fas fa-hospital me-2"></i>PharmaGeo
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navRes">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navRes">
                <ul class="navbar-nav ms-auto">
                    <?php if ($est_connecte): ?>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>pages/patient/dashboard.php"><i class="fas fa-tachometer-alt me-1"></i>Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>pages/patient/recherche.php"><i class="fas fa-search me-1"></i>Rechercher</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>pages/patient/historique.php"><i class="fas fa-history me-1"></i>Historique</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>deconnexion.php"><i class="fas fa-sign-out-alt me-1"></i>Déconnexion</a></li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>connexion.php"><i class="fas fa-sign-in-alt me-1"></i>Se connecter</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container reservation-wrap">
        <div class="reservation-card animate-fade-in">
            <div class="reservation-header">
                <h1><i class="fas fa-layer-group me-2"></i>Tout réserver</h1>
            </div>
            <div class="reservation-body">

                <!-- ===== RÉSULTAT ===== -->
                <?php if ($resultat): ?>
                    <?php
                    $nb_creees = 0;
                    foreach ($resultat['creees'] as $lignes) $nb_creees += count($lignes);
                    $nb_deja = 0;
                    foreach ($resultat['deja'] as $lignes) $nb_deja += count($lignes);
                    $nb_pharmacies = count($resultat['creees']);
                    ?>

                    <?php if ($nb_creees > 0): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            <?php if ($resultat['compte_creer']): ?>
                                Votre compte patient a été créé avec succès.
                            <?php endif; ?>
                            <strong><?php echo $nb_creees; ?> médicament(s)</strong> réservé(s)
                            dans <strong><?php echo $nb_pharmacies; ?></strong> pharmacie(s).
                            Chaque pharmacien reçoit sa propre demande.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-check-circle me-2"></i>
                            <?php if ($resultat['compte_creer']): ?>
                                Votre compte patient a été créé avec succès.
                            <?php endif; ?>
                            Vos <?php echo $nb_deja; ?> médicament(s) sont
                            <strong>déjà en attente</strong>. Aucune nouvelle réservation n'a été créée.
                        </div>
                    <?php endif; ?>

                    <?php if ($nb_creees > 0 && $nb_deja > 0): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-info-circle me-2"></i>
                            Ces médicaments étaient déjà en attente de votre part, ils n'ont pas été
                            réservés une seconde fois :
                            <?php
                            $noms_deja = array();
                            foreach ($resultat['deja'] as $pid => $lignes) {
                                foreach ($lignes as $ligne) $noms_deja[] = $ligne['Nom_commercial'];
                            }
                            echo htmlspecialchars(implode(', ', $noms_deja));
                            ?>.
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($resultat['echecs'])): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            Aucune réservation n'a pu être enregistrée pour :
                            <?php echo htmlspecialchars(implode(', ', $resultat['echecs'])); ?>.
                        </div>
                    <?php endif; ?>

                    <?php if ($nb_creees > 0): ?>
                        <?php $total_affiche = 0; ?>
                        <?php foreach ($resultat['creees'] as $pid => $lignes): ?>
                            <h6 class="fw-bold mt-4 mb-2">
                                <i class="fas fa-store me-1" style="color: var(--primary-orange);"></i>
                                <?php echo htmlspecialchars($resultat['noms'][$pid]); ?>
                            </h6>
                            <?php foreach ($lignes as $ligne): ?>
                                <div class="med-row d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-semibold"><?php echo htmlspecialchars($ligne['Nom_commercial']); ?></span>
                                    <span>
                                        <span class="text-muted small me-2">x<?php echo (int)$ligne['Quantite_demandee']; ?></span>
                                        <span class="price-badge">
                                            <?php echo $ligne['Montant'] !== null
                                                ? number_format($ligne['Montant'], 0, ',', ' ') . ' FCFA'
                                                : 'Prix non communiqué'; ?>
                                        </span>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>

                        <div class="d-flex justify-content-between align-items-center rounded-3 p-3 mt-3 bg-white border">
                            <span class="fw-bold"><i class="fas fa-money-bill-wave me-1" style="color: var(--primary-green);"></i>Total estimé</span>
                            <span class="fw-bold fs-5" style="color: var(--primary-green);">
                                <?php echo $resultat['total_connu']
                                    ? number_format($resultat['total'], 0, ',', ' ') . ' FCFA'
                                    : 'Prix non communiqué'; ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <div class="text-center mt-4">
                        <?php
                        // Redirection directe vers la page des réservations
                        // créées, et non vers l'historique général.
                        $urlMaReservation = BASE_URL . 'pages/patient/ma_reservation.php';
                        if (!empty($resultat['ids'])) {
                            $urlMaReservation .= '?ids=' . implode(',', array_map('intval', $resultat['ids']));
                        }
                        ?>
                        <a href="<?php echo htmlspecialchars($urlMaReservation); ?>" class="btn btn-submit">
                            <i class="fas fa-ticket me-2"></i>Voir ma réservation
                        </a>
                        <a href="<?php echo $url_recherche; ?>" class="btn btn-outline-dark ms-2">
                            <i class="fas fa-search me-2"></i>Nouvelle recherche
                        </a>
                    </div>

                <!-- ===== FORMULAIRE ===== -->
                <?php elseif (!empty($groupes)): ?>

                    <?php if ($erreur): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i><?php echo $erreur; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($introuvables)): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Introuvable dans les pharmacies proposées :
                            <?php foreach ($introuvables as $nom): ?>
                                <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($nom); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <p class="info-line">
                        <strong><?php echo count($ids_demandes); ?></strong> médicament(s) sélectionné(s)
                        répartis dans <strong><?php echo count($groupes); ?></strong> pharmacie(s).
                        Ajustez les quantités puis confirmez : chaque pharmacie reçoit une seule demande.
                    </p>

                    <form method="POST" action="">
                        <?php foreach ($ids_demandes as $mid): ?>
                            <input type="hidden" name="med[]" value="<?php echo (int)$mid; ?>">
                        <?php endforeach; ?>

                        <?php foreach ($groupes as $pid => $groupe): ?>
                            <?php
                            $nb_demandes = count($ids_demandes);
                            $complet = count($groupe['lignes']) >= $nb_demandes;
                            ?>
                            <div class="groupe-pharmacie<?php echo $complet ? ' complet' : ''; ?>">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h5 class="pharmacy-name mb-1">
                                            <i class="fas fa-store me-1"></i><?php echo htmlspecialchars($groupe['Nom']); ?>
                                        </h5>
                                        <div class="info-line">
                                            <i class="fas fa-map-marker-alt me-1"></i>
                                            <?php echo htmlspecialchars($groupe['Quartier'] ?? ''); ?>
                                            <?php if (!empty($groupe['Adresse'])): ?> - <?php echo htmlspecialchars($groupe['Adresse']); ?><?php endif; ?>
                                        </div>
                                        <?php if (!empty($groupe['Telephone'])): ?>
                                            <div class="info-line"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($groupe['Telephone']); ?></div>
                                        <?php endif; ?>
<?php $distance_groupe = geoFormaterDistance($groupe['distance']); ?>
                                        <?php if ($distance_groupe !== null): ?>
                                            <div class="info-line">
                                                <i class="fas fa-route me-1"></i>
                                                À <?php echo $distance_groupe; ?> de vous
                                            </div>
                                        <?php else: ?>
                                            <div class="info-line text-muted">
                                                <i class="fas fa-location-arrow me-1"></i>
                                                Distance inconnue — activez la localisation
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="badge <?php echo $complet ? 'pharma-complet' : 'pharma-partiel'; ?>">
                                        <i class="fas fa-check-circle me-1"></i>
                                        <?php echo count($groupe['lignes']); ?>/<?php echo $nb_demandes; ?>
                                    </span>
                                </div>

                                <?php if ($complet): ?>
                                    <div class="alert alert-success py-2 small mb-2">
                                        <i class="fas fa-thumbs-up me-1"></i>
                                        Cette pharmacie possède tous vos médicaments.
                                    </div>
                                <?php endif; ?>

                                <?php foreach ($groupe['lignes'] as $ligne): ?>
                                    <div class="med-row d-flex justify-content-between align-items-center mb-2">
                                        <div>
                                            <span class="fw-semibold"><?php echo htmlspecialchars($ligne['Nom_commercial']); ?></span>
                                            <?php if ($ligne['Dosage']): ?>
                                                <span class="badge bg-light text-dark ms-1"><?php echo htmlspecialchars($ligne['Dosage']); ?></span>
                                            <?php endif; ?>
                                            <div class="text-muted small">
                                                <?php echo $ligne['Prix'] ? number_format($ligne['Prix'], 0, ',', ' ') . ' FCFA' : 'Prix non indiqué'; ?>
                                                · <?php echo (int)$ligne['Quantite']; ?> en stock
                                            </div>
                                        </div>
                                        <input type="number" class="qty-input" name="qte[<?php echo (int)$ligne['Id_stock']; ?>]"
                                               value="<?php echo (int)$ligne['Quantite_demandee']; ?>"
                                               min="1" max="<?php echo (int)$ligne['Quantite']; ?>"
                                               data-prix="<?php echo (float)$ligne['Prix']; ?>" required>
                                    </div>
                                <?php endforeach; ?>

                                <?php if (!empty($groupe['manquants'])): ?>
                                    <div class="mt-2">
                                        <span class="text-muted small me-1">Non réservables ici :</span>
                                        <?php foreach ($groupe['manquants'] as $manquant): ?>
                                            <span class="manquant-chip" title="<?php echo htmlspecialchars($manquant['raison']); ?>">
                                                <?php echo htmlspecialchars($manquant['Nom_commercial']); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="text-end mt-3">
                                    <button type="submit" name="pharmacie_action" value="<?php echo (int)$pid; ?>"
                                            class="btn btn-outline-dark btn-sm">
                                        <i class="fas fa-store me-1"></i>Réserver chez cette pharmacie
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if (!$est_connecte): ?>
                            <!-- ===== CRÉATION DE COMPTE PATIENT (visiteur) ===== -->
                            <div class="guest-account mt-4">
                                <h6 class="fw-bold mb-1">
                                    <i class="fas fa-user-plus me-2" style="color: var(--primary-green);"></i>Créer votre compte patient
                                </h6>
                                <p class="text-muted small mb-3">
                                    Réservez sans compte : ces informations créeront votre compte patient
                                    automatiquement, une seule fois pour toute la commande.
                                    Si vous avez déjà un compte, entrez votre email et votre mot de passe.
                                </p>
                                <div class="row g-2 mb-2">
                                    <div class="col-6">
                                        <label class="form-label small mb-1">Nom <span class="text-danger">*</span></label>
                                        <input type="text" name="nom" class="form-control form-control-sm"
                                               value="<?php echo htmlspecialchars($invite['nom']); ?>" required placeholder="Nom">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small mb-1">Prénom <span class="text-danger">*</span></label>
                                        <input type="text" name="prenom" class="form-control form-control-sm"
                                               value="<?php echo htmlspecialchars($invite['prenom']); ?>" required placeholder="Prénom">
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small mb-1">Adresse email <span class="text-danger">*</span></label>
                                    <input type="email" name="email" class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars($invite['email']); ?>" required placeholder="vous@exemple.com">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small mb-1">Téléphone <span class="text-muted">(optionnel)</span></label>
                                    <input type="tel" name="telephone" class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars($invite['telephone']); ?>" placeholder="+229 00 00 00 00">
                                </div>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label class="form-label small mb-1">Mot de passe <span class="text-danger">*</span></label>
                                        <input type="password" name="password" class="form-control form-control-sm"
                                               required placeholder="8 caractères min.">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small mb-1">Confirmation <span class="text-danger">*</span></label>
                                        <input type="password" name="confirm_password" class="form-control form-control-sm"
                                               required placeholder="Confirmer">
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex justify-content-between align-items-center rounded-3 p-3 mt-3 bg-white border">
                            <span class="fw-bold">
                                <i class="fas fa-money-bill-wave me-1" style="color: var(--primary-green);"></i>
                                Total estimé (<?php echo $nb_total_lignes; ?> médicament(s))
                            </span>
                            <span class="fw-bold fs-5" id="totalGroupe" style="color: var(--primary-green);">
                                <?php echo $total_connu ? number_format($total, 0, ',', ' ') . ' FCFA' : 'Prix non communiqué'; ?>
                            </span>
                        </div>

                        <button type="submit" name="pharmacie_action" value="tout" class="btn btn-tout w-100 mt-3">
                            <i class="fas fa-calendar-check me-2"></i>
                            <?php if ($pharmacie_unique): ?>
                                Confirmer la réservation de <?php echo $nb_total_lignes; ?> médicament(s)
                            <?php else: ?>
                                Tout réserver dans <?php echo count($groupes); ?> pharmacie(s)
                            <?php endif; ?>
                        </button>
                        <p class="text-muted small text-center mt-3 mb-0">
                            <i class="fas fa-info-circle me-1"></i>
                            Une seule demande par pharmacie, quel que soit le nombre de médicaments.
                        </p>
                    </form>

                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-layer-group text-muted" style="font-size: 4rem;"></i>
                        <h4 class="mt-3 text-muted">Rien à réserver pour le moment</h4>
                        <p>
                            <?php echo $erreur !== ''
                                ? $erreur
                                : 'Aucune pharmacie ne propose les médicaments sélectionnés.'; ?>
                        </p>
                        <a href="<?php echo $url_recherche; ?>" class="btn btn-submit">
                            <i class="fas fa-search me-2"></i>Aller à la recherche
                        </a>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <!-- ===== SCRIPTS ===== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const champs = document.querySelectorAll('input.qty-input[data-prix]');
            const totalElem = document.getElementById('totalGroupe');

            function majTotal() {
                if (!totalElem) return;
                let total = 0;
                champs.forEach(function (champ) {
                    const prix = parseFloat(champ.dataset.prix) || 0;
                    if (prix <= 0) {
                        totalElem.textContent = 'Prix non communiqué';
                        return;
                    }
                    const qte = Math.max(1, parseInt(champ.value) || 1);
                    total += prix * qte;
                });
                totalElem.textContent = total.toLocaleString('fr-FR', { maximumFractionDigits: 0 }) + ' FCFA';
            }

            champs.forEach(champ => champ.addEventListener('input', majTotal));
            majTotal();
        });
    </script>
</body>
</html>