<?php
/* Copyright (C) 2024 Antigravity Project
 * GPL v2 or later
 */

/**
 * \file       pdfbuilder/core/modules/facture/pdf_pdfbuilder_invoice.class.php
 * \ingroup    pdfbuilder
 * \brief      Modèle PDF pour les factures clients - PDF-builder
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/facture/modules_facture.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

dol_include_once('/pdfbuilder/class/pdfbuilder.class.php');
dol_include_once('/pdfbuilder/lib/pdfbuilder.lib.php');
dol_include_once('/pdfbuilder/class/pdfbuildertools.class.php');

/**
 * Classe de génération PDF pour les factures clients avec PDF-builder
 */
class pdf_pdfbuilder_invoice extends ModelePDFFactures
{
    /** @var string Nom du modèle */
    public $name = 'pdfbuilder_invoice';

    /** @var string Description */
    public $description = 'Modèle PDF-builder pour les factures clients';

    /** @var string Version */
    public $version = '1.0.0';

    /** @var string Type */
    public $type = 'pdf';

    /** @var PdfBuilderTheme Thème actif */
    private $theme;

    /** @var DoliDB Connexion BDD */
    public $db;

    /** @var int Hauteur minimale entre chaque ligne (mm) */
    private $line_height = 5.5;

    /** @var int Position Y du tableau de produits */
    private $tab_top;

    /** @var int Largeur utile de la page (mm) */
    private $page_width;

    /**
     * Constructeur
     * @param DoliDB $db Connexion BDD
     */
    public function __construct($db)
    {
        global $langs, $mysoc;

        $this->db = $db;

        // Chargement du thème actif pour les factures
        $this->theme = PdfBuilderTheme::getActive($db, 'invoice');

        $langs->loadLangs(array('main', 'bills', 'products', 'dict', 'companies', 'pdfbuilder@pdfbuilder'));

        // Paramètres du modèle
        $this->page_largeur  = $this->theme->paper_format == 'A3' ? 297 : 210;
        $this->page_hauteur  = $this->theme->paper_format == 'A3' ? 420 : 297;
        $this->format        = array($this->page_largeur, $this->page_hauteur);
        $this->marge_gauche  = (float) ($this->theme->margin_left  ?: 11);
        $this->marge_droite  = (float) ($this->theme->margin_right ?: 10);
        $this->marge_haute   = (float) ($this->theme->margin_top   ?: 10);
        $this->marge_basse   = (float) ($this->theme->margin_bottom ?: 10);

        $this->page_width = $this->page_largeur - $this->marge_gauche - $this->marge_droite;
    }

    /**
     * Génère le fichier PDF
     *
     * @param object    $object          Objet Facture
     * @param Translate $outputlangs     Objet langue de sortie
     * @param string    $srctemplatepath Chemin du template source
     * @param int       $hidedetails     1 = masque le détail
     * @param int       $hidedesc        1 = masque la description
     * @param int       $hideref         1 = masque la référence
     * @return int <0 si erreur, 1 si OK
     */
    public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
    {
        global $user, $langs, $conf, $mysoc, $hookmanager;

        if (!is_object($outputlangs)) {
            $outputlangs = $langs;
        }
        if (!empty($conf->global->MAIN_USE_FPDF)) {
            $outputlangs->charset_output = 'ISO-8859-1';
        }

        $outputlangs->loadLangs(array('main', 'dict', 'companies', 'bills', 'products', 'pdfbuilder@pdfbuilder'));

        // === BRANCHE LAYOUT GRAPHIQUE (éditeur pdfbuilder) ===
        // Cas 1 : srctemplatepath est un ID numérique (sélection directe layout)
        if (!empty($srctemplatepath) && ctype_digit((string) $srctemplatepath)) {
            return $this->_writeFileWithLayout($object, $outputlangs, (int) $srctemplatepath);
        }
        // Cas 2 : chercher le layout par défaut pour les factures
        $activeLayout = pdfbuilder_get_active_layout($this->db, 'invoice');
        if ($activeLayout) {
            return $this->_writeFileWithLayout($object, $outputlangs, (int) $activeLayout->id);
        }

        // Fallback : ancien système thème monolithique
        $this->theme = PdfBuilderTheme::getActive($this->db, 'invoice');

        $nblines = count($object->lines);

        // === Calcul du dossier de destination ===
        if ($conf->facture->multidir_output[$object->entity]) {
            $dir = $conf->facture->multidir_output[$object->entity];
        } else {
            $dir = $conf->facture->dir_output;
        }

        if (!$dir) {
            $this->error = $langs->trans('ErrorUndefinedOutputDir');
            return -1;
        }

        $objectref = dol_sanitizeFileName($object->ref);
        if (!preg_match('/specimen/i', $objectref)) {
            $dir .= '/'.$objectref;
        }

        if (!file_exists($dir)) {
            if (dol_mkdir($dir) < 0) {
                $this->error = $langs->trans('ErrorCantCreateDir', $dir);
                return -1;
            }
        }

        if (file_exists($dir)) {
            $filename = $objectref.'.pdf';
            $file     = $dir.'/'.$filename;

            // === Initialisation FPDF ===
            $pdf = pdf_getInstance($this->format);

            $heightforfreetext = (float) ($this->theme->freetext_height ?: 12);
            $heightforinfotot  = 50;  // Zone totaux en bas de page
            $heightforfooter   = $this->marge_basse + $heightforfreetext + 7;

            if (class_exists('TCPDF')) {
                $pdf->setPrintHeader(false);
                $pdf->setPrintFooter(false);
            }

            $pdf->SetAutoPageBreak(1, 0);

            if (getDolGlobalString('MAIN_PDF_TITLE_OVERWRITE')) {
                $pdf->SetTitle(getDolGlobalString('MAIN_PDF_TITLE_OVERWRITE'));
            } else {
                $pdf->SetTitle($outputlangs->convToOutputCharset($object->ref));
            }
            $pdf->SetSubject($outputlangs->transnoentities('PdfInvoiceTitle'));
            $pdf->SetCreator('Dolibarr '.DOL_VERSION.' PDF-builder '.$this->version);
            $pdf->SetAuthor($outputlangs->convToOutputCharset($user->getFullName($outputlangs)));
            $pdf->SetKeyWords($outputlangs->convToOutputCharset($object->ref).' '.$outputlangs->transnoentities('PdfInvoiceTitle'));

            if (getDolGlobalString('MAIN_DISABLE_PDF_COMPRESSION')) {
                $pdf->SetCompression(false);
            }

            // Thème couleurs
            $colorFont = $this->theme->hex2rgb($this->theme->color_font ?: '#333333');
            $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);

            // Police
            $pdf->SetFont($this->theme->font_family ?: 'DejaVuSans', '', (int) ($this->theme->font_size ?: 9));

            // Marges
            $pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);
            $pdf->SetHeaderMargin($this->marge_haute);
            $pdf->SetFooterMargin($this->marge_basse);

