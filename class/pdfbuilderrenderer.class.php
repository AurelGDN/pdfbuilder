<?php
/* Copyright (C) 2024 Antigravity Project - GPL v2 or later */

/**
 * \file       pdfbuilder/class/pdfbuilderrenderer.class.php
 * \ingroup    pdfbuilder
 * \brief      Moteur de rendu TCPDF par zones positionnables
 */

dol_include_once('/pdfbuilder/class/pdfbuilderlayout.class.php');
dol_include_once('/pdfbuilder/class/pdfbuilderzone.class.php');
dol_include_once('/pdfbuilder/lib/pdfbuilder.lib.php');

/**
 * Class PdfBuilderRenderer
 * Lit un PdfBuilderLayout + ses PdfBuilderZone[] et génère le PDF TCPDF.
 */
class PdfBuilderRenderer
{
    /** @var DoliDB */
    private $db;

    /** @var PdfBuilderLayout */
    private $layout;

    /** @var PdfBuilderZone[] Zones triées par sort_order */
    private $zones = array();

    /** @var float Largeur de page utile en mm */
    private $pageW;

    /** @var float Hauteur de page en mm */
    private $pageH;

    /** @var float Y absolu (depuis le haut de page) après la dernière ligne du tableau */
    private $tableEndY    = 0.0;

    /** @var int Numéro de page TCPDF après la dernière ligne du tableau */
    private $tableEndPage = 1;

    /** @var bool Vrai si renderTableLines() a été exécuté */
    private $tableRendered = false;

    /** @var float Décalage Y additionnel appliqué dans _zoneCoords() pour les zones post-table */
    private $_yOffset = 0.0;

    /**
     * @param DoliDB           $db     Handler BDD
     * @param PdfBuilderLayout $layout Layout chargé
     */
    public function __construct($db, $layout)
    {
        $this->db     = $db;
        $this->layout = $layout;

        // Charger les zones
        $zones = PdfBuilderZone::fetchByLayout($db, $layout->id);
        $this->zones = is_array($zones) ? $zones : array();

        // Dimensions de page
        $fmt = strtoupper($layout->paper_format ?: 'A4');
        if ($fmt === 'A3') {
            $this->pageW = 297;
            $this->pageH = 420;
        } elseif ($fmt === 'LETTER') {
            $this->pageW = 215.9;
            $this->pageH = 279.4;
        } else {
            $this->pageW = 210;
            $this->pageH = 297;
        }
    }

    /**
     * Point d'entrée : génère le PDF en positionnant chaque zone.
     * Les zones de type 'page_footer' sont rendues sur toutes les pages après le corps.
     *
     * @param TCPDF     $pdf         Instance TCPDF initialisée (après AddPage)
     * @param object    $object      Objet Dolibarr (Facture, Propal, etc.)
     * @param Translate $outputlangs Langue de sortie
     * @return int 1 si OK, <0 si erreur
     */
    public function render($pdf, $object, $outputlangs)
    {
        if (is_object($outputlangs)) {
            $outputlangs->loadLangs(array('main', 'companies', 'bills', 'propale', 'orders', 'products', 'pdfbuilder@pdfbuilder', 'dict'));
        }

        dol_syslog('PdfBuilderRenderer::render layout='.$this->layout->id.' zones='.count($this->zones), LOG_DEBUG);

        // Fond de page depuis layout.params (page courante = page 1)
        $this->_applyLayoutBackground($pdf);

        $bodyZones   = array();
        $footerZones = array();
        foreach ($this->zones as $zone) {
            if (!$zone->active) {
                continue;
            }
            if ($zone->zone_type === 'page_footer') {
                $footerZones[] = $zone;
            } else {
                $bodyZones[] = $zone;
            }
        }

        // Identifier la zone table_lines (au plus une)
        $tableZone = null;
        foreach ($bodyZones as $zone) {
            if ($zone->zone_type === 'table_lines') {
                $tableZone = $zone;
                break;
            }
        }

        if ($tableZone === null) {
            // Pas de tableau : rendu classique
            foreach ($bodyZones as $zone) {
                try {
                    $this->renderZone($pdf, $zone, $object, $outputlangs);
                } catch (Exception $e) {
                    dol_syslog('PdfBuilderRenderer::renderZone error zone='.$zone->id.' type='.$zone->zone_type.' '.$e->getMessage(), LOG_WARNING);
                }
            }
        } else {
            $mt = (float) ($this->layout->margin_top ?? 10);
            // Limite basse du tableau telle que définie dans le layout (en mm relatif à la zone de contenu)
            $tableZoneBottomRel = (float) $tableZone->pos_y + (float) $tableZone->height;

            // ── Phase 1 : zones pré-table (y compris le tableau lui-même) ──
            foreach ($bodyZones as $zone) {
                // Ignorer les zones dont le bord supérieur est au niveau ou en dessous du bas du tableau
                if ($zone->zone_type !== 'table_lines' && (float) $zone->pos_y >= $tableZoneBottomRel - 0.5) {
                    continue;
                }
                try {
                    $this->renderZone($pdf, $zone, $object, $outputlangs);
                } catch (Exception $e) {
                    dol_syslog('PdfBuilderRenderer::renderZone error zone='.$zone->id.' type='.$zone->zone_type.' '.$e->getMessage(), LOG_WARNING);
                }
            }

            // ── Phase 2 : zones post-table (sous le bas du tableau dans le layout) ──
            if ($this->tableRendered) {
                $pdf->setPage($this->tableEndPage);

                $tableZoneBottomAbs = $mt + $tableZoneBottomRel;
                // Décalage dû au débordement du tableau (0 si le tableau tient dans sa zone)
                $delta = max(0.0, $this->tableEndY - $tableZoneBottomAbs);
                $this->_yOffset = $delta;

                $marginBottom = (float) ($this->layout->margin_bottom ?? 10);
                $pageMaxY     = $this->pageH - $marginBottom;

                // Trier les zones post-table par pos_y croissant
                $postZones = array();
                foreach ($bodyZones as $zone) {
                    if ($zone->zone_type !== 'table_lines'
                        && $zone->zone_type !== 'page_footer'
                        && (float) $zone->pos_y >= $tableZoneBottomRel - 0.5) {
                        $postZones[] = $zone;
                    }
                }
                usort($postZones, function ($a, $b) {
                    return ((float) $a->pos_y < (float) $b->pos_y) ? -1 : 1;
                });

                // Rendre chaque groupe de zones au même Y, avec gestion de saut de page
                $lastGroupPosY = null;
                foreach ($postZones as $zone) {
                    $posY = (float) $zone->pos_y;

                    // Nouveau groupe Y ?
                    if ($lastGroupPosY === null || abs($posY - $lastGroupPosY) > 0.5) {
                        $absY = $mt + $posY + $this->_yOffset;
                        // Si la zone déborde de la page, ajouter une nouvelle page
                        if ($absY + (float) $zone->height > $pageMaxY) {
                            $pdf->AddPage('P', array($this->pageW, $this->pageH));
                            // Recalculer l'offset pour que ce groupe démarre à margin_top
                            $this->_yOffset = $mt - $mt - $posY; // = -posY
                        }
                        $lastGroupPosY = $posY;
                    }

                    try {
                        $this->renderZone($pdf, $zone, $object, $outputlangs);
                    } catch (Exception $e) {
                        dol_syslog('PdfBuilderRenderer::renderZone error zone='.$zone->id.' type='.$zone->zone_type.' '.$e->getMessage(), LOG_WARNING);
                    }
                }

                $this->_yOffset = 0.0; // Reset
            }
        }

        // ── Rendu des zones pied de page sur chaque page ──
        if (!empty($footerZones)) {
            $numPages    = $pdf->getNumPages();
            $currentPage = $pdf->getPage();
            for ($p = 1; $p <= $numPages; $p++) {
                $pdf->setPage($p);
                foreach ($footerZones as $zone) {
                    try {
                        $this->renderPageFooter($pdf, $zone, $outputlangs, $p, $numPages);
                    } catch (Exception $e) {
                        dol_syslog('PdfBuilderRenderer::renderPageFooter error zone='.$zone->id.' '.$e->getMessage(), LOG_WARNING);
                    }
                }
            }
            $pdf->setPage($currentPage);
        }

        // Pli de correspondance (marque de pliage sur la page 1)
        $lp = $this->_getLayoutParams();
        if (!empty($lp['show_fold_mark'])) {
            $lastPage = $pdf->getNumPages();
            $pdf->setPage(1);
            $pdf->SetDrawColor(150, 150, 150);
            $pdf->SetLineStyle(array('width' => 0.3, 'dash' => '3,2', 'cap' => 'butt'));
            $pdf->Line(0, round($this->pageH / 3, 1), 5, round($this->pageH / 3, 1));
            $pdf->SetLineStyle(array('dash' => 0, 'width' => 0.2));
            $pdf->SetDrawColor(0, 0, 0);
            $pdf->setPage($lastPage); // Restaurer la dernière page
        }

        // ── Rendu des mentions spéciales du layout (Auto-liquidation, LCR) ──
        $this->_renderLayoutSpecialMentions($pdf, $object, $outputlangs);

        return 1;
    }

