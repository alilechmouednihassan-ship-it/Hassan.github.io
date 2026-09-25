<?php
/**
 * Initialisation automatique de la base de données cc_recact_db
 */
require_once 'config.php';

try {
    // 1. Table des utilisateurs
    $pdo->exec("CREATE TABLE IF NOT EXISTS utilisateurs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        nom_complet VARCHAR(100) NOT NULL,
        role VARCHAR(20) DEFAULT 'admin',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 2. Table des prestations de catalogue
    $pdo->exec("CREATE TABLE IF NOT EXISTS prestations_catalogue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) NOT NULL,
        libelle VARCHAR(255) NOT NULL,
        categorie VARCHAR(100) NOT NULL DEFAULT 'Général',
        prix_unitaire_ht DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        taux_tva DECIMAL(5,2) NOT NULL DEFAULT 20.00,
        est_debours TINYINT(1) NOT NULL DEFAULT 0,
        description TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 3. Table des factures (Client stocké en TEXTE direct, sans dépendance de clé étrangère)
    $pdo->exec("CREATE TABLE IF NOT EXISTS factures (
        id INT AUTO_INCREMENT PRIMARY KEY,
        numero_facture VARCHAR(50) UNIQUE NOT NULL,
        client VARCHAR(255) NOT NULL,
        date_facture DATE NOT NULL,
        date_echeance DATE NOT NULL,
        montant_ht DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        taux_tva DECIMAL(5,2) NOT NULL DEFAULT 20.00,
        montant_tva DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        montant_ttc DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        total_regle DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        reste_a_payer DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        statut VARCHAR(50) NOT NULL DEFAULT 'En attente',
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 4. Table des lignes de facture
    $pdo->exec("CREATE TABLE IF NOT EXISTS facture_lignes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        facture_id INT NOT NULL,
        code_rubrique VARCHAR(50) NULL,
        description TEXT NOT NULL,
        quantite DECIMAL(10,2) NOT NULL DEFAULT 1.00,
        prix_unitaire_ht DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        taux_tva DECIMAL(5,2) NOT NULL DEFAULT 20.00,
        est_debours TINYINT(1) NOT NULL DEFAULT 0,
        total_ht DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        total_ttc DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        CONSTRAINT fk_cc_facture FOREIGN KEY (facture_id) REFERENCES factures(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 5. Table des règlements
    $pdo->exec("CREATE TABLE IF NOT EXISTS facture_reglements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        facture_id INT NOT NULL,
        date_reglement DATE NOT NULL,
        montant DECIMAL(12,2) NOT NULL,
        mode_reglement VARCHAR(100) NOT NULL DEFAULT 'Virement Bancaire',
        reference_piece VARCHAR(100) NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_cc_reglement FOREIGN KEY (facture_id) REFERENCES factures(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Insérer l'utilisateur admin par défaut s'il n'existe pas
    $chkAdmin = $pdo->prepare("SELECT COUNT(*) FROM utilisateurs WHERE username = 'admin'");
    $chkAdmin->execute();
    if ($chkAdmin->fetchColumn() == 0) {
        $passHash = password_hash('admin', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO utilisateurs (username, password_hash, nom_complet, role) VALUES (?, ?, ?, ?)");
        $stmt->execute(['admin', $passHash, 'Administrateur ABT NEGOS', 'admin']);
    }

    // Insérer des prestations du catalogue de test si vide
    $chkCat = $pdo->query("SELECT COUNT(*) FROM prestations_catalogue")->fetchColumn();
    if ($chkCat == 0) {
        $prestations = [
            ['PR0001', 'Commission sur vente commerciale DU MOIS 03/2026', 'Transit', 1200.00, 20.00, 0, 'Commission d exploitation'],
            ['PR0002', 'Prestation de négoce et courtage international', 'Négoce', 4500.00, 20.00, 0, 'Honoraires de courtage'],
            ['PR0003', 'Assistance logistique et manutention portuaire', 'Logistique', 850.00, 20.00, 0, 'Frais d assistance port Beni Nsar'],
            ['PR0004', 'Frais et débours portuaires (Facturation à l identique)', 'Débours', 3200.00, 0.00, 1, 'Avance pour le compte du client']
        ];
        $pStmt = $pdo->prepare("INSERT INTO prestations_catalogue (code, libelle, categorie, prix_unitaire_ht, taux_tva, est_debours, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($prestations as $p) {
            $pStmt->execute($p);
        }
    }

    // Insérer les factures initiales conformes à la capture si vide
    $chkFac = $pdo->query("SELECT COUNT(*) FROM factures")->fetchColumn();
    if ($chkFac == 0) {
        $factures = [
            [
                'FAC-2026-0005',
                'STE RADOUAN ET BAGHDAD',
                '2026-09-18',
                '2026-11-17',
                1850.00,
                20.00,
                370.00,
                5420.00,
                5420.00,
                0.00,
                'Payée',
                'Paiement comptant reçu',
                [
                    ['PR0001', 'Commission sur vente commerciale', 1850.00, 20.00, 0],
                    ['PR0004', 'Débours portuaires réels', 3200.00, 0.00, 1]
                ]
            ],
            [
                'FAC-2026-0004',
                'ETTAHIRI ELMOKHTAR',
                '2026-08-15',
                '2026-10-15',
                12500.00,
                20.00,
                2500.00,
                15000.00,
                15000.00,
                0.00,
                'Payée',
                'Règlement reçu par virement',
                [
                    ['PR0002', 'Prestation de négoce et courtage international', 12500.00, 20.00, 0]
                ]
            ],
            [
                'FAC-2026-0003',
                'AXALTA COATING SYSTEMS MAROC',
                '2026-06-10',
                '2026-07-10',
                10000.00,
                20.00,
                2000.00,
                12000.00,
                4000.00,
                8000.00,
                'En attente',
                'Acompte de 4 000 MAD reçu',
                [
                    ['PR0002', 'Prestation logistique et courtage', 10000.00, 20.00, 0]
                ]
            ]
        ];

        $insFac = $pdo->prepare("INSERT INTO factures (numero_facture, client, date_facture, date_echeance, montant_ht, taux_tva, montant_tva, montant_ttc, total_regle, reste_a_payer, statut, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insLig = $pdo->prepare("INSERT INTO facture_lignes (facture_id, code_rubrique, description, quantite, prix_unitaire_ht, taux_tva, est_debours, total_ht, total_ttc) VALUES (?, ?, ?, 1.00, ?, ?, ?, ?, ?)");

        foreach ($factures as $fac) {
            $insFac->execute([$fac[0], $fac[1], $fac[2], $fac[3], $fac[4], $fac[5], $fac[6], $fac[7], $fac[8], $fac[9], $fac[10], $fac[11]]);
            $facId = (int)$pdo->lastInsertId();

            foreach ($fac[12] as $lig) {
                $ht = $lig[2];
                $tva = $lig[4] ? 0.00 : round($ht * ($lig[3] / 100), 2);
                $ttc = round($ht + $tva, 2);
                $insLig->execute([$facId, $lig[0], $lig[1], $ht, $lig[3], $lig[4], $ht, $ttc]);
            }
        }
    }

    echo "Base cc_recact_db initialisée avec succès !";
} catch (PDOException $e) {
    echo "Erreur d'initialisation : " . $e->getMessage();
}
