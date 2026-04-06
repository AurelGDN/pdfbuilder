<?php
/* Copyright (C) 2024 Antigravity Project - GPL v2 or later */

/**
 * \file       pdfbuilder/core/modules/supplier_order/pdf_pdfbuilder_supplierorder.class.php
 * \ingroup    pdfbuilder
 * \brief      Modèle PDF pour les commandes fournisseurs - PDF-builder
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/supplier_order/modules_commandefournisseur.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

dol_include_once('/pdfbuilder/class/pdfbuilder.class.php');
dol_include_once('/pdfbuilder/lib/pdfbuilder.lib.php');

/**
 * Classe de génération PDF pour les commandes fournisseurs avec PDF-builder
 */
class pdf_pdfbuilder_supplierorder extends ModelePDFSuppliersOrders
{
    public $name = 'pdfbuilder_supplierorder';
    public $description = 'Modèle PDF-builder pour les commandes fournisseurs';
    public $version = '1.0.0';
    public $type = 'pdf';

    /** @var PdfBuilderTheme */
    private $theme;
    public $db;
    private $line_height = 5.5;
    private $page_width;

    public function __construct($db)
    {
        global $langs;
        $this->db = $db;
        $this->theme = PdfBuilderTheme::getActive($db, 'supplier_order');
        $langs->loadLangs(array('main', 'bills', 'products', 'dict', 'companies', 'orders', 'pdfbuilder@pdfbuilder'));

        $this->page_largeur = $this->theme->paper_format == 'A3' ? 297 : 210;
        $this->page_hauteur = $this->theme->paper_format == 'A3' ? 420 : 297;
        $this->format       = array($this->page_largeur, $this->page_hauteur);
        $this->marge_gauche = (float) ($this->theme->margin_left  ?: 11);
        $this->marge_droite = (float) ($this->theme->margin_right ?: 10);
        $this->marge_haute  = (float) ($this->theme->margin_top   ?: 10);
        $this->marge_basse  = (float) ($this->theme->margin_bottom ?: 10);
        $this->page_width = $this->page_largeur - $this->marge_gauche - $this->marge_droite;
    }

    public function write_file($object, $outputlangs = '', $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
    {
        global $user, $langs, $conf, $mysoc;

        if (!is_object($outputlangs)) $outputlangs = $langs;
        $outputlangs->loadLangs(array('main', 'dict', 'companies', 'orders', 'products', 'pdfbuilder@pdfbuilder'));

        $this->theme = PdfBuilderTheme::getActive($this->db, 'supplier_order');
        $nblines = count($object->lines);

        if ($conf->fournisseur->commande->multidir_output[$object->entity]) {
            $dir = $conf->fournisseur->commande->multidir_output[$object->entity];
        } elseif (isset($conf->supplier_order->multidir_output[$object->entity])) {
            $dir = $conf->supplier_order->multidir_output[$object->entity];
        } else {
            $dir = $conf->fournisseur->commande->dir_output;
        }
        if (!$dir) { $this->error = $langs->trans('ErrorUndefinedOutputDir'); return -1; }

        $objectref = dol_sanitizeFileName($object->ref);
        if (!preg_match('/specimen/i', $objectref)) {
            $dir .= '/'.get_exdir($object->id, 2, 0, 0, $object, 'order_supplier').$objectref;
        }
        if (!file_exists($dir)) { if (dol_mkdir($dir) < 0) { $this->error = $langs->trans('ErrorCantCreateDir', $dir); return -1; } }

        $filename = $objectref.'.pdf';
        $file     = $dir.'/'.$filename;

        $pdf = pdf_getInstance($this->format);
        if (class_exists('TCPDF')) { $pdf->setPrintHeader(false); $pdf->setPrintFooter(false); }
        $pdf->SetAutoPageBreak(1, 0);
        $pdf->SetTitle($outputlangs->convToOutputCharset($object->ref));
        $pdf->SetCreator('Dolibarr '.DOL_VERSION.' PDF-builder '.$this->version);
        $pdf->SetAuthor($outputlangs->convToOutputCharset($user->getFullName($outputlangs)));
        if (getDolGlobalString('MAIN_DISABLE_PDF_COMPRESSION')) $pdf->SetCompression(false);

        // Assurer que le tiers est chargé pour avoir accès au code client, TVA, etc.
        if (method_exists($object, 'fetch_thirdparty') && (empty($object->thirdparty) || !is_object($object->thirdparty))) {
            $object->fetch_thirdparty();
        }

        $colorFont = $this->theme->hex2rgb($this->theme->color_font ?: '#333333');

        $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);
        $pdf->SetFont($this->theme->font_family ?: 'DejaVuSans', '', (int) ($this->theme->font_size ?: 9));
        $pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);