    /**
     * Rendu des mentions spéciales héritées du layout (Auto-liquidation TVA, LCR)
     * s'ajoute à la fin du contenu body (position Y actuelle du PDF).
     */
    private function _renderLayoutSpecialMentions(&$pdf, $object, $outputlangs)
    {
        global $conf;
        
        $lp = $this->_getLayoutParams();
        $ml = (float) ($this->layout->margin_left ?? 11);
        $mr = (float) ($this->layout->margin_right ?? 10);
        $pageW = $this->pageW;
        
        // --- Mention auto-liquidation TVA ---
        if (!empty($lp['auto_liquidation']) || getDolGlobalString('PDFBUILDER_INVOICE_AUTO_LIQUIDATION')) {
            $pdf->SetXY($ml, $pdf->GetY() + 2);
            $pdf->SetFont('DejaVuSans', 'I', 7);
            $pdf->MultiCell($pageW - $ml - $mr, 4, $outputlangs->transnoentities('PDFBuilderAutoLiquidationMsg'), 0, 'L', false);
        }
        
        // --- Mention LCR (règlement par lettre de change) ---
        if (!empty($lp['show_lcr']) && !empty($object->mode_reglement_code) && $object->mode_reglement_code === 'LCR') {
            $pdf->SetXY($ml, $pdf->GetY() + 2);
            $pdf->SetFont('DejaVuSans', 'IB', 7);
            $pdf->MultiCell($pageW - $ml - $mr, 4, $outputlangs->transnoentities('PdfBuilderLCRMention'), 0, 'L', false);
        }
    }

    /**
     * Retourne les params du layout sous forme de tableau (avec valeurs par défaut vides)
     * @return array
     */
    private function _getLayoutParams()
    {
        return $this->layout->getParamsAsArray();
    }

    /**
     * Applique le fond de page depuis layout.params (bg_image) sur la page courante TCPDF
     * @param TCPDF $pdf
     */
    private function _applyLayoutBackground($pdf)
    {
        $lp = $this->_getLayoutParams();
        if (!empty($lp['bg_image']) && file_exists($lp['bg_image'])) {
            $opacity = isset($lp['bg_opacity']) ? (float) $lp['bg_opacity'] : 0.10;
            if (method_exists($pdf, 'SetAlpha')) {
                $pdf->SetAlpha($opacity);
            }
            $pdf->Image($lp['bg_image'], 0, 0, $this->pageW, $this->pageH, '', '', '', false, 300);
            if (method_exists($pdf, 'SetAlpha')) {
                $pdf->SetAlpha(1);
            }
        }
    }

    /**
     * Calcule les coordonnées absolues (en mm) d'une zone sur la page
     * @param PdfBuilderZone $zone
     * @return array [x, y, w, h]
     */
    private function _zoneCoords($zone)
    {
        $ml = (float) ($this->layout->margin_left ?? 11);
        $mt = (float) ($this->layout->margin_top  ?? 10);
        return array(
            'x' => $ml + (float) $zone->pos_x,
            'y' => $mt + (float) $zone->pos_y + $this->_yOffset,
            'w' => (float) $zone->width,
            'h' => (float) $zone->height,
        );
    }

    /**
     * Dispatche une zone vers son renderer spécialisé
     */
    private function renderZone($pdf, $zone, $object, $outputlangs)
    {
        switch ($zone->zone_type) {
            case 'logo_main':
                $this->renderLogoMain($pdf, $zone, $object);
                break;
            case 'logo_alt':
                $this->renderLogoAlt($pdf, $zone);
                break;
            case 'address_sender':
                $this->renderAddressSender($pdf, $zone, $object, $outputlangs);
                break;
            case 'address_recipient':
                $this->renderAddressRecipient($pdf, $zone, $object, $outputlangs);
                break;
            case 'field_ref':
                $this->renderField($pdf, $zone, $object, $outputlangs, 'ref');
                break;
            case 'field_date':
                $this->renderField($pdf, $zone, $object, $outputlangs, 'date');
                break;
            case 'field_duedate':
                $this->renderField($pdf, $zone, $object, $outputlangs, 'date_echeance');
                break;
            case 'field_object':
                $this->renderField($pdf, $zone, $object, $outputlangs, 'object');
                break;
            case 'field_recipient_vat':
                $this->renderField($pdf, $zone, $object, $outputlangs, 'recipient_vat');
                break;
            case 'field_customer_code':
                $this->renderField($pdf, $zone, $object, $outputlangs, 'customer_code');
                break;
            case 'field_company_ids':
                $this->renderProfessionalIds($pdf, $zone, $outputlangs);
                break;
            case 'field_web':
                $this->renderField($pdf, $zone, $object, $outputlangs, 'web');
                break;
            case 'table_lines':
                $this->renderTableLines($pdf, $zone, $object, $outputlangs);
                break;
            case 'table_totals':
                $this->renderTableTotals($pdf, $zone, $object, $outputlangs);
                break;
            case 'table_vat_breakdown':
                $this->renderVatBreakdown($pdf, $zone, $object, $outputlangs);
                break;
            case 'rib_block':
                $this->renderRib($pdf, $zone, $object, $outputlangs);
                break;
            case 'qrcode':
                $this->renderQrCode($pdf, $zone, $object);
                break;
            case 'text_static':
                $this->renderTextStatic($pdf, $zone);
                break;
            case 'text_freetext':
                $this->renderFreetext($pdf, $zone, $object);
                break;
            case 'image_bg':
                $this->renderImageBg($pdf, $zone);
                break;
            case 'separator':
                $this->renderSeparator($pdf, $zone);
                break;
            case 'watermark':
                $this->renderWatermark($pdf, $zone, $outputlangs);
                break;
            case 'signature_block':
                $this->renderSignatureBlock($pdf, $zone, $outputlangs);
                break;
            case 'outstanding':
                $this->renderOutstanding($pdf, $zone, $object, $outputlangs);
                break;
            case 'page_footer':
                // Rendu différé dans render() sur chaque page — ignoré ici
                break;
            case 'document_type':
                $this->renderDocumentType($pdf, $zone, $object, $outputlangs);
                break;
            default:
                dol_syslog('PdfBuilderRenderer: unknown zone_type '.$zone->zone_type, LOG_DEBUG);
        }
    }

    // =========================================================
    // RENDERERS PAR TYPE
    // =========================================================

