<?php
/* Copyright (C) 2024 Antigravity Project - GPL v2 or later */

/**
 * \file       pdfbuilder/class/pdfbuilder_preview_factory.class.php
 * \ingroup    pdfbuilder
 * \brief      Fabrique un objet Facture fictif pour la prévisualisation du designer
 */

/**
 * Class PdfBuilderPreviewFactory
 * Génère des objets Dolibarr fictifs utilisables par PdfBuilderRenderer
 * sans nécessiter de données réelles en base.
 */
class PdfBuilderPreviewFactory
{
    /** @var DoliDB */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Crée un objet facture fictif complet
     * @return stdClass Objet simulant Facture Dolibarr
     */
    public function makeInvoice()
    {
        $obj = new stdClass();
        $obj->id              = 0;
        $obj->table_element   = 'facture';
        $obj->ref             = 'FAC-PREVIEW-001';
        $obj->date            = dol_now();
        $obj->date_echeance   = dol_now() + 30 * 86400;
        $obj->date_lim_reglement = dol_now() + 30 * 86400;
        $obj->label           = 'Facture de démonstration PDF-builder';
        $obj->titre           = $obj->label;
        global $conf;
        $obj->multicurrency_code = !empty($conf->currency) ? $conf->currency : 'EUR';
        $obj->note_public     = "Conditions de règlement : 30 jours net\nMerci de votre confiance.";
        $obj->total_ht        = 1250.00;
        $obj->total_tva       = 250.00;
        $obj->total_ttc       = 1500.00;
        $obj->multicurrency_code = '';
        $obj->entity          = 1;
        $obj->fk_account      = 1; // Simulation d'un compte bancaire sélectionné
        $obj->mode_reglement_code = 'LCR'; // Permet de tester la mention LCR si activée

        // Tiers fictif
        $tp = new stdClass();
        $tp->name    = 'Société Exemple SARL';
        $tp->address = '42 Rue de la République';
        $tp->zip     = '75001';
        $tp->town    = 'Paris';
        $tp->country = 'France';
        $tp->country_code = 'FR';
        $tp->phone   = '01 23 45 67 89';
        $tp->email   = 'contact@exemple.fr';
        $tp->code_client = 'CUS-PREVIEW-123';
        $tp->tva_intra   = 'FR 12 345678901';
        $tp->siret       = '123 456 789 00012';
        $tp->url         = 'https://www.exemple.fr';
        $tp->idprof1     = '123456789'; // SIREN
        $tp->idprof2     = '12345678900012'; // SIRET
        $tp->outstanding_limit = 5000.00;
        $obj->thirdparty = $tp;


        // Lignes de produits fictives
        $obj->lines = array(
            $this->_makeLine('PROD-001', 'Prestation de conseil stratégique et accompagnement à la transformation digitale sur une durée de 5 jours incluant le diagnostic complet de l\'infrastructure existante.', 5, 150.00, 20, 750.00),
            $this->_makeLine('PROD-002', 'Développement logiciel', 2, 200.00, 20, 400.00),
            $this->_makeLine('PROD-003', 'Support et maintenance', 1, 100.00, 20, 100.00),
        );

        return $obj;
    }

    /**
     * Crée un objet devis fictif
     * @return stdClass
     */
    public function makePropal()
    {
        $obj = $this->makeInvoice();
        $obj->table_element = 'propal';
        $obj->ref   = 'DEVIS-PREVIEW-001';
        $obj->label = 'Devis de démonstration PDF-builder';
        $obj->titre = $obj->label;
        return $obj;
    }

    /**
     * Crée un objet commande fictif
     * @return stdClass
     */
    public function makeOrder()
    {
        $obj = $this->makeInvoice();
        $obj->table_element = 'commande';
        $obj->ref   = 'CMD-PREVIEW-001';
        $obj->label = 'Commande de démonstration PDF-builder';
        $obj->titre = $obj->label;
        return $obj;
    }

    /**
     * Retourne l'objet fictif correspondant au doc_type du layout
     * @param string $doc_type
     * @return stdClass
     */
    public function makeForDocType($doc_type)
    {
        switch ($doc_type) {
            case 'propal':
                return $this->makePropal();
            case 'order':
            case 'supplier_order':
                return $this->makeOrder();
            case 'invoice':
            case 'supplier_invoice':
            default:
                return $this->makeInvoice();
        }
    }

    /**
     * Crée une ligne de produit fictive
     */
    private function _makeLine($ref, $desc, $qty, $pu, $vatTx, $totalHt)
    {
        $line = new stdClass();
        $line->product_ref   = $ref;
        $line->product_label = $desc;
        $line->desc          = $desc;
        $line->qty           = $qty;
        $line->subprice      = $pu;
        $line->tva_tx        = $vatTx;
        $line->total_ht      = $totalHt;
        $line->total_tva     = round($totalHt * $vatTx / 100, 2);
        $line->total_ttc     = $totalHt + $line->total_tva;
        return $line;
    }
}
