<?php
/* Copyright (C) 2024 Antigravity Project
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       pdfbuilder/core/modules/modPdfBuilder.class.php
 * \defgroup   pdfbuilder Module PDF-builder
 * \brief      Constructeur de modèles PDF modernes pour Dolibarr
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * \class  modPdfBuilder
 * \brief  Descripteur et classe d'activation du module PDF-builder
 */
class modPdfBuilder extends DolibarrModules
{
    /**
     * Constructeur
     * @param DoliDB $db Handler de base de données
     */
    public function __construct($db)
    {
        global $langs, $conf;

        $this->db = $db;

        // --- Identification ---
        $this->numero       = 310200;   // Numéro unique (à vérifier unicité dans l'écosystème)
        $this->rights_class = 'pdfbuilder';
        $this->family       = 'technic';
        $this->module_position = 500;
        $this->name         = preg_replace('/^mod/i', '', get_class($this)); // PdfBuilder
        $this->description  = 'Constructeur de modèles PDF modernes et personnalisables pour tous les types de documents Dolibarr';
        $this->descriptionlong = 'PDF-builder permet de créer des modèles PDF entièrement personnalisables (couleurs, polices, logos, colonnes) pour les factures, devis, commandes et tous les documents Dolibarr. Il remplace et modernise UltimatePDF avec une architecture Active Record, des thèmes sauvegardés en base, et une interface card-based moderne.';
        $this->editor_name  = 'Antigravity Project';
        $this->editor_url   = 'https://bergerie-aurelien.com';
        $this->version      = '1.0.0-beta1';
        $this->const_name   = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->special      = 0;
        $this->picto        = 'pdfbuilder@pdfbuilder';

        // --- Compatibilité ---
        $this->phpmin                = array(8, 0);
        $this->need_dolibarr_version = array(20, 0);

        // --- Répertoires de données ---
        $this->dirs = array(
            '/pdfbuilder/logos',
            '/pdfbuilder/backgrounds',
        );

        // --- Page de configuration ---
        $this->config_page_url = array('setup.php@pdfbuilder');

        // --- Langues ---
        $this->langfiles = array('pdfbuilder@pdfbuilder');

        // --- Parties du module ---
        $this->module_parts = array(
            'models'  => 1,
            'css'     => array('/pdfbuilder/css/pdfbuilder.css'),
            'hooks'   => array(
                'invoicecard',
                'propalcard',
                'ordercard',
                'invoicesuppliercard',
                'ordersuppliercard',
                'interventioncard',
                'pdfgeneration',
            ),
        );

        // --- Dépendances ---
        $this->depends   = array();
        $this->requiredby = array();

        // === Constantes module ===
        $this->const = array();
        $r = 0;

        // Format papier global
        $this->const[$r][0] = 'PDFBUILDER_PAPER_FORMAT';
        $this->const[$r][1] = 'chaine';
        $this->const[$r][2] = 'A4';
        $this->const[$r][3] = 'PDF-builder paper format';
        $this->const[$r][4] = 0;
        $this->const[$r][5] = 0;
        $r++;

        // Masquer descriptions longues (global)
        $this->const[$r][0] = 'PDFBUILDER_GLOBAL_HIDE_DESC';
        $this->const[$r][1] = 'yesno';
        $this->const[$r][2] = '0';
        $this->const[$r][3] = 'Hide long descriptions globally';
        $this->const[$r][4] = 0;
        $this->const[$r][5] = 0;
        $r++;

        // Masquer références (global)
        $this->const[$r][0] = 'PDFBUILDER_GLOBAL_HIDE_REF';
        $this->const[$r][1] = 'yesno';
        $this->const[$r][2] = '0';
        $this->const[$r][3] = 'Hide product references globally';
        $this->const[$r][4] = 0;
        $this->const[$r][5] = 0;
        $r++;

        // Afficher lignes TTC (global)
        $this->const[$r][0] = 'PDFBUILDER_GLOBAL_SHOW_LINE_TTC';
        $this->const[$r][1] = 'yesno';
        $this->const[$r][2] = '0';
        $this->const[$r][3] = 'Show line total TTC globally';
        $this->const[$r][4] = 0;
        $this->const[$r][5] = 0;
        $r++;

        // Masquer TVA colonne (global)
        $this->const[$r][0] = 'PDFBUILDER_GLOBAL_HIDE_VAT_COLUMN';
        $this->const[$r][1] = 'yesno';
        $this->const[$r][2] = '0';
        $this->const[$r][3] = 'Hide VAT column globally';
        $this->const[$r][4] = 0;
        $this->const[$r][5] = 0;
        $r++;

        // Masquer RIB (global)
        $this->const[$r][0] = 'PDFBUILDER_BANK_HIDE_NUMBER';
        $this->const[$r][1] = 'yesno';
        $this->const[$r][2] = '0';
        $this->const[$r][3] = 'Show only BIC/IBAN, hide account number';
        $this->const[$r][4] = 0;
        $this->const[$r][5] = 0;
        $r++;

        // --- Factures ---
        $this->const[$r][0] = 'PDFBUILDER_INVOICE_WITH_LINE_NUMBER';
        $this->const[$r][1] = 'yesno';
        $this->const[$r][2] = '0';
        $this->const[$r][3] = 'Show line numbers on invoices';
        $this->const[$r][4] = 0;
        $this->const[$r][5] = 0;
        $r++;

        $this->const[$r][0] = 'PDFBUILDER_INVOICE_WITH_PICTURE';
        $this->const[$r][1] = 'yesno';
        $this->const[$r][2] = '0';
        $this->const[$r][3] = 'Show product pictures on invoices';
        $this->const[$r][4] = 0;
        $this->const[$r][5] = 0;
        $r++;

        $this->const[$r][0] = 'PDFBUILDER_INVOICE_AUTO_LIQUIDATION';
        $this->const[$r][1] = 'yesno';
        $this->const[$r][2] = '0';
        $this->const[$r][3] = 'Show auto-liquidation mention on invoices';
        $this->const[$r][4] = 0;
        $this->const[$r][5] = 0;
        $r++;

        $this->const[$r][0] = 'PDFBUILDER_INVOICE_WITH_OUTSTANDING';
        $this->const[$r][1] = 'yesno';
        $this->const[$r][2] = '0';
        $this->const[$r][3] = 'Show outstanding bill on invoices';
        $this->const[$r][4] = 0;
        $this->const[$r][5] = 0;
        $r++;

        $this->const[$r][0] = 'PDFBUILDER_INVOICE_WITH_MERGED_PDF';
        $this->const[$r][1] = 'yesno';
        $this->const[$r][2] = '0';
        $this->const[$r][3] = 'Enable PDF merge for invoices';
        $this->const[$r][4] = 0;
        $this->const[$r][5] = 0;
        $r++;

        // --- Devis ---
        $this->const[$r][0] = 'PDFBUILDER_PROPAL_WITH_LINE_NUMBER';
        $this->const[$r][1] = 'yesno';
        $this->const[$r][2] = '0';
        $this->const[$r][3] = 'Show line numbers on proposals';
        $this->const[$r][4] = 0;
        $this->const[$r][5] = 0;
        $r++;

        $this->const[$r][0] = 'PDFBUILDER_PROPAL_WITH_PICTURE';
        $this->const[$r][1] = 'yesno';
        $this->const[$r][2] = '0';
        $this->const[$r][3] = 'Show product pictures on proposals';
        $this->const[$r][4] = 0;
        $this->const[$r][5] = 0;
        $r++;

        $this->const[$r][0] = 'PDFBUILDER_PROPAL_WITH_MERGED_PDF';
        $this->const[$r][1] = 'yesno';
        $this->const[$r][2] = '0';
        $this->const[$r][3] = 'Enable PDF merge for proposals';
        $this->const[$r][4] = 0;
        $this->const[$r][5] = 0;
        $r++;

        // --- Commandes ---
        $this->const[$r][0] = 'PDFBUILDER_ORDER_WITH_LINE_NUMBER';
        $this->const[$r][1] = 'yesno';
        $this->const[$r][2] = '0';
        $this->const[$r][3] = 'Show line numbers on orders';
        $this->const[$r][4] = 0;
        $this->const[$r][5] = 0;
        $r++;

        $this->const[$r][0] = 'PDFBUILDER_ORDER_WITH_PICTURE';
        $this->const[$r][1] = 'yesno';
        $this->const[$r][2] = '0';
        $this->const[$r][3] = 'Show product pictures on orders';
        $this->const[$r][4] = 0;
        $this->const[$r][5] = 0;
        $r++;

        // Version module
        $this->const[$r][0] = 'PDFBUILDER_VERSION';
        $this->const[$r][1] = 'chaine';
        $this->const[$r][2] = $this->version;
        $this->const[$r][3] = 'PDF-builder module version';
        $this->const[$r][4] = 0;
        $this->const[$r][5] = 'current';
        $this->const[$r][6] = 1;
        $r++;

        // === Droits ===
        $this->rights = array();
        $r = 0;

        $r++;
        $this->rights[$r][0] = 310201;
        $this->rights[$r][1] = 'Consulter les modèles PDF-builder';
        $this->rights[$r][2] = 'r';
        $this->rights[$r][3] = 1;
        $this->rights[$r][4] = 'read';

        $r++;
        $this->rights[$r][0] = 310202;
        $this->rights[$r][1] = 'Créer et modifier les modèles PDF-builder';
        $this->rights[$r][2] = 'w';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'write';

        $r++;
        $this->rights[$r][0] = 310203;
        $this->rights[$r][1] = 'Supprimer des modèles PDF-builder';
        $this->rights[$r][2] = 'd';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'delete';

        // === Menus ===
        $this->menus = array();

        // === Boxes ===
        $this->boxes = array();
    }