    private function renderLogoMain($pdf, $zone, $object)
    {
        global $mysoc, $conf;
        $c = $this->_zoneCoords($zone);
        $params = $zone->getParamsAsArray();

        $logo = '';
        if (!empty($mysoc->logo) && file_exists($conf->mycompany->dir_output.'/logos/'.$mysoc->logo)) {
            $logo = $conf->mycompany->dir_output.'/logos/'.$mysoc->logo;
        } elseif (!empty($conf->global->MAIN_INFO_SOCIETE_LOGO) && file_exists($conf->mycompany->dir_output.'/logos/'.$conf->global->MAIN_INFO_SOCIETE_LOGO)) {
            $logo = $conf->mycompany->dir_output.'/logos/'.$conf->global->MAIN_INFO_SOCIETE_LOGO;
        }

        if ($logo) {
            $pdf->Image($logo, $c['x'], $c['y'], $c['w'], $c['h'], '', '', '', false, 300);
        }
    }

    private function renderLogoAlt($pdf, $zone)
    {
        global $conf;
        $c = $this->_zoneCoords($zone);
        $params = $zone->getParamsAsArray();
        $path = !empty($params['src']) ? $params['src'] : '';
        
        if ($path) {
            $fullpath = $path;
            // Résolution du chemin si relatif aux documents
            if (!file_exists($fullpath)) {
                $fullpath = DOL_DATA_ROOT . '/' . $path;
            }
            
            if (file_exists($fullpath) && !is_dir($fullpath)) {
                $pdf->Image($fullpath, $c['x'], $c['y'], $c['w'], $c['h'], '', '', '', false, 300);
            }
        }
    }

    private function renderAddressSender($pdf, $zone, $object, $outputlangs)
    {
        global $mysoc;
        $c = $this->_zoneCoords($zone);
        $params = $zone->getParamsAsArray();

        $this->_applyZoneFont($pdf, $params);
        $this->_applyZoneBg($pdf, $params, $c);
        $this->_applyZoneBorder($pdf, $params, $c);

        $lines = array();
        $lines[] = $mysoc->name;
        if ($mysoc->address) {
            $lines[] = $mysoc->address;
        }
        if ($mysoc->zip || $mysoc->town) {
            $lines[] = trim($mysoc->zip.' '.$mysoc->town);
        }
        if ($mysoc->country && empty($params['hide_country'])) {
            $lines[] = $mysoc->country;
        }
        if ($mysoc->phone) {
            $lines[] = $outputlangs->transnoentities('Phone').': '.$mysoc->phone;
        }
        if ($mysoc->email) {
            $lines[] = $mysoc->email;
        }

        // Identifiants pro de la société
        if (!empty($params['show_profids'])) {
            $cc = $mysoc->country_code;
            if ($mysoc->idprof1) $lines[] = $this->_getProfIdLabel($outputlangs, 1, $cc).': '.$mysoc->idprof1;
            if ($mysoc->idprof2) $lines[] = $this->_getProfIdLabel($outputlangs, 2, $cc).': '.$mysoc->idprof2;
            if ($mysoc->tva_intra) $lines[] = $this->_getVATIntraLabel($outputlangs, $cc).': '.$mysoc->tva_intra;
        }

        $text = implode("\n", array_filter($lines));
        $align = !empty($params['align']) ? strtoupper($params['align'][0]) : 'L';
        $padding = $this->_getPadding($params);
        $pdf->SetXY($c['x'] + $padding['left'], $c['y'] + $padding['top']);
        $pdf->MultiCell($c['w'] - $padding['left'] - $padding['right'], $c['h'] - $padding['top'] - $padding['bottom'], $outputlangs->convToOutputCharset($text), 0, $align, false, 1, null, null, true);
    }

    private function renderAddressRecipient($pdf, $zone, $object, $outputlangs)
    {
        $c = $this->_zoneCoords($zone);
        $params = $zone->getParamsAsArray();

        $this->_applyZoneFont($pdf, $params);
        $this->_applyZoneBg($pdf, $params, $c);
        $this->_applyZoneBorder($pdf, $params, $c);

        $lp = $this->_getLayoutParams();
        $lines = array();
        if (!empty($object->thirdparty)) {
            $tp = $object->thirdparty;
            $lines[] = $tp->name;
            if ($tp->address) {
                $lines[] = $tp->address;
            }
            if ($tp->zip || $tp->town) {
                $lines[] = trim($tp->zip.' '.$tp->town);
            }
            if ($tp->country && empty($params['hide_country'])) {
                $lines[] = $tp->country;
            }
            // add_client_details : ajoute tél/email de la société cliente
            if (!empty($lp['add_client_details'])) {
                if ($tp->phone) {
                    $lines[] = $outputlangs->transnoentities('Phone').': '.$tp->phone;
                }
                if ($tp->email) {
                    $lines[] = $tp->email;
                }
            }

            // Identifiants pro du tiers
            if (!empty($params['show_profids'])) {
                $cc = $tp->country_code;
                if ($tp->idprof1) $lines[] = $this->_getProfIdLabel($outputlangs, 1, $cc).': '.$tp->idprof1;
                if ($tp->idprof2) $lines[] = $this->_getProfIdLabel($outputlangs, 2, $cc).': '.$tp->idprof2;
                if ($tp->tva_intra) $lines[] = $this->_getVATIntraLabel($outputlangs, $cc).': '.$tp->tva_intra;
            }
        }
        // add_target_details : ajoute tél/email du contact lié au document
        if (!empty($lp['add_target_details']) && !empty($object->contact_id)) {
            require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
            $contact = new Contact($this->db);
            if ($contact->fetch((int) $object->contact_id) > 0) {
                if ($contact->phone_pro) {
                    $lines[] = $outputlangs->transnoentities('Phone').': '.$contact->phone_pro;
                }
                if ($contact->email) {
                    $lines[] = $contact->email;
                }
            }
        }

        $text = implode("\n", array_filter($lines));
        $align = !empty($params['align']) ? strtoupper($params['align'][0]) : 'L';
        $padding = $this->_getPadding($params);
        $pdf->SetXY($c['x'] + $padding['left'], $c['y'] + $padding['top']);
        $pdf->MultiCell($c['w'] - $padding['left'] - $padding['right'], $c['h'] - $padding['top'] - $padding['bottom'], $outputlangs->convToOutputCharset($text), 0, $align, false, 1, null, null, true);
    }

