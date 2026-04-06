<?php
/* Copyright (C) 2024 Antigravity Project - GPL v2 or later */

/**
 * \file       pdfbuilder/core/modules/ficheinter/pdf_pdfbuilder_fichinter.class.php
 * \ingroup    pdfbuilder
 * \brief      Modèle PDF pour les fiches d'intervention - PDF-builder
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/fichinter/modules_fichinter.php';
require_once DOL_DOCUMENT_ROOT.'/fichinter/class/fichinter.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

dol_include_once('/pdfbuilder/class/pdfbuilder.class.php');
dol_include_once('/pdfbuilder/lib/pdfbuilder.lib.php');

/**
 * Classe de génération PDF pour les fiches d'intervention avec PDF-builder
 */
class pdf_pdfbuilder_fichinter extends ModelePDFFicheinter
{
    public $name = 'pdfbuilder_fichinter';
    public $description = 'Modèle PDF-builder pour les fiches d\'intervention';
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
        $this->theme = PdfBuilderTheme::getActive($db, 'fichinter');
        $langs->loadLangs(array('main', 'bills', 'products', 'dict', 'companies', 'interventions', 'pdfbuilder@pdfbuilder'));

        $this->page_largeur = $this->theme->paper_format == 'A3' ? 297 : 210;
        $this->page_hauteur = $this->theme->paper_format == 'A3' ? 420 : 297;
        $this->format       = array($this->page_largeur, $this->page_hauteur);
        $this->marge_gauche = (float) ($this->theme->margin_left  ?: 11);
        $this->marge_droite = (float) ($this->theme->margin_right ?: 10);
        $this->marge_haute  = (float) ($this->theme->margin_top   ?: 10);
        $this->marge_basse  = (float) ($this->theme->margin_bottom ?: 10);
        $this->page_width   = $this->page_largeur - $this->marge_gauche - $this->marge_droite;
    }

    public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
    {
        global $user, $langs, $conf, $mysoc;

        if (!is_object($outputlangs)) $outputlangs = $langs;
        $outputlangs->loadLangs(array('main', 'dict', 'companies', 'interventions', 'pdfbuilder@pdfbuilder'));

        $this->theme = PdfBuilderTheme::getActive($this->db, 'fichinter');
        $nblines = count($object->lines);

        if ($conf->ficheinter->multidir_output[$object->entity]) {
            $dir = $conf->ficheinter->multidir_output[$object->entity];
        } else {
            $dir = $conf->ficheinter->dir_output;
        }
        if (!$dir) { $this->error = $langs->trans('ErrorUndefinedOutputDir'); return -1; }

        $objectref = dol_sanitizeFileName($object->ref);
        if (!preg_match('/specimen/i', $objectref)) $dir .= '/'.$objectref;
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

        $heightforfooter = $this->marge_basse + 20;

        $pdf->AddPage('P', $this->format);
        $tab_top = $this->_pagehead($pdf, $object, 1, $outputlangs);
        $pdf->SetFont($this->theme->font_family ?: 'DejaVuSans', '', (int) ($this->theme->font_size ?: 9));
        $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);

        // En-tête du tableau des interventions : Date | Durée | Description
        $this->_tableau_header($pdf, $tab_top, $outputlangs);

        $curY = $tab_top + 7;
        for ($i = 0; $i < $nblines; $i++) {
            $line = $object->lines[$i];

            $colorBorder = $this->theme->hex2rgb($this->theme->color_border ?: '#cccccc');
            $pdf->SetDrawColor($colorBorder['r'], $colorBorder['g'], $colorBorder['b']);

            if ($i % 2 === 0) {
                $rgb = $this->theme->hex2rgb($this->theme->color_address_bg ?: '#f7f9fc');
                $pdf->SetFillColor($rgb['r'], $rgb['g'], $rgb['b']);
            } else {
                $pdf->SetFillColor(255, 255, 255);
            }

            // Hauteur de la ligne
            $desc = $outputlangs->convToOutputCharset($line->desc ?: '');
            $descWidth = $this->page_width - 30 - 20; // 30mm date, 20mm durée
            $nbCharPerLine = max(1, (int) ($descWidth * 1.8));
            $wrap = wordwrap($desc, $nbCharPerLine, "\n", true);
            $nlines = max(1, substr_count($wrap, "\n") + 1);
            $cellH = max($this->line_height, $nlines * ($this->line_height - 1));

            // Saut de page
            if ($curY + $cellH + 2 > $this->page_hauteur - $heightforfooter - 50) {
                $pdf->AddPage('P', $this->format);
                $tab_top = $this->_pagehead($pdf, $object, 0, $outputlangs);
                $this->_tableau_header($pdf, $tab_top, $outputlangs);
                $curY = $tab_top + 7;
            }

            $pdf->Rect($this->marge_gauche, $curY, $this->page_width, $cellH + 1, 'F');

            $colX = $this->marge_gauche;
            $w = (float) ($this->theme->col_width_vat ?: 13) + (float) ($this->theme->col_width_puht ?: 19);
            $pdf->SetXY($colX, $curY);
            $pdf->SetFont($this->theme->font_family ?: 'DejaVuSans', $this->theme->font_style_desc, (int) ($this->theme->font_size_desc ?: 9));
            $pdf->Cell($w, $cellH, dol_print_date($line->date ?: $object->date, 'day', 'output', $outputlangs), 0, 0, 'C');
            $colX += $w;

            // Durée
            $duree = '';
            if ($line->duration > 0) {
                $heures  = intdiv($line->duration, 3600);
                $minutes = intdiv($line->duration % 3600, 60);
                $duree   = $heures.'h'.str_pad($minutes, 2, '0', STR_PAD_LEFT);
            }
            $pdf->SetXY($this->marge_gauche + 30, $curY + 0.5);
            $pdf->SetFont($this->theme->font_family ?: 'DejaVuSans', $this->theme->font_style_desc, (int) ($this->theme->font_size_desc ?: 8));
            $pdf->Cell(20, $cellH, $duree, 0, 0, 'C');

            // Description
            $pdf->SetXY($colX, $curY + 0.5);
            $pdf->SetFont($this->theme->font_family ?: 'DejaVuSans', $this->theme->font_style_desc, (int) ($this->theme->font_size_desc ?: 9));
            $pdf->MultiCell($descWidth, $this->line_height - 1, $outputlangs->convToOutputCharset($line->label ?: $line->desc), 0, 'L');

            $pdf->Line($this->marge_gauche, $curY + $cellH + 1, $this->page_largeur - $this->marge_droite, $curY + $cellH + 1);

            $curY += $cellH + 1;
        }

        $heightforinfotot = 35; // Espace nécessaire pour total et signatures
        $pdf->SetY($curY);
        if ($curY + $heightforinfotot > $this->page_hauteur - $heightforfooter) {
            $pdf->AddPage('P', $this->format);
            $tab_top = $this->_pagehead($pdf, $object, 0, $outputlangs);
            $this->_tableau_header($pdf, $tab_top, $outputlangs);
            $curY = $tab_top + 7;
        }

        // Durée totale
        $totalDuration = 0;
        foreach ($object->lines as $l) $totalDuration += $l->duration;
        $totalH = intdiv($totalDuration, 3600);
        $totalM = intdiv($totalDuration % 3600, 60);

        $colorHeader = $this->theme->hex2rgb($this->theme->color_header_bg ?: '#4a6fa1');
        $pdf->SetFillColor($colorHeader['r'], $colorHeader['g'], $colorHeader['b']);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont($this->theme->font_family ?: 'DejaVuSans', 'B', 9);
        $pdf->SetXY($this->page_largeur - $this->marge_droite - 60, $curY + 3);
        $pdf->Cell(30, 6, $outputlangs->transnoentities('TotalDuration'), 0, 0, 'L', true);
        $pdf->Cell(30, 6, $totalH.'h'.str_pad($totalM, 2, '0', STR_PAD_LEFT), 0, 0, 'R', true);

        // Signature
        if (!empty($this->theme->show_signature)) {
            $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);
            $sigY = $curY + 14;
            $pdf->SetFont($this->theme->font_family ?: 'DejaVuSans', 'B', 8);
            $pdf->SetXY($this->marge_gauche, $sigY);
            $pdf->Cell(80, 5, $outputlangs->transnoentities('SignatureClient'), 0, 0, 'L');
            $pdf->Cell(80, 5, $outputlangs->transnoentities('SignatureTechnicien'), 0, 0, 'L');
            $sigY += 6;
            $pdf->Rect($this->marge_gauche, $sigY, 75, 22);
            $pdf->Rect($this->marge_gauche + 85, $sigY, 75, 22);
        }

        // Pied de page
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
        $pdf->MultiCell(90, 7, $outputlangs->transnoentities('InterventionCard'), 0, 'R');

        $pdf->SetXY($this->page_largeur - $this->marge_droite - 90, $posy + 8);
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', 'B', 10);
        $pdf->MultiCell(90, 6, $outputlangs->convToOutputCharset($object->ref), 0, 'R');

        // Description de l'intervention
        $nextY = $posy + 15;
        $pdf->SetXY($this->page_largeur - $this->marge_droite - 90, $nextY);
        $pdf->MultiCell(90, 5, $outputlangs->transnoentities('Date').': '.dol_print_date($object->datec, 'day', false, $outputlangs, true), 0, 'R');
        $nextY += 5;

        if ($object->description) {
            $pdf->SetXY($this->page_largeur - $this->marge_droite - 90, $nextY);
            $pdf->SetFont($theme->font_family ?: 'DejaVuSans', 'I', 7);
            $pdf->MultiCell(90, 4, $outputlangs->convToOutputCharset(dol_trunc($object->description, 120)), 0, 'R');
            $nextY += 4;
        }

        // Code client
        if (!empty($theme->show_customer_code) && !empty($object->thirdparty->code_client)) {
            $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_recipient, (int) ($theme->font_size_recipient ?: 8));
            $colorFontRecipient = $theme->hex2rgb($theme->color_font_recipient ?: ($theme->color_font ?: '#333333'));
            $pdf->SetTextColor($colorFontRecipient['r'], $colorFontRecipient['g'], $colorFontRecipient['b']);
            $pdf->SetXY($this->page_largeur - $this->marge_droite - 90, $nextY);
            $pdf->MultiCell(90, 5, $outputlangs->transnoentities('CustomerCode').': '.$object->thirdparty->code_client, 0, 'R', false);
            $nextY += 5;
        }

        // --- Calcul de la nouvelle position Y pour éviter les chevauchements ---
        $logoHeight = (float) ($theme->logo_height ?: 18);
        $headerInfoHeight = $nextY - $this->marge_haute;
        $posy = $this->marge_haute + max($logoHeight, $headerInfoHeight) + (float) ($theme->header_spacing ?: 2.0);

        if ($showaddress) {
            $blkWidth = ($this->page_width - 4) / 2;

            $pdf->SetFillColor($colorAddress['r'], $colorAddress['g'], $colorAddress['b']);
            $pdf->Rect($this->marge_gauche, $posy, $blkWidth, 38, 'F');

            $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_sender, (int) ($theme->font_size_sender ?: 8));
            $colorFontSender = $theme->hex2rgb($theme->color_font_sender ?: ($theme->color_font ?: '#333333'));
            $pdf->SetTextColor($colorFontSender['r'], $colorFontSender['g'], $colorFontSender['b']);
            $pdf->SetXY($this->marge_gauche + 2, $posy + 2);
            $sLines = $mysoc->name."\n".$mysoc->address."\n".$mysoc->zip.' '.$mysoc->town;
            $pdf->MultiCell($blkWidth - 4, 4, $outputlangs->convToOutputCharset($sLines), 0, 'L');

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
                $pdf->MultiCell($blkWidth - 4, 4, $outputlangs->convToOutputCharset($rLines), 0, 'L');
            }

            $posy += 44;
        }
        return $posy;
    }

    private function _tableau_header(&$pdf, $tab_top, $outputlangs)
    {
        $theme = $this->theme;
        $colorHeader = $theme->hex2rgb($theme->color_header_bg ?: '#4a6fa1');
        $colorHeaderTxt = $theme->hex2rgb($theme->color_header_txt ?: '#ffffff');
        $colorBorder = $theme->hex2rgb($theme->color_border ?: '#cccccc');

        $pdf->SetFillColor($colorHeader['r'], $colorHeader['g'], $colorHeader['b']);
        $pdf->Rect($this->marge_gauche, $tab_top, $this->page_width, 6, 'F');
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', 'B', 7);
        $pdf->SetTextColor($colorHeaderTxt['r'], $colorHeaderTxt['g'], $colorHeaderTxt['b']);

        $pdf->SetXY($this->marge_gauche, $tab_top + 1);
        $pdf->Cell(30, 4, $outputlangs->transnoentities('Date'), 0, 0, 'L');
        $pdf->Cell(20, 4, $outputlangs->transnoentities('Duration'), 0, 0, 'C');
        $pdf->Cell($this->page_width - 50, 4, $outputlangs->transnoentities('Description'), 0, 0, 'L');

        $pdf->SetDrawColor($colorBorder['r'], $colorBorder['g'], $colorBorder['b']);
        $pdf->Line($this->marge_gauche, $tab_top + 6, $this->page_largeur - $this->marge_droite, $tab_top + 6);
    }

    private function _pagefoot(&$pdf, $object, $outputlangs)
    {
        global $conf, $mysoc;
        $theme = $this->theme;
        $colorBorder = $theme->hex2rgb($theme->color_border ?: '#cccccc');
        $colorFont = $theme->hex2rgb($theme->color_font ?: '#333333');

        $footerY = $this->page_hauteur - $this->marge_basse;

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
            $pdf->SetXY($this->marge_gauche, $footerY - 22); // Rehaussé par rapport à la ligne de pied de page
            $pdf->SetFont($theme->font_family ?: 'DejaVuSans', 'I', (int) ($theme->freetext_fontsize ?: 7));
            $pdf->MultiCell($this->page_width, (float) ($theme->freetext_height ?: 12), $noteText, 0, 'L', false);
        }

        $pdf->SetDrawColor($colorBorder['r'], $colorBorder['g'], $colorBorder['b']);
        $pdf->Line($this->marge_gauche, $footerY - 8, $this->page_largeur - $this->marge_droite, $footerY - 8);

        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_footer, (int) ($theme->font_size_footer ?: 6));
        $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);
        $pdf->SetXY($this->marge_gauche, $footerY - 7);
        $fp = array(); if ($mysoc->name) $fp[] = $mysoc->name; if ($mysoc->siret) $fp[] = 'SIRET : '.$mysoc->siret; if ($mysoc->tva_intra) $fp[] = 'TVA : '.$mysoc->tva_intra;
        $pdf->MultiCell($this->page_width - 30, 6, implode(' — ', $fp), 0, 'C');

        $pdf->SetXY($this->page_largeur - $this->marge_droite - 20, $footerY - 7);
        $pdf->SetFont($theme->font_family ?: 'DejaVuSans', $theme->font_style_footer, (int) ($theme->font_size_footer ?: 7));
        $pdf->Cell(20, 6, $pdf->getPage().'/'.$pdf->getNumPages(), 0, 0, 'R');
    }
}
