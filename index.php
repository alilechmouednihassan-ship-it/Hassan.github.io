<?php
/**
 * CC RECACT - Système de Facturation & Catalogue
 * Application autonome simplifiée en PHP / HTML / CSS
 */
require_once 'config.php';
requireLogin();

$user = $_SESSION['user'];
$activeTab = $_GET['tab'] ?? 'factures';
if (!in_array($activeTab, ['factures', 'catalogue'])) {
    $activeTab = 'factures';
}

// -------------------------------------------------------------
// API AJAX : Récupérer une facture et ses lignes pour modification
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_facture') {
    header('Content-Type: application/json; charset=utf-8');
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'Identifiant invalide']);
        exit;
    }
    $fStmt = $pdo->prepare("SELECT * FROM factures WHERE id = ?");
    $fStmt->execute([$id]);
    $fac = $fStmt->fetch();
    if (!$fac) {
        echo json_encode(['success' => false, 'error' => 'Facture introuvable']);
        exit;
    }
    $lStmt = $pdo->prepare("SELECT * FROM facture_lignes WHERE facture_id = ? ORDER BY id ASC");
    $lStmt->execute([$id]);
    $lignes = $lStmt->fetchAll();

    echo json_encode([
        'success' => true,
        'facture' => $fac,
        'lignes'  => $lignes
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$message = '';
$error = '';

// -------------------------------------------------------------
// TRAITEMENT DES ACTIONS POST
// -------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    // Action 1 : Ajouter une facture
    if ($action === 'ajouter_facture') {
        $num = trim($_POST['numero_facture'] ?? '');
        $client = trim($_POST['client'] ?? ''); // Champ texte libre
        $dateFac = trim($_POST['date_facture'] ?? '');
        $dateEch = trim($_POST['date_echeance'] ?? '');
        $statut = trim($_POST['statut'] ?? 'En attente');
        $notes = trim($_POST['notes'] ?? '');

        // Lignes de prestations
        $l_codes = $_POST['ligne_code'] ?? [];
        $l_descs = $_POST['ligne_desc'] ?? [];
        $l_pus   = $_POST['ligne_pu'] ?? [];
        $l_tvas  = $_POST['ligne_tva'] ?? [];
        $l_debs  = $_POST['ligne_debours'] ?? [];

        if (empty($num) || empty($client) || empty($dateFac) || empty($dateEch)) {
            $error = "Veuillez renseigner le client, le numéro et les dates de la facture.";
        } else {
            $check = $pdo->prepare("SELECT COUNT(*) FROM factures WHERE numero_facture = ?");
            $check->execute([$num]);
            if ($check->fetchColumn() > 0) {
                $error = "Ce numéro de facture ($num) existe déjà.";
            } else {
                $totalHt = 0;
                $totalTva = 0;
                $totalDebours = 0;
                $validLignes = [];

                for ($i = 0; $i < count($l_descs); $i++) {
                    $desc = trim($l_descs[$i] ?? '');
                    $pu = floatval($l_pus[$i] ?? 0);
                    if (!empty($desc) && $pu >= 0) {
                        $code = trim($l_codes[$i] ?? '');
                        $tvaRate = floatval($l_tvas[$i] ?? 20.00);
                        $isDeb = (!empty($l_debs[$i]) && $l_debs[$i] == '1') ? 1 : 0;

                        $lineHt = $pu;
                        $lineTva = $isDeb ? 0.00 : round($lineHt * ($tvaRate / 100), 2);
                        $lineTtc = round($lineHt + $lineTva, 2);

                        if ($isDeb) {
                            $totalDebours += $lineHt;
                        } else {
                            $totalHt += $lineHt;
                            $totalTva += $lineTva;
                        }

                        $validLignes[] = [
                            'code'        => $code,
                            'description' => $desc,
                            'pu'          => $pu,
                            'taux_tva'    => $tvaRate,
                            'is_debours'  => $isDeb,
                            'total_ht'    => $lineHt,
                            'total_ttc'   => $lineTtc
                        ];
                    }
                }

                if (empty($validLignes)) {
                    $error = "Veuillez ajouter au moins une ligne valide avec un montant.";
                } else {
                    $totalTtc = round($totalHt + $totalTva + $totalDebours, 2);
                    $regle = ($statut === 'Payée') ? $totalTtc : 0.00;
                    $reste = round($totalTtc - $regle, 2);

                    try {
                        $pdo->beginTransaction();

                        $stmt = $pdo->prepare("INSERT INTO factures (numero_facture, client, date_facture, date_echeance, montant_ht, taux_tva, montant_tva, montant_ttc, total_regle, reste_a_payer, statut, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$num, $client, $dateFac, $dateEch, $totalHt, 20.00, $totalTva, $totalTtc, $regle, $reste, $statut, $notes]);
                        $newFacId = (int)$pdo->lastInsertId();

                        $stmtLig = $pdo->prepare("INSERT INTO facture_lignes (facture_id, code_rubrique, description, quantite, prix_unitaire_ht, taux_tva, est_debours, total_ht, total_ttc) VALUES (?, ?, ?, 1.00, ?, ?, ?, ?, ?)");
                        foreach ($validLignes as $vl) {
                            $stmtLig->execute([$newFacId, $vl['code'], $vl['description'], $vl['pu'], $vl['taux_tva'], $vl['is_debours'], $vl['total_ht'], $vl['total_ttc']]);
                        }

                        $pdo->commit();
                        $message = "La facture $num a été enregistrée avec succès.";
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $error = "Erreur lors de l'enregistrement : " . $e->getMessage();
                    }
                }
            }
        }
    }

    // Action 2 : Modifier une facture
    elseif ($action === 'modifier_facture') {
        $facId = filter_var($_POST['facture_id'] ?? null, FILTER_VALIDATE_INT);
        $num = trim($_POST['numero_facture'] ?? '');
        $client = trim($_POST['client'] ?? '');
        $dateFac = trim($_POST['date_facture'] ?? '');
        $dateEch = trim($_POST['date_echeance'] ?? '');
        $statut = trim($_POST['statut'] ?? 'En attente');
        $notes = trim($_POST['notes'] ?? '');

        $l_codes = $_POST['ligne_code'] ?? [];
        $l_descs = $_POST['ligne_desc'] ?? [];
        $l_pus   = $_POST['ligne_pu'] ?? [];
        $l_tvas  = $_POST['ligne_tva'] ?? [];
        $l_debs  = $_POST['ligne_debours'] ?? [];

        if (!$facId || empty($num) || empty($client) || empty($dateFac) || empty($dateEch)) {
            $error = "Veuillez renseigner tous les champs obligatoires.";
        } else {
            $check = $pdo->prepare("SELECT COUNT(*) FROM factures WHERE numero_facture = ? AND id != ?");
            $check->execute([$num, $facId]);
            if ($check->fetchColumn() > 0) {
                $error = "Ce numéro de facture ($num) est déjà attribué à une autre facture.";
            } else {
                $totalHt = 0;
                $totalTva = 0;
                $totalDebours = 0;
                $validLignes = [];

                for ($i = 0; $i < count($l_descs); $i++) {
                    $desc = trim($l_descs[$i] ?? '');
                    $pu = floatval($l_pus[$i] ?? 0);
                    if (!empty($desc) && $pu >= 0) {
                        $code = trim($l_codes[$i] ?? '');
                        $tvaRate = floatval($l_tvas[$i] ?? 20.00);
                        $isDeb = (!empty($l_debs[$i]) && $l_debs[$i] == '1') ? 1 : 0;

                        $lineHt = $pu;
                        $lineTva = $isDeb ? 0.00 : round($lineHt * ($tvaRate / 100), 2);
                        $lineTtc = round($lineHt + $lineTva, 2);

                        if ($isDeb) {
                            $totalDebours += $lineHt;
                        } else {
                            $totalHt += $lineHt;
                            $totalTva += $lineTva;
                        }

                        $validLignes[] = [
                            'code'        => $code,
                            'description' => $desc,
                            'pu'          => $pu,
                            'taux_tva'    => $tvaRate,
                            'is_debours'  => $isDeb,
                            'total_ht'    => $lineHt,
                            'total_ttc'   => $lineTtc
                        ];
                    }
                }

                if (empty($validLignes)) {
                    $error = "Veuillez spécifier au moins une prestation avec un montant.";
                } else {
                    $totalTtc = round($totalHt + $totalTva + $totalDebours, 2);
                    
                    $currFac = $pdo->prepare("SELECT total_regle FROM factures WHERE id = ?");
                    $currFac->execute([$facId]);
                    $regle = floatval($currFac->fetchColumn() ?: 0.00);

                    if ($statut === 'Payée' && $regle < $totalTtc) {
                        $regle = $totalTtc;
                    }
                    $reste = max(0.00, round($totalTtc - $regle, 2));

                    try {
                        $pdo->beginTransaction();

                        $stmt = $pdo->prepare("UPDATE factures SET numero_facture = ?, client = ?, date_facture = ?, date_echeance = ?, montant_ht = ?, montant_tva = ?, montant_ttc = ?, total_regle = ?, reste_a_payer = ?, statut = ?, notes = ? WHERE id = ?");
                        $stmt->execute([$num, $client, $dateFac, $dateEch, $totalHt, $totalTva, $totalTtc, $regle, $reste, $statut, $notes, $facId]);

                        $del = $pdo->prepare("DELETE FROM facture_lignes WHERE facture_id = ?");
                        $del->execute([$facId]);

                        $stmtLig = $pdo->prepare("INSERT INTO facture_lignes (facture_id, code_rubrique, description, quantite, prix_unitaire_ht, taux_tva, est_debours, total_ht, total_ttc) VALUES (?, ?, ?, 1.00, ?, ?, ?, ?, ?)");
                        foreach ($validLignes as $vl) {
                            $stmtLig->execute([$facId, $vl['code'], $vl['description'], $vl['pu'], $vl['taux_tva'], $vl['is_debours'], $vl['total_ht'], $vl['total_ttc']]);
                        }

                        $pdo->commit();
                        $message = "La facture $num a été mise à jour avec succès.";
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $error = "Erreur lors de la modification : " . $e->getMessage();
                    }
                }
            }
        }
    }

    // Action 3 : Supprimer une facture
    elseif ($action === 'supprimer_facture') {
        $facId = filter_var($_POST['facture_id'] ?? null, FILTER_VALIDATE_INT);
        if ($facId) {
            $stmt = $pdo->prepare("DELETE FROM factures WHERE id = ?");
            $stmt->execute([$facId]);
            $message = "La facture a été supprimée avec succès.";
        }
    }

    // Action 4 : Enregistrer un règlement (+ Payé)
    elseif ($action === 'enregistrer_reglement') {
        $facId = filter_var($_POST['facture_id'] ?? null, FILTER_VALIDATE_INT);
        $montant = floatval($_POST['montant_reglement'] ?? 0);
        $dateReg = trim($_POST['date_reglement'] ?? '') ?: date('Y-m-d');
        $modeReg = trim($_POST['mode_reglement'] ?? 'Virement Bancaire');
        $refPiece = trim($_POST['reference_piece'] ?? '');
        $notesReg = trim($_POST['notes_reglement'] ?? '');

        if (!$facId || $montant <= 0) {
            $error = "Veuillez saisir un montant de règlement valide supérieur à 0.";
        } else {
            $fStmt = $pdo->prepare("SELECT montant_ttc, total_regle FROM factures WHERE id = ?");
            $fStmt->execute([$facId]);
            $fRow = $fStmt->fetch();

            if ($fRow) {
                $newRegle = round(floatval($fRow['total_regle']) + $montant, 2);
                $newReste = max(0.00, round(floatval($fRow['montant_ttc']) - $newRegle, 2));
                $newStatut = ($newReste <= 0.00) ? 'Payée' : 'En attente';

                try {
                    $pdo->beginTransaction();

                    $insReg = $pdo->prepare("INSERT INTO facture_reglements (facture_id, date_reglement, mode_reglement, montant, reference_piece, notes) VALUES (?, ?, ?, ?, ?, ?)");
                    $insReg->execute([$facId, $dateReg, $modeReg, $montant, $refPiece, $notesReg]);

                    $upFac = $pdo->prepare("UPDATE factures SET total_regle = ?, reste_a_payer = ?, statut = ? WHERE id = ?");
                    $upFac->execute([$newRegle, $newReste, $newStatut, $facId]);

                    $pdo->commit();
                    $message = "Le règlement de " . number_format($montant, 2, ',', ' ') . " MAD a été enregistré avec succès.";
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "Erreur lors de l'enregistrement du règlement : " . $e->getMessage();
                }
            }
        }
    }

    // Action 5 : Ajouter une prestation au catalogue
    elseif ($action === 'ajouter_prestation') {
        $code = trim($_POST['code'] ?? '');
        $libelle = trim($_POST['libelle'] ?? '');
        $categorie = trim($_POST['categorie'] ?? 'Transit');
        $prixHt = floatval($_POST['prix_unitaire_ht'] ?? 0);
        $tauxTva = floatval($_POST['taux_tva'] ?? 20.00);
        $estDebours = isset($_POST['est_debours']) ? 1 : 0;
        $description = trim($_POST['description'] ?? '');

        if (empty($libelle)) {
            $error = "Le libellé de la prestation est obligatoire.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO prestations_catalogue (code, libelle, categorie, prix_unitaire_ht, taux_tva, est_debours, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$code, $libelle, $categorie, $prixHt, $tauxTva, $estDebours, $description]);
            $message = "La prestation a été ajoutée au catalogue.";
            $activeTab = 'catalogue';
        }
    }

    // Action 6 : Modifier une prestation du catalogue
    elseif ($action === 'modifier_prestation') {
        $catId = filter_var($_POST['catalogue_id'] ?? null, FILTER_VALIDATE_INT);
        $code = trim($_POST['code'] ?? '');
        $libelle = trim($_POST['libelle'] ?? '');
        $categorie = trim($_POST['categorie'] ?? 'Transit');
        $prixHt = floatval($_POST['prix_unitaire_ht'] ?? 0);
        $tauxTva = floatval($_POST['taux_tva'] ?? 20.00);
        $estDebours = isset($_POST['est_debours']) ? 1 : 0;
        $description = trim($_POST['description'] ?? '');

        if (!$catId || empty($libelle)) {
            $error = "Le libellé de la prestation est obligatoire.";
        } else {
            $stmt = $pdo->prepare("UPDATE prestations_catalogue SET code = ?, libelle = ?, categorie = ?, prix_unitaire_ht = ?, taux_tva = ?, est_debours = ?, description = ? WHERE id = ?");
            $stmt->execute([$code, $libelle, $categorie, $prixHt, $tauxTva, $estDebours, $description, $catId]);
            $message = "La prestation a été modifiée avec succès.";
            $activeTab = 'catalogue';
        }
    }

    // Action 7 : Supprimer une prestation du catalogue
    elseif ($action === 'supprimer_prestation') {
        $catId = filter_var($_POST['catalogue_id'] ?? null, FILTER_VALIDATE_INT);
        if ($catId) {
            $stmt = $pdo->prepare("DELETE FROM prestations_catalogue WHERE id = ?");
            $stmt->execute([$catId]);
            $message = "La prestation a été supprimée du catalogue.";
            $activeTab = 'catalogue';
        }
    }
}

// -------------------------------------------------------------
// CHARGEMENT DES DONNÉES
// -------------------------------------------------------------

// Liste des factures
$factures = $pdo->query("SELECT * FROM factures ORDER BY id DESC")->fetchAll();

// Statistiques KPI
$stats = $pdo->query("SELECT 
    COUNT(*) as total_count,
    COALESCE(SUM(montant_ttc), 0) as total_ttc,
    COALESCE(SUM(total_regle), 0) as total_encaisse,
    COALESCE(SUM(reste_a_payer), 0) as total_impaye,
    COALESCE(SUM(CASE WHEN statut = 'En retard' THEN reste_a_payer ELSE 0 END), 0) as total_retard
    FROM factures")->fetch();

// Génération du prochain numéro de facture
$nextNum = "FAC-2026-" . str_pad((string)(count($factures) + 1), 4, "0", STR_PAD_LEFT);

// Prestations du catalogue
$catalogue = $pdo->query("SELECT * FROM prestations_catalogue ORDER BY categorie ASC, id ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facturation & Catalogue - CC RECACT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>

<!-- En-tête supérieur avec titre Facturation centré -->
<header class="top-header mb-4 shadow-sm">
    <div class="container-fluid px-4 position-relative d-flex justify-content-center align-items-center py-1">
        <div class="text-center">
            <h1 class="header-facturation-title m-0">FACTURATION</h1>
        </div>

        <div class="position-absolute end-0 me-4">
            <a href="logout.php" class="btn btn-outline-secondary btn-sm px-3 d-flex align-items-center gap-1" title="Déconnexion">
                <i class="bi bi-box-arrow-right"></i> Déconnexion
            </a>
        </div>
    </div>
</header>

<div class="container-fluid px-4">

    <!-- Messages de confirmation et alertes -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-success alert-dismissible fade show mt-2 py-2 px-3 small border-success" role="alert">
            <i class="bi bi-check-circle me-1"></i> <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show mt-2 py-2 px-3 small" role="alert">
            <i class="bi bi-exclamation-triangle me-1"></i> <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Navigation des deux sous-onglets : Facturation & Catalogue -->
    <ul class="nav nav-tabs-custom">
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'factures' ? 'active' : '' ?>" href="index.php?tab=factures">
                Facturation
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'catalogue' ? 'active' : '' ?>" href="index.php?tab=catalogue">
                Catalogue
            </a>
        </li>
    </ul>

    <!-- ========================================================= -->
    <!-- ONGLET 1 : FACTURATION                                     -->
    <!-- ========================================================= -->
    <?php if ($activeTab === 'factures'): ?>

        <!-- Cartes Statistiques / KPIs -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="kpi-card">
                    <div class="kpi-label">Total Facturé (TTC)</div>
                    <div class="kpi-valeur"><?= number_format($stats['total_ttc'], 2, ',', ' ') ?> <span class="fs-6 fw-normal text-muted">MAD</span></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card kpi-encaisse">
                    <div class="kpi-label">Total Encaissé</div>
                    <div class="kpi-valeur text-success"><?= number_format($stats['total_encaisse'], 2, ',', ' ') ?> <span class="fs-6 fw-normal text-muted">MAD</span></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card kpi-impaye">
                    <div class="kpi-label">Total Impayé</div>
                    <div class="kpi-valeur text-warning"><?= number_format($stats['total_impaye'], 2, ',', ' ') ?> <span class="fs-6 fw-normal text-muted">MAD</span></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card kpi-retard">
                    <div class="kpi-label">Factures en Retard</div>
                    <div class="kpi-valeur text-danger"><?= number_format($stats['total_retard'], 2, ',', ' ') ?> <span class="fs-6 fw-normal text-muted">MAD</span></div>
                </div>
            </div>
        </div>

        <!-- Section Tableau des Factures -->
        <div class="card-table mb-5">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                <div>
                    <h5 class="fw-bold mb-1 text-dark">Suivi des Paiements et des Factures</h5>
                    <div class="text-muted small" id="compteurFactures">Total : <?= count($factures) ?> facture(s) enregistrée(s)</div>
                </div>

                <!-- Zone de Recherche & Bouton d'Ajout -->
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <div class="search-container">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" id="rechercheFacture" class="form-control search-input" placeholder="Rechercher (N°, Client, Statut...)" onkeyup="filtrerFactures()">
                    </div>

                    <button type="button" class="btn btn-vert d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#modalAjoutFacture">
                        <i class="bi bi-plus-lg"></i> Ajouter une Facture
                    </button>
                </div>
            </div>

            <!-- Tableau des Factures -->
            <div class="table-responsive">
                <table class="table table-custom table-hover align-middle" id="tableauFactures">
                    <thead>
                        <tr>
                            <th>N° Facture</th>
                            <th>Client</th>
                            <th>Date Facture</th>
                            <th>Date Échéance</th>
                            <th class="text-end">Montant HT</th>
                            <th class="text-end">Montant TTC</th>
                            <th class="text-end">Encaissé</th>
                            <th class="text-end">Reste à Payer</th>
                            <th class="text-center">Statut</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($factures)): ?>
                            <tr id="ligneAucuneFacture">
                                <td colspan="10" class="text-center py-4 text-muted">Aucune facture enregistrée.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($factures as $f): ?>
                                <tr class="ligne-facture">
                                    <td class="fw-bold text-success col-num"><?= htmlspecialchars($f['numero_facture']) ?></td>
                                    <td class="col-client">
                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($f['client']) ?></div>
                                    </td>
                                    <td><?= date('d/m/Y', strtotime($f['date_facture'])) ?></td>
                                    <td><?= date('d/m/Y', strtotime($f['date_echeance'])) ?></td>
                                    <td class="text-end"><?= number_format($f['montant_ht'], 2, ',', ' ') ?> MAD</td>
                                    <td class="text-end fw-bold"><?= number_format($f['montant_ttc'], 2, ',', ' ') ?> MAD</td>
                                    <td class="text-end text-success"><?= number_format($f['total_regle'], 2, ',', ' ') ?> MAD</td>
                                    <td class="text-end fw-semibold text-secondary"><?= number_format($f['reste_a_payer'], 2, ',', ' ') ?> MAD</td>
                                    <td class="text-center col-statut">
                                        <?php 
                                            $bClass = 'badge-attente';
                                            if ($f['statut'] === 'Payée') $bClass = 'badge-payee';
                                            elseif ($f['statut'] === 'En retard') $bClass = 'badge-retard';
                                        ?>
                                        <span class="badge <?= $bClass ?> px-2 py-1"><?= htmlspecialchars($f['statut']) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-inline-flex gap-1">
                                            <button type="button" class="btn btn-outline-vert btn-sm" onclick="ouvrirModaleModifierFacture(<?= $f['id'] ?>)" title="Modifier la facture">Modifier</button>
                                            <?php if ($f['statut'] !== 'Payée'): ?>
                                                <button type="button" class="btn btn-outline-vert btn-sm" onclick="ouvrirModaleReglement(<?= $f['id'] ?>, '<?= htmlspecialchars($f['numero_facture'], ENT_QUOTES) ?>', <?= (float)$f['reste_a_payer'] ?>)" title="Enregistrer un Règlement">
                                                    + Payé
                                                </button>
                                            <?php endif; ?>

                                            <form method="POST" action="index.php?tab=factures" onsubmit="return confirm('Confirmer la suppression de la facture <?= htmlspecialchars($f['numero_facture']) ?> ?');">
                                                <input type="hidden" name="action" value="supprimer_facture">
                                                <input type="hidden" name="facture_id" value="<?= $f['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Supprimer">Supprimer</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ========================================================= -->
        <!-- MODALE 1 : AJOUTER UNE FACTURE (Client en TEXTE, sans Mode) -->
        <!-- ========================================================= -->
        <div class="modal fade" id="modalAjoutFacture" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header modal-header-vert">
                        <h6 class="modal-title fw-bold">Nouvelle Facture de Vente</h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" action="index.php?tab=factures" id="formNouvelleFacture">
                        <input type="hidden" name="action" value="ajouter_facture">
                        <div class="modal-body p-4">
                            <!-- Section 1 : Entête de la facture -->
                            <div class="row g-3 mb-4">
                                <!-- Client * (Champ TEXTE direct demandé par l'utilisateur) -->
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Client *</label>
                                    <input type="text" name="client" class="form-control form-control-sm" placeholder="Nom ou Raison sociale du Client" required>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Numéro de Facture *</label>
                                    <input type="text" name="numero_facture" class="form-control form-control-sm" value="<?= $nextNum ?>" required>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Date d'Émission *</label>
                                    <input type="date" name="date_facture" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Date d'Échéance (+60 jours) *</label>
                                    <input type="date" name="date_echeance" class="form-control form-control-sm" value="<?= date('Y-m-d', strtotime('+60 days')) ?>" required>
                                </div>

                                <!-- Mode de Règlement Prévu est SUPPRIMÉ comme demandé -->

                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Statut Initial</label>
                                    <select name="statut" class="form-select form-select-sm">
                                        <option value="En attente">En attente</option>
                                        <option value="Payée">Payée</option>
                                        <option value="En retard">En retard</option>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Notes / Référence Interne</label>
                                    <input type="text" name="notes" class="form-control form-control-sm" placeholder="Ex: Dossier Nador, Réf...">
                                </div>
                            </div>

                            <!-- Section 2 : Tableau des Lignes de Prestations & Débours -->
                            <div class="border-top pt-3 mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <strong class="text-dark" style="font-size: 0.95rem;">Lignes de Prestations &amp; Débours</strong>
                                    <button type="button" onclick="ajouterLigneFacture()" class="btn btn-outline-vert btn-sm">
                                        + Ajouter une ligne
                                    </button>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered align-middle" id="tableLignesFacture">
                                        <thead class="table-light">
                                            <tr class="small text-secondary">
                                                <th style="width: 25%;">Prestation Catalogue (Optionnel)</th>
                                                <th style="width: 12%;">Code</th>
                                                <th>Description / Libellé *</th>
                                                <th style="width: 14%;">Prix HT (MAD) *</th>
                                                <th style="width: 10%;">TVA (%)</th>
                                                <th style="width: 8%;" class="text-center">Débours</th>
                                                <th style="width: 14%;" class="text-end">Total TTC (MAD)</th>
                                                <th style="width: 5%;" class="text-center">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="corpsLignesFacture">
                                            <!-- Ligne 1 par défaut -->
                                            <tr>
                                                <td>
                                                    <select class="form-select form-select-sm sel-catalogue" onchange="choisirPrestation(this)">
                                                        <option value="">-- Choisir du catalogue --</option>
                                                        <?php foreach ($catalogue as $cat): ?>
                                                            <option value="<?= $cat['id'] ?>" 
                                                                    data-code="<?= htmlspecialchars($cat['code']) ?>" 
                                                                    data-desc="<?= htmlspecialchars($cat['libelle']) ?>" 
                                                                    data-pu="<?= $cat['prix_unitaire_ht'] ?>" 
                                                                    data-tva="<?= $cat['taux_tva'] ?>" 
                                                                    data-debours="<?= $cat['est_debours'] ?>">
                                                                <?= htmlspecialchars($cat['code']) ?> - <?= htmlspecialchars($cat['libelle']) ?> (<?= number_format($cat['prix_unitaire_ht'], 2) ?> MAD)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td><input type="text" name="ligne_code[]" class="form-control form-control-sm lig-code" placeholder="Code"></td>
                                                <td><input type="text" name="ligne_desc[]" class="form-control form-control-sm lig-desc" placeholder="Désignation" required></td>
                                                <td><input type="number" step="0.01" name="ligne_pu[]" class="form-control form-control-sm lig-pu text-end" value="0.00" oninput="calculerTotauxModal()" required></td>
                                                <td>
                                                    <select name="ligne_tva[]" class="form-select form-select-sm lig-tva" onchange="calculerTotauxModal()">
                                                        <option value="20.00" selected>20 %</option>
                                                        <option value="14.00">14 %</option>
                                                        <option value="10.00">10 %</option>
                                                        <option value="7.00">7 %</option>
                                                        <option value="0.00">0 %</option>
                                                    </select>
                                                </td>
                                                <td class="text-center">
                                                    <input type="checkbox" name="ligne_debours[]" value="1" class="form-check-input lig-deb" onchange="calculerTotauxModal()">
                                                </td>
                                                <td class="text-end fw-semibold lig-ttc">0,00 MAD</td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-outline-danger btn-sm p-1 px-2" onclick="supprimerLigneFacture(this)">×</button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Récapitulatif financier -->
                                <div class="row justify-content-end mt-3">
                                    <div class="col-md-5">
                                        <div class="bg-light p-3 rounded border">
                                            <div class="d-flex justify-content-between small mb-1">
                                                <span>Total Prestations HT :</span>
                                                <strong id="recapHt">0,00 MAD</strong>
                                            </div>
                                            <div class="d-flex justify-content-between small mb-1">
                                                <span>Total TVA :</span>
                                                <strong id="recapTva">0,00 MAD</strong>
                                            </div>
                                            <div class="d-flex justify-content-between small mb-2">
                                                <span>Total Débours (Exonéré) :</span>
                                                <strong id="recapDebours">0,00 MAD</strong>
                                            </div>
                                            <div class="d-flex justify-content-between border-top pt-2 fs-6">
                                                <strong class="text-dark">Net à Payer (TTC) :</strong>
                                                <strong class="text-success" id="recapTtc">0,00 MAD</strong>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                            <button type="submit" class="btn btn-vert btn-sm px-4">Enregistrer la Facture</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ========================================================= -->
        <!-- MODALE 2 : MODIFIER UNE FACTURE                            -->
        <!-- ========================================================= -->
        <div class="modal fade" id="modalModifierFacture" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header modal-header-vert">
                        <h6 class="modal-title fw-bold">Modifier la Facture</h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" action="index.php?tab=factures" id="formModifierFacture">
                        <input type="hidden" name="action" value="modifier_facture">
                        <input type="hidden" name="facture_id" id="modFactureId">
                        <div class="modal-body p-4">
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Client *</label>
                                    <input type="text" name="client" id="modClient" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Numéro de Facture *</label>
                                    <input type="text" name="numero_facture" id="modNum" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Date d'Émission *</label>
                                    <input type="date" name="date_facture" id="modDateFac" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Date d'Échéance *</label>
                                    <input type="date" name="date_echeance" id="modDateEch" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Statut</label>
                                    <select name="statut" id="modStatut" class="form-select form-select-sm">
                                        <option value="En attente">En attente</option>
                                        <option value="Payée">Payée</option>
                                        <option value="En retard">En retard</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Notes</label>
                                    <input type="text" name="notes" id="modNotes" class="form-control form-control-sm">
                                </div>
                            </div>

                            <div class="border-top pt-3 mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <strong class="text-dark" style="font-size: 0.95rem;">Lignes de Prestations &amp; Débours</strong>
                                    <button type="button" onclick="ajouterLigneModifier()" class="btn btn-outline-vert btn-sm">
                                        + Ajouter une ligne
                                    </button>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered align-middle">
                                        <thead class="table-light">
                                            <tr class="small text-secondary">
                                                <th style="width: 25%;">Prestation Catalogue</th>
                                                <th style="width: 12%;">Code</th>
                                                <th>Description / Libellé *</th>
                                                <th style="width: 14%;">Prix HT (MAD) *</th>
                                                <th style="width: 10%;">TVA (%)</th>
                                                <th style="width: 8%;" class="text-center">Débours</th>
                                                <th style="width: 14%;" class="text-end">Total TTC (MAD)</th>
                                                <th style="width: 5%;" class="text-center">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="corpsLignesModifier">
                                            <!-- Lignes injectées par AJAX -->
                                        </tbody>
                                    </table>
                                </div>

                                <div class="row justify-content-end mt-3">
                                    <div class="col-md-5">
                                        <div class="bg-light p-3 rounded border">
                                            <div class="d-flex justify-content-between small mb-1">
                                                <span>Total Prestations HT :</span>
                                                <strong id="modRecapHt">0,00 MAD</strong>
                                            </div>
                                            <div class="d-flex justify-content-between small mb-1">
                                                <span>Total TVA :</span>
                                                <strong id="modRecapTva">0,00 MAD</strong>
                                            </div>
                                            <div class="d-flex justify-content-between small mb-2">
                                                <span>Total Débours :</span>
                                                <strong id="modRecapDebours">0,00 MAD</strong>
                                            </div>
                                            <div class="d-flex justify-content-between border-top pt-2 fs-6">
                                                <strong class="text-dark">Net à Payer (TTC) :</strong>
                                                <strong class="text-success" id="modRecapTtc">0,00 MAD</strong>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                            <button type="submit" class="btn btn-vert btn-sm px-4">Mettre à jour la Facture</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ========================================================= -->
        <!-- MODALE 3 : ENREGISTRER UN RÈGLEMENT (+ PAYÉ)               -->
        <!-- ========================================================= -->
        <div class="modal fade" id="modalReglement" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header modal-header-vert">
                        <h6 class="modal-title fw-bold">Enregistrer un Règlement</h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" action="index.php?tab=factures">
                        <input type="hidden" name="action" value="enregistrer_reglement">
                        <input type="hidden" name="facture_id" id="regFactureId">
                        <div class="modal-body p-4">
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Facture concernée</label>
                                <input type="text" id="regNumFacture" class="form-control form-control-sm bg-light fw-bold" readonly>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Reste à Payer (MAD)</label>
                                    <input type="text" id="regResteAffichage" class="form-control form-control-sm bg-light text-danger fw-bold" readonly>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Montant Réglé *</label>
                                    <input type="number" step="0.01" name="montant_reglement" id="regMontant" class="form-control form-control-sm fw-bold text-success" required>
                                </div>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Date de Règlement *</label>
                                    <input type="date" name="date_reglement" value="<?= date('Y-m-d') ?>" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Mode de Règlement</label>
                                    <select name="mode_reglement" class="form-select form-select-sm" required>
                                        <option value="Virement Bancaire">Virement Bancaire</option>
                                        <option value="Chèque">Chèque</option>
                                        <option value="Versement">Versement</option>
                                        <option value="Espèces">Espèces</option>
                                        <option value="Effet / Lettre de Change">Effet / Lettre de Change</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Référence / N° de Chèque / N° Transaction</label>
                                <input type="text" name="reference_piece" class="form-control form-control-sm" placeholder="Ex: VIR-849202...">
                            </div>
                            <div>
                                <label class="form-label small fw-semibold">Notes complémentaires</label>
                                <textarea name="notes_reglement" class="form-control form-control-sm" rows="2" placeholder="Observations..."></textarea>
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                            <button type="submit" class="btn btn-vert btn-sm px-4">Valider le Paiement</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    <!-- ========================================================= -->
    <!-- ONGLET 2 : CATALOGUE                                       -->
    <!-- ========================================================= -->
    <?php elseif ($activeTab === 'catalogue'): ?>

        <div class="card-table mb-5">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                <div>
                    <h5 class="fw-bold mb-1 text-dark">Catalogue des Prestations et Débours</h5>
                    <div class="text-muted small" id="compteurCatalogue">Total : <?= count($catalogue) ?> prestation(s) répertoriée(s)</div>
                </div>
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <div class="search-container">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" id="rechercheCatalogue" class="form-control search-input" placeholder="Rechercher une prestation..." onkeyup="filtrerCatalogue()">
                    </div>
                    <button type="button" class="btn btn-vert d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#modalAjoutCatalogue">
                        <i class="bi bi-plus-lg"></i> Nouvelle Prestation
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-custom table-hover align-middle" id="tableauCatalogue">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Libellé Prestation</th>
                            <th>Catégorie</th>
                            <th class="text-end">Prix Unitaire HT</th>
                            <th class="text-center">TVA</th>
                            <th class="text-center">Nature</th>
                            <th>Description</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($catalogue)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">Aucune prestation dans le catalogue.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($catalogue as $cat): ?>
                                <tr class="ligne-catalogue">
                                    <td class="fw-bold text-success cat-col-code"><?= htmlspecialchars($cat['code']) ?></td>
                                    <td class="fw-semibold cat-col-libelle"><?= htmlspecialchars($cat['libelle']) ?></td>
                                    <td class="cat-col-cat"><span class="badge bg-light text-dark border"><?= htmlspecialchars($cat['categorie']) ?></span></td>
                                    <td class="text-end fw-bold"><?= number_format($cat['prix_unitaire_ht'], 2, ',', ' ') ?> MAD</td>
                                    <td class="text-center"><?= number_format($cat['taux_tva'], 0) ?> %</td>
                                    <td class="text-center">
                                        <?php if ($cat['est_debours']): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning">Débours</span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success-emphasis border border-success">Prestation</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><?= htmlspecialchars($cat['description'] ?? '') ?></td>
                                    <td class="text-center">
                                        <div class="d-inline-flex gap-1">
                                            <button type="button" class="btn btn-outline-vert btn-sm" 
                                                    onclick='ouvrirModaleModifierCatalogue(<?= json_encode($cat, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>
                                                Modifier
                                            </button>
                                            <form method="POST" action="index.php?tab=catalogue" onsubmit="return confirm('Confirmer la suppression de la prestation <?= htmlspecialchars($cat['libelle']) ?> ?');">
                                                <input type="hidden" name="action" value="supprimer_prestation">
                                                <input type="hidden" name="catalogue_id" value="<?= $cat['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm">Supprimer</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- MODALE AJOUT CATALOGUE -->
        <div class="modal fade" id="modalAjoutCatalogue" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header modal-header-vert">
                        <h6 class="modal-title fw-bold">Ajouter une Prestation au Catalogue</h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" action="index.php?tab=catalogue">
                        <input type="hidden" name="action" value="ajouter_prestation">
                        <div class="modal-body p-4">
                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Code Rubrique</label>
                                    <input type="text" name="code" class="form-control form-control-sm" placeholder="Ex: PR0005" required>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label small fw-semibold">Libellé / Désignation *</label>
                                    <input type="text" name="libelle" class="form-control form-control-sm" placeholder="Libellé de la prestation" required>
                                </div>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Catégorie</label>
                                    <input type="text" name="categorie" class="form-control form-control-sm" placeholder="Ex: Transit, Négoce, Logistique..." required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Prix Unitaire HT (MAD) *</label>
                                    <input type="number" step="0.01" name="prix_unitaire_ht" class="form-control form-control-sm" value="0.00" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Taux TVA (%)</label>
                                    <select name="taux_tva" class="form-select form-select-sm">
                                        <option value="20.00" selected>20 %</option>
                                        <option value="14.00">14 %</option>
                                        <option value="10.00">10 %</option>
                                        <option value="7.00">7 %</option>
                                        <option value="0.00">0 % (Exonéré)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="est_debours" value="1" id="checkDebours">
                                    <label class="form-check-label small" for="checkDebours">
                                        Il s'agit d'un débours (Frais avancés pour le client / Exonéré de TVA)
                                    </label>
                                </div>
                            </div>
                            <div>
                                <label class="form-label small fw-semibold">Description / Notes</label>
                                <textarea name="description" class="form-control form-control-sm" rows="2" placeholder="Description détaillée..."></textarea>
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                            <button type="submit" class="btn btn-vert btn-sm px-4">Ajouter au Catalogue</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- MODALE MODIFIER CATALOGUE -->
        <div class="modal fade" id="modalModifierCatalogue" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header modal-header-vert">
                        <h6 class="modal-title fw-bold">Modifier la Prestation</h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" action="index.php?tab=catalogue">
                        <input type="hidden" name="action" value="modifier_prestation">
                        <input type="hidden" name="catalogue_id" id="editCatId">
                        <div class="modal-body p-4">
                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Code Rubrique</label>
                                    <input type="text" name="code" id="editCatCode" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label small fw-semibold">Libellé / Désignation *</label>
                                    <input type="text" name="libelle" id="editCatLibelle" class="form-control form-control-sm" required>
                                </div>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Catégorie</label>
                                    <input type="text" name="categorie" id="editCatCategorie" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Prix Unitaire HT (MAD) *</label>
                                    <input type="number" step="0.01" name="prix_unitaire_ht" id="editCatPrix" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Taux TVA (%)</label>
                                    <select name="taux_tva" id="editCatTva" class="form-select form-select-sm">
                                        <option value="20.00">20 %</option>
                                        <option value="14.00">14 %</option>
                                        <option value="10.00">10 %</option>
                                        <option value="7.00">7 %</option>
                                        <option value="0.00">0 % (Exonéré)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="est_debours" value="1" id="editCatDebours">
                                    <label class="form-check-label small" for="editCatDebours">
                                        Il s'agit d'un débours (Exonéré de TVA)
                                    </label>
                                </div>
                            </div>
                            <div>
                                <label class="form-label small fw-semibold">Description / Notes</label>
                                <textarea name="description" id="editCatDescription" class="form-control form-control-sm" rows="2"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                            <button type="submit" class="btn btn-vert btn-sm px-4">Mettre à jour</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- Options catalogue encodées pour injection JavaScript -->
<script>
const catalogueOptions = <?= json_encode($catalogue, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;

// -------------------------------------------------------------
// FILTRAGE DYNAMIQUE (RECHERCHE EN TEMPS RÉEL)
// -------------------------------------------------------------
function filtrerFactures() {
    const query = document.getElementById('rechercheFacture').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#tableauFactures tbody tr.ligne-facture');
    let visibleCount = 0;

    rows.forEach(row => {
        const num = row.querySelector('.col-num')?.innerText.toLowerCase() || '';
        const client = row.querySelector('.col-client')?.innerText.toLowerCase() || '';
        const statut = row.querySelector('.col-statut')?.innerText.toLowerCase() || '';

        if (num.includes(query) || client.includes(query) || statut.includes(query)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    const compteur = document.getElementById('compteurFactures');
    if (compteur) {
        compteur.innerText = `Total : ${visibleCount} facture(s) affichée(s)`;
    }
}

function filtrerCatalogue() {
    const query = document.getElementById('rechercheCatalogue').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#tableauCatalogue tbody tr.ligne-catalogue');
    let visibleCount = 0;

    rows.forEach(row => {
        const code = row.querySelector('.cat-col-code')?.innerText.toLowerCase() || '';
        const lib = row.querySelector('.cat-col-libelle')?.innerText.toLowerCase() || '';
        const cat = row.querySelector('.cat-col-cat')?.innerText.toLowerCase() || '';

        if (code.includes(query) || lib.includes(query) || cat.includes(query)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    const compteur = document.getElementById('compteurCatalogue');
    if (compteur) {
        compteur.innerText = `Total : ${visibleCount} prestation(s) affichée(s)`;
    }
}

// -------------------------------------------------------------
// CALCULS DES TOTAUX POUR L'AJOUT DE FACTURE
// -------------------------------------------------------------
function choisirPrestation(selectElem) {
    const selectedOption = selectElem.options[selectElem.selectedIndex];
    const row = selectElem.closest('tr');
    if (!selectedOption || !selectedOption.value) return;

    row.querySelector('.lig-code').value = selectedOption.dataset.code || '';
    row.querySelector('.lig-desc').value = selectedOption.dataset.desc || '';
    row.querySelector('.lig-pu').value = parseFloat(selectedOption.dataset.pu || 0).toFixed(2);
    row.querySelector('.lig-tva').value = parseFloat(selectedOption.dataset.tva || 20).toFixed(2);
    row.querySelector('.lig-deb').checked = (selectedOption.dataset.debours === '1');

    calculerTotauxModal();
}

function calculerTotauxModal() {
    const rows = document.querySelectorAll('#corpsLignesFacture tr');
    let totalHt = 0;
    let totalTva = 0;
    let totalDebours = 0;

    rows.forEach(row => {
        const pu = parseFloat(row.querySelector('.lig-pu')?.value || 0);
        const tva = parseFloat(row.querySelector('.lig-tva')?.value || 0);
        const isDeb = row.querySelector('.lig-deb')?.checked;

        let lineHt = pu;
        let lineTva = isDeb ? 0.00 : (lineHt * (tva / 100));
        let lineTtc = lineHt + lineTva;

        if (isDeb) {
            totalDebours += lineHt;
        } else {
            totalHt += lineHt;
            totalTva += lineTva;
        }

        const ttcCell = row.querySelector('.lig-ttc');
        if (ttcCell) {
            ttcCell.innerText = lineTtc.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' MAD';
        }
    });

    let totalTtc = totalHt + totalTva + totalDebours;

    document.getElementById('recapHt').innerText = totalHt.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' MAD';
    document.getElementById('recapTva').innerText = totalTva.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' MAD';
    document.getElementById('recapDebours').innerText = totalDebours.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' MAD';
    document.getElementById('recapTtc').innerText = totalTtc.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' MAD';
}

function ajouterLigneFacture() {
    const tbody = document.getElementById('corpsLignesFacture');
    const tr = document.createElement('tr');

    let optsHtml = '<option value="">-- Choisir du catalogue --</option>';
    catalogueOptions.forEach(cat => {
        optsHtml += `<option value="${cat.id}" data-code="${cat.code}" data-desc="${cat.libelle}" data-pu="${cat.prix_unitaire_ht}" data-tva="${cat.taux_tva}" data-debours="${cat.est_debours}">${cat.code} - ${cat.libelle} (${parseFloat(cat.prix_unitaire_ht).toFixed(2)} MAD)</option>`;
    });

    tr.innerHTML = `
        <td><select class="form-select form-select-sm sel-catalogue" onchange="choisirPrestation(this)">${optsHtml}</select></td>
        <td><input type="text" name="ligne_code[]" class="form-control form-control-sm lig-code" placeholder="Code"></td>
        <td><input type="text" name="ligne_desc[]" class="form-control form-control-sm lig-desc" placeholder="Désignation" required></td>
        <td><input type="number" step="0.01" name="ligne_pu[]" class="form-control form-control-sm lig-pu text-end" value="0.00" oninput="calculerTotauxModal()" required></td>
        <td>
            <select name="ligne_tva[]" class="form-select form-select-sm lig-tva" onchange="calculerTotauxModal()">
                <option value="20.00" selected>20 %</option>
                <option value="14.00">14 %</option>
                <option value="10.00">10 %</option>
                <option value="7.00">7 %</option>
                <option value="0.00">0 %</option>
            </select>
        </td>
        <td class="text-center">
            <input type="checkbox" name="ligne_debours[]" value="1" class="form-check-input lig-deb" onchange="calculerTotauxModal()">
        </td>
        <td class="text-end fw-semibold lig-ttc">0,00 MAD</td>
        <td class="text-center">
            <button type="button" class="btn btn-outline-danger btn-sm p-1 px-2" onclick="supprimerLigneFacture(this)">×</button>
        </td>
    `;
    tbody.appendChild(tr);
}

function supprimerLigneFacture(btn) {
    const tbody = document.getElementById('corpsLignesFacture');
    if (tbody.children.length > 1) {
        btn.closest('tr').remove();
        calculerTotauxModal();
    } else {
        alert('La facture doit comporter au moins une ligne.');
    }
}

// -------------------------------------------------------------
// GESTION DE LA MODIFICATION DE FACTURE
// -------------------------------------------------------------
function ouvrirModaleModifierFacture(factureId) {
    fetch(`index.php?action=get_facture&id=${factureId}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                alert(data.error || 'Erreur lors du chargement de la facture.');
                return;
            }

            const f = data.facture;
            document.getElementById('modFactureId').value = f.id;
            document.getElementById('modClient').value = f.client; // Champ texte
            document.getElementById('modNum').value = f.numero_facture;
            document.getElementById('modDateFac').value = f.date_facture;
            document.getElementById('modDateEch').value = f.date_echeance;
            document.getElementById('modStatut').value = f.statut;
            document.getElementById('modNotes').value = f.notes || '';

            const tbody = document.getElementById('corpsLignesModifier');
            tbody.innerHTML = '';

            let lignes = data.lignes || [];
            if (lignes.length === 0) {
                ajouterLigneModifier();
            } else {
                lignes.forEach(lig => {
                    ajouterLigneModifier(lig);
                });
            }

            calculerTotauxModifier();
            new bootstrap.Modal(document.getElementById('modalModifierFacture')).show();
        })
        .catch(err => {
            console.error(err);
            alert('Erreur technique de communication avec le serveur.');
        });
}

function ajouterLigneModifier(ligData = null) {
    const tbody = document.getElementById('corpsLignesModifier');
    const tr = document.createElement('tr');

    let optsHtml = '<option value="">-- Choisir du catalogue --</option>';
    catalogueOptions.forEach(cat => {
        optsHtml += `<option value="${cat.id}" data-code="${cat.code}" data-desc="${cat.libelle}" data-pu="${cat.prix_unitaire_ht}" data-tva="${cat.taux_tva}" data-debours="${cat.est_debours}">${cat.code} - ${cat.libelle} (${parseFloat(cat.prix_unitaire_ht).toFixed(2)} MAD)</option>`;
    });

    const code = ligData ? (ligData.code_rubrique || '') : '';
    const desc = ligData ? (ligData.description || '') : '';
    const pu = ligData ? parseFloat(ligData.prix_unitaire_ht || 0).toFixed(2) : '0.00';
    const tva = ligData ? parseFloat(ligData.taux_tva || 20).toFixed(2) : '20.00';
    const isDeb = (ligData && parseInt(ligData.est_debours) === 1) ? 'checked' : '';

    tr.innerHTML = `
        <td><select class="form-select form-select-sm sel-catalogue" onchange="choisirPrestationMod(this)">${optsHtml}</select></td>
        <td><input type="text" name="ligne_code[]" class="form-control form-control-sm mod-lig-code" value="${code}" placeholder="Code"></td>
        <td><input type="text" name="ligne_desc[]" class="form-control form-control-sm mod-lig-desc" value="${desc}" placeholder="Désignation" required></td>
        <td><input type="number" step="0.01" name="ligne_pu[]" class="form-control form-control-sm mod-lig-pu text-end" value="${pu}" oninput="calculerTotauxModifier()" required></td>
        <td>
            <select name="ligne_tva[]" class="form-select form-select-sm mod-lig-tva" onchange="calculerTotauxModifier()">
                <option value="20.00" ${tva == '20.00' ? 'selected' : ''}>20 %</option>
                <option value="14.00" ${tva == '14.00' ? 'selected' : ''}>14 %</option>
                <option value="10.00" ${tva == '10.00' ? 'selected' : ''}>10 %</option>
                <option value="7.00" ${tva == '7.00' ? 'selected' : ''}>7 %</option>
                <option value="0.00" ${tva == '0.00' ? 'selected' : ''}>0 %</option>
            </select>
        </td>
        <td class="text-center">
            <input type="checkbox" name="ligne_debours[]" value="1" class="form-check-input mod-lig-deb" ${isDeb} onchange="calculerTotauxModifier()">
        </td>
        <td class="text-end fw-semibold mod-lig-ttc">0,00 MAD</td>
        <td class="text-center">
            <button type="button" class="btn btn-outline-danger btn-sm p-1 px-2" onclick="supprimerLigneModifier(this)">×</button>
        </td>
    `;
    tbody.appendChild(tr);
}

function choisirPrestationMod(selectElem) {
    const selectedOption = selectElem.options[selectElem.selectedIndex];
    const row = selectElem.closest('tr');
    if (!selectedOption || !selectedOption.value) return;

    row.querySelector('.mod-lig-code').value = selectedOption.dataset.code || '';
    row.querySelector('.mod-lig-desc').value = selectedOption.dataset.desc || '';
    row.querySelector('.mod-lig-pu').value = parseFloat(selectedOption.dataset.pu || 0).toFixed(2);
    row.querySelector('.mod-lig-tva').value = parseFloat(selectedOption.dataset.tva || 20).toFixed(2);
    row.querySelector('.mod-lig-deb').checked = (selectedOption.dataset.debours === '1');

    calculerTotauxModifier();
}

function supprimerLigneModifier(btn) {
    const tbody = document.getElementById('corpsLignesModifier');
    if (tbody.children.length > 1) {
        btn.closest('tr').remove();
        calculerTotauxModifier();
    } else {
        alert('La facture doit comporter au moins une ligne.');
    }
}

function calculerTotauxModifier() {
    const rows = document.querySelectorAll('#corpsLignesModifier tr');
    let totalHt = 0;
    let totalTva = 0;
    let totalDebours = 0;

    rows.forEach(row => {
        const pu = parseFloat(row.querySelector('.mod-lig-pu')?.value || 0);
        const tva = parseFloat(row.querySelector('.mod-lig-tva')?.value || 0);
        const isDeb = row.querySelector('.mod-lig-deb')?.checked;

        let lineHt = pu;
        let lineTva = isDeb ? 0.00 : (lineHt * (tva / 100));
        let lineTtc = lineHt + lineTva;

        if (isDeb) {
            totalDebours += lineHt;
        } else {
            totalHt += lineHt;
            totalTva += lineTva;
        }

        const ttcCell = row.querySelector('.mod-lig-ttc');
        if (ttcCell) {
            ttcCell.innerText = lineTtc.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' MAD';
        }
    });

    let totalTtc = totalHt + totalTva + totalDebours;

    document.getElementById('modRecapHt').innerText = totalHt.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' MAD';
    document.getElementById('modRecapTva').innerText = totalTva.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' MAD';
    document.getElementById('modRecapDebours').innerText = totalDebours.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' MAD';
    document.getElementById('modRecapTtc').innerText = totalTtc.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' MAD';
}

// -------------------------------------------------------------
// GESTION DU RÈGLEMENT (+ PAYÉ)
// -------------------------------------------------------------
function ouvrirModaleReglement(factureId, numFacture, resteAPayer) {
    document.getElementById('regFactureId').value = factureId;
    document.getElementById('regNumFacture').value = numFacture;
    document.getElementById('regResteAffichage').value = parseFloat(resteAPayer).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' MAD';
    document.getElementById('regMontant').value = parseFloat(resteAPayer).toFixed(2);
    document.getElementById('regMontant').max = parseFloat(resteAPayer).toFixed(2);

    new bootstrap.Modal(document.getElementById('modalReglement')).show();
}

// -------------------------------------------------------------
// GESTION DU CATALOGUE (MODIFICATION)
// -------------------------------------------------------------
function ouvrirModaleModifierCatalogue(cat) {
    document.getElementById('editCatId').value = cat.id;
    document.getElementById('editCatCode').value = cat.code;
    document.getElementById('editCatLibelle').value = cat.libelle;
    document.getElementById('editCatCategorie').value = cat.categorie;
    document.getElementById('editCatPrix').value = parseFloat(cat.prix_unitaire_ht).toFixed(2);
    document.getElementById('editCatTva').value = parseFloat(cat.taux_tva).toFixed(2);
    document.getElementById('editCatDebours').checked = (parseInt(cat.est_debours) === 1);
    document.getElementById('editCatDescription').value = cat.description || '';

    new bootstrap.Modal(document.getElementById('modalModifierCatalogue')).show();
}

// Calcul initial au chargement
document.addEventListener('DOMContentLoaded', () => {
    calculerTotauxModal();
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