    private function renderField($pdf, $zone, $object, $outputlangs, $field)
    {
        global $mysoc;
        $c = $this->_zoneCoords($zone);
        $params = $zone->getParamsAsArray();

        $this->_applyZoneFont($pdf, $params);
        $this->_applyZoneBg($pdf, $params, $c);
        $this->_applyZoneBorder($pdf, $params, $c);

        $label = '';
        $value = '';

        switch ($field) {
            case 'ref':
                $label = '';
                $value = $object->ref;
                break;
            case 'date':
                $label = $outputlangs->transnoentities('Date').': ';
                $value = dol_print_date($object->date, 'day', false, $outputlangs);
                break;
            case 'date_echeance':
                // date_lim_reglement = champ Facture Dolibarr ; date_echeance = alias autres doc types
                $dueDate = !empty($object->date_lim_reglement) ? $object->date_lim_reglement
                         : (!empty($object->date_echeance)     ? $object->date_echeance     : null);
                if ($dueDate) {
                    $label = $outputlangs->transnoentities('DateDue').': ';
                    $value = dol_print_date($dueDate, 'day', false, $outputlangs);
                }
                break;
            case 'object':
                $label = '';
                $value = !empty($object->label) ? $object->label : (!empty($object->titre) ? $object->titre : '');
                break;
            case 'recipient_vat':
                if (!empty($object->thirdparty->tva_intra)) {
                    $label = $outputlangs->transnoentities('PdfBuilderRecipientVat').': ';
                    $value = $object->thirdparty->tva_intra;
                }
                break;
            case 'customer_code':
                if (!empty($object->thirdparty->code_client)) {
                    $label = $outputlangs->transnoentities('CustomerCode').': ';
                    $value = $object->thirdparty->code_client;
                }
                break;
            case 'company_ids':
                $this->renderProfessionalIds($pdf, $zone, $outputlangs);
                return; // renderField standard sort ici car renderProfessionalIds gère le MultiCell
            case 'web':
                $value = preg_replace('/^https?:\/\//i', '', $mysoc->url);
                break;
        }

        // Override avec content_key si défini
        if (!empty($params['content_key'])) {
            $value = $this->resolveFieldValue($params['content_key'], $object, $outputlangs);
            $label = '';
        }

        $align = !empty($params['align']) ? strtoupper($params['align'][0]) : 'L';
        $showLabel = isset($params['show_label']) ? (bool) $params['show_label'] : true;
        $text = $showLabel ? $label.$value : $value;
        $padding = $this->_getPadding($params);
        $pdf->SetXY($c['x'] + $padding['left'], $c['y'] + $padding['top']);
        $pdf->MultiCell($c['w'] - $padding['left'] - $padding['right'], $c['h'] - $padding['top'] - $padding['bottom'], $outputlangs->convToOutputCharset($text), 0, $align, false, 1, null, null, true);
    }

    private function renderTableLines($pdf, $zone, $object, $outputlangs)
    {
        $c = $this->_zoneCoords($zone);
        $params = $zone->getParamsAsArray();
        $lp     = $this->_getLayoutParams();

        if (empty($object->lines)) {
            return;
        }

        $this->_applyZoneFont($pdf, $params);
        $this->_applyZoneBg($pdf, $params, $c);
        $this->_applyZoneBorder($pdf, $params, $c);

        // Numéros de ligne (depuis layout.params ou zone.params)
        $showLineNumbers = !empty($lp['show_line_numbers']) || !empty($params['show_line_numbers']);
        $colNum   = $showLineNumbers ? (float) ($params['col_number'] ?? 8) : 0;

        // Trait entre les lignes (depuis layout.params)
        $dashBetweenLines = !empty($lp['dash_between_lines']);

        $colRef   = (float) ($params['col_ref']   ?? 22);
        $colDesc  = 0; // Calculé automatiquement
        $colQty   = (float) ($params['col_qty']   ?? 13);
        $colPuht  = (float) ($params['col_puht']  ?? 18);
        $colTotal = (float) ($params['col_total'] ?? 21);
        $colVat   = (float) ($params['col_vat']   ?? 15);

        $showWeight     = !empty($params['show_weight']);
        $colWeight      = $showWeight ? (float) ($params['col_weight'] ?? 15) : 0;
        $showPricePerKg = !empty($params['show_price_per_kg']);

        $totalCols = $colNum + $colRef + $colQty + $colWeight + $colPuht + $colTotal + $colVat;
        $colDesc   = max(5, $c['w'] - $totalCols);

        $lineH     = (float) ($params['line_height'] ?? 5);
        $headerBg  = !empty($params['header_bg'])  ? $params['header_bg']  : '#4a6fa1';
        $headerTxt = !empty($params['header_txt']) ? $params['header_txt'] : '#ffffff';
        $ff        = !empty($params['font_family']) ? $params['font_family'] : 'DejaVuSans';
        $fsHeader  = (int) ($params['font_size_header'] ?? 8);
        $fsBody    = (int) ($params['font_size'] ?? 8);

        $rgb       = pdfbuilder_hex2rgb($headerBg);
        $rgbTxt    = pdfbuilder_hex2rgb($headerTxt);
        $mainColorRgb = pdfbuilder_hex2rgb(!empty($params['color_font']) ? $params['color_font'] : '#333333');

        // Limite basse de page (avant marge basse) pour détecter les sauts de page
        $marginBottom = (float) ($this->layout->margin_bottom ?? 10);
        $pageMaxY     = $this->pageH - $marginBottom;

        $xStart = $c['x'];

        // Closure interne : dessine l'en-tête du tableau à la position $y
        $drawHeader = function($y) use ($pdf, $xStart, $colNum, $colRef, $colDesc, $colQty, $colWeight, $colPuht, $colVat, $colTotal, $lineH, $rgb, $rgbTxt, $ff, $fsHeader, $outputlangs) {
            $pdf->SetFillColor($rgb['r'], $rgb['g'], $rgb['b']);
            $pdf->SetTextColor($rgbTxt['r'], $rgbTxt['g'], $rgbTxt['b']);
            $pdf->SetFont($ff, 'B', $fsHeader);
            $pdf->SetXY($xStart, $y);
            if ($colNum > 0) {
                $pdf->Cell($colNum,   $lineH, '#',                                               0, 0, 'C', true);
            }
            if ($colRef > 0) {
                $pdf->Cell($colRef,   $lineH, $outputlangs->transnoentities('Ref'),              0, 0, 'C', true);
            }
            $pdf->Cell($colDesc,      $lineH, $outputlangs->transnoentities('Description'),      0, 0, 'L', true);
            $pdf->Cell($colQty,       $lineH, $outputlangs->transnoentities('Qty'),              0, 0, 'C', true);
            if ($colWeight > 0) {
                $pdf->Cell($colWeight,$lineH, $outputlangs->transnoentities('PdfBuilderColWeight'), 0, 0, 'C', true);
            }
            $pdf->Cell($colPuht,      $lineH, $outputlangs->transnoentities('PriceUHT'),         0, 0, 'R', true);
            $pdf->Cell($colVat,       $lineH, $outputlangs->transnoentities('VAT'),               0, 0, 'C', true);
            $pdf->Cell($colTotal,     $lineH, $outputlangs->transnoentities('TotalHTShort'),      0, 1, 'R', true);
        };

        // En-tête initial
        $drawHeader($c['y']);

        // Lignes produits
        $pdf->SetFont($ff, '', $fsBody);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetTextColor($mainColorRgb['r'], $mainColorRgb['g'], $mainColorRgb['b']);

        $rowAltEnabled = !empty($params['row_alt_enabled']);
        $rowAltBg      = !empty($params['row_alt_bg']) ? $params['row_alt_bg'] : '#f0f4fa';
        $altRgb        = pdfbuilder_hex2rgb($rowAltBg);

        $curY = $c['y'] + $lineH;
        $i = 0;
        foreach ($object->lines as $line) {
            $i++;

            $ref  = isset($line->product_ref)   ? $line->product_ref   : '';
            $desc = isset($line->desc)           ? $line->desc          : (isset($line->product_label) ? $line->product_label : '');
            // Clean description
            $desc = dol_html_entity_decode(dol_string_nohtmltag($desc, 1), ENT_QUOTES);

            $qty  = isset($line->qty)            ? price($line->qty)    : '';
            $pu   = isset($line->subprice)       ? price($line->subprice)    : '';
            $vat  = isset($line->tva_tx)         ? price($line->tva_tx).'%'  : '';
            $tot  = isset($line->total_ht)       ? price($line->total_ht)    : '';

            // Poids de la ligne — conversion en kg selon weight_units Dolibarr (-3=mg, 0=g, 3=kg, 6=t)
            $weightTxt   = '';
            $pricePerKgTxt = '';
            if ($showWeight || $showPricePerKg) {
                $lineWeight      = isset($line->weight)       ? (float) $line->weight       : 0;
                $lineWeightUnits = isset($line->weight_units) ? (int) $line->weight_units   : 0;
                $lineQty         = isset($line->qty)          ? (float) $line->qty          : 1;
                if ($lineWeight > 0) {
                    $factorToKg     = pow(10, $lineWeightUnits - 3); // ex: units=0(g) → 0.001
                    $weightKgUnit   = $lineWeight * $factorToKg;     // poids unitaire en kg
                    $weightKgTotal  = $weightKgUnit * $lineQty;      // poids total ligne en kg
                    $weightTxt      = number_format($weightKgTotal, 3, ',', ' ');
                    if ($showPricePerKg && $weightKgUnit > 0 && isset($line->subprice) && (float) $line->subprice > 0) {
                        $pricePerKg    = (float) $line->subprice / $weightKgUnit;
                        $pricePerKgTxt = price($pricePerKg).' /kg';
                    }
                }
            }

            // Calcul hauteur réelle de la ligne
            $puCellContent = $pu . ($pricePerKgTxt ? "\n".$pricePerKgTxt : '');
            $rowH = $pdf->getStringHeight($colDesc, $outputlangs->convToOutputCharset($desc));
            if ($pricePerKgTxt) {
                $rowH = max($rowH, $pdf->getStringHeight($colPuht, $puCellContent));
            }
            $rowH = max($rowH, $lineH);

            // Saut de page si on dépasse la limite basse
            if ($curY + $rowH > $pageMaxY) {
                $pdf->AddPage('P', array($this->pageW, $this->pageH));
                $this->_applyLayoutBackground($pdf);
                $mt   = (float) ($this->layout->margin_top ?? 10);
                $curY = $mt;
                $drawHeader($curY);
                $curY += $lineH;
                $pdf->SetFont($ff, '', $fsBody);
                $pdf->SetFillColor(255, 255, 255);
                $pdf->SetTextColor($mainColorRgb['r'], $mainColorRgb['g'], $mainColorRgb['b']);
            }

            // Gestion couleur alternée — dessine le fond avant les cellules (Rect fiable vs fill=true sur MultiCell en mode flow)
            if ($rowAltEnabled && ($i % 2 === 0)) {
                $pdf->SetFillColor($altRgb['r'], $altRgb['g'], $altRgb['b']);
                $pdf->Rect($xStart, $curY, $c['w'], $rowH, 'F');
            }

            $pdf->SetTextColor($mainColorRgb['r'], $mainColorRgb['g'], $mainColorRgb['b']);
            $pdf->SetXY($xStart, $curY);
            if ($colNum > 0) {
                $pdf->MultiCell($colNum, $rowH, (string) $i, 0, 'C', false, 0);
            }
            if ($colRef > 0) {
                $pdf->MultiCell($colRef, $rowH, $outputlangs->convToOutputCharset($ref), 0, 'C', false, 0);
            }
            // Description (MultiCell supporte les sauts de ligne)
            $pdf->MultiCell($colDesc, $rowH, $outputlangs->convToOutputCharset($desc), 0, 'L', false, 0);
            $pdf->MultiCell($colQty,  $rowH, $qty, 0, 'C', false, 0);
            if ($colWeight > 0) {
                $pdf->MultiCell($colWeight, $rowH, $weightTxt, 0, 'C', false, 0);
            }
            $pdf->MultiCell($colPuht, $rowH, $puCellContent, 0, 'R', false, 0);
            $pdf->MultiCell($colVat,  $rowH, $vat, 0, 'C', false, 0);
            $pdf->MultiCell($colTotal,$rowH, $tot, 0, 'R', false, 1);
            
            $curY += $rowH;

            // Trait pointillé entre lignes
            if ($dashBetweenLines) {
                $dashRgb = pdfbuilder_hex2rgb(!empty($params['color_font']) ? $params['color_font'] : '#cccccc');
                $pdf->SetDrawColor($dashRgb['r'], $dashRgb['g'], $dashRgb['b']);
                if (method_exists($pdf, 'SetLineStyle')) {
                    $pdf->SetLineStyle(array('width' => 0.1, 'dash' => 1, 'space' => 1, 'color' => array($dashRgb['r'], $dashRgb['g'], $dashRgb['b'])));
                }
                $pdf->SetLineWidth(0.1);
                $pdf->Line($c['x'], $curY, $c['x'] + $c['w'], $curY);
                if (method_exists($pdf, 'SetLineStyle')) {
                    $pdf->SetLineStyle(array('width' => 0.2, 'dash' => 0));
                }
                $pdf->SetLineWidth(0.2);
            }
        }

        // Ligne de séparation finale
        $pdf->Line($c['x'], $curY, $c['x'] + $c['w'], $curY);

        // Mémoriser la position de fin pour le calcul du décalage des zones post-table
        $this->tableEndY    = $curY;
        $this->tableEndPage = $pdf->getPage();
        $this->tableRendered = true;
    }