    /**
     * Fonction appelée lors de l'activation du module.
     * Crée les tables, enregistre les constantes, les droits,
     * et enregistre les modèles PDF dans llx_document_model.
     *
     * @param string $options Options d'activation
     * @return int 1 si OK, 0 si KO
     */
    public function init($options = '')
    {
        global $conf, $user;

        // Chargement des tables SQL
        $result = $this->load_tables();
        if ($result < 0) {
            return -1;
        }

        // Enregistrement des modèles PDF dans llx_document_model
        $sql = array();

        $models = array(
            array('pdfbuilder_invoice',          'invoice'),
            array('pdfbuilder_propal',           'propal'),
            array('pdfbuilder_order',            'order'),
            array('pdfbuilder_supplierinvoice',  'invoice_supplier'),
            array('pdfbuilder_supplierorder',    'order_supplier'),
            // array('pdfbuilder_supplierproposal', 'supplier_proposal'),
            // array('pdfbuilder_shipment',         'shipping'),
            // array('pdfbuilder_receipt',          'delivery'),
            // array('pdfbuilder_contract',         'contract'),
            array('pdfbuilder_fichinter',        'ficheinter'),
            // array('pdfbuilder_expensereport',    'expensereport'),
        );

        foreach ($models as $m) {
            $sql[] = "INSERT IGNORE INTO ".MAIN_DB_PREFIX."document_model (nom, entity, type) VALUES ('".$m[0]."', '".$conf->entity."', '".$m[1]."')";
        }

        // Vérifier et mettre à jour le schéma des tables
        $this->_check_tables();
        $this->_check_tables_layout();

        // Créer un thème par défaut si aucun n'existe
        $this->_createDefaultThemeIfNone($user, $conf->entity);

        return $this->_init($sql, $options);
    }