            $pdf->AddPage('P', $this->format);

            // === FOND IMAGE (Phase 3) ===
            if (!empty($this->theme->bg_image) && file_exists($this->theme->bg_image)) {
                PdfBuilderTools::drawBackgroundImage($pdf, $this->theme->bg_image, (float) ($this->theme->bg_opacity ?: 0.10), $this->page_largeur, $this->page_hauteur);
            }

            // === EN-TÊTE ===
            $tab_top = $this->_pagehead($pdf, $object, 1, $outputlangs);
            $pdf->SetFont($this->theme->font_family ?: 'DejaVuSans', '', (int) ($this->theme->font_size ?: 9));
            $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);

            $tab_top_newpage = (!empty($this->theme->no_repeat_header) ? $this->marge_haute + 5 : $tab_top);

            // === CORPS DU DOCUMENT (tableau produits) ===
            $iniY      = $tab_top + 7;
            $curY      = $iniY;
            $nexY      = $curY;
            $pageposbeforeprintlines = $pdf->getPage();

            $pdf->startTransaction();

            $this->_tableau($pdf, $tab_top, $this->page_hauteur - $heightforfooter - $tab_top, 0, $outputlangs, 0, 0, $object->multicurrency_code);

            $bottomlasttab = $this->page_hauteur - $heightforfooter - $tab_top;

            // Lignes de produits
            $pageposafterheader = $pdf->getPage();
            $pagenb = $pdf->getPage();
            $pdf->rollbackTransaction(true);

            $pagenb = $pdf->getPage();

            $this->_tableau($pdf, $tab_top, $this->page_hauteur - $heightforfooter - $tab_top, 0, $outputlangs, 0, 0, $object->multicurrency_code);

            $bottomlasttab = $tab_top;

            // Parcourir les lignes
            $ytop = $tab_top + 7;
            $curY = $ytop;

            for ($i = 0; $i < $nblines; $i++) {
                $curY = $this->_outputLine($pdf, $object, $i, $curY, $outputlangs, $heightforfooter, $heightforinfotot, $tab_top_newpage);
                if ($curY < 0) {
                    $curY = -$curY;
                    // Nouvelle page
                    $pdf->AddPage('P', $this->format);
                    // Fond image sur nouvelle page
                    if (!empty($this->theme->bg_image) && file_exists($this->theme->bg_image)) {
                        PdfBuilderTools::drawBackgroundImage($pdf, $this->theme->bg_image, (float) ($this->theme->bg_opacity ?: 0.10), $this->page_largeur, $this->page_hauteur);
                    }
                    if (empty($this->theme->no_repeat_header)) {
                        $tab_top = $this->_pagehead($pdf, $object, 0, $outputlangs);
                        $pdf->SetFont($this->theme->font_family ?: 'DejaVuSans', '', (int) ($this->theme->font_size ?: 9));
                    } else {
                        $tab_top = $tab_top_newpage;
                    }
                    $this->_tableau($pdf, $tab_top, $this->page_hauteur - $heightforfooter - $tab_top, 0, $outputlangs, 1, 0, $object->multicurrency_code);
                    $curY = $tab_top + 7;
                    $curY = $this->_outputLine($pdf, $object, $i, $curY, $outputlangs, $heightforfooter, $heightforinfotot, $tab_top_newpage);
                }
                $bottomlasttab = max($bottomlasttab, $curY);
            }

            $pdf->SetY($curY);
            if ($curY + $heightforinfotot > $this->page_hauteur - $heightforfooter) {
                $pdf->AddPage('P', $this->format);
                if (!empty($this->theme->bg_image) && file_exists($this->theme->bg_image)) {
                    PdfBuilderTools::drawBackgroundImage($pdf, $this->theme->bg_image, (float) ($this->theme->bg_opacity ?: 0.10), $this->page_largeur, $this->page_hauteur);
                }
                if (empty($this->theme->no_repeat_header)) {
                    $this->_pagehead($pdf, $object, 0, $outputlangs);
                }
                $pdf->SetY($tab_top_newpage + 7);
            }

            // === ZONE TOTAUX ===
            $this->_pagefoot($pdf, $object, $outputlangs, 0);

            // === Sauvegarde ===
            $pdf->Close();
            $pdf->Output($file, 'F');

            // === FOND PDF (Phase 3 — FPDI overlay) ===
            if (!empty($this->theme->bg_pdf) && file_exists($this->theme->bg_pdf)) {
                PdfBuilderTools::applyBackgroundPdf($file, $this->theme->bg_pdf);
            }

            // === FUSION PDF ANNEXES (Phase 3) ===
            if (getDolGlobalString('PDFBUILDER_INVOICE_WITH_MERGED_PDF')) {
                $mergePdfs = PdfBuilderTools::getMergePdfs($this->db, $object, 'invoice');
                if (!empty($mergePdfs)) {
                    array_unshift($mergePdfs, $file);
                    PdfBuilderTools::mergePdfs($mergePdfs, $file);
                }
            }

            // Référencer le fichier dans Dolibarr
            // $this->_addFileToECM($object, $file, $filename); // Supprimé: l'indexation ECM est gérée nativement par Dolibarr 20+

            // Anti-cache
            if (!empty($conf->global->MAIN_UMASK)) {
                @chmod($file, octdec($conf->global->MAIN_UMASK));
            }

            return 1;
        } else {
            $this->error = $langs->trans('ErrorConstantNotDefined', 'FAC_ADDON_PDF_PATH');
            return -1;
        }
    }

    /**
     * Affiche l'en-tête de la page (logo, adresses, infos document)
     *
     * @param TCPDF $pdf Objet PDF
     * @param Facture $object Facture
     * @param int $showaddress Afficher les adresses
     * @param Translate $outputlangs Langue de sortie
     * @return float Position Y après l'en-tête
     */
    private function _pagehead(&$pdf, $object, $showaddress, $outputlangs)
    {
        global $conf, $langs, $mysoc;

        $theme = $this->theme;
        $colorHeader = $theme->hex2rgb($theme->color_header_bg ?: '#4a6fa1');
        $colorHeaderTxt = $theme->hex2rgb($theme->color_header_txt ?: '#ffffff');
        $colorAddress = $theme->hex2rgb($theme->color_address_bg ?: '#f0f4fa');
        $colorFont = $theme->hex2rgb($theme->color_font ?: '#333333');

        $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);

        $posy = $this->marge_haute;
        $posx = $this->marge_gauche;

        // --- Logo ---
        $logoFile = null;
        if (!empty($theme->show_logo_alt) && !empty($theme->logo_alt) && file_exists($theme->logo_alt)) {
            $logoFile = $theme->logo_alt;
        } elseif (empty($theme->hide_main_logo)) {
            $logo = $conf->mycompany->dir_output.'/logos/'.dol_sanitizeFileName($mysoc->logo);
            if ($mysoc->logo && file_exists($logo)) {
                $logoFile = $logo;
            }
        }

        if ($logoFile) {
            $pdf->Image($logoFile, $posx, $posy, 0, (float) ($theme->logo_height ?: 18), '', '', '', false, 150, '', false, false, 0, false, false, false);
        }

        // --- Titre du document ---
        $pdf->SetXY($this->page_largeur - $this->marge_droite - 90, $posy);
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', 'B', 14);
        $pdf->SetTextColor($colorHeader['r'], $colorHeader['g'], $colorHeader['b']);
        $pdf->MultiCell(90, 7, $outputlangs->transnoentities('PdfInvoiceTitle'), 0, 'R', false);

        $posy_right = $posy + 8;

        // Numéro de facture
        $pdf->SetXY($this->page_largeur - $this->marge_droite - 90, $posy_right);
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', 'B', 10);
        $pdf->MultiCell(90, 6, $outputlangs->convToOutputCharset($object->ref), 0, 'R', false);
        $posy_right += 6;

        // Code client
        if (!empty($object->thirdparty->code_client)) {
            $pdf->SetXY($this->page_largeur - $this->marge_droite - 90, $posy_right);
            $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_ref, (int) ($theme->font_size_ref ?: $theme->font_size ?: 9) - 1);
            $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);
            $pdf->MultiCell(90, 5, $outputlangs->transnoentities('CustomerCode').': '.$object->thirdparty->code_client, 0, 'R', false);
            $posy_right += 5;
        }

        // Date de facture
        $pdf->SetXY($this->page_largeur - $this->marge_droite - 90, $posy_right);
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_ref, (int) ($theme->font_size_ref ?: $theme->font_size ?: 9) - 1);
        $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);
        $pdf->MultiCell(90, 5, $outputlangs->transnoentities('DateInvoice').': '.dol_print_date($object->date, 'day'), 0, 'R', false);
        $posy_right += 5;

        // Échéance
        if (!empty($object->date_lim_reglement)) {
            $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_ref, (int) ($theme->font_size_ref ?: $theme->font_size ?: 9) - 1);
            $pdf->SetXY($this->page_largeur - $this->marge_droite - 90, $posy_right);
            $pdf->MultiCell(90, 5, $outputlangs->transnoentities('DateMaxPayment').': '.dol_print_date($object->date_lim_reglement, 'day', false, $outputlangs, true), 0, 'R', false);
            $posy_right += 5;
        }

        $nextY = $posy_right;

        // --- Calcul de la nouvelle position Y pour éviter les chevauchements ---
        $logoHeight = (float) ($theme->logo_height ?: 18);
        $headerInfoHeight = $nextY - $this->marge_haute;
        $posy = $this->marge_haute + max($logoHeight, $headerInfoHeight) + (float) ($theme->header_spacing ?: 2.0);

        if ($showaddress) {
            $blkWidth = (float) ($theme->address_block_width ?: ($this->page_width - 4) / 2);

            // --- Bloc adresse émetteur ---
            $addrLeft  = $theme->reverse_address ? ($this->marge_gauche + $blkWidth + 4) : $this->marge_gauche;
            $addrRight = $theme->reverse_address ? $this->marge_gauche : ($this->marge_gauche + $blkWidth + 4);

            // Émetteur
            $pdf->SetFillColor($colorAddress['r'], $colorAddress['g'], $colorAddress['b']);
            $pdf->Rect($addrLeft, $posy, $blkWidth, 38, 'F');

            $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_sender, (int) ($theme->font_size_sender ?: 8));
            $colorFontSender = $theme->hex2rgb($theme->color_font_sender ?: ($theme->color_font ?: '#333333'));
            $pdf->SetTextColor($colorFontSender['r'], $colorFontSender['g'], $colorFontSender['b']);
            $pdf->SetXY($addrLeft + 2, $posy + 2);
            $senderLines = $mysoc->name."\n";
            $senderLines .= $mysoc->address."\n";
            $senderLines .= $mysoc->zip.' '.$mysoc->town;
            if ($mysoc->siret) {
                $senderLines .= "\nSIRET : ".$mysoc->siret;
            }
            if ($mysoc->tva_intra) {
                $senderLines .= "\nTVA : ".$mysoc->tva_intra;
            }
            $pdf->MultiCell($blkWidth - 4, 4, $outputlangs->convToOutputCharset($senderLines), 0, 'L', false);

            // --- Bloc adresse destinataire ---
            $colorAddress2 = $theme->hex2rgb($theme->color_address_bg2 ?: '#f0f4fa');
            $pdf->SetFillColor($colorAddress2['r'], $colorAddress2['g'], $colorAddress2['b']);
            $pdf->Rect($addrRight, $posy, $blkWidth, 38, 'F');

            $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_recipient, (int) ($theme->font_size_recipient ?: 8));
            $colorFontRecipient = $theme->hex2rgb($theme->color_font_recipient ?: ($theme->color_font ?: '#333333'));
            $pdf->SetTextColor($colorFontRecipient['r'], $colorFontRecipient['g'], $colorFontRecipient['b']);
            $pdf->SetXY($addrRight + 2, $posy + 2);

            $thirdparty = $object->thirdparty;
            if (is_object($thirdparty)) {
                $recipLines = $thirdparty->name."\n";
                if ($thirdparty->address) {
                    $recipLines .= $thirdparty->address."\n";
                }
                $recipLines .= $thirdparty->zip.' '.$thirdparty->town;
                if ($thirdparty->country) {
                    $recipLines .= "\n".$thirdparty->country;
                }
                if ($thirdparty->tva_intra) {
                    $recipLines .= "\nTVA : ".$thirdparty->tva_intra;
                }
                $pdf->MultiCell($blkWidth - 4, 4, $outputlangs->convToOutputCharset($recipLines), 0, 'L', false);
            }

            $posy += 44;
        }

        return $posy;
    }

    /**
     * Affiche l'en-tête du tableau des lignes de produits
     *
     * @param TCPDF    $pdf         Objet PDF
     * @param float    $tab_top     Position Y du haut du tableau
     * @param float    $tab_height  Hauteur du tableau
     * @param int      $nexY        Pas utilisé ici
     * @param Translate $outputlangs Langue
     * @param int      $hidetop     1 = masquer l'en-tête
     * @param int      $hidebottom  1 = masquer le bas
     * @param string   $currency    Code monétaire
     */
    private function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop = 0, $hidebottom = 0, $currency = '')
    {
        global $conf;

        $theme = $this->theme;
        $colorHeader = $theme->hex2rgb($theme->color_header_bg ?: '#4a6fa1');
        $colorHeaderTxt = $theme->hex2rgb($theme->color_header_txt ?: '#ffffff');
        $colorBorder = $theme->hex2rgb($theme->color_border ?: '#cccccc');
        $colorFont = $theme->hex2rgb($theme->color_font ?: '#333333');

        $curX = $this->marge_gauche;
        $curY = $tab_top;

        if ($hidetop == 0) {
            // Fond de l'en-tête
            $pdf->SetFillColor($colorHeader['r'], $colorHeader['g'], $colorHeader['b']);
            $pdf->Rect($curX, $curY, $this->page_width, 6, 'F');

            $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_theader ?: 'B', (int) ($theme->font_size_theader ?: 7));
            $pdf->SetTextColor($colorHeaderTxt['r'], $colorHeaderTxt['g'], $colorHeaderTxt['b']);

            // Colonnes
            $colX = $curX;

            // Numérotation
            if (!empty($theme->show_line_numbers)) {
                $w = (float) ($theme->col_width_number ?: 8);
                $pdf->SetXY($colX, $curY + 1);
                $pdf->Cell($w, 4, '#', 0, 0, 'C', false);
                $colX += $w;
            }


            // Référence
            if (!empty($theme->show_ref_column)) {
                $w = (float) ($theme->col_width_ref ?: 22);
                $pdf->SetXY($colX, $curY + 1);
                $pdf->Cell($w, 4, $outputlangs->transnoentities('Ref'), 0, 0, 'L', false);
                $colX += $w;
            }

            // Description (largeur restante)
            $descWidth = $this->_getDescWidth();
            $pdf->SetXY($colX, $curY + 1);
            $pdf->Cell($descWidth, 4, $outputlangs->transnoentities('Description'), 0, 0, 'L', false);
            $colX += $descWidth;

            // TVA
            if (empty($theme->hide_vat_column)) {
                $w = (float) ($theme->col_width_vat ?: 13);
                $pdf->SetXY($colX, $curY + 1);
                $pdf->Cell($w, 4, $outputlangs->transnoentities('VAT'), 0, 0, 'C', false);
                $colX += $w;
            }

            // Prix unitaire HT
            if (empty($theme->hide_puht)) {
                $w = (float) ($theme->col_width_puht ?: 19);
                $pdf->SetXY($colX, $curY + 1);
                $pdf->Cell($w, 4, $outputlangs->transnoentities('PriceUHT'), 0, 0, 'R', false);
                $colX += $w;
            }

            // Quantité
            if (empty($theme->hide_qty)) {
                $w = (float) ($theme->col_width_qty ?: 13);
                $pdf->SetXY($colX, $curY + 1);
                $pdf->Cell($w, 4, $outputlangs->transnoentities('Qty'), 0, 0, 'C', false);
                $colX += $w;
            }

            // Remise
            if (empty($theme->hide_discount)) {
                $w = (float) ($theme->col_width_discount ?: 12);
                $pdf->SetXY($colX, $curY + 1);
                $pdf->Cell($w, 4, $outputlangs->transnoentities('ReductionShort'), 0, 0, 'C', false);
                $colX += $w;
            }

            // Total HT (ou TTC)
            $w = (float) ($theme->col_width_total ?: 22);
            $pdf->SetXY($colX, $curY + 1);
            $pdf->Cell($w, 4, empty($theme->show_line_ttc) ? $outputlangs->transnoentities('TotalHT') : $outputlangs->transnoentities('TotalTTC'), 0, 0, 'R', false);

            $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);
        }

        // Bordure du tableau
        $pdf->SetDrawColor($colorBorder['r'], $colorBorder['g'], $colorBorder['b']);
        if ($hidetop == 0) {
            $pdf->Line($this->marge_gauche, $tab_top + 6, $this->page_largeur - $this->marge_droite, $tab_top + 6);
        }
    }

    /**
     * Calcule la largeur disponible pour la colonne description
     * @return float Largeur en mm
     */
    private function _getDescWidth()
    {
        $theme  = $this->theme;
        $width  = $this->page_width;

        if (!empty($theme->show_line_numbers)) {
            $width -= (float) ($theme->col_width_number ?: 8);
        }
        if (!empty($theme->show_ref_column)) {
            $width -= (float) ($theme->col_width_ref ?: 22);
        }
        if (!empty($theme->show_pictures)) {
            $width -= (float) ($theme->col_width_img ?: 20);
        }
        if (empty($theme->hide_vat_column)) {
            $width -= (float) ($theme->col_width_vat ?: 13);
        }
        if (empty($theme->hide_puht)) {
            $width -= (float) ($theme->col_width_puht ?: 19);
        }
        if (empty($theme->hide_qty)) {
            $width -= (float) ($theme->col_width_qty ?: 13);
        }
        if (empty($theme->hide_discount)) {
            $width -= (float) ($theme->col_width_discount ?: 12);
        }
        $width -= (float) ($theme->col_width_total ?: 22);

        return max(20, $width);
    }

    /**
     * Affiche une ligne de produit
     *
     * @param TCPDF     $pdf            PDF
     * @param Object    $object         Facture
     * @param int       $i              Index de la ligne
     * @param float     $curY           Position Y courante
     * @param Translate $outputlangs    Langue
     * @param float     $heightff       Hauteur zone texte libre
     * @param float     $heightft       Hauteur zone totaux
     * @param float     $tab_top_np     Tab top nouvelle page
     * @return float Nouvelle position Y (négative si on a changé de page)
     */
    private function _outputLine(&$pdf, $object, $i, $curY, $outputlangs, $heightff, $heightft, $tab_top_np)
    {
        global $conf;

        $theme = $this->theme;
        $line  = $object->lines[$i];

        $colorFont   = $theme->hex2rgb($theme->color_font ?: '#333333');
        $colorBorder = $theme->hex2rgb($theme->color_border ?: '#cccccc');
        $colLeft = $theme->margin_left;

        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_desc, (int) ($theme->font_size_desc ?: $theme->font_size ?: 9));
        $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);
        $pdf->SetDrawColor($colorBorder['r'], $colorBorder['g'], $colorBorder['b']);

        // Fond alterné sur les lignes paires
        if ($i % 2 === 0) {
            $rgb = $theme->hex2rgb($theme->color_address_bg ?: '#f7f9fc');
            $pdf->SetFillColor($rgb['r'], $rgb['g'], $rgb['b']);
        } else {
            $pdf->SetFillColor(255, 255, 255);
        }

        $colX = $this->marge_gauche;

        // Description (pour calculer hauteur)
        $descLines = '';
        if (empty($theme->hide_desc_long) && !empty($line->desc) && $line->desc != $line->product_label) {
            $descLines = $outputlangs->convToOutputCharset($line->desc);
        }
        $mainLabel = $outputlangs->convToOutputCharset($line->label ?: $line->product_label ?: $line->desc);

        // --- Facture de situation (Phase 4) ---
        if (empty($theme->hide_situation) && isset($line->situation_percent) && $line->situation_percent > 0) {
            $mainLabel .= ' ('.$outputlangs->transnoentities("Situation").' : '.price($line->situation_percent, 0, $outputlangs, 0, 0, -1, '').'%)';
        }

        $descWidth = $this->_getDescWidth();
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', '', (int) ($theme->font_size ?: 9));

        // Nombre de lignes dans la cellule description
        $nbCharPerLine = max(1, (int) ($descWidth * 1.8));
        $wrap = wordwrap($mainLabel.($descLines ? "\n".$descLines : ''), $nbCharPerLine, "\n", true);
        $nlines = substr_count($wrap, "\n") + 1;
        $lineH = $this->line_height;
        $cellH = max($lineH, $nlines * ($lineH - 1));

        // Vérifier si on dépasse la page
        $heightForLine = $cellH + 2;
        $limit = $this->page_hauteur - $this->marge_basse - $heightff - $heightft;
        if ($curY + $heightForLine > $limit) {
            return -$curY; // Signal : nouvelle page
        }

        // Fond de ligne
        $pdf->Rect($colX, $curY, $this->page_width, $cellH + 1, 'F');

        // Numérotation
        if (!empty($theme->show_line_numbers)) {
            $w = (float) ($theme->col_width_number ?: 8);
            $pdf->SetXY($colX, $curY);
            $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_desc, (int) ($theme->font_size_desc ?: $theme->font_size ?: 9));
            $pdf->Cell($w, $cellH, ($i + 1), 0, 0, 'C', false);
            $colX += $w;
        }

        // Référence
        if (!empty($theme->show_ref_column)) {
            $w = (float) ($theme->col_width_ref ?: 22);
            $pdf->SetXY($colX, $curY);
            $pdf->MultiCell($w, $cellH, $outputlangs->convToOutputCharset($line->product_ref ?: ''), 0, 'L', false);
            $colX += $w;
        }

        // Description
        $pdf->SetXY($colX, $curY + 0.5);
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_desc, (int) ($theme->font_size_desc ?: 9));
        $pdf->MultiCell($descWidth, $lineH - 1, $outputlangs->convToOutputCharset($mainLabel), 0, 'L', false);
        if ($descLines && empty($theme->hide_desc_long)) {
            $pdf->SetXY($colX, $pdf->GetY());
            $pdf->SetFont($theme->font_family ?: 'DejaVuSans', '', (int) ($theme->font_size ?: 9) - 1);
            $pdf->MultiCell($descWidth, $lineH - 1.5, $descLines, 0, 'L', false);
        }
        $colX += $descWidth;

        // TVA
        if (empty($theme->hide_vat_column)) {
            $w = (float) ($theme->col_width_vat ?: 13);
            $pdf->SetXY($colX, $curY);
            $vatLabel = !empty($line->tva_tx) ? price($line->tva_tx, 0, $outputlangs).'%' : '';
            $pdf->Cell($w, $cellH, $vatLabel, 0, 0, 'C', false);
            $colX += $w;
        }

        // Prix unitaire HT
        if (empty($theme->hide_puht)) {
            $w = (float) ($theme->col_width_puht ?: 19);
            $pdf->SetXY($colX, $curY);
            $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_desc, (int) ($theme->font_size_desc ?: 9));
            $pdf->Cell($w, $cellH, price($line->subprice, 0, $outputlangs), 0, 0, 'R', false);
            $colX += $w;
        }

        // Quantité
        if (empty($theme->hide_qty)) {
            $w = (float) ($theme->col_width_qty ?: 13);
            $pdf->SetXY($colX, $curY);
            $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_desc, (int) ($theme->font_size_desc ?: 9));
            $pdf->Cell($w, $cellH, price($line->qty, 0, $outputlangs, 0, 0), 0, 0, 'C', false);
            $colX += $w;
        }

        // Remise
        if (empty($theme->hide_discount)) {
            $w = (float) ($theme->col_width_discount ?: 12);
            $pdf->SetXY($colX, $curY);
            $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_desc, (int) ($theme->font_size_desc ?: 9));
            $pdf->Cell($w, $cellH, ($line->remise_percent ? price($line->remise_percent, 0, $outputlangs).'%' : ''), 0, 0, 'C', false);
            $colX += $w;
        }

        // Total HT ou TTC
        $w = (float) ($theme->col_width_total ?: 22);
        $pdf->SetXY($colX, $curY);
        $total = empty($theme->show_line_ttc) ? $line->total_ht : $line->total_ttc;
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_desc, (int) ($theme->font_size_desc ?: 9));
        $pdf->Cell($w, $cellH, price($total, 0, $outputlangs), 0, 0, 'R', false);

        // Ligne de séparation
        $pdf->SetDrawColor($colorBorder['r'], $colorBorder['g'], $colorBorder['b']);
        $pdf->Line($this->marge_gauche, $curY + $cellH + 1, $this->page_largeur - $this->marge_droite, $curY + $cellH + 1);

        return $curY + $cellH + 1;
    }

    /**
     * Affiche le pied de page (totaux, RIB, texte libre, mentions légales)
     *
     * @param TCPDF     $pdf          PDF
     * @param Facture   $object       Facture
     * @param Translate $outputlangs  Langue
     * @param int       $hidefreetext 1 = masquer le texte libre
     */
    private function _pagefoot(&$pdf, $object, $outputlangs, $hidefreetext = 0)
    {
        global $conf, $mysoc;

        $theme = $this->theme;
        $colorHeader = $theme->hex2rgb($theme->color_header_bg ?: '#4a6fa1');
        $colorHeaderTxt = $theme->hex2rgb($theme->color_header_txt ?: '#ffffff');
        $colorBorder = $theme->hex2rgb($theme->color_border ?: '#cccccc');
        $colorFont   = $theme->hex2rgb($theme->color_font ?: '#333333');

        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', '', 7);
        $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);
        $pdf->SetDrawColor($colorBorder['r'], $colorBorder['g'], $colorBorder['b']);

        $curY = $pdf->GetY() + 4;

        // --- Zone totaux ---
        $totalWidth = 70;
        $totalX     = $this->page_largeur - $this->marge_droite - $totalWidth;
        
        if (!empty($theme->show_vat_breakdown)) {
            PdfBuilderTools::drawVatBreakdown($pdf, $object, $this->marge_gauche, $curY, $colorHeader, $colorHeaderTxt, $colorFont, $colorBorder, $theme->font_family, $outputlangs, (int) ($theme->font_size_theader ?: 8), $theme->font_style_theader, (int) ($theme->font_size_desc ?: 7), $theme->font_style_desc);
        }

        $pdf->SetFillColor($colorHeader['r'], $colorHeader['g'], $colorHeader['b']);
        $pdf->SetTextColor($colorHeaderTxt['r'], $colorHeaderTxt['g'], $colorHeaderTxt['b']);
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', 'B', 8);

        // Total HT
        $pdf->SetXY($totalX, $curY);
        $pdf->Cell($totalWidth / 2, 6, $outputlangs->transnoentities('TotalHT'), 'LTB', 0, 'L', true);
        $pdf->Cell($totalWidth / 2, 6, price($object->total_ht, 0, $outputlangs, 1, -1, -1, $conf->currency), 'RTB', 0, 'R', true);
        $curY += 6;

        // Remplissage en-têtes
        $pdf->SetFillColor($colorHeader['r'], $colorHeader['g'], $colorHeader['b']);
        $pdf->SetTextColor($colorHeaderTxt['r'], $colorHeaderTxt['g'], $colorHeaderTxt['b']);
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_theader, (int) ($theme->font_size_theader ?: 8));
        $pdf->SetXY($totalX, $curY);
        $pdf->Cell($totalWidth / 2, 5, $outputlangs->transnoentities('TotalVAT'), 'LB', 0, 'L', true);
        $pdf->Cell($totalWidth / 2, 5, price($object->total_tva, 0, $outputlangs, 1, -1, -1, $conf->currency), 'RB', 0, 'R', true);
        $curY += 5;

        // Total TTC
        $pdf->SetFillColor($colorHeader['r'], $colorHeader['g'], $colorHeader['b']);
        $pdf->SetTextColor($colorHeaderTxt['r'], $colorHeaderTxt['g'], $colorHeaderTxt['b']);
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', 'B', 10);
        $pdf->SetXY($totalX, $curY);
        $pdf->Cell($totalWidth / 2, 7, $outputlangs->transnoentities('TotalTTC'), 'LTB', 0, 'L', true);
        $pdf->Cell($totalWidth / 2, 7, price($object->total_ttc, 0, $outputlangs, 1, -1, -1, $conf->currency), 'RTB', 0, 'R', true);
        $curY += 7;
        
        $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_note, (int) ($theme->font_size_note ?: 8));

        // 1. Mentions complémentaires / Note publique ---
        if ($hidefreetext == 0) {
            $noteText = '';
            if (!empty($theme->note_public)) {
                $noteText .= $outputlangs->convToOutputCharset($theme->note_public);
            }
            if (!empty($object->note_public)) {
                if ($noteText) $noteText .= "\n";
                $noteText .= $outputlangs->convToOutputCharset($object->note_public);
            }

            if ($noteText) {
                $pdf->SetXY($this->marge_gauche, $curY + 2);
                $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_freetext ?: 'I', (int) ($theme->freetext_fontsize ?: 7));
                $pdf->MultiCell($this->page_width - $totalWidth - 5, (float) ($theme->freetext_height ?: 12), $noteText, 0, 'L', false);
            }
        }

        // === MARQUE DE PLI (Phase 3) ===
        if (!empty($theme->show_fold_mark)) {
            PdfBuilderTools::drawFoldMark($pdf, $this->marge_gauche);
        }

        // === QR CODE DOCUMENT (Phase 3) ===
        if (!empty($theme->color_qrcode) && $theme->color_qrcode !== '#000000_off') {
            PdfBuilderTools::drawDocumentQrCode(
                $pdf, $object,
                $this->marge_gauche, $curY + 4,
                22, $theme->color_qrcode ?: '#000000'
            );
        }

        // === INFOS BANCAIRES RIB/IBAN (Phase 3) ===
        if (empty($theme->hide_rib)) {
            PdfBuilderTools::drawBankInfo(
                $pdf,
                $object,
                $this->marge_gauche, $curY + 28,
                $this->page_width / 2,
                $colorFont, $colorBorder,
                $theme->font_family,
                !empty($conf->global->PDFBUILDER_BANK_HIDE_NUMBER),
                $outputlangs,
                (int) ($theme->font_size_note ?: 7),
                $theme->font_style_note
            );
        }

        // === ENCOURS CLIENT (Phase 3) ===
        PdfBuilderTools::drawOutstandingBalance(
            $pdf, $object,
            $totalX, $curY + 2,
            $totalWidth,
            $colorFont, $colorHeader,
            $theme->font_family,
            $outputlangs
        );

        // --- Mention auto-liquidation ---
        if (!empty($conf->global->PDFBUILDER_INVOICE_AUTO_LIQUIDATION)) {
            $pdf->SetXY($this->marge_gauche, $pdf->GetY() + 2);
            $pdf->SetFont($theme->font_family ?: 'DejaVuSans', 'I', 7);
            $pdf->MultiCell($this->page_width, 4, $outputlangs->transnoentities('PDFBuilderAutoLiquidationMsg'), 0, 'L', false);
        }

        // --- Mention LCR (Phase 4) ---
        if ($object->mode_reglement_code === 'LCR') {
            $pdf->SetXY($this->marge_gauche, $pdf->GetY() + 2);
            $pdf->SetFont($theme->font_family ?: 'DejaVuSans', 'IB', 7);
            $pdf->MultiCell($this->page_width, 4, $outputlangs->transnoentities('PdfBuilderLCRMention'), 0, 'L', false);
        }

        // --- Ligne de pied de page (numéros légaux) ---
        $footerY = $this->page_hauteur - $this->marge_basse;
        $pdf->SetDrawColor($colorBorder['r'], $colorBorder['g'], $colorBorder['b']);
        $pdf->Line($this->marge_gauche, $footerY - 8, $this->page_largeur - $this->marge_droite, $footerY - 8);

        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_footer, (int) ($theme->font_size_footer ?: 6));
        $pdf->SetXY($this->marge_gauche, $footerY - 7);

        $footerParts = array();
        if ($mysoc->name) {
            $footerParts[] = $mysoc->name;
        }
        if ($mysoc->siret) {
            $footerParts[] = 'SIRET : '.$mysoc->siret;
        }
        if ($mysoc->idprof1) {
            $footerParts[] = 'SIREN : '.$mysoc->idprof1;
        }
        if ($mysoc->tva_intra) {
            $footerParts[] = 'TVA : '.$mysoc->tva_intra;
        }
        if ($mysoc->capital) {
            $footerParts[] = 'Capital : '.price($mysoc->capital, 0, $outputlangs).' '.$conf->currency;
        }

        $pdf->MultiCell($this->page_width - 30, 6, implode(' — ', $footerParts), 0, 'C', false);

        // Numéro de page
        $pdf->SetXY($this->page_largeur - $this->marge_droite - 20, $footerY - 7);
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_footer, (int) ($theme->font_size_footer ?: 7));
        $pdf->Cell(20, 6, $pdf->getPage().'/'.$pdf->getNumPages(), 0, 0, 'R', false);
    }

    // (La méthode _addFileToECM a été supprimée car l'indexation est nativement gérée par CommonObject)

    /**
     * Génère le PDF en utilisant un PdfBuilderLayout (éditeur graphique) + PdfBuilderRenderer.
     * Appelé depuis write_file() quand $srctemplatepath est un ID de layout numérique.
     *
     * @param object    $object      Objet Facture Dolibarr
     * @param Translate $outputlangs Langue de sortie
     * @param int       $layout_id   ID du layout dans llx_pdfbuilder_layout
     * @return int <0 si erreur, 1 si OK
     */
    private function _writeFileWithLayout($object, $outputlangs, $layout_id)
    {
        global $user, $langs, $conf;

        dol_include_once('/pdfbuilder/class/pdfbuilderlayout.class.php');
        dol_include_once('/pdfbuilder/class/pdfbuilderrenderer.class.php');
        require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

        // Charger le layout
        $layout = new PdfBuilderLayout($this->db);
        if ($layout->fetch($layout_id) <= 0) {
            $this->error = 'PdfBuilderLayout '.$layout_id.' not found';
            dol_syslog('pdf_pdfbuilder_invoice::_writeFileWithLayout '.$this->error, LOG_ERR);
            return -1;
        }

        // Assurer que le tiers est chargé pour avoir accès au code client, TVA, etc.
        if (method_exists($object, 'fetch_thirdparty') && (empty($object->thirdparty) || !is_object($object->thirdparty))) {
            $object->fetch_thirdparty();
        }

        // Dossier de destination
        if (!empty($conf->facture->multidir_output[$object->entity])) {
            $dir = $conf->facture->multidir_output[$object->entity];
        } else {
            $dir = $conf->facture->dir_output;
        }

        if (empty($dir)) {
            $this->error = $langs->trans('ErrorUndefinedOutputDir');
            return -1;
        }

        $objectref = dol_sanitizeFileName($object->ref);
        if (!preg_match('/specimen/i', $objectref)) {
            $dir .= '/'.$objectref;
        }

        if (!file_exists($dir) && dol_mkdir($dir) < 0) {
            $this->error = $langs->trans('ErrorCantCreateDir', $dir);
            return -1;
        }

        $file = $dir.'/'.$objectref.'.pdf';

        // Init TCPDF
        $fmt   = strtoupper($layout->paper_format ?: 'A4');
        $pageW = ($fmt === 'A3') ? 297 : 210;
        $pageH = ($fmt === 'A3') ? 420 : 297;

        $pdf = pdf_getInstance(array($pageW, $pageH));
        if (class_exists('TCPDF')) {
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
        }
        $pdf->SetAutoPageBreak(1, 0);
        $pdf->SetTitle($outputlangs->convToOutputCharset($object->ref));
        $pdf->SetSubject($outputlangs->transnoentities('PdfInvoiceTitle'));
        $pdf->SetCreator('Dolibarr '.DOL_VERSION.' PDF-builder (layout #'.$layout_id.')');
        $pdf->SetAuthor($outputlangs->convToOutputCharset($user->getFullName($outputlangs)));
        if (getDolGlobalString('MAIN_DISABLE_PDF_COMPRESSION')) {
            $pdf->SetCompression(false);
        }
        $pdf->SetMargins($layout->margin_left, $layout->margin_top, $layout->margin_right);
        $pdf->SetFont('DejaVuSans', '', 9);
        $pdf->AddPage('P', array($pageW, $pageH));

        // Rendu par zones
        $renderer = new PdfBuilderRenderer($this->db, $layout);
        $renderer->render($pdf, $object, $outputlangs);

        $lp = $layout->getParamsAsArray();

        // Sauvegarde
        $pdf->Output($file, 'F');
        dolChmod($file);

        // Fond PDF (papier à en-tête via FPDI)
        if (!empty($lp['bg_pdf']) && file_exists($lp['bg_pdf'])) {
            dol_include_once('/pdfbuilder/class/pdfbuildertools.class.php');
            PdfBuilderTools::applyBackgroundPdf($file, $lp['bg_pdf']);
        }

        // Fusion PDF annexes
        if (!empty($lp['with_merged_pdf']) || getDolGlobalString('PDFBUILDER_INVOICE_WITH_MERGED_PDF')) {
            dol_include_once('/pdfbuilder/class/pdfbuildertools.class.php');
            $mergePdfs = PdfBuilderTools::getMergePdfs($this->db, $object, 'invoice');
            if (!empty($mergePdfs)) {
                array_unshift($mergePdfs, $file);
                PdfBuilderTools::mergePdfs($mergePdfs, $file);
            }
        }

        $this->result = array('fullpath' => $file);
        dol_syslog('pdf_pdfbuilder_invoice::_writeFileWithLayout generated '.$file.' (layout #'.$layout_id.')', LOG_DEBUG);

        return 1;
    }
}