    private function renderTableTotals($pdf, $zone, $object, $outputlangs)
    {
        $c = $this->_zoneCoords($zone);
        $params = $zone->getParamsAsArray();

        $this->_applyZoneFont($pdf, $params);
        $this->_applyZoneBg($pdf, $params, $c);
        $this->_applyZoneBorder($pdf, $params, $c);

        $lineH  = (float) ($params['line_height'] ?? 5);
        $labelW = (float) ($params['label_width'] ?? 60);
        $valueW = $c['w'] - $labelW;

        $ff = !empty($params['font_family']) ? $params['font_family'] : 'DejaVuSans';
        $fs = (int) ($params['font_size'] ?? 9);

        global $conf;
        $currency = $conf->currency;
        if (!empty($object->multicurrency_code)) $currency = $object->multicurrency_code;
        // Format: "Total HT en EUR"
        $inCurrency = ' ' . $outputlangs->trans("In") . ' ' . $currency;

        $rows = array();
        if (isset($object->total_ht)) {
            $rows[] = array($outputlangs->transnoentities('TotalHT') . $inCurrency, price($object->total_ht));
        }
        if (isset($object->total_tva)) {
            $rows[] = array($outputlangs->transnoentities('TotalVAT'), price($object->total_tva));
        }
        if (isset($object->total_ttc)) {
            $rows[] = array($outputlangs->transnoentities('TotalTTC') . $inCurrency, price($object->total_ttc));
        }

        $borderBg = !empty($params['header_bg']) ? $params['header_bg'] : '#4a6fa1';
        $rgb = pdfbuilder_hex2rgb($borderBg);
        $rgbTxt = pdfbuilder_hex2rgb('#ffffff');

        $curY = $c['y'];
        foreach ($rows as $i => $row) {
            $isLast = ($i === count($rows) - 1);
            if ($isLast) {
                $pdf->SetFillColor($rgb['r'], $rgb['g'], $rgb['b']);
                $pdf->SetTextColor($rgbTxt['r'], $rgbTxt['g'], $rgbTxt['b']);
                $pdf->SetFont($ff, 'B', $fs);
                $fill = true;
            } else {
                $pdf->SetFillColor(245, 245, 245);
                $mainRgb = pdfbuilder_hex2rgb('#333333');
                $pdf->SetTextColor($mainRgb['r'], $mainRgb['g'], $mainRgb['b']);
                $pdf->SetFont($ff, '', $fs);
                $fill = true;
            }
            $pdf->SetXY($c['x'], $curY);
            $pdf->Cell($labelW, $lineH, $outputlangs->convToOutputCharset($row[0]), 0, 0, 'L', $fill);
            $pdf->Cell($valueW, $lineH, $row[1], 0, 1, 'R', $fill);
            $curY += $lineH;
        }
    }