    /**
     * Fonction appelée lors de la désactivation du module.
     * Retire les modèles de llx_document_model.
     *
     * @param string $options Options de désactivation
     * @return int 1 si OK, 0 si KO
     */
    public function remove($options = '')
    {
        global $conf;

        $models = array(
            'pdfbuilder_invoice', 'pdfbuilder_propal', 'pdfbuilder_order',
            'pdfbuilder_supplierinvoice', 'pdfbuilder_supplierorder', 'pdfbuilder_supplierproposal',
            'pdfbuilder_shipment', 'pdfbuilder_receipt', 'pdfbuilder_contract',
            'pdfbuilder_fichinter', 'pdfbuilder_expensereport',
        );

        $noms = "'".implode("', '", $models)."'";
        $sql = array(
            "DELETE FROM ".MAIN_DB_PREFIX."document_model WHERE entity = '".$conf->entity."' AND nom IN (".$noms.")",
        );

        return $this->_remove($sql, $options);
    }

    /**
     * Charge les tables SQL depuis le dossier sql/
     * @return int <0 si erreur, >0 si OK
     */
    public function load_tables()
    {
        return $this->_load_tables('/pdfbuilder/sql/');
    }

    /**
     * Crée un thème par défaut pour chaque type de document si aucun n'existe
     * @param User $user Utilisateur courant
     * @param int $entity Entité
     */
    private function _createDefaultThemeIfNone($user, $entity)
    {
        if (!class_exists('PdfBuilderTheme')) {
            dol_include_once('/pdfbuilder/class/pdfbuilder.class.php');
        }

        $types = array(
            'invoice', 'propal', 'order', 'supplier_invoice',
            'supplier_order', 'supplier_proposal', 'shipment',
            'receipt', 'contract', 'fichinter', 'expensereport',
        );

        foreach ($types as $type) {
            $sql = "SELECT count(rowid) as nb FROM ".MAIN_DB_PREFIX."pdfbuilder_theme";
            $sql .= " WHERE entity = ".((int) $entity);
            $sql .= " AND doc_type = '".$this->db->escape($type)."'";
            $res = $this->db->query($sql);
            if ($res) {
                $obj = $this->db->fetch_object($res);
                if ($obj && $obj->nb == 0) {
                    $t              = new PdfBuilderTheme($this->db);
                    $t->entity      = $entity;
                    $t->label       = 'Thème par défaut';
                    $t->description = 'Thème créé automatiquement à l\'activation du module';
                    $t->doc_type    = $type;
                    $t->is_default  = 1;
                    $t->create($user);
                }
            }
        }
    }

