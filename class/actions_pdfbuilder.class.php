<?php
/* Copyright (C) 2024 Antigravity Project - GPL v2 or later */

/**
 * \file       pdfbuilder/class/actions_pdfbuilder.class.php
 * \ingroup    pdfbuilder
 * \brief      Classe de hooks du module pdfbuilder
 *
 * Hook principal : getListOfModels
 * Injecte dynamiquement les layouts graphiques créés dans l'éditeur
 * comme entrées distinctes dans les menus de sélection de modèle PDF.
 *
 * Syntaxe de l'entrée injectée : 'pdfbuilder_invoice:42'
 * → Dolibarr appelle pdf_pdfbuilder_invoice::write_file() avec $srctemplatepath = '42'
 * → write_file charge PdfBuilderLayout(42) et utilise PdfBuilderRenderer
 */

/**
 * Class ActionsPdfbuilder
 */
class ActionsPdfbuilder
{
    /** @var string Dernière erreur */
    public $error = '';

    /**
     * Injecte les layouts pdfbuilder dans les menus de sélection de modèle PDF.
     *
     * Appelé par getListOfModels() (core/lib/functions2.lib.php) via hookmanager.
     * $parameters['list'] est une référence vers $docmodels : les entrées ajoutées
     * apparaissent immédiatement dans le select de génération de document.
     *
     * @param array  $parameters Paramètres du hook (list, type, maxfilenamelength)
     * @param object $object     Objet courant (non utilisé ici)
     * @param string $action     Action courante (non utilisée ici)
     * @return int 0 = OK, ne remplace pas le code standard
     */
    public function getListOfModels($parameters, &$object, &$action)
    {
        global $db, $conf;

        // Correspondance type llx_document_model → doc_type llx_pdfbuilder_layout + classe PDF
        static $typeMap = array(
            'invoice'           => array('doc_type' => 'invoice',          'base' => 'pdfbuilder_invoice'),
            'propal'            => array('doc_type' => 'propal',           'base' => 'pdfbuilder_propal'),
            'order'             => array('doc_type' => 'order',            'base' => 'pdfbuilder_order'),
            'invoice_supplier'  => array('doc_type' => 'invoice_supplier', 'base' => 'pdfbuilder_supplierinvoice'),
            'order_supplier'    => array('doc_type' => 'order_supplier',   'base' => 'pdfbuilder_supplierorder'),
            'ficheinter'        => array('doc_type' => 'ficheinter',       'base' => 'pdfbuilder_fichinter'),
        );

        if (empty($parameters['type']) || !isset($typeMap[$parameters['type']])) {
            return 0;
        }

        $docType  = $typeMap[$parameters['type']]['doc_type'];
        $baseNom  = $typeMap[$parameters['type']]['base'];

        $sql  = "SELECT rowid, label, is_default FROM ".MAIN_DB_PREFIX."pdfbuilder_layout";
        $sql .= " WHERE entity = ".((int) $conf->entity);
        $sql .= " AND doc_type = '".$db->escape($docType)."'";
        $sql .= " AND active = 1";
        $sql .= " ORDER BY is_default DESC, label ASC";

        $res = $db->query($sql);
        if (!$res) {
            return 0;
        }

        while ($obj = $db->fetch_object($res)) {
            // Clé : 'pdfbuilder_invoice:42'  → Dolibarr résout la classe pdf_pdfbuilder_invoice
            //                                   et passe '42' comme $srctemplatepath
            $key = $baseNom.':'.$obj->rowid;
            $label = $obj->label;
            if ($obj->is_default) {
                $label .= ' [défaut]';
            }
            $parameters['list'][$key] = $label;
        }

        return 0;
    }
}