    private function renderVatBreakdown($pdf, $zone, $object, $outputlangs)
    {
        $c = $this->_zoneCoords($zone);
        $params = $zone->getParamsAsArray();

        if (empty($object->lines)) {
            return;
        }

        $this->_applyZoneFont($pdf, $params);
        $this->_applyZoneBg($pdf, $params, $c);
        $this->_applyZoneBorder($pdf, $params, $c);

        // Regrouper par taux de TVA
        $vatLines = array();
        foreach ($object->lines as $line) {
            $tx = isset($line->tva_tx) ? (float) $line->tva_tx : 0;
            $key = number_format($tx, 2);
            if (!isset($vatLines[$key])) {
                $vatLines[$key] = array('tx' => $tx, 'base' => 0, 'tva' => 0);
            }
            $vatLines[$key]['base'] += isset($line->total_ht) ? $line->total_ht : 0;
            $vatLines[$key]['tva']  += isset($line->total_tva) ? $line->total_tva : 0;
        }

        $lineH = (float) ($params['line_height'] ?? 5);
        $ff = !empty($params['font_family']) ? $params['font_family'] : 'DejaVuSans';
        $fs = (int) ($params['font_size'] ?? 8);
        $pdf->SetFont($ff, '', $fs);
        $mainRgb = pdfbuilder_hex2rgb('#333333');
        $pdf->SetTextColor($mainRgb['r'], $mainRgb['g'], $mainRgb['b']);

        $curY = $c['y'];
        $colW = $c['w'] / 3;
        foreach ($vatLines as $vl) {
            $pdf->SetXY($c['x'], $curY);
            $pdf->Cell($colW, $lineH, $vl['tx'].'%', 0, 0, 'C');
            $pdf->Cell($colW, $lineH, price($vl['base']), 0, 0, 'R');
            $pdf->Cell($colW, $lineH, price($vl['tva']), 0, 1, 'R');
            $curY += $lineH;
        }
    }

    private function renderRib($pdf, $zone, $object, $outputlangs)
    {
        global $conf, $mysoc;
        $c = $this->_zoneCoords($zone);
        $params = $zone->getParamsAsArray();

        $this->_applyZoneFont($pdf, $params);
        $this->_applyZoneBg($pdf, $params, $c);
        $this->_applyZoneBorder($pdf, $params, $c);

        $lines = array();

        // Récupérer le compte bancaire si disponible
        require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
        $bankaccount = new Account($this->db);
        $bankId = isset($object->fk_account) ? (int) $object->fk_account : (!empty($conf->global->FACTURE_RIB_NUMBER) ? (int) $conf->global->FACTURE_RIB_NUMBER : 0);

        if ($bankId > 0 && $bankaccount->fetch($bankId) > 0) {
            // Ok
        } elseif (empty($object->id) || $object->id < 0) {
            // Mode aperçu (Designer)
            $bankaccount->label = "Banque Exemple (Aperçu)";
            $bankaccount->iban  = "FR76 1234 5678 9012 3456 7890 123";
            $bankaccount->bic   = "EXMPFR2X";
            $bankaccount->bank  = "Banque de Démonstration";
        } else {
            // Rendu réel mais pas de compte spécifié : on cherche le premier compte actif
            $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."bank_account WHERE entity IN (".getEntity('bank_account').") AND clos = 0 LIMIT 1";
            $resql = $this->db->query($sql);
            if ($resql && $this->db->num_rows($resql) > 0) {
                $objb = $this->db->fetch_object($resql);
                $bankaccount->fetch($objb->rowid);
            }
        }

        if ($bankaccount->label || $bankaccount->iban) {
            if ($bankaccount->label) {
                $lines[] = $bankaccount->label;
            }
            if ($bankaccount->bank) {
                $lines[] = $bankaccount->bank;
            }
            if ($bankaccount->iban) {
                $lines[] = $outputlangs->transnoentities("IBAN").' : '.$bankaccount->iban;
            }
            if ($bankaccount->bic) {
                $lines[] = $outputlangs->transnoentities("BIC").' : '.$bankaccount->bic;
            }
        } elseif (!empty($mysoc->bank_account)) {
            $lines[] = $mysoc->bank_account;
        }

        $text = implode("\n", array_filter($lines));
        $padding = $this->_getPadding($params);
        $pdf->SetXY($c['x'] + $padding['left'], $c['y'] + $padding['top']);
        $align = !empty($params['align']) ? strtoupper($params['align'][0]) : 'L';
        $pdf->MultiCell($c['w'] - $padding['left'] - $padding['right'], $c['h'] - $padding['top'] - $padding['bottom'], $outputlangs->convToOutputCharset($text), 0, $align, false, 1, null, null, true);
    }

    private function renderQrCode($pdf, $zone, $object)
    {
        $c = $this->_zoneCoords($zone);
        $params = $zone->getParamsAsArray();

        $url = !empty($params['url']) ? $params['url'] : '';
        if (empty($url)) {
            // Construire une URL par défaut vers le document
            global $dolibarr_main_url_root;
            if (!empty($dolibarr_main_url_root)) {
                $url = $dolibarr_main_url_root.'/compta/facture/card.php?id='.(int) $object->id;
            }
        }

        if (empty($url)) {
            return;
        }

        $style = array(
            'border' => false,
            'vpadding' => 'auto',
            'hpadding' => 'auto',
            'fgcolor' => array(0, 0, 0),
            'bgcolor' => false,
            'module_width' => 1,
            'module_height' => 1,
        );

        if (!empty($params['color'])) {
            $rgb = pdfbuilder_hex2rgb($params['color']);
            $style['fgcolor'] = array($rgb['r'], $rgb['g'], $rgb['b']);
        }

        $pdf->write2DBarcode($url, 'QRCODE,H', $c['x'], $c['y'], $c['w'], $c['h'], $style, 'N');
    }

    private function renderTextStatic($pdf, $zone)
    {
        $c = $this->_zoneCoords($zone);
        $params = $zone->getParamsAsArray();

        $this->_applyZoneFont($pdf, $params);
        $this->_applyZoneBg($pdf, $params, $c);
        $this->_applyZoneBorder($pdf, $params, $c);

        $text = !empty($params['text']) ? $params['text'] : (!empty($zone->label) ? $zone->label : '');
        $align = !empty($params['align']) ? strtoupper($params['align'][0]) : 'L';
        $padding = $this->_getPadding($params);
        $pdf->SetXY($c['x'] + $padding['left'], $c['y'] + $padding['top']);
        $pdf->MultiCell($c['w'] - $padding['left'] - $padding['right'], $c['h'] - $padding['top'] - $padding['bottom'], $text, 0, $align, false, 1, null, null, true);
    }

    private function renderFreetext($pdf, $zone, $object)
    {
        $c = $this->_zoneCoords($zone);
        $params = $zone->getParamsAsArray();

        $this->_applyZoneFont($pdf, $params);
        $this->_applyZoneBg($pdf, $params, $c);
        $this->_applyZoneBorder($pdf, $params, $c);

        $field = !empty($params['field']) ? $params['field'] : 'note_public';
        $text = isset($object->$field) ? $object->$field : '';

        if (empty($text)) {
            return;
        }

        $align = !empty($params['align']) ? strtoupper($params['align'][0]) : 'L';
        $padding = $this->_getPadding($params);
        $pdf->writeHTMLCell($c['w'] - $padding['left'] - $padding['right'], $c['h'] - $padding['top'] - $padding['bottom'], $c['x'] + $padding['left'], $c['y'] + $padding['top'], nl2br($text), 0, 1, false, true, $align);
    }

    private function renderImageBg($pdf, $zone)
    {
        global $conf;
        $c = $this->_zoneCoords($zone);
        $params = $zone->getParamsAsArray();

        $src = !empty($params['src']) ? $params['src'] : '';
        if (!$src) {
            return;
        }

        $fullpath = $src;
        if (!file_exists($fullpath)) {
            $fullpath = DOL_DATA_ROOT . '/' . $src;
        }

        if (!file_exists($fullpath) || is_dir($fullpath)) {
            return;
        }

        $opacity = isset($params['opacity']) ? (float) $params['opacity'] : 0.1;
        $pdf->SetAlpha($opacity);
        $pdf->Image($fullpath, 0, 0, $this->pageW, $this->pageH, '', '', '', false, 300);
        $pdf->SetAlpha(1);
    }

