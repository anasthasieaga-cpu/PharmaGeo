<?php
// ============================================================
// PharmaGeo — Administration : Statistiques
// Tableau de bord analytique : indicateurs, graphiques et classements
// ============================================================

// ===== ACTIVATION DES ERREURS =====
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ===== DÉFINITION DES CONSTANTES =====
define('BASE_URL', 'http://localhost/pharmageo/');

// ===== GESTION DES IMAGES (photos de profil) =====
require_once __DIR__ . '/../includes/images.php';

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

// ===== VÉRIFICATION ADMIN =====
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'administrateur') {
    header('Location: ' . BASE_URL . 'connexion.php');
    exit();
}

// ===== PÉRIODE DE FILTRAGE =====
$periode = $_GET['periode'] ?? 'mois';
$date_debut = $_GET['date_debut'] ?? date('Y-m-01');
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');

// ===== RÉCUPÉRATION DES STATISTIQUES =====
try {
    // ===== STATISTIQUES GÉNÉRALES =====
    $stats_generales = [
        'total_utilisateurs' => $pdo->query("SELECT COUNT(*) as total FROM utilisateur")->fetch()['total'] ?? 0,
        'total_patients' => $pdo->query("SELECT COUNT(*) as total FROM utilisateur WHERE Role = 'patient'")->fetch()['total'] ?? 0,
        'total_pharmaciens' => $pdo->query("SELECT COUNT(*) as total FROM utilisateur WHERE Role = 'pharmacien'")->fetch()['total'] ?? 0,
        'total_administrateurs' => $pdo->query("SELECT COUNT(*) as total FROM utilisateur WHERE Role = 'administrateur'")->fetch()['total'] ?? 0,
        'total_pharmacies' => $pdo->query("SELECT COUNT(*) as total FROM pharmacie")->fetch()['total'] ?? 0,
        'pharmacies_actives' => $pdo->query("SELECT COUNT(*) as total FROM pharmacie WHERE Statut = 'active'")->fetch()['total'] ?? 0,
        'pharmacies_attente' => $pdo->query("SELECT COUNT(*) as total FROM pharmacie WHERE Statut = 'en_attente'")->fetch()['total'] ?? 0,
        'pharmacies_suspendues' => $pdo->query("SELECT COUNT(*) as total FROM pharmacie WHERE Statut = 'suspendue'")->fetch()['total'] ?? 0,
        'total_medicaments' => $pdo->query("SELECT COUNT(*) as total FROM medicament")->fetch()['total'] ?? 0,
        'total_categories' => $pdo->query("SELECT COUNT(*) as total FROM categorie")->fetch()['total'] ?? 0,
        'total_reservations' => $pdo->query("SELECT COUNT(*) as total FROM reservation")->fetch()['total'] ?? 0,
        'total_recherches' => $pdo->query("SELECT COUNT(*) as total FROM recherche")->fetch()['total'] ?? 0
    ];

    // ===== STATUTS DES RÉSERVATIONS =====
    $stats_reservations = [
        'en_attente' => $pdo->query("SELECT COUNT(*) as total FROM reservation WHERE Statut = 'en_attente'")->fetch()['total'] ?? 0,
        'confirmees' => $pdo->query("SELECT COUNT(*) as total FROM reservation WHERE Statut = 'confirmee'")->fetch()['total'] ?? 0,
        'refusees' => $pdo->query("SELECT COUNT(*) as total FROM reservation WHERE Statut = 'refusee'")->fetch()['total'] ?? 0,
        'retirees' => $pdo->query("SELECT COUNT(*) as total FROM reservation WHERE Statut = 'retiree'")->fetch()['total'] ?? 0,
        'annulees' => $pdo->query("SELECT COUNT(*) as total FROM reservation WHERE Statut = 'annulee'")->fetch()['total'] ?? 0,
        'expirees' => $pdo->query("SELECT COUNT(*) as total FROM reservation WHERE Statut = 'expiree'")->fetch()['total'] ?? 0
    ];

    // ===== STATUTS DES STOCKS =====
    $stats_stocks = [
        'disponible' => $pdo->query("SELECT COUNT(*) as total FROM stock WHERE Statut = 'disponible' AND Quantite > 0")->fetch()['total'] ?? 0,
        'stock_faible' => $pdo->query("SELECT COUNT(*) as total FROM stock WHERE Statut = 'stock_faible' AND Quantite > 0")->fetch()['total'] ?? 0,
        'rupture' => $pdo->query("SELECT COUNT(*) as total FROM stock WHERE Statut = 'rupture' OR Quantite = 0")->fetch()['total'] ?? 0,
        'total' => $pdo->query("SELECT COUNT(*) as total FROM stock")->fetch()['total'] ?? 0
    ];

    // ===== ÉVOLUTION MENSUELLE =====
    $evolution_mensuelle = $pdo->query("
        SELECT 
            DATE_FORMAT(Date_creation, '%Y-%m') as mois,
            COUNT(*) as total_inscriptions,
            SUM(CASE WHEN Role = 'patient' THEN 1 ELSE 0 END) as patients,
            SUM(CASE WHEN Role = 'pharmacien' THEN 1 ELSE 0 END) as pharmaciens,
            SUM(CASE WHEN Role = 'administrateur' THEN 1 ELSE 0 END) as admins
        FROM utilisateur 
        WHERE Date_creation >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(Date_creation, '%Y-%m')
        ORDER BY mois ASC
    ")->fetchAll();

    // ===== RÉSERVATIONS PAR MOIS =====
    $reservations_par_mois = $pdo->query("
        SELECT 
            DATE_FORMAT(Date_reservation, '%Y-%m') as mois,
            COUNT(*) as total,
            SUM(CASE WHEN Statut = 'confirmee' THEN 1 ELSE 0 END) as confirmees,
            SUM(CASE WHEN Statut = 'refusee' THEN 1 ELSE 0 END) as refusees
        FROM reservation 
        WHERE Date_reservation >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(Date_reservation, '%Y-%m')
        ORDER BY mois ASC
    ")->fetchAll();

    // ===== MÉDICAMENTS LES PLUS RECHERCHÉS =====
    $medicaments_populaires = $pdo->query("
        SELECT m.Nom_commercial, m.DCI, COUNT(r.Id_recherche) as total_recherches
        FROM medicament m
        LEFT JOIN recherche r ON r.Mot_recherche LIKE CONCAT('%', m.Nom_commercial, '%')
        GROUP BY m.Id_medicament
        ORDER BY total_recherches DESC
        LIMIT 10
    ")->fetchAll();

    // ===== MÉDICAMENTS LES PLUS RÉSERVÉS =====
    $medicaments_reserves = $pdo->query("
        SELECT m.Nom_commercial, m.DCI, COUNT(r.Id_reservation) as total_reservations
        FROM medicament m
        JOIN stock s ON m.Id_medicament = s.Id_medicament
        JOIN reservation r ON s.Id_stock = r.Id_stock
        WHERE r.Statut = 'confirmee'
        GROUP BY m.Id_medicament
        ORDER BY total_reservations DESC
        LIMIT 10
    ")->fetchAll();

    // ===== PHARMACIES LES PLUS ACTIVES =====
    $pharmacies_actives = $pdo->query("
        SELECT p.Nom, p.Quartier, p.Commune,
               COUNT(r.Id_reservation) as total_reservations,
               COUNT(DISTINCT r.Id_utilisateur) as total_patients
        FROM pharmacie p
        JOIN stock s ON p.Id_pharmacie = s.Id_pharmacie
        JOIN reservation r ON s.Id_stock = r.Id_stock
        WHERE r.Statut = 'confirmee'
        GROUP BY p.Id_pharmacie
        ORDER BY total_reservations DESC
        LIMIT 10
    ")->fetchAll();

    // ===== DERNIÈRES ACTIVITÉS =====
    $dernieres_activites = $pdo->query("
        SELECT l.*, u.Nom, u.Prenom, u.Email
        FROM log l
        JOIN utilisateur u ON l.Id_utilisateur = u.Id_utilisateur
        ORDER BY l.Date_action DESC
        LIMIT 10
    ")->fetchAll();

    // ===== TAUX DE CONVERSION =====
    $taux_conversion = [
        'recherches_vers_reservations' => 0,
        'reservations_vers_confirmations' => 0
    ];
    
    if ($stats_generales['total_recherches'] > 0) {
        $taux_conversion['recherches_vers_reservations'] = round(($stats_generales['total_reservations'] / $stats_generales['total_recherches']) * 100, 2);
    }
    if ($stats_generales['total_reservations'] > 0) {
        $taux_conversion['reservations_vers_confirmations'] = round(($stats_reservations['confirmees'] / $stats_generales['total_reservations']) * 100, 2);
    }

    // ===== RÉPARTITION PAR COMMUNE =====
    $repartition_communes = $pdo->query("
        SELECT Commune, COUNT(*) as total
        FROM pharmacie
        WHERE Statut = 'active'
        GROUP BY Commune
        ORDER BY total DESC
    ")->fetchAll();

    // ===== ACTIVITÉ PAR JOUR DE SEMAINE =====
    $activite_semaine = $pdo->query("
        SELECT 
            DAYNAME(Date_reservation) as jour,
            COUNT(*) as total
        FROM reservation
        GROUP BY DAYNAME(Date_reservation)
        ORDER BY FIELD(DAYNAME(Date_reservation), 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')
    ")->fetchAll();

    // ===== SANTÉ DE LA PLATEFORME =====
    $sante_plateforme = [
        'taux_disponibilite' => 0,
        'taux_validation_pharmaciens' => 0,
        'taux_actif_utilisateurs' => 0
    ];
    
    if ($stats_generales['total_pharmaciens'] > 0) {
        $sante_plateforme['taux_validation_pharmaciens'] = round(($stats_generales['pharmacies_actives'] / $stats_generales['total_pharmaciens']) * 100, 2);
    }
    if ($stats_stocks['total'] > 0) {
        $sante_plateforme['taux_disponibilite'] = round(($stats_stocks['disponible'] / $stats_stocks['total']) * 100, 2);
    }

} catch(PDOException $e) {
    $stats_generales = [];
    $stats_reservations = [];
    $stats_stocks = [];
    $evolution_mensuelle = [];
    $reservations_par_mois = [];
    $medicaments_populaires = [];
    $medicaments_reserves = [];
    $pharmacies_actives = [];
    $dernieres_activites = [];
    $taux_conversion = ['recherches_vers_reservations' => 0, 'reservations_vers_confirmations' => 0];
    $repartition_communes = [];
    $activite_semaine = [];
    $sante_plateforme = ['taux_disponibilite' => 0, 'taux_validation_pharmaciens' => 0, 'taux_actif_utilisateurs' => 0];
}

// ===== EXPORT PDF / EXCEL =====
if (isset($_GET['export']) && in_array($_GET['export'], ['pdf', 'excel', 'docx'], true)) {

    // ===== DONNÉES POUR L'EXPORT =====
    $export_sections = [];

    $export_sections[] = [
        'title'   => 'Statistiques générales',
        'columns' => ['Indicateur', 'Valeur'],
        'rows'    => [
            ['Utilisateurs',        number_format($stats_generales['total_utilisateurs'] ?? 0)],
            ['Patients',            number_format($stats_generales['total_patients'] ?? 0)],
            ['Pharmaciens',         number_format($stats_generales['total_pharmaciens'] ?? 0)],
            ['Administrateurs',     number_format($stats_generales['total_administrateurs'] ?? 0)],
            ['Pharmacies',          number_format($stats_generales['total_pharmacies'] ?? 0)],
            ['Pharmacies actives',  number_format($stats_generales['pharmacies_actives'] ?? 0)],
            ['Pharmacies en attente', number_format($stats_generales['pharmacies_attente'] ?? 0)],
            ['Pharmacies suspendues', number_format($stats_generales['pharmacies_suspendues'] ?? 0)],
            ['Médicaments',         number_format($stats_generales['total_medicaments'] ?? 0)],
            ['Catégories',          number_format($stats_generales['total_categories'] ?? 0)],
            ['Réservations',        number_format($stats_generales['total_reservations'] ?? 0)],
            ['Recherches',          number_format($stats_generales['total_recherches'] ?? 0)],
        ],
    ];

    $export_sections[] = [
        'title'   => 'Statut des réservations',
        'columns' => ['Statut', 'Nombre'],
        'rows'    => [
            ['En attente', number_format($stats_reservations['en_attente'] ?? 0)],
            ['Confirmées', number_format($stats_reservations['confirmees'] ?? 0)],
            ['Refusées',   number_format($stats_reservations['refusees'] ?? 0)],
            ['Retirées',   number_format($stats_reservations['retirees'] ?? 0)],
            ['Annulées',   number_format($stats_reservations['annulees'] ?? 0)],
            ['Expirées',   number_format($stats_reservations['expirees'] ?? 0)],
        ],
    ];

    $export_sections[] = [
        'title'   => 'Statut des stocks',
        'columns' => ['Statut', 'Nombre'],
        'rows'    => [
            ['Disponible',   number_format($stats_stocks['disponible'] ?? 0)],
            ['Stock faible', number_format($stats_stocks['stock_faible'] ?? 0)],
            ['Rupture',      number_format($stats_stocks['rupture'] ?? 0)],
            ['Total',        number_format($stats_stocks['total'] ?? 0)],
        ],
    ];

    $evo_rows = [];
    foreach ($evolution_mensuelle as $e) {
        $evo_rows[] = [
            $e['mois'] ?? '',
            (int)($e['total_inscriptions'] ?? 0),
            (int)($e['patients'] ?? 0),
            (int)($e['pharmaciens'] ?? 0),
            (int)($e['admins'] ?? 0)
        ];
    }
    $export_sections[] = [
        'title'   => 'Évolution des inscriptions (12 derniers mois)',
        'columns' => ['Mois', 'Inscriptions', 'Patients', 'Pharmaciens', 'Admins'],
        'rows'    => $evo_rows
    ];

    $res_rows = [];
    foreach ($reservations_par_mois as $r) {
        $res_rows[] = [
            $r['mois'] ?? '',
            (int)($r['total'] ?? 0),
            (int)($r['confirmees'] ?? 0),
            (int)($r['refusees'] ?? 0)
        ];
    }
    $export_sections[] = [
        'title'   => 'Réservations par mois (12 derniers mois)',
        'columns' => ['Mois', 'Total', 'Confirmées', 'Refusées'],
        'rows'    => $res_rows
    ];

    $pop_rows = [];
    foreach ($medicaments_populaires as $m) {
        $pop_rows[] = [$m['Nom_commercial'] ?? '', $m['DCI'] ?? '', (int)($m['total_recherches'] ?? 0)];
    }
    $export_sections[] = [
        'title'   => 'Médicaments les plus recherchés',
        'columns' => ['Nom commercial', 'DCI', 'Recherches'],
        'rows'    => $pop_rows
    ];

    $resv_rows = [];
    foreach ($medicaments_reserves as $m) {
        $resv_rows[] = [$m['Nom_commercial'] ?? '', $m['DCI'] ?? '', (int)($m['total_reservations'] ?? 0)];
    }
    $export_sections[] = [
        'title'   => 'Médicaments les plus réservés',
        'columns' => ['Nom commercial', 'DCI', 'Réservations'],
        'rows'    => $resv_rows
    ];

    $ph_rows = [];
    foreach ($pharmacies_actives as $p) {
        $ph_rows[] = [
            $p['Nom'] ?? '', $p['Quartier'] ?? '', $p['Commune'] ?? '',
            (int)($p['total_reservations'] ?? 0),
            (int)($p['total_patients'] ?? 0)
        ];
    }
    $export_sections[] = [
        'title'   => 'Pharmacies les plus actives',
        'columns' => ['Nom', 'Quartier', 'Commune', 'Réservations', 'Patients'],
        'rows'    => $ph_rows
    ];

    $comm_rows = [];
    foreach ($repartition_communes as $c) {
        $comm_rows[] = [$c['Commune'] ?? '', (int)($c['total'] ?? 0)];
    }
    $export_sections[] = [
        'title'   => 'Répartition des pharmacies par commune',
        'columns' => ['Commune', 'Pharmacies'],
        'rows'    => $comm_rows
    ];

    $sem_rows = [];
    foreach ($activite_semaine as $d) {
        $sem_rows[] = [$d['jour'] ?? '', (int)($d['total'] ?? 0)];
    }
    $export_sections[] = [
        'title'   => 'Activité par jour de la semaine',
        'columns' => ['Jour', 'Réservations'],
        'rows'    => $sem_rows
    ];

    $export_sections[] = [
        'title'   => 'Taux de conversion & santé de la plateforme',
        'columns' => ['Indicateur', 'Valeur'],
        'rows'    => [
            ['Recherches vers réservations (%)',       number_format($taux_conversion['recherches_vers_reservations'] ?? 0, 2)],
            ['Réservations vers confirmations (%)',    number_format($taux_conversion['reservations_vers_confirmations'] ?? 0, 2)],
            ['Taux de disponibilité des stocks (%)',   number_format($sante_plateforme['taux_disponibilite'] ?? 0, 2)],
            ['Taux de validation des pharmaciens (%)', number_format($sante_plateforme['taux_validation_pharmaciens'] ?? 0, 2)],
        ],
    ];

    $date_export = date('d/m/Y H:i');

    // ===== EXPORT EXCEL (.xlsx) =====
    if ($_GET['export'] === 'excel') {
        $esc = function($s) { return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
        $colL = function($n) { $r = ''; while ($n >= 0) { $r = chr(65 + ($n % 26)) . $r; $n = floor($n / 26) - 1; } return $r; };

        $maxCols = 2;
        foreach ($export_sections as $sec) { $maxCols = max($maxCols, count($sec['columns'])); }

        $cW = array_fill(0, $maxCols, 10);
        foreach ($export_sections as $sec) {
            $nc = count($sec['columns']);
            for ($i = 0; $i < $nc; $i++) { $cW[$i] = max($cW[$i], mb_strlen($sec['columns'][$i])); }
            foreach ($sec['rows'] as $row) {
                for ($i = 0; $i < $nc; $i++) { $cW[$i] = max($cW[$i], mb_strlen((string)($row[$i] ?? ''))); }
            }
        }
        for ($i = 0; $i < $maxCols; $i++) { $cW[$i] = min(55, max(12, ceil($cW[$i] * 1.2))); }

        $colsXml = '<cols>';
        for ($i = 0; $i < $maxCols; $i++) {
            $colsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $cW[$i] . '" customWidth="1"/>';
        }
        $colsXml .= '</cols>';

        $rN = 1; $merges = []; $sd = '';
        $lastCL = $colL($maxCols - 1);

        $merges[] = "A{$rN}:{$lastCL}{$rN}";
        $sd .= '<row r="' . $rN . '" ht="30"><c r="A' . $rN . '" t="inlineStr" s="1"><is><t>PharmaGeo — Rapport de Statistiques</t></is></c>';
        for ($i = 1; $i < $maxCols; $i++) { $sd .= '<c r="' . $colL($i) . $rN . '" t="inlineStr" s="1"><is><t></t></is></c>'; }
        $sd .= '</row>'; $rN++;

        $merges[] = "A{$rN}:{$lastCL}{$rN}";
        $sd .= '<row r="' . $rN . '"><c r="A' . $rN . '" t="inlineStr" s="2"><is><t>' . $esc('Généré le ' . $date_export) . '</t></is></c>';
        for ($i = 1; $i < $maxCols; $i++) { $sd .= '<c r="' . $colL($i) . $rN . '" t="inlineStr" s="2"><is><t></t></is></c>'; }
        $sd .= '</row>'; $rN += 2;

        foreach ($export_sections as $sec) {
            $nc = count($sec['columns']);
            $merges[] = "A{$rN}:{$lastCL}{$rN}";
            $sd .= '<row r="' . $rN . '" ht="22"><c r="A' . $rN . '" t="inlineStr" s="3"><is><t>' . $esc($sec['title']) . '</t></is></c>';
            for ($i = 1; $i < $maxCols; $i++) { $sd .= '<c r="' . $colL($i) . $rN . '" t="inlineStr" s="3"><is><t></t></is></c>'; }
            $sd .= '</row>'; $rN++;

            $sd .= '<row r="' . $rN . '">';
            for ($i = 0; $i < $nc; $i++) { $sd .= '<c r="' . $colL($i) . $rN . '" t="inlineStr" s="4"><is><t>' . $esc($sec['columns'][$i]) . '</t></is></c>'; }
            for ($i = $nc; $i < $maxCols; $i++) { $sd .= '<c r="' . $colL($i) . $rN . '" t="inlineStr" s="4"><is><t></t></is></c>'; }
            $sd .= '</row>'; $rN++;

            $ri = 0;
            foreach ($sec['rows'] as $row) {
                $st = ($ri % 2 === 0) ? 5 : 6;
                $sd .= '<row r="' . $rN . '">';
                for ($i = 0; $i < $nc; $i++) {
                    $sd .= '<c r="' . $colL($i) . $rN . '" t="inlineStr" s="' . $st . '"><is><t>' . $esc((string)($row[$i] ?? '')) . '</t></is></c>';
                }
                for ($i = $nc; $i < $maxCols; $i++) { $sd .= '<c r="' . $colL($i) . $rN . '" t="inlineStr" s="' . $st . '"><is><t></t></is></c>'; }
                $sd .= '</row>'; $rN++; $ri++;
            }
            $rN++;
        }

        $mergeXml = '<mergeCells count="' . count($merges) . '">';
        foreach ($merges as $m) { $mergeXml .= '<mergeCell ref="' . $m . '"/>'; }
        $mergeXml .= '</mergeCells>';

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
' . $colsXml . '
<sheetData>' . $sd . '</sheetData>
' . $mergeXml . '
<pageMargins left="0.5" right="0.5" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>
</worksheet>';

        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<fonts count="6">
<font><sz val="11"/><name val="Calibri"/></font>
<font><b/><sz val="11"/><name val="Calibri"/></font>
<font><b/><sz val="16"/><color rgb="FF2AC32A"/><name val="Calibri"/></font>
<font><b/><sz val="12"/><color rgb="FF333333"/><name val="Calibri"/></font>
<font><i/><sz val="11"/><color rgb="FF666666"/><name val="Calibri"/></font>
<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
</fonts>
<fills count="5">
<fill><patternFill patternType="none"/></fill>
<fill><patternFill patternType="gray125"/></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FF2AC32A"/></patternFill></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FFE8F5E9"/></patternFill></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FFF5F5F5"/></patternFill></fill>
</fills>
<borders count="2">
<border><left/><right/><top/><bottom/><diagonal/></border>
<border>
<left style="thin"><color auto="1"/></left>
<right style="thin"><color auto="1"/></right>
<top style="thin"><color auto="1"/></top>
<bottom style="thin"><color auto="1"/></bottom>
<diagonal/>
</border>
</borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="7">
<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>
<xf numFmtId="0" fontId="3" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
<xf numFmtId="0" fontId="5" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>
<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>
<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1"/>
</cellXfs>
</styleSheet>';

        $wbXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets><sheet name="Statistiques" sheetId="1" r:id="rId1"/></sheets></workbook>';

        $ctXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';

        $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';

        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $zip->addFromString('[Content_Types].xml', $ctXml);
            $zip->addFromString('_rels/.rels', $relsXml);
            $zip->addFromString('xl/workbook.xml', $wbXml);
            $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
            $zip->addFromString('xl/styles.xml', $stylesXml);
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
            $zip->close();
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=utf-8');
            header('Content-Disposition: attachment; filename="statistiques_pharmageo_' . date('Ymd_His') . '.xlsx"');
            header('Cache-Control: max-age=0');
            header('Pragma: public');
            readfile($tmp);
            @unlink($tmp);
            exit;
        }
        @unlink($tmp);
    }

    // ===== EXPORT WORD (.docx) =====
    if ($_GET['export'] === 'docx') {
        $esc = function($s) { return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };

        $bodyXml = '';

        // Titre
        $bodyXml .= '<w:p><w:pPr><w:jc w:val="center"/></w:pPr>';
        $bodyXml .= '<w:r><w:rPr><w:b/><w:sz w:val="36"/><w:szCs w:val="36"/><w:color w:val="2AC32A"/><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/></w:rPr>';
        $bodyXml .= '<w:t>' . $esc('PharmaGeo — Rapport de Statistiques') . '</w:t></w:r></w:p>';

        // Sous-titre
        $bodyXml .= '<w:p><w:pPr><w:jc w:val="center"/></w:pPr>';
        $bodyXml .= '<w:r><w:rPr><w:i/><w:color w:val="666666"/><w:sz w:val="22"/><w:szCs w:val="22"/><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/></w:rPr>';
        $bodyXml .= '<w:t>' . $esc('Généré le ' . $date_export) . '</w:t></w:r></w:p>';

        $bodyXml .= '<w:p/>';

        foreach ($export_sections as $sec) {
            $nc = count($sec['columns']);

            // En-tête de section
            $bodyXml .= '<w:p><w:pPr><w:spacing w:before="220" w:after="100"/></w:pPr>';
            $bodyXml .= '<w:r><w:rPr><w:b/><w:sz w:val="28"/><w:szCs w:val="28"/><w:color w:val="2AC32A"/><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/></w:rPr>';
            $bodyXml .= '<w:t>' . $esc($sec['title']) . '</w:t></w:r></w:p>';

            // Table
            $bodyXml .= '<w:tbl><w:tblPr>';
            $bodyXml .= '<w:tblW w:w="5000" w:type="pct"/>';
            $bodyXml .= '<w:tblBorders>';
            $bodyXml .= '<w:top w:val="single" w:sz="4" w:space="0" w:color="BFBFBF"/>';
            $bodyXml .= '<w:left w:val="single" w:sz="4" w:space="0" w:color="BFBFBF"/>';
            $bodyXml .= '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="BFBFBF"/>';
            $bodyXml .= '<w:right w:val="single" w:sz="4" w:space="0" w:color="BFBFBF"/>';
            $bodyXml .= '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="BFBFBF"/>';
            $bodyXml .= '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="BFBFBF"/>';
            $bodyXml .= '</w:tblBorders>';
            $bodyXml .= '<w:tblLook w:val="04A0" w:firstRow="1" w:lastRow="0" w:firstColumn="0" w:lastColumn="0" w:noHBand="0" w:noVBand="1"/>';
            $bodyXml .= '</w:tblPr>';

            $bodyXml .= '<w:tblGrid>';
            $cwg = intval(9026 / $nc);
            for ($i = 0; $i < $nc; $i++) { $bodyXml .= '<w:gridCol w:w="' . $cwg . '"/>'; }
            $bodyXml .= '</w:tblGrid>';

            // Ligne d'en-tête (fond vert, texte blanc, gras)
            $bodyXml .= '<w:tr>';
            foreach ($sec['columns'] as $h) {
                $bodyXml .= '<w:tc><w:tcPr><w:shd w:val="clear" w:color="auto" w:fill="2AC32A"/></w:tcPr>';
                $bodyXml .= '<w:p><w:pPr><w:spacing w:before="0" w:after="0"/></w:pPr>';
                $bodyXml .= '<w:r><w:rPr><w:b/><w:color w:val="FFFFFF"/><w:sz w:val="20"/><w:szCs w:val="20"/><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/></w:rPr>';
                $bodyXml .= '<w:t>' . $esc($h) . '</w:t></w:r></w:p></w:tc>';
            }
            $bodyXml .= '</w:tr>';

            // Lignes de données
            $ri = 0;
            foreach ($sec['rows'] as $row) {
                $bg = ($ri % 2 === 1) ? 'F5F5F5' : 'FFFFFF';
                $bodyXml .= '<w:tr>';
                foreach ($row as $cell) {
                    $bodyXml .= '<w:tc><w:tcPr><w:shd w:val="clear" w:color="auto" w:fill="' . $bg . '"/></w:tcPr>';
                    $bodyXml .= '<w:p><w:pPr><w:spacing w:before="0" w:after="0"/></w:pPr>';
                    $bodyXml .= '<w:r><w:rPr><w:sz w:val="20"/><w:szCs w:val="20"/><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/></w:rPr>';
                    $bodyXml .= '<w:t>' . $esc((string)$cell) . '</w:t></w:r></w:p></w:tc>';
                }
                $bodyXml .= '</w:tr>';
                $ri++;
            }

            $bodyXml .= '</w:tbl>';
            $bodyXml .= '<w:p/>';
        }

        $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<w:body>' . $bodyXml . '
<w:sectPr>
<w:pgSz w:w="11906" w:h="16838"/>
<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="720" w:footer="720"/>
</w:sectPr>
</w:body>
</w:document>';

        $ctDocx = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>';

        $relsDocx = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>';

        $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'docx_');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $zip->addFromString('[Content_Types].xml', $ctDocx);
            $zip->addFromString('_rels/.rels', $relsDocx);
            $zip->addFromString('word/document.xml', $documentXml);
            $zip->addFromString('word/_rels/document.xml.rels', $docRels);
            $zip->close();
            header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document; charset=utf-8');
            header('Content-Disposition: attachment; filename="statistiques_pharmageo_' . date('Ymd_His') . '.docx"');
            header('Cache-Control: max-age=0');
            header('Pragma: public');
            readfile($tmp);
            @unlink($tmp);
            exit;
        }
        @unlink($tmp);
    }

    // ===== EXPORT PDF =====
    if (!function_exists('export_pdf_esc')) {
        function export_pdf_esc($s) {
            $s = (string)($s ?? '');
            $enc = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s);
            if ($enc === false) { $enc = ''; }
            $enc = str_replace(["\r", "\n"], ' ', $enc);
            return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $enc);
        }
    }

    class PharmaStatPdf {
        private $buff = '';
        private $offsets = [];
        private $count = 0;
        private $font1 = 0;
        private $font2 = 0;
        private $contents = [];
        private $content = '';
        private $y = 780;
        private $date_export = '';

        public function __construct($date_export) {
            $this->date_export = $date_export;
            $this->font1 = $this->obj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>");
            $this->font2 = $this->obj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>");
        }

        private function obj($body) {
            $this->count++;
            $this->offsets[$this->count] = 9 + strlen($this->buff);
            $this->buff .= $this->count . " 0 obj\n" . $body . "\nendobj\n";
            return $this->count;
        }

        private function flushPage() {
            if ($this->content === '') { return; }
            $contentObj = $this->obj("<< /Length " . strlen($this->content) . " >>\nstream\n" . $this->content . "endstream");
            $this->contents[] = $contentObj;
            $this->content = '';
            $this->y = 780;
        }

        public function ensure($h) {
            if ($this->y - $h < 50) { $this->flushPage(); }
        }

        public function text($x, $y, $s, $bold = false, $size = 9.5) {
            $f = $bold ? $this->font2 : $this->font1;
            $this->content .= sprintf("BT /F%d %.1f Tf %.1f %.1f Td (%s) Tj ET\n", $f, $size, $x, $y, export_pdf_esc($s));
        }

        public function line($x1, $y1, $x2, $y2) {
            $this->content .= sprintf("%.1f %.1f m %.1f %.1f l S\n", $x1, $y1, $x2, $y2);
        }

        public function renderHeader() {
            $this->text(50, $this->y, 'PharmaGeo — Plateforme de localisation des pharmacies', true, 13);
            $this->y -= 6;
            $this->text(50, $this->y, 'Rapport de statistiques spécialisé', false, 10);
            $this->y -= 4;
            $this->text(50, $this->y, 'Généré le ' . $this->date_export, false, 9);
            $this->y -= 8;
            $this->line(50, $this->y, 545, $this->y);
            $this->y -= 16;
        }

        public function sectionTitle($title) {
            $this->ensure(34);
            $this->text(50, $this->y, $title, true, 11);
            $this->y -= 4;
            $this->line(50, $this->y, 545, $this->y);
            $this->y -= 13;
        }

        public function table($sec) {
            $cols = $sec['columns'];
            $rows = $sec['rows'];
            $n = count($cols);
            $pageW = 495;

            $lens = [];
            foreach ($cols as $i => $h) { if ($i < $n) $lens[$i] = mb_strlen($h); }
            foreach ($rows as $row) {
                foreach ($row as $i => $cell) {
                    $L = mb_strlen((string)$cell);
                    if ($i < $n && $L > ($lens[$i] ?? 0)) { $lens[$i] = $L; }
                }
            }
            foreach ($lens as $i => $L) { $lens[$i] = min($L, 38); }
            $totalL = array_sum($lens);
            if ($totalL <= 0) { $totalL = $n; }

            $widths = [];
            foreach ($lens as $i => $L) { $widths[$i] = max(35, round(($L / $totalL) * $pageW)); }
            $sumW = array_sum($widths);
            if ($sumW > $pageW) {
                $k = $pageW / $sumW;
                foreach ($widths as $i => $w) { $widths[$i] = max(18, round($w * $k)); }
                $sumW = array_sum($widths);
            }
            $xs = [0 => 50];
            for ($i = 1; $i < $n; $i++) { $xs[$i] = $xs[$i - 1] + $widths[$i - 1]; }

            $this->ensure(16);
            foreach ($cols as $i => $h) {
                $this->text($xs[$i] + 2, $this->y, $h, true, 9.5);
            }
            $this->y -= 4;
            $this->line(50, $this->y, 50 + min($sumW, $pageW), $this->y);
            $this->y -= 12;

            foreach ($rows as $row) {
                $this->ensure(15);
                foreach ($row as $i => $cell) {
                    if ($i < $n) { $this->text($xs[$i] + 2, $this->y, (string)$cell, false, 9); }
                }
                $this->y -= 13;
            }
            $this->y -= 5;
        }

        public function output() {
            $this->flushPage();
            if (count($this->contents) === 0) {
                $this->content = '';
                $this->flushPage();
            }
            $N = count($this->contents);
            $pagesObjNum = 2 + 2 * $N + 1;

            $kids = [];
            for ($i = 0; $i < $N; $i++) {
                $c = $this->contents[$i];
                $p = $this->obj("<< /Type /Page /Parent $pagesObjNum 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 {$this->font1} 0 R /F2 {$this->font2} 0 R >> >> /Contents $c 0 R >>");
                $kids[] = "$p 0 R";
            }
            $pagesObj = $this->obj("<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count $N >>");
            $catalogObj = $this->obj("<< /Type /Catalog /Pages $pagesObj 0 R >>");

            $pdf = "%PDF-1.4\n";
            $pdf .= $this->buff;
            $count = $this->count;
            $xrefStart = strlen($pdf);
            $pdf .= "xref\n0 " . ($count + 1) . "\n0000000000 65535 f \n";
            for ($i = 1; $i <= $count; $i++) {
                $pdf .= sprintf("%010d 00000 n \n", $this->offsets[$i]);
            }
            $pdf .= "trailer\n<< /Size " . ($count + 1) . " /Root $catalogObj 0 R >>\nstartxref\n$xrefStart\n%%EOF";
            return $pdf;
        }
    }

    header('Content-Type: application/pdf; charset=utf-8');
    header('Content-Disposition: attachment; filename="statistiques_pharmageo_' . date('Ymd_His') . '.pdf"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    $pdf = new PharmaStatPdf($date_export);
    $pdf->renderHeader();
    foreach ($export_sections as $sec) {
        $pdf->sectionTitle($sec['title']);
        $pdf->table($sec);
    }
    echo $pdf->output();
    exit;
}

$page_title = 'Statistiques - Administration PharmaGeo';
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
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
            --shadow-xl: 0 30px 80px rgba(0,0,0,0.16);
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

        /* ===== SIDEBAR ===== */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            display: flex;
            flex-direction: column;
            background: var(--dark-text);
            color: white;
            padding: 20px 0;
            z-index: 1000;
            overflow-y: auto;
            transition: var(--transition);
            box-shadow: 4px 0 30px rgba(0,0,0,0.1);
        }

        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-thumb { background: var(--primary-green); border-radius: 4px; }

.sidebar .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 18px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

.sidebar .brand .brand-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
         color: #fff;}

.sidebar .brand h4 {
            font-size: 1.1rem;
            font-weight: 800;
            margin: 0;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .sidebar .brand small {
            color: rgba(255,255,255,0.4);
            font-size: 0.75rem;
            display: block;
        }

        .sidebar .nav-menu { padding: 20px 15px; flex: 1; }

        .sidebar .nav-menu .nav-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255,255,255,0.3);
            padding: 10px 15px 5px;
            font-weight: 600;
        }

        .sidebar .nav-menu .nav-item {
            display: flex;
            align-items: center;
            padding: 12px 18px;
            color: rgba(255,255,255,0.6);
            text-decoration: none;
            border-radius: 12px;
            transition: var(--transition);
            margin-bottom: 2px;
            cursor: pointer;
            border: none;
            background: none;
            width: 100%;
            font-size: 0.9rem;
            font-weight: 500;
            gap: 12px;
        }

        .sidebar .nav-menu .nav-item:hover {
            background: rgba(255,255,255,0.05);
            color: white;
        }

        .sidebar .nav-menu .nav-item.active {
            background: linear-gradient(135deg, rgba(22,163,74,0.2), rgba(21,128,61,0.2));
            color: var(--primary-green);
            border-left: 3px solid var(--primary-green);
        }

        .sidebar .nav-menu .nav-item i { width: 20px; text-align: center; font-size: 1rem; }

        .sidebar .user-profile {
            padding: 20px;
            border-top: 1px solid rgba(255,255,255,0.05);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar .user-profile .avatar {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-orange));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            color: white;
        }

        .sidebar .user-profile .user-info { flex: 1; }
        .sidebar .user-profile .user-info .name { font-weight: 600; font-size: 0.9rem; color: white; }
        .sidebar .user-profile .user-info .role { font-size: 0.7rem; color: rgba(255,255,255,0.4); }
        a.user-profile { text-decoration: none; color: inherit; transition: background-color 0.2s ease; }
        a.user-profile:hover { background-color: rgba(255,255,255,0.07); }
        a.user-profile .profile-chevron { margin-left: auto; font-size: 0.7rem; color: rgba(255,255,255,0.45); }
        a.user-profile:hover .profile-chevron { color: #fff; }

        /* ===== MAIN CONTENT ===== */
        .main-content {
            margin-left: 260px;
            padding: 20px 30px 30px;
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
            transform: translateY(-2px);
            box-shadow: 0 12px 40px rgba(22,163,74,0.3);
        }

        .top-bar .page-actions .btn-action.btn-outline-custom {
            border: 2px solid var(--primary-green);
            color: var(--primary-green);
            background: transparent;
        }

        .top-bar .page-actions .btn-action.btn-outline-custom:hover {
            background: var(--primary-green);
            color: white;
        }

        /* ===== TOGGLE SIDEBAR ===== */
        .toggle-sidebar {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--dark-text);
            padding: 5px 10px;
            border-radius: 10px;
            transition: var(--transition);
        }

        .toggle-sidebar:hover { background: rgba(0,0,0,0.05); }

        @media (max-width: 991px) {
            .toggle-sidebar { display: block; }
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 15px; }
            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 999;
            }
            .sidebar-overlay.active { display: block; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 10px; }
            .top-bar .page-title h1 { font-size: 1.3rem; }
            .top-bar { flex-direction: column; align-items: stretch; }
            .top-bar .page-actions .btn-action { font-size: 0.8rem; padding: 8px 16px; }
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

        .stat-card .stat-icon.green { background: rgba(22,163,74,0.1); color: var(--primary-green); }
        .stat-card .stat-icon.orange { background: rgba(21,128,61,0.1); color: var(--primary-orange); }
        .stat-card .stat-icon.blue { background: rgba(22,83,52,0.1); color: #166534; }
        .stat-card .stat-icon.purple { background: rgba(155,89,182,0.1); color: #1F2937; }
        .stat-card .stat-icon.red { background: rgba(220,38,38,0.1); color: #DC2626; }
        .stat-card .stat-icon.teal { background: rgba(26,188,156,0.1); color: #16A34A; }

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

        /* ===== CHART CONTAINER ===== */
        .section-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 24px;
            border: 1px solid rgba(0,0,0,0.02);
            transition: var(--transition);
        }

        .section-card:hover {
            box-shadow: var(--shadow-md);
        }

        .section-card .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .section-card .section-header h5 {
            font-weight: 700;
            margin: 0;
            font-size: 1.1rem;
        }

        .section-card .section-header h5 i {
            margin-right: 8px;
            color: var(--primary-green);
        }

        .chart-container {
            position: relative;
            height: 280px;
        }

        /* ===== TABLE ===== */
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
            padding: 12px 10px;
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

        .badge-status {
            padding: 4px 14px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-status.success { background: rgba(22,163,74,0.1); color: var(--primary-green); }
        .badge-status.warning { background: rgba(21,128,61,0.1); color: var(--primary-orange); }
        .badge-status.danger { background: rgba(220,38,38,0.1); color: #DC2626; }
        .badge-status.info { background: rgba(22,83,52,0.1); color: #166534; }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 30px 20px;
        }

        .empty-state i {
            font-size: 3rem;
            color: #ddd;
            margin-bottom: 12px;
        }

        .empty-state h6 {
            font-weight: 600;
            color: var(--gray-text);
        }

        .empty-state p {
            color: #bbb;
            font-size: 0.9rem;
            margin: 0;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
            .chart-container { height: 200px; }
            .section-card { padding: 16px; }
        }

        @media (max-width: 576px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card { padding: 12px; }
            .stat-card .stat-number { font-size: 1.3rem; }
            .stat-card .stat-label { font-size: 0.7rem; }
            .stat-card .stat-icon { width: 32px; height: 32px; font-size: 0.9rem; }
            .table-custom { font-size: 0.8rem; min-width: 500px; }
            .table-custom thead th,
            .table-custom tbody td { padding: 8px 6px; }
        }    </style>
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

    <!-- ===== SIDEBAR OVERLAY ===== -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar" id="sidebar">
        <a href="<?php echo BASE_URL; ?>index.php" class="brand" style="text-decoration: none;">
            <div class="brand-icon"><img class="pg-brand-logo" src="<?php echo BASE_URL; ?>assets/identite/icones/pharmageo-icone-blanc.svg" alt=""></div>
            <div><h4>PharmaGeo</h4><small>Administration</small></div>
        </a>

        <nav class="nav-menu">
            <div class="nav-label">Navigation</div>
            <a href="<?php echo BASE_URL; ?>admin/dashboard.php" class="nav-item">
                <i class="fas fa-tachometer-alt"></i>
                <span>Tableau de bord</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/pharmacies.php" class="nav-item">
                <i class="fas fa-store"></i>
                <span>Pharmacies</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/utilisateurs.php" class="nav-item">
                <i class="fas fa-users"></i>
                <span>Utilisateurs</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/medicaments.php" class="nav-item">
                <i class="fas fa-capsules"></i>
                <span>Médicaments</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/categories.php" class="nav-item">
                <i class="fas fa-tags"></i>
                <span>Catégories</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/reservations.php" class="nav-item">
                <i class="fas fa-calendar-check"></i>
                <span>Réservations</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/statistiques.php" class="nav-item active">
                <i class="fas fa-chart-bar"></i>
                <span>Statistiques</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/logs.php" class="nav-item">
                <i class="fas fa-history"></i>
                <span>Journaux d'activité</span>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/parametres.php" class="nav-item">
                <i class="fas fa-cog"></i>
                <span>Paramètres</span>
            </a>

            <div class="nav-label" style="margin-top: 20px;">Mon compte</div>
            <a href="<?php echo BASE_URL; ?>admin/profil.php" class="nav-item">
                <i class="fas fa-user-edit"></i>
                <span>Mon profil</span>
            </a>

        </nav>

        <a class="user-profile" href="<?php echo BASE_URL; ?>admin/profil.php" title="Voir mon profil">
            <div class="avatar">
                <?php if (!empty($_SESSION['photo']) && pharma_image_disponible($_SESSION['photo'])): ?>
                    <img src="<?php echo htmlspecialchars(pharma_image_url($_SESSION['photo'])); ?>" alt="">
                <?php else: ?>
                    <?php echo strtoupper(substr($_SESSION['prenom'] ?? 'A', 0, 1) . substr($_SESSION['nom'] ?? 'D', 0, 1)); ?>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <div class="name"><?php echo htmlspecialchars(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')); ?></div>
                <div class="role"><i class="fas fa-crown me-1" style="color: #DC2626;"></i> Administrateur</div>
            <i class="fas fa-chevron-right profile-chevron"></i>
        </a>
    
    <?php require_once __DIR__ . '/../includes/reinitialiser.php'; echo html_bloc_reinitialiser(); ?>
    <a href="<?php echo BASE_URL; ?>deconnexion.php" class="nav-item" style="color: #FEF2F2; padding: 14px 20px; border-top: 1px solid rgba(255,255,255,0.15); margin-top: auto; text-decoration: none;">
        <i class="fas fa-sign-out-alt"></i>
        <span>Déconnexion</span>
    </a>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">

        <!-- ===== TOP BAR ===== -->
        <div class="top-bar animate-fade-up">
            <div>
                <div class="d-flex align-items-center gap-3">
                    <button class="toggle-sidebar" id="toggleSidebar">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="page-title">
                        <h1><span class="highlight">Statistiques</span></h1>
                        <p><i class="fas fa-chart-bar me-1" style="color: var(--primary-green);"></i> Analysez les performances de la plateforme</p>
                    </div>
                </div>
            </div>
            <div class="page-actions">
                <a href="<?php echo BASE_URL; ?>admin/statistiques.php?export=docx" class="btn-action btn-primary-custom">
                    <i class="fas fa-file-word"></i> Exporter Word
                </a>
                <a href="<?php echo BASE_URL; ?>admin/statistiques.php?export=excel" class="btn-action btn-outline-custom">
                    <i class="fas fa-file-excel"></i> Exporter Excel
                </a>
            </div>
        </div>

        <!-- ===== STATS GÉNÉRALES ===== -->
        <div class="stats-grid animate-fade-up delay-1">
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo number_format($stats_generales['total_utilisateurs'] ?? 0); ?></div>
                        <div class="stat-label">Utilisateurs</div>
                    </div>
                    <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo number_format($stats_generales['total_pharmacies'] ?? 0); ?></div>
                        <div class="stat-label">Pharmacies</div>
                    </div>
                    <div class="stat-icon green"><i class="fas fa-store"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo number_format($stats_generales['total_medicaments'] ?? 0); ?></div>
                        <div class="stat-label">Médicaments</div>
                    </div>
                    <div class="stat-icon purple"><i class="fas fa-capsules"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo number_format($stats_generales['total_reservations'] ?? 0); ?></div>
                        <div class="stat-label">Réservations</div>
                    </div>
                    <div class="stat-icon orange"><i class="fas fa-calendar-check"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo number_format($stats_generales['total_recherches'] ?? 0); ?></div>
                        <div class="stat-label">Recherches</div>
                    </div>
                    <div class="stat-icon teal"><i class="fas fa-search"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-number"><?php echo number_format($stats_generales['total_categories'] ?? 0); ?></div>
                        <div class="stat-label">Catégories</div>
                    </div>
                    <div class="stat-icon purple"><i class="fas fa-tags"></i></div>
                </div>
            </div>
        </div>

        <!-- ===== GRAPHIQUES ===== -->
        <div class="row g-4 animate-fade-up delay-2">
            <!-- Évolution des inscriptions -->
            <div class="col-lg-8">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-chart-line"></i> Évolution des inscriptions</h5>
                        <span class="text-muted small">12 derniers mois</span>
                    </div>
                    <div class="chart-container">
                        <canvas id="evolutionChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Taux de disponibilité -->
            <div class="col-lg-4">
                <div class="section-card h-100">
                    <div class="section-header">
                        <h5><i class="fas fa-chart-pie"></i> Disponibilité des stocks</h5>
                    </div>
                    <div class="chart-container" style="height: 250px;">
                        <canvas id="donutChart"></canvas>
                    </div>
                    <div class="row mt-3 text-center">
                        <div class="col-4">
                            <div class="small text-muted">Disponible</div>
                            <div class="fw-bold" style="color: var(--primary-green);"><?php echo $stats_stocks['disponible'] ?? 0; ?></div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted">Stock faible</div>
                            <div class="fw-bold" style="color: var(--primary-orange);"><?php echo $stats_stocks['stock_faible'] ?? 0; ?></div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted">Rupture</div>
                            <div class="fw-bold" style="color: #DC2626;"><?php echo $stats_stocks['rupture'] ?? 0; ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== RÉSERVATIONS PAR MOIS ===== -->
        <div class="row g-4 animate-fade-up delay-3">
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-calendar-check" style="color: var(--primary-orange);"></i> Réservations par mois</h5>
                    </div>
                    <div class="chart-container">
                        <canvas id="reservationsChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Répartition des réservations -->
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-chart-pie"></i> Répartition des statuts</h5>
                    </div>
                    <div class="chart-container">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== TAUX DE CONVERSION ET SANTÉ ===== -->
        <div class="row g-4 animate-fade-up delay-4">
            <!-- Taux de conversion -->
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-funnel-dollar"></i> Taux de conversion</h5>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="p-3 bg-light rounded-3 text-center">
                                <div class="text-muted small">Recherches → Réservations</div>
                                <div class="h2 fw-bold" style="color: var(--primary-green);"><?php echo $taux_conversion['recherches_vers_reservations']; ?>%</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 bg-light rounded-3 text-center">
                                <div class="text-muted small">Réservations → Confirmées</div>
                                <div class="h2 fw-bold" style="color: var(--primary-orange);"><?php echo $taux_conversion['reservations_vers_confirmations']; ?>%</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Santé de la plateforme -->
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-heartbeat" style="color: #DC2626;"></i> Santé de la plateforme</h5>
                    </div>
                    <div class="row g-3">
                        <div class="col-4">
                            <div class="p-3 bg-light rounded-3 text-center">
                                <div class="text-muted small">Disponibilité</div>
                                <div class="h3 fw-bold" style="color: var(--primary-green);"><?php echo $sante_plateforme['taux_disponibilite']; ?>%</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-3 bg-light rounded-3 text-center">
                                <div class="text-muted small">Pharmaciens validés</div>
                                <div class="h3 fw-bold" style="color: var(--primary-orange);"><?php echo $sante_plateforme['taux_validation_pharmaciens']; ?>%</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-3 bg-light rounded-3 text-center">
                                <div class="text-muted small">Taux global</div>
                                <div class="h3 fw-bold" style="color: #166534;"><?php echo round(($sante_plateforme['taux_disponibilite'] + $sante_plateforme['taux_validation_pharmaciens']) / 2, 2); ?>%</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== MÉDICAMENTS POPULAIRES & PHARMACIES ACTIVES ===== -->
        <div class="row g-4 animate-fade-up delay-5">
            <!-- Médicaments populaires -->
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-fire" style="color: var(--primary-orange);"></i> Médicaments les plus recherchés</h5>
                    </div>
                    <?php if (!empty($medicaments_populaires)): ?>
                        <div class="table-responsive">
                            <table class="table table-custom">
                                <thead>
                                    <tr>
                                        <th>Médicament</th>
                                        <th>DCI</th>
                                        <th class="text-end">Recherches</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($medicaments_populaires as $med): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($med['Nom_commercial']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($med['DCI'] ?? '-'); ?></td>
                                            <td class="text-end"><span class="badge-status success"><?php echo $med['total_recherches']; ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-search"></i>
                            <h6>Aucune donnée</h6>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pharmacies actives -->
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-store" style="color: var(--primary-green);"></i> Pharmacies les plus actives</h5>
                    </div>
                    <?php if (!empty($pharmacies_actives)): ?>
                        <div class="table-responsive">
                            <table class="table table-custom">
                                <thead>
                                    <tr>
                                        <th>Pharmacie</th>
                                        <th>Réservations</th>
                                        <th>Patients</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pharmacies_actives as $p): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($p['Nom']); ?></strong></td>
                                            <td><span class="badge-status info"><?php echo $p['total_reservations']; ?></span></td>
                                            <td><?php echo $p['total_patients']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-store"></i>
                            <h6>Aucune donnée</h6>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===== RÉPARTITION PAR COMMUNE ===== -->
        <?php if (!empty($repartition_communes)): ?>
        <div class="row g-4 animate-fade-up delay-6">
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-map-marker-alt"></i> Répartition par commune</h5>
                    </div>
                    <div class="chart-container">
                        <canvas id="communesChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-calendar-week"></i> Activité par jour de semaine</h5>
                    </div>
                    <div class="chart-container">
                        <canvas id="semaineChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ===== DERNIÈRES ACTIVITÉS ===== -->
        <div class="section-card animate-fade-up delay-7">
            <div class="section-header">
                <h5><i class="fas fa-history"></i> Dernières activités</h5>
                <a href="<?php echo BASE_URL; ?>admin/logs.php" class="btn-link-custom" style="color: var(--primary-green); text-decoration: none; font-weight: 600; font-size: 0.85rem; transition: var(--transition);">Voir tout <i class="fas fa-arrow-right"></i></a>
            </div>
            <?php if (!empty($dernieres_activites)): ?>
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead>
                            <tr>
                                <th>Utilisateur</th>
                                <th>Action</th>
                                <th>Résultat</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dernieres_activites as $log): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($log['Prenom'] . ' ' . $log['Nom']); ?></td>
                                    <td><?php echo htmlspecialchars($log['Action']); ?></td>
                                    <td>
                                        <span class="badge-status <?php echo $log['Resultat'] === 'succes' ? 'success' : 'danger'; ?>">
                                            <?php echo ucfirst($log['Resultat']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($log['Date_action'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <h6>Aucune activité</h6>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- ===== SCRIPTS ===== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {

            // ===== SIDEBAR TOGGLE =====
            const toggleBtn = document.getElementById('toggleSidebar');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');

            function toggleSidebar() {
                sidebar.classList.toggle('open');
                overlay.classList.toggle('active');
                document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
            }

            if (toggleBtn) {
                toggleBtn.addEventListener('click', toggleSidebar);
            }

            if (overlay) {
                overlay.addEventListener('click', toggleSidebar);
            }

            // Fermer la sidebar sur les grands écrans
            const mediaQuery = window.matchMedia('(min-width: 992px)');
            mediaQuery.addEventListener('change', function(e) {
                if (e.matches) {
                    sidebar.classList.remove('open');
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });

            // ===== CHART: ÉVOLUTION DES INSCRIPTIONS =====
            <?php if (!empty($evolution_mensuelle)): ?>
            const evolutionData = <?php echo json_encode($evolution_mensuelle); ?>;
            const ctx1 = document.getElementById('evolutionChart').getContext('2d');

            const labels = evolutionData.map(item => {
                const [year, month] = item.mois.split('-');
                const months = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
                return months[parseInt(month) - 1] + ' ' + year;
            });

            new Chart(ctx1, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Patients',
                            data: evolutionData.map(item => parseInt(item.patients || 0)),
                            backgroundColor: 'rgba(22,83,52,0.6)',
                            borderColor: '#166534',
                            borderWidth: 2,
                            borderRadius: 4
                        },
                        {
                            label: 'Pharmaciens',
                            data: evolutionData.map(item => parseInt(item.pharmaciens || 0)),
                            backgroundColor: 'rgba(22,163,74,0.6)',
                            borderColor: '#16A34A',
                            borderWidth: 2,
                            borderRadius: 4
                        },
                        {
                            label: 'Administrateurs',
                            data: evolutionData.map(item => parseInt(item.admins || 0)),
                            backgroundColor: 'rgba(21,128,61,0.6)',
                            borderColor: '#166534',
                            borderWidth: 2,
                            borderRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                padding: 20,
                                font: { size: 11, family: 'Inter' }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { stepSize: 1, font: { size: 10 } },
                            grid: { display: true, color: 'rgba(0,0,0,0.04)' }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { font: { size: 10 } }
                        }
                    }
                }
            });
            <?php endif; ?>

            // ===== CHART: DONUT DISPONIBILITÉ =====
            const disponibles = <?php echo $stats_stocks['disponible'] ?? 0; ?>;
            const stockFaible = <?php echo $stats_stocks['stock_faible'] ?? 0; ?>;
            const rupture = <?php echo $stats_stocks['rupture'] ?? 0; ?>;

            new Chart(document.getElementById('donutChart'), {
                type: 'doughnut',
                data: {
                    labels: ['Disponible', 'Stock faible', 'Rupture'],
                    datasets: [{
                        data: [disponibles, stockFaible, rupture],
                        backgroundColor: ['#16A34A', '#166534', '#DC2626'],
                        borderWidth: 3,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '70%',
                    plugins: { legend: { display: false } }
                }
            });

            // ===== CHART: RÉSERVATIONS PAR MOIS =====
            <?php if (!empty($reservations_par_mois)): ?>
            const resData = <?php echo json_encode($reservations_par_mois); ?>;
            const ctx3 = document.getElementById('reservationsChart').getContext('2d');

            const resLabels = resData.map(item => {
                const [year, month] = item.mois.split('-');
                const months = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
                return months[parseInt(month) - 1] + ' ' + year;
            });

            new Chart(ctx3, {
                type: 'line',
                data: {
                    labels: resLabels,
                    datasets: [
                        {
                            label: 'Total',
                            data: resData.map(item => parseInt(item.total || 0)),
                            borderColor: '#166534',
                            backgroundColor: 'rgba(22,83,52,0.1)',
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Confirmées',
                            data: resData.map(item => parseInt(item.confirmees || 0)),
                            borderColor: '#16A34A',
                            backgroundColor: 'rgba(22,163,74,0.1)',
                            fill: true,
                            tension: 0.4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                padding: 20,
                                font: { size: 11, family: 'Inter' }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { stepSize: 1, font: { size: 10 } },
                            grid: { display: true, color: 'rgba(0,0,0,0.04)' }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { font: { size: 10 } }
                        }
                    }
                }
            });
            <?php endif; ?>

            // ===== CHART: RÉPARTITION DES STATUTS =====
            const statusData = <?php echo json_encode($stats_reservations); ?>;
            
            new Chart(document.getElementById('statusChart'), {
                type: 'pie',
                data: {
                    labels: ['En attente', 'Confirmées', 'Refusées', 'Retirées', 'Annulées', 'Expirées'],
                    datasets: [{
                        data: [
                            statusData.en_attente || 0,
                            statusData.confirmees || 0,
                            statusData.refusees || 0,
                            statusData.retirees || 0,
                            statusData.annulees || 0,
                            statusData.expirees || 0
                        ],
                        backgroundColor: [
                            '#DC2626', '#16A34A', '#DC2626', 
                            '#166534', '#64748B', '#1F2937'
                        ],
                        borderWidth: 3,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                usePointStyle: true,
                                padding: 15,
                                font: { size: 11, family: 'Inter' }
                            }
                        }
                    }
                }
            });

            // ===== CHART: RÉPARTITION PAR COMMUNE =====
            <?php if (!empty($repartition_communes)): ?>
            const communesData = <?php echo json_encode($repartition_communes); ?>;
            new Chart(document.getElementById('communesChart'), {
                type: 'bar',
                data: {
                    labels: communesData.map(item => item.Commune || 'Non renseigné'),
                    datasets: [{
                        label: 'Pharmacies',
                        data: communesData.map(item => parseInt(item.total || 0)),
                        backgroundColor: 'rgba(22,163,74,0.6)',
                        borderColor: '#16A34A',
                        borderWidth: 2,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { stepSize: 1, font: { size: 10 } },
                            grid: { display: true, color: 'rgba(0,0,0,0.04)' }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { font: { size: 10 } }
                        }
                    }
                }
            });
            <?php endif; ?>

            // ===== CHART: ACTIVITÉ PAR JOUR DE SEMAINE =====
            <?php if (!empty($activite_semaine)): ?>
            const semaineData = <?php echo json_encode($activite_semaine); ?>;
            const jours = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            const joursFr = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
            
            const sortedData = jours.map(jour => {
                const found = semaineData.find(item => item.jour === jour);
                return found ? parseInt(found.total || 0) : 0;
            });

            new Chart(document.getElementById('semaineChart'), {
                type: 'bar',
                data: {
                    labels: joursFr,
                    datasets: [{
                        label: 'Réservations',
                        data: sortedData,
                        backgroundColor: 'rgba(22,83,52,0.6)',
                        borderColor: '#166534',
                        borderWidth: 2,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { stepSize: 1, font: { size: 10 } },
                            grid: { display: true, color: 'rgba(0,0,0,0.04)' }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { font: { size: 10 } }
                        }
                    }
                }
            });
            <?php endif; ?>

            // ===== KEYBOARD SHORTCUTS =====
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && sidebar.classList.contains('open')) {
                    toggleSidebar();
                }
            });

            // ===== CONSOLE LOG =====
            console.log('%c PharmaGeo - Statistiques ', 'background: linear-gradient(135deg, #16A34A, #166534); color: white; font-size: 16px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');

        });
    </script>

</body>
</html>