    /**
     * Vérifie si des colonnes sont manquantes et les ajoute si nécessaire.
     * Cette fonction permet une mise à jour fluide sans intervention manuelle.
     */
    private function _check_tables()
    {
        // 1. logo_height
        $sql = "SHOW COLUMNS FROM ".MAIN_DB_PREFIX."pdfbuilder_theme LIKE 'logo_height'";
        $res = $this->db->query($sql);
        if ($res && $this->db->num_rows($res) == 0) {
            $sql = "ALTER TABLE ".MAIN_DB_PREFIX."pdfbuilder_theme ADD COLUMN logo_height DECIMAL(5,1) DEFAULT 18.0 AFTER hide_main_logo";
            $this->db->query($sql);
        }

        // 2. show_vat_breakdown (ajouté en phase précédente)
        $sql = "SHOW COLUMNS FROM ".MAIN_DB_PREFIX."pdfbuilder_theme LIKE 'show_vat_breakdown'";
        $res = $this->db->query($sql);
        if ($res && $this->db->num_rows($res) == 0) {
            $sql = "ALTER TABLE ".MAIN_DB_PREFIX."pdfbuilder_theme ADD COLUMN show_vat_breakdown TINYINT UNSIGNED DEFAULT 0 AFTER no_repeat_header";
            $this->db->query($sql);
        }

        // 3. header_spacing
        $sql = "SHOW COLUMNS FROM ".MAIN_DB_PREFIX."pdfbuilder_theme LIKE 'header_spacing'";
        $res = $this->db->query($sql);
        if ($res && $this->db->num_rows($res) == 0) {
            $sql = "ALTER TABLE ".MAIN_DB_PREFIX."pdfbuilder_theme ADD COLUMN header_spacing DECIMAL(4,1) DEFAULT 2.0 AFTER logo_height";
            $this->db->query($sql);
        }

        // 4. show_customer_code
        $sql = "SHOW COLUMNS FROM ".MAIN_DB_PREFIX."pdfbuilder_theme LIKE 'show_customer_code'";
        $res = $this->db->query($sql);
        if ($res && $this->db->num_rows($res) == 0) {
            $sql = "ALTER TABLE ".MAIN_DB_PREFIX."pdfbuilder_theme ADD COLUMN show_customer_code TINYINT UNSIGNED DEFAULT 0 AFTER header_spacing";
            $this->db->query($sql);
        }

        // 5. note_public
        $sql = "SHOW COLUMNS FROM ".MAIN_DB_PREFIX."pdfbuilder_theme LIKE 'note_public'";
        $res = $this->db->query($sql);
        if ($res && $this->db->num_rows($res) == 0) {
            $sql = "ALTER TABLE ".MAIN_DB_PREFIX."pdfbuilder_theme ADD COLUMN note_public TEXT AFTER freetext_fontsize";
            $this->db->query($sql);
        }

        // 6. Typography Columns
        $typoFields = array(
            'font_size_ref'     => "TINYINT UNSIGNED DEFAULT 9 AFTER font_size",
            'font_style_ref'    => "VARCHAR(10) DEFAULT '' AFTER font_size_ref",
            'font_size_address' => "TINYINT UNSIGNED DEFAULT 9 AFTER font_style_ref",
            'font_style_address'=> "VARCHAR(10) DEFAULT '' AFTER font_size_address",
            'font_size_theader' => "TINYINT UNSIGNED DEFAULT 8 AFTER font_style_address",
            'font_style_theader'=> "VARCHAR(10) DEFAULT 'B' AFTER font_size_theader",
            'font_size_desc'    => "TINYINT UNSIGNED DEFAULT 9 AFTER font_style_theader",
            'font_style_desc'   => "VARCHAR(10) DEFAULT '' AFTER font_size_desc",
            'font_size_note'    => "TINYINT UNSIGNED DEFAULT 8 AFTER font_style_desc",
            'font_style_note'   => "VARCHAR(10) DEFAULT '' AFTER font_size_note",
            'font_size_footer'  => "TINYINT UNSIGNED DEFAULT 7 AFTER font_style_note",
            'font_style_footer' => "VARCHAR(10) DEFAULT '' AFTER font_size_footer",
            'font_style_freetext' => "VARCHAR(10) DEFAULT '' AFTER font_style_footer",
            'font_size_sender'   => "TINYINT UNSIGNED DEFAULT 9 AFTER font_style_freetext",
            'font_style_sender'  => "VARCHAR(10) DEFAULT '' AFTER font_size_sender",
            'font_size_recipient'=> "TINYINT UNSIGNED DEFAULT 9 AFTER font_style_sender",
            'font_style_recipient'=> "VARCHAR(10) DEFAULT '' AFTER font_size_recipient",
            'hide_situation'      => "TINYINT UNSIGNED DEFAULT 0 AFTER font_style_recipient",
            'show_total_weight' => "TINYINT UNSIGNED DEFAULT 0 AFTER hide_situation",
            'address_block_width' => "DECIMAL(6,2) DEFAULT 93.0 AFTER show_total_weight",
            'color_font_sender'   => "VARCHAR(10) DEFAULT '' AFTER address_block_width",
            'color_font_recipient'=> "VARCHAR(10) DEFAULT '' AFTER color_font_sender",
        );

        foreach ($typoFields as $field => $def) {
            $sql = "SHOW COLUMNS FROM ".MAIN_DB_PREFIX."pdfbuilder_theme LIKE '".$this->db->escape($field)."'";
            $res = $this->db->query($sql);
            if ($res && $this->db->num_rows($res) == 0) {
                $sql_alter = "ALTER TABLE ".MAIN_DB_PREFIX."pdfbuilder_theme ADD COLUMN ".$field." ".$def;
                $this->db->query($sql_alter);
                dol_syslog("modPdfBuilder::_check_tables added column ".$field, LOG_DEBUG);

                // Pour les nouveaux champs de police, initialiser avec la valeur de font_size_address si possible
                if (in_array($field, array('font_size_sender', 'font_size_recipient'))) {
                    $sql_mig = "UPDATE ".MAIN_DB_PREFIX."pdfbuilder_theme SET ".$field." = font_size_address WHERE ".$field." IS NULL OR ".$field." = 0";
                    $this->db->query($sql_mig);
                }
                if (in_array($field, array('font_style_sender', 'font_style_recipient'))) {
                    $sql_mig = "UPDATE ".MAIN_DB_PREFIX."pdfbuilder_theme SET ".$field." = font_style_address WHERE ".$field." IS NULL OR ".$field." = ''";
                    $this->db->query($sql_mig);
                }
                if (in_array($field, array('color_font_sender', 'color_font_recipient'))) {
                    $sql_mig = "UPDATE ".MAIN_DB_PREFIX."pdfbuilder_theme SET ".$field." = color_font WHERE ".$field." IS NULL OR ".$field." = ''";
                    $this->db->query($sql_mig);
                }
            }
        }
    }