    private function renderSeparator($pdf, $zone)
    {
        $c = $this->_zoneCoords($zone);
        $params = $zone->getParamsAsArray();

        $color = !empty($params['color']) ? $params['color'] : '#cccccc';
        $rgb = pdfbuilder_hex2rgb($color);
        $pdf->SetDrawColor($rgb['r'], $rgb['g'], $rgb['b']);
        $lineW = isset($params['line_width']) ? (float) $params['line_width'] : 0.3;
        $pdf->SetLineWidth($lineW);
        $midY = $c['y'] + $c['h'] / 2;
        $pdf->Line($c['x'], $midY, $c['x'] + $c['w'], $midY);
        $pdf->SetLineWidth(0.2);
    }

    private function renderWatermark($pdf, $zone, $outputlangs)
    {
        $c = $this->_zoneCoords($zone);
        $params = $zone->getParamsAsArray();

        $text = !empty($params['text']) ? $params['text'] : 'BROUILLON';
        $color = !empty($params['color']) ? $params['color'] : '#cccccc';
        $angle = isset($params['angle']) ? (float) $params['angle'] : 45;

        $rgb = pdfbuilder_hex2rgb($color);
        $pdf->SetTextColor($rgb['r'], $rgb['g'], $rgb['b']);
        $fs = isset($params['font_size']) ? (int) $params['font_size'] : 40;
        $ff = !empty($params['font_family']) ? $params['font_family'] : 'DejaVuSans';
        $pdf->SetFont($ff, 'B', $fs);

        $cx = $this->pageW / 2;
        $cy = $this->pageH / 2;
        $pdf->StartTransform();
        $pdf->Rotate($angle, $cx, $cy);
        $pdf->SetXY($cx - 60, $cy - 10);
        $pdf->Cell(120, 20, $outputlangs->convToOutputCharset($text), 0, 0, 'C');
        $pdf->StopTransform();
    }

    private function renderSignatureBlock($pdf, $zone, $outputlangs)
    {
        $c = $this->_zoneCoords($zone);
        $params = $zone->getParamsAsArray();

        $this->_applyZoneFont($pdf, $params);
        $this->_applyZoneBg($pdf, $params, $c);

        $text = !empty($params['text']) ? $params['text'] : $outputlangs->transnoentities('GoodForAgreement');
        $pdf->SetXY($c['x'], $c['y']);
        $pdf->Cell($c['w'], 5, $outputlangs->convToOutputCharset($text), 0, 1, 'C');

        // Boîte de signature
        $boxY = $c['y'] + 6;
        $cBox = array('x' => $c['x'], 'y' => $boxY, 'w' => $c['w'], 'h' => $c['h'] - 8);
        $this->_applyZoneBorder($pdf, $params, $cBox);
    }

    /**
     * Rendu du type de document (FACTURE, DEVIS, COMMANDE, etc.)
     * Affiche le libellé du type de document de manière lisible.
     *
     * @param TCPDF     $pdf         Instance TCPDF
     * @param PdfBuilderZone $zone   Zone type document
     * @param object    $object      Objet Dolibarr
     * @param Translate $outputlangs Langue
     */
    private function renderDocumentType($pdf, $zone, $object, $outputlangs)
    {
        $c = $this->_zoneCoords($zone);
        $params = $zone->getParamsAsArray();

        if (!isset($object->table_element)) {
            dol_syslog('PdfBuilderRenderer::renderDocumentType: object->table_element not found', LOG_WARNING);
            return;
        }

        $this->_applyZoneFont($pdf, $params);
        $this->_applyZoneBg($pdf, $params, $c);

        // Mapper table_element vers libellé
        $docTypeLabel = $this->_getDocumentTypeLabel($object, $outputlangs);

        // Appliquer la bordure si définie
        $this->_applyZoneBorder($pdf, $params, $c);

        $align = !empty($params['align']) ? strtoupper($params['align'][0]) : 'C';
        $padding = $this->_getPadding($params);
        $pdf->SetXY($c['x'] + $padding['left'], $c['y'] + $padding['top']);
        $pdf->MultiCell($c['w'] - $padding['left'] - $padding['right'], $c['h'] - $padding['top'] - $padding['bottom'], $outputlangs->convToOutputCharset($docTypeLabel), 0, $align, false, 1, null, null, true);
    }

    /**
     * Retourne le libellé du type de document en fonction de table_element
     */
    private function _getDocumentTypeLabel($object, $outputlangs)
    {
        static $map = array(
            'facture'              => 'PdfInvoiceTitle',          // Facture
            'facture_fourn'        => 'SupplierInvoice',         // Facture fournisseur
            'commande'             => 'Order',                    // Commande
            'commande_fournisseur' => 'SupplierOrder',           // Commande fournisseur
            'propal'               => 'Proposal',                 // Devis/Propal
            'ficheinter'           => 'InterventionCard',        // Fiche d'intervention
        );

        $tableElement = isset($object->table_element) ? $object->table_element : 'unknown';
        $key = isset($map[$tableElement]) ? $map[$tableElement] : 'Document';

        dol_syslog('PdfBuilderRenderer::_getDocumentTypeLabel table_element='.$tableElement.' key='.$key, LOG_DEBUG);

        return $outputlangs->transnoentities($key);
    }

    /**
     * Rendu d'une zone pied de page répétée sur chaque page.
     * Le paramètre 'text' supporte les tokens {page} et {pages}.
     *
     * @param TCPDF     $pdf         Instance TCPDF
     * @param PdfBuilderZone $zone   Zone pied de page
     * @param Translate $outputlangs Langue
     * @param int       $pageNum     Numéro de la page courante (1-based)
     * @param int       $totalPages  Nombre total de pages
     */
    private function renderPageFooter($pdf, $zone, $outputlangs, $pageNum, $totalPages)
    {
        $c = $this->_zoneCoords($zone);
        $params = $zone->getParamsAsArray();

        $this->_applyZoneFont($pdf, $params);
        $this->_applyZoneBg($pdf, $params, $c);
        $this->_applyZoneBorder($pdf, $params, $c);

        $text = !empty($params['text']) ? $params['text'] : 'Page {page} / {pages}';
        $text = str_replace(array('{page}', '{pages}'), array($pageNum, $totalPages), $text);

        $align = !empty($params['align']) ? strtoupper($params['align'][0]) : 'C';
        $padding = $this->_getPadding($params);
        $pdf->SetXY($c['x'] + $padding['left'], $c['y'] + $padding['top']);
        $pdf->MultiCell($c['w'] - $padding['left'] - $padding['right'], $c['h'] - $padding['top'] - $padding['bottom'], $outputlangs->convToOutputCharset($text), 0, $align, false, 1, null, null, true);
    }