        $heightforfreetext = (float) ($this->theme->freetext_height ?: 12);
        $heightforfooter   = $this->marge_basse + $heightforfreetext + 7;
        $heightforinfotot  = 50;

        $pdf->AddPage('P', $this->format);
        $tab_top = $this->_pagehead($pdf, $object, 1, $outputlangs);
        $pdf->SetFont($this->theme->font_family ?: 'DejaVuSans', '', (int) ($this->theme->font_size ?: 9));
        $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);

        $tab_top_newpage = !empty($this->theme->no_repeat_header) ? $this->marge_haute + 5 : $tab_top;

        $this->_tableau($pdf, $tab_top, $this->page_hauteur - $heightforfooter - $tab_top, 0, $outputlangs);

        $curY = $tab_top + 7;
        for ($i = 0; $i < $nblines; $i++) {
            $curY = $this->_outputLine($pdf, $object, $i, $curY, $outputlangs, $heightforfooter, $heightforinfotot, $tab_top_newpage);
            if ($curY < 0) {
                $curY = -$curY;
                if (!empty($conf->global->MAIN_USE_FPDF)) {
                    $outputlangs->charset_output = 'ISO-8859-1';
                }
                $pdf->AddPage('P', $this->format);
                if (empty($this->theme->no_repeat_header)) {
                    $tab_top = $this->_pagehead($pdf, $object, 0, $outputlangs);
                    $pdf->SetFont($this->theme->font_family ?: 'DejaVuSans', '', (int) ($this->theme->font_size ?: 9));
                } else { $tab_top = $tab_top_newpage; }
                $this->_tableau($pdf, $tab_top, $this->page_hauteur - $heightforfooter - $tab_top, 0, $outputlangs, 1);
                $curY = $tab_top + 7;
                $curY = $this->_outputLine($pdf, $object, $i, $curY, $outputlangs, $heightforfooter, $heightforinfotot, $tab_top_newpage);
            }
        }

        $pdf->SetY($curY);
        if ($curY + $heightforinfotot > $this->page_hauteur - $heightforfooter) {
            $pdf->AddPage('P', $this->format);
            if (empty($this->theme->no_repeat_header)) {
                $this->_pagehead($pdf, $object, 0, $outputlangs);
            }
            $pdf->SetY($tab_top_newpage + 7);
        }

        $this->_pagefoot($pdf, $object, $outputlangs);
        $pdf->Close();
        $pdf->Output($file, 'F');
        if (!empty($conf->global->MAIN_UMASK)) @chmod($file, octdec($conf->global->MAIN_UMASK));
        return 1;
    }

    private function _pagehead(&$pdf, $object, $showaddress, $outputlangs)
    {
        global $conf, $mysoc;
        $theme = $this->theme;
        $colorHeader = $theme->hex2rgb($theme->color_header_bg ?: '#4a6fa1');
        $colorAddress = $theme->hex2rgb($theme->color_address_bg ?: '#f0f4fa');
        $colorFont = $theme->hex2rgb($theme->color_font ?: '#333333');

        $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);
        $posy = $this->marge_haute;

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
            $pdf->Image($logoFile, $this->marge_gauche, $posy, 0, (float) ($theme->logo_height ?: 18), '', '', '', false, 150, '', false, false, 0, false, false, false);
        }

        $pdf->SetXY($this->page_largeur - $this->marge_droite - 90, $posy);
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', 'B', 14);
        $pdf->SetTextColor($colorHeader['r'], $colorHeader['g'], $colorHeader['b']);
        $pdf->MultiCell(90, 7, $outputlangs->transnoentities('SupplierOrder'), 0, 'R');

        $pdf->SetXY($this->page_largeur - $this->marge_droite - 90, $posy + 8);
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', 'B', 10);
        $pdf->MultiCell(90, 6, $outputlangs->convToOutputCharset($object->ref), 0, 'R');

        $nextY = $posy + 15;
        if ($object->ref_supplier) {
            $pdf->SetFont($theme->font_family ?: 'DejaVuSans', '', 8);
            $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);
            $pdf->SetXY($this->page_largeur - $this->marge_droite - 90, $nextY);
            $pdf->MultiCell(90, 5, $outputlangs->transnoentities('RefSupplier').': '.$outputlangs->convToOutputCharset($object->ref_supplier), 0, 'R');
            $nextY += 5;
        }

        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', '', 8);
        $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);
        $pdf->SetXY($this->page_largeur - $this->marge_droite - 90, $nextY);
        $pdf->MultiCell(90, 5, $outputlangs->transnoentities('Date').': '.dol_print_date($object->date, 'day', false, $outputlangs, true), 0, 'R');
        $nextY += 5;

        if ($object->delivery_date) {
            $pdf->SetXY($this->page_largeur - $this->marge_droite - 90, $nextY);
            $pdf->MultiCell(90, 5, $outputlangs->transnoentities('DateDeliveryPlanned').': '.dol_print_date($object->delivery_date, 'day', false, $outputlangs, true), 0, 'R');
            $nextY += 5;
        }

        // Code fournisseur
        if (!empty($theme->show_customer_code) && !empty($object->thirdparty->code_fournisseur)) {
            $pdf->SetXY($this->page_largeur - $this->marge_droite - 90, $nextY);
            $pdf->MultiCell(90, 5, $outputlangs->transnoentities('SupplierCode').': '.$object->thirdparty->code_fournisseur, 0, 'R', false);
            $nextY += 5;
        }

        // --- Calcul de la nouvelle position Y pour éviter les chevauchements ---
        $logoHeight = (float) ($theme->logo_height ?: 18);
        $headerInfoHeight = $nextY - $this->marge_haute;
        $posy = $this->marge_haute + max($logoHeight, $headerInfoHeight) + (float) ($theme->header_spacing ?: 2.0);

        if ($showaddress) {
            $blkWidth = (float) ($theme->address_block_width ?: ($this->page_width - 4) / 2);

            // Nous sommes l'émetteur de la commande
            $pdf->SetFillColor($colorAddress['r'], $colorAddress['g'], $colorAddress['b']);
            $pdf->Rect($this->marge_gauche, $posy, $blkWidth, 38, 'F');
 
            $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_sender, (int) ($theme->font_size_sender ?: 8));
            $colorFontSender = $theme->hex2rgb($theme->color_font_sender ?: ($theme->color_font ?: '#333333'));
            $pdf->SetTextColor($colorFontSender['r'], $colorFontSender['g'], $colorFontSender['b']);
            $pdf->SetXY($this->marge_gauche + 2, $posy + 2);
            $sLines = $mysoc->name."\n".$mysoc->address."\n".$mysoc->zip.' '.$mysoc->town;
            $pdf->MultiCell($blkWidth - 4, 4, $outputlangs->convToOutputCharset($sLines), 0, 'L');

            // Fournisseur est le destinataire de la commande
            $colorAddress2 = $theme->hex2rgb($theme->color_address_bg2 ?: '#f0f4fa');
            $pdf->SetFillColor($colorAddress2['r'], $colorAddress2['g'], $colorAddress2['b']);
            $pos2 = $this->marge_gauche + $blkWidth + 4;
            $pdf->Rect($pos2, $posy, $blkWidth, 38, 'F');
 
            $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_recipient, (int) ($theme->font_size_recipient ?: 8));
            $colorFontRecipient = $theme->hex2rgb($theme->color_font_recipient ?: ($theme->color_font ?: '#333333'));
            $pdf->SetTextColor($colorFontRecipient['r'], $colorFontRecipient['g'], $colorFontRecipient['b']);
            $pdf->SetXY($pos2 + 2, $posy + 2);
            $tp = $object->thirdparty;
            if (is_object($tp)) {
                $rLines = $tp->name."\n"; if ($tp->address) $rLines .= $tp->address."\n"; $rLines .= $tp->zip.' '.$tp->town;
                if ($tp->tva_intra) $rLines .= "\nTVA : ".$tp->tva_intra;
                $pdf->MultiCell($blkWidth - 4, 4, $outputlangs->convToOutputCharset($rLines), 0, 'L');
            }

            $posy += 44;
        }

        return $posy;
    }

    private function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop = 0)
    {
        $theme = $this->theme;
        $colorHeader = $theme->hex2rgb($theme->color_header_bg ?: '#4a6fa1');
        $colorHeaderTxt = $theme->hex2rgb($theme->color_header_txt ?: '#ffffff');
        $colorBorder = $theme->hex2rgb($theme->color_border ?: '#cccccc');
        $colorFont = $theme->hex2rgb($theme->color_font ?: '#333333');

        if ($hidetop == 0) {
            $pdf->SetFillColor($colorHeader['r'], $colorHeader['g'], $colorHeader['b']);
            $pdf->Rect($this->marge_gauche, $tab_top, $this->page_width, 6, 'F');
            $pdf->SetFont($theme->font_family ?: 'DejaVuSans', 'B', 7);
            $pdf->SetTextColor($colorHeaderTxt['r'], $colorHeaderTxt['g'], $colorHeaderTxt['b']);

            $colX = $this->marge_gauche;
            if (!empty($theme->show_line_numbers)) { $w = (float) ($theme->col_width_number ?: 8); $pdf->SetXY($colX, $tab_top + 1); $pdf->Cell($w, 4, '#', 0, 0, 'C'); $colX += $w; }
            if (!empty($theme->show_ref_column))   { $w = (float) ($theme->col_width_ref ?: 22); $pdf->SetXY($colX, $tab_top + 1); $pdf->Cell($w, 4, $outputlangs->transnoentities('Ref'), 0, 0, 'L'); $colX += $w; }

            $descWidth = $this->_getDescWidth();
            $pdf->SetXY($colX, $tab_top + 1); $pdf->Cell($descWidth, 4, $outputlangs->transnoentities('Description'), 0, 0, 'L'); $colX += $descWidth;

            if (empty($theme->hide_vat_column)) { $w = (float) ($theme->col_width_vat ?: 13); $pdf->SetXY($colX, $tab_top + 1); $pdf->Cell($w, 4, $outputlangs->transnoentities('VAT'), 0, 0, 'C'); $colX += $w; }
            if (empty($theme->hide_puht))       { $w = (float) ($theme->col_width_puht ?: 19); $pdf->SetXY($colX, $tab_top + 1); $pdf->Cell($w, 4, $outputlangs->transnoentities('PriceUHT'), 0, 0, 'R'); $colX += $w; }
            if (empty($theme->hide_qty))        { $w = (float) ($theme->col_width_qty ?: 13); $pdf->SetXY($colX, $tab_top + 1); $pdf->Cell($w, 4, $outputlangs->transnoentities('Qty'), 0, 0, 'C'); $colX += $w; }
            if (empty($theme->hide_discount))   { $w = (float) ($theme->col_width_discount ?: 12); $pdf->SetXY($colX, $tab_top + 1); $pdf->Cell($w, 4, $outputlangs->transnoentities('ReductionShort'), 0, 0, 'C'); $colX += $w; }

            $w = (float) ($theme->col_width_total ?: 22); $pdf->SetXY($colX, $tab_top + 1);
            $pdf->Cell($w, 4, empty($theme->show_line_ttc) ? $outputlangs->transnoentities('TotalHT') : $outputlangs->transnoentities('TotalTTC'), 0, 0, 'R');
            $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);
        }
        $pdf->SetDrawColor($colorBorder['r'], $colorBorder['g'], $colorBorder['b']);
        if ($hidetop == 0) $pdf->Line($this->marge_gauche, $tab_top + 6, $this->page_largeur - $this->marge_droite, $tab_top + 6);
    }

    private function _getDescWidth()
    {
        $theme = $this->theme; $width = $this->page_width;
        if (!empty($theme->show_line_numbers)) $width -= (float) ($theme->col_width_number ?: 8);
        if (!empty($theme->show_ref_column))   $width -= (float) ($theme->col_width_ref ?: 22);
        if (empty($theme->hide_vat_column))    $width -= (float) ($theme->col_width_vat ?: 13);
        if (empty($theme->hide_puht))          $width -= (float) ($theme->col_width_puht ?: 19);
        if (empty($theme->hide_qty))           $width -= (float) ($theme->col_width_qty ?: 13);
        if (empty($theme->hide_discount))      $width -= (float) ($theme->col_width_discount ?: 12);
        $width -= (float) ($theme->col_width_total ?: 22);
        return max(20, $width);
    }

    private function _outputLine(&$pdf, $object, $i, $curY, $outputlangs, $heightff, $heightft, $tab_top_np)
    {
        $theme = $this->theme;
        $line = $object->lines[$i];
        $colorFont = $theme->hex2rgb($theme->color_font ?: '#333333');
        $colorBorder = $theme->hex2rgb($theme->color_border ?: '#cccccc');

        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', '', (int) ($theme->font_size ?: 9));
        $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);
        $pdf->SetDrawColor($colorBorder['r'], $colorBorder['g'], $colorBorder['b']);
        if ($i % 2 === 0) { $rgb = $theme->hex2rgb($theme->color_address_bg ?: '#f7f9fc'); $pdf->SetFillColor($rgb['r'], $rgb['g'], $rgb['b']); } else { $pdf->SetFillColor(255, 255, 255); }

        $mainLabel = $outputlangs->convToOutputCharset($line->label ?: $line->product_label ?: $line->desc);
        $descLines = '';
        if (empty($theme->hide_desc_long) && !empty($line->desc) && $line->desc != $line->product_label) $descLines = $outputlangs->convToOutputCharset($line->desc);

        $descWidth = $this->_getDescWidth();
        $nbCharPerLine = max(1, (int) ($descWidth * 1.8));
        $wrap = wordwrap($mainLabel.($descLines ? "\n".$descLines : ''), $nbCharPerLine, "\n", true);
        $lineH = $this->line_height;
        $cellH = max($lineH, (substr_count($wrap, "\n") + 1) * ($lineH - 1));

        $limit = $this->page_hauteur - $this->marge_basse - $heightff - $heightft;
        if ($curY + $cellH + 2 > $limit) return -$curY;

        $colX = $this->marge_gauche;
        $pdf->Rect($colX, $curY, $this->page_width, $cellH + 1, 'F');

        if (!empty($theme->show_line_numbers)) { $w = (float) ($theme->col_width_number ?: 8); $pdf->SetXY($colX, $curY); $pdf->SetFont($theme->font_family ?: 'DejaVuSans', '', 7); $pdf->Cell($w, $cellH, ($i+1), 0, 0, 'C'); $pdf->SetFont($theme->font_family ?: 'DejaVuSans', '', (int) ($theme->font_size ?: 9)); $colX += $w; }
        if (!empty($theme->show_ref_column)) { $w = (float) ($theme->col_width_ref ?: 22); $pdf->SetXY($colX, $curY); $pdf->MultiCell($w, $cellH, $outputlangs->convToOutputCharset($line->product_ref ?: ''), 0, 'L'); $colX += $w; }

        $pdf->SetXY($colX, $curY + 0.5);
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_desc, (int) ($theme->font_size_desc ?: 9));
        $pdf->MultiCell($descWidth, $lineH - 1, $outputlangs->convToOutputCharset($mainLabel), 0, 'L');
        if ($descLines && empty($theme->hide_desc_long)) { $pdf->SetXY($colX, $pdf->GetY()); $pdf->SetFont($theme->font_family ?: 'DejaVuSans', '', (int) ($theme->font_size ?: 9) - 1); $pdf->MultiCell($descWidth, $lineH - 1.5, $descLines, 0, 'L'); }
        $colX += $descWidth;

        if (empty($theme->hide_vat_column)) { $w = (float) ($theme->col_width_vat ?: 13); $pdf->SetXY($colX, $curY); $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_desc, (int) ($theme->font_size_desc ?: 9)); $pdf->Cell($w, $cellH, (!empty($line->tva_tx) ? price($line->tva_tx, 0, $outputlangs).'%' : ''), 0, 0, 'C'); $colX += $w; }
        if (empty($theme->hide_puht)) { $w = (float) ($theme->col_width_puht ?: 19); $pdf->SetXY($colX, $curY); $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_desc, (int) ($theme->font_size_desc ?: 9)); $pdf->Cell($w, $cellH, price($line->subprice, 0, $outputlangs), 0, 0, 'R'); $colX += $w; }
        if (empty($theme->hide_qty)) { $w = (float) ($theme->col_width_qty ?: 13); $pdf->SetXY($colX, $curY); $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_desc, (int) ($theme->font_size_desc ?: 9)); $pdf->Cell($w, $cellH, price($line->qty, 0, $outputlangs, 0, 0), 0, 0, 'C'); $colX += $w; }
        if (empty($theme->hide_discount)) { $w = (float) ($theme->col_width_discount ?: 12); $pdf->SetXY($colX, $curY); $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_desc, (int) ($theme->font_size_desc ?: 9)); $pdf->Cell($w, $cellH, ($line->remise_percent ? price($line->remise_percent, 0, $outputlangs).'%' : ''), 0, 0, 'C'); $colX += $w; }

        $w = (float) ($theme->col_width_total ?: 22); $pdf->SetXY($colX, $curY);
        $total = empty($theme->show_line_ttc) ? $line->total_ht : $line->total_ttc;
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_desc, (int) ($theme->font_size_desc ?: 9));
        $pdf->Cell($w, $cellH, price($total, 0, $outputlangs), 0, 0, 'R');

        $pdf->Line($this->marge_gauche, $curY + $cellH + 1, $this->page_largeur - $this->marge_droite, $curY + $cellH + 1);
        return $curY + $cellH + 1;
    }

    private function _pagefoot(&$pdf, $object, $outputlangs)
    {
        global $conf, $mysoc;
        $theme = $this->theme;
        $colorHeader = $theme->hex2rgb($theme->color_header_bg ?: '#4a6fa1');
        $colorHeaderTxt = $theme->hex2rgb($theme->color_header_txt ?: '#ffffff');
        $colorBorder = $theme->hex2rgb($theme->color_border ?: '#cccccc');
        $colorFont = $theme->hex2rgb($theme->color_font ?: '#333333');

        $curY = $pdf->GetY() + 4;
        $totalWidth = 70;
        $totalX = $this->page_largeur - $this->marge_droite - $totalWidth;

        if (!empty($theme->show_vat_breakdown)) {
            PdfBuilderTools::drawVatBreakdown($pdf, $object, $this->marge_gauche, $curY, $colorHeader, $colorHeaderTxt, $colorFont, $colorBorder, $theme->font_family, $outputlangs);
        }

        $pdf->SetFillColor($colorHeader['r'], $colorHeader['g'], $colorHeader['b']);
        $pdf->SetTextColor($colorHeaderTxt['r'], $colorHeaderTxt['g'], $colorHeaderTxt['b']);
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', 'B', 8);
        $pdf->SetXY($totalX, $curY);
        $pdf->Cell($totalWidth / 2, 6, $outputlangs->transnoentities('TotalHT'), 'LTB', 0, 'L', true);
        $pdf->Cell($totalWidth / 2, 6, price($object->total_ht, 0, $outputlangs, 1, -1, -1, $conf->currency), 'RTB', 0, 'R', true);
        $curY += 6;

        $pdf->SetFillColor(220, 230, 245);
        $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', '', 8);
        $pdf->SetXY($totalX, $curY);
        $pdf->Cell($totalWidth / 2, 5, $outputlangs->transnoentities('TotalVAT'), 'LB', 0, 'L', true);
        $pdf->Cell($totalWidth / 2, 5, price($object->total_tva, 0, $outputlangs, 1, -1, -1, $conf->currency), 'RB', 0, 'R', true);
        $curY += 5;

        $pdf->SetFillColor($colorHeader['r'], $colorHeader['g'], $colorHeader['b']);
        $pdf->SetTextColor($colorHeaderTxt['r'], $colorHeaderTxt['g'], $colorHeaderTxt['b']);
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', 'B', 10);
        $pdf->SetXY($totalX, $curY);
        $pdf->Cell($totalWidth / 2, 7, $outputlangs->transnoentities('TotalTTC'), 'LTB', 0, 'L', true);
        $pdf->Cell($totalWidth / 2, 7, price($object->total_ttc, 0, $outputlangs, 1, -1, -1, $conf->currency), 'RTB', 0, 'R', true);

        $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);

        // Note publique
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
            $pdf->SetFont($theme->font_family ?: 'DejaVuSans', 'I', (int) ($theme->freetext_fontsize ?: 7));
            $pdf->MultiCell($this->page_width - $totalWidth - 5, (float) ($theme->freetext_height ?: 12), $noteText, 0, 'L', false);
        }

        $footerY = $this->page_hauteur - $this->marge_basse;
        $pdf->SetDrawColor($colorBorder['r'], $colorBorder['g'], $colorBorder['b']);
        $pdf->Line($this->marge_gauche, $footerY - 8, $this->page_largeur - $this->marge_droite, $footerY - 8);
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_footer, (int) ($theme->font_size_footer ?: 6));
        $pdf->SetXY($this->marge_gauche, $footerY - 7);
        $fp = array(); if ($mysoc->name) $fp[] = $mysoc->name; if ($mysoc->siret) $fp[] = 'SIRET : '.$mysoc->siret; if ($mysoc->tva_intra) $fp[] = 'TVA : '.$mysoc->tva_intra;
        $pdf->MultiCell($this->page_width - 30, 6, implode(' — ', $fp), 0, 'C');
        $pdf->SetXY($this->page_largeur - $this->marge_droite - 20, $footerY - 7);
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_footer, (int) ($theme->font_size_footer ?: 7));
        $pdf->Cell(20, 6, $pdf->getPage().'/'.$pdf->getNumPages(), 0, 0, 'R');
    }
}