    /**
     * Crée les tables layout/zone si elles n'existent pas encore.
     * Permet une mise à jour fluide des installations existantes du module.
     */
    private function _check_tables_layout()
    {
        // Table llx_pdfbuilder_layout
        $sql = "SHOW TABLES LIKE '".MAIN_DB_PREFIX."pdfbuilder_layout'";
        $res = $this->db->query($sql);
        if ($res && $this->db->num_rows($res) == 0) {
            $this->db->query("CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."pdfbuilder_layout (
                rowid         INTEGER      NOT NULL AUTO_INCREMENT,
                entity        INTEGER      NOT NULL DEFAULT 1,
                label         VARCHAR(100) NOT NULL,
                description   TEXT,
                doc_type      VARCHAR(50)  NOT NULL DEFAULT 'invoice',
                paper_format  VARCHAR(20)  DEFAULT 'A4',
                margin_top    DECIMAL(4,1) DEFAULT 10.0,
                margin_left   DECIMAL(4,1) DEFAULT 11.0,
                margin_right  DECIMAL(4,1) DEFAULT 10.0,
                margin_bottom DECIMAL(4,1) DEFAULT 10.0,
                is_default    TINYINT      DEFAULT 0,
                active        TINYINT      DEFAULT 1,
                fk_theme      INTEGER      DEFAULT NULL,
                date_creation DATETIME,
                tms           TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                fk_user_creat INTEGER,
                fk_user_modif INTEGER,
                PRIMARY KEY (rowid),
                KEY idx_pdfbuilder_layout_entity (entity),
                KEY idx_pdfbuilder_layout_doc_type (doc_type)
            ) ENGINE=InnoDB");
            dol_syslog("modPdfBuilder::_check_tables_layout created table pdfbuilder_layout", LOG_DEBUG);
        }

        // Migration : colonne params sur llx_pdfbuilder_layout (ajoutée en phase 5)
        $sql = "SHOW COLUMNS FROM ".MAIN_DB_PREFIX."pdfbuilder_layout LIKE 'params'";
        $res = $this->db->query($sql);
        if ($res && $this->db->num_rows($res) == 0) {
            $this->db->query("ALTER TABLE ".MAIN_DB_PREFIX."pdfbuilder_layout ADD COLUMN params TEXT AFTER margin_bottom");
            dol_syslog("modPdfBuilder::_check_tables_layout added params column to pdfbuilder_layout", LOG_DEBUG);
        }

        // Table llx_pdfbuilder_zone
        $sql = "SHOW TABLES LIKE '".MAIN_DB_PREFIX."pdfbuilder_zone'";
        $res = $this->db->query($sql);
        if ($res && $this->db->num_rows($res) == 0) {
            $this->db->query("CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."pdfbuilder_zone (
                rowid        INTEGER      NOT NULL AUTO_INCREMENT,
                fk_layout    INTEGER      NOT NULL,
                zone_type    VARCHAR(50)  NOT NULL,
                page_context VARCHAR(10)  DEFAULT 'body',
                pos_x        DECIMAL(5,2) DEFAULT 0,
                pos_y        DECIMAL(5,2) DEFAULT 0,
                width        DECIMAL(5,2) DEFAULT 50,
                height       DECIMAL(5,2) DEFAULT 10,
                z_index      TINYINT      DEFAULT 0,
                params       TEXT,
                label        VARCHAR(100),
                active       TINYINT      DEFAULT 1,
                sort_order   SMALLINT     DEFAULT 0,
                PRIMARY KEY (rowid),
                KEY idx_pdfbuilder_zone_layout (fk_layout),
                KEY idx_pdfbuilder_zone_type (zone_type)
            ) ENGINE=InnoDB");
            dol_syslog("modPdfBuilder::_check_tables_layout created table pdfbuilder_zone", LOG_DEBUG);
        }
    }
}