    private function renderProfessionalIds($pdf, $zone, $outputlangs)
    {
        global $mysoc;
        $c = $this->_zoneCoords($zone);
        $params = $zone->getParamsAsArray();

        $this->_applyZoneFont($pdf, $params);
        $this->_applyZoneBg($pdf, $params, $c);
        $this->_applyZoneBorder($pdf, $params, $c);

        $lines = array();
        $cc = $mysoc->country_code;
        if ($mysoc->idprof1) $lines[] = $this->_getProfIdLabel($outputlangs, 1, $cc).': '.$mysoc->idprof1;
        if ($mysoc->idprof2) $lines[] = $this->_getProfIdLabel($outputlangs, 2, $cc).': '.$mysoc->idprof2;
        if ($mysoc->idprof3) $lines[] = $this->_getProfIdLabel($outputlangs, 3, $cc).': '.$mysoc->idprof3;
        if ($mysoc->idprof4) $lines[] = $this->_getProfIdLabel($outputlangs, 4, $cc).': '.$mysoc->idprof4;
        if ($mysoc->tva_intra) $lines[] = $this->_getVATIntraLabel($outputlangs, $cc).': '.$mysoc->tva_intra;

        $text = implode("\n", array_filter($lines));
        $align = !empty($params['align']) ? strtoupper($params['align'][0]) : 'L';
        $padding = $this->_getPadding($params);
        $pdf->SetXY($c['x'] + $padding['left'], $c['y'] + $padding['top']);
        $pdf->MultiCell($c['w'] - $padding['left'] - $padding['right'], $c['h'] - $padding['top'] - $padding['bottom'], $outputlangs->convToOutputCharset($text), 0, $align, false, 1, null, null, true);
    }

    private function renderOutstanding($pdf, $zone, $object, $outputlangs)
    {
        $c = $this->_zoneCoords($zone);
        $params = $zone->getParamsAsArray();

        if (empty($object->thirdparty)) {
            return;
        }

        $this->_applyZoneFont($pdf, $params);
        $this->_applyZoneBg($pdf, $params, $c);
        $this->_applyZoneBorder($pdf, $params, $c);

        // Calcul encours
        require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
        $outstanding = $object->thirdparty->outstanding_limit;

        $text = $outputlangs->transnoentities('CurrentOutstanding').': '.price((float) $outstanding);
        $padding = $this->_getPadding($params);
        $pdf->SetXY($c['x'] + $padding['left'], $c['y'] + $padding['top']);
        $pdf->MultiCell($c['w'] - $padding['left'] - $padding['right'], $c['h'] - $padding['top'] - $padding['bottom'], $outputlangs->convToOutputCharset($text), 0, 'L', false, 1, null, null, true);
    }

    // =========================================================
    // HELPERS
    // =========================================================

    /**
     * Applique police et couleur de texte depuis les params d'une zone
     */
    private function _applyZoneFont($pdf, array $params)
    {
        $ff = !empty($params['font_family']) ? $params['font_family'] : 'DejaVuSans';
        $fs = isset($params['font_size']) ? (int) $params['font_size'] : 9;
        $bold = !empty($params['bold']) ? 'B' : '';
        $italic = !empty($params['italic']) ? 'I' : '';
        $pdf->SetFont($ff, $bold.$italic, $fs);

        $color = !empty($params['color_font']) ? $params['color_font'] : '#333333';
        $rgb = pdfbuilder_hex2rgb($color);
        $pdf->SetTextColor($rgb['r'], $rgb['g'], $rgb['b']);
    }

    /**
     * Applique un fond coloré sur la zone si défini
     */
    private function _applyZoneBg($pdf, array $params, array $c)
    {
        if (empty($params['bg_color'])) {
            return;
        }
        $rgb = pdfbuilder_hex2rgb($params['bg_color']);
        $pdf->SetFillColor($rgb['r'], $rgb['g'], $rgb['b']);
        $pdf->Rect($c['x'], $c['y'], $c['w'], $c['h'], 'F');
    }

    /**
     * Applique une bordure sur la zone selon les params
     * Supporte : border_style, border_width, border_color
     * Note: border_radius n'est pas supporté par TCPDF
     */
    private function _applyZoneBorder($pdf, array $params, array $c)
    {
        $borderStyle = !empty($params['border_style']) ? $params['border_style'] : 'none';
        if ($borderStyle === 'none') {
            return;
        }

        $borderWidth = isset($params['border_width']) ? (float) $params['border_width'] : 0.5;
        $borderColor = !empty($params['border_color']) ? $params['border_color'] : '#333333';
        $rgb = pdfbuilder_hex2rgb($borderColor);

        $pdf->SetDrawColor($rgb['r'], $rgb['g'], $rgb['b']);
        $pdf->SetLineWidth($borderWidth);

        // TCPDF supporte les styles de ligne via SetLineStyle()
        // Si la méthode existe, l'utiliser pour dashed/dotted
        if ($borderStyle === 'dashed' && method_exists($pdf, 'SetLineStyle')) {
            $pdf->SetLineStyle(array('width' => $borderWidth, 'dash' => 3, 'space' => 2, 'color' => array($rgb['r'], $rgb['g'], $rgb['b'])));
            $pdf->Rect($c['x'], $c['y'], $c['w'], $c['h'], 'D');
            $pdf->SetLineStyle(array('width' => 0.2, 'dash' => 0));
        } elseif ($borderStyle === 'dotted' && method_exists($pdf, 'SetLineStyle')) {
            $pdf->SetLineStyle(array('width' => $borderWidth, 'dash' => 1, 'space' => 1, 'color' => array($rgb['r'], $rgb['g'], $rgb['b'])));
            $pdf->Rect($c['x'], $c['y'], $c['w'], $c['h'], 'D');
            $pdf->SetLineStyle(array('width' => 0.2, 'dash' => 0));
        } else {
            // Fallback: dessiner une bordure pleine pour tous les autres cas
            $pdf->Rect($c['x'], $c['y'], $c['w'], $c['h'], 'D');
        }

        $pdf->SetLineWidth(0.2);
    }

    /**
     * Résout la valeur d'un champ depuis un content_key arbitraire
     * Ex: "total_ttc", "ref", "date", "thirdparty.name"
     */
    private function resolveFieldValue($contentKey, $object, $outputlangs = null)
    {
        if (strpos($contentKey, '.') !== false) {
            list($objKey, $fieldKey) = explode('.', $contentKey, 2);
            if (isset($object->$objKey) && is_object($object->$objKey)) {
                return (string) (isset($object->$objKey->$fieldKey) ? $object->$objKey->$fieldKey : '');
            }
            return '';
        }

        if (isset($object->$contentKey)) {
            $val = $object->$contentKey;
            // Formater les dates
            if (is_numeric($val) && in_array($contentKey, array('date', 'date_echeance', 'date_livraison'))) {
                return $outputlangs ? dol_print_date($val, 'day', false, $outputlangs) : dol_print_date($val, 'day');
            }
            // Formater les montants
            if (in_array($contentKey, array('total_ht', 'total_tva', 'total_ttc', 'multicurrency_total_ht'))) {
                return price($val);
            }
            return (string) $val;
        }

        return '';
    }
    /**
     * Retourne les marges internes (padding) d'une zone
     *
     * @param array $params Paramètres de la zone
     * @return array array('top'=>float, 'right'=>float, 'bottom'=>float, 'left'=>float)
     */
    private function _getPadding($params)
    {
        return array(
            'top'    => (float) (isset($params['padding_top'])    ? $params['padding_top']    : 0),
            'right'  => (float) (isset($params['padding_right'])  ? $params['padding_right']  : 0),
            'bottom' => (float) (isset($params['padding_bottom']) ? $params['padding_bottom'] : 0),
            'left'   => (float) (isset($params['padding_left'])   ? $params['padding_left']   : 0),
        );
    }

    private function _getVATIntraLabel($outputlangs, $countryCode)
    {
        $key = 'PdfBuilderVATIntra'.$countryCode;
        $label = $outputlangs->transnoentities($key);
        if ($label === $key) {
            return $outputlangs->transnoentities("VATIntraShort");
        }
        return $label;
    }

    private function _getProfIdLabel($outputlangs, $idNumber, $countryCode)
    {
        $key = 'PdfBuilderProfId'.$idNumber.$countryCode;
        $label = $outputlangs->transnoentities($key);
        if ($label === $key) {
            return $outputlangs->transcountry("ProfId".$idNumber, $countryCode);
        }
        return $label;
    }
}

