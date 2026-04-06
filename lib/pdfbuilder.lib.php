<?php
/* Copyright (C) 2024 Antigravity Project
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       pdfbuilder/lib/pdfbuilder.lib.php
 * \ingroup    pdfbuilder
 * \brief      Fonctions utilitaires du module PDF-builder
 */

/**
 * Prépare les onglets de la page d'administration
 * @return array Tableau des onglets pour dol_fiche_head()
 */
function pdfbuilder_prepare_head()
{
    global $langs, $conf;

    $langs->load('pdfbuilder@pdfbuilder');

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath('/pdfbuilder/admin/designer.php', 1);
    $head[$h][1] = $langs->trans('PdfBuilderDesigner');
    $head[$h][2] = 'designer';
    $h++;

    $head[$h][0] = dol_buildpath('/pdfbuilder/admin/backgrounds.php', 1);
    $head[$h][1] = $langs->trans('PDFBuilderBackgrounds');
    $head[$h][2] = 'backgrounds';
    $h++;

    $head[$h][0] = dol_buildpath('/pdfbuilder/admin/about.php', 1);
    $head[$h][1] = $langs->trans('PdfBuilderAbout');
    $head[$h][2] = 'about';
    $h++;

    complete_head_from_modules($conf, $langs, null, $head, $h, 'pdfbuilder');

    return $head;
}

/**
 * Convertit une couleur HEX en tableau [R, G, B] compatible FPDF
 * @param string $hex Couleur HEX avec ou sans # (ex: #4a6fa1 ou 4a6fa1)
 * @return array [r => int, g => int, b => int]
 */
function pdfbuilder_hex2rgb($hex)
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    return array(
        'r' => hexdec(substr($hex, 0, 2)),
        'g' => hexdec(substr($hex, 2, 2)),
        'b' => hexdec(substr($hex, 4, 2)),
    );
}

/**
 * Retourne le thème actif pour un type de document (wrapper statique)
 * Charge la classe si nécessaire.
 * @param DoliDB $db Connexion base de données
 * @param string $doc_type Type de document
 * @return PdfBuilderTheme Thème actif (ou thème par défaut si aucun en BDD)
 */
function pdfbuilder_get_active_theme($db, $doc_type)
{
    if (!class_exists('PdfBuilderTheme')) {
        require_once dol_buildpath('/pdfbuilder/class/pdfbuilder.class.php', 0);
    }
    return PdfBuilderTheme::getActive($db, $doc_type);
}

/**
 * Retourne les types de documents supportés avec leur libellé
 * @return array ['code' => 'Libellé']
 */
function pdfbuilder_get_doc_types()
{
    global $langs;

    $langs->load('pdfbuilder@pdfbuilder');

    return array(
        'invoice'          => $langs->trans('PdfBuilderDocTypeInvoice'),
        'propal'           => $langs->trans('PdfBuilderDocTypePropal'),
        'order'            => $langs->trans('PdfBuilderDocTypeOrder'),
        'supplier_invoice' => $langs->trans('PdfBuilderDocTypeSupplierInvoice'),
        'supplier_order'   => $langs->trans('PdfBuilderDocTypeSupplierOrder'),
        'supplier_proposal' => $langs->trans('PdfBuilderDocTypeSupplierProposal'),
        'shipment'         => $langs->trans('PdfBuilderDocTypeShipment'),
        'receipt'          => $langs->trans('PdfBuilderDocTypeReceipt'),
        'contract'         => $langs->trans('PdfBuilderDocTypeContract'),
        'fichinter'        => $langs->trans('PdfBuilderDocTypeFichinter'),
        'expensereport'    => $langs->trans('PdfBuilderDocTypeExpenseReport'),
    );
}

/**
 * Retourne les polices disponibles pour FPDF
 * @return array ['code' => 'Libellé']
 */
function pdfbuilder_get_fonts()
{
    return array(
        'DejaVuSans'            => 'DejaVu Sans (recommandé)',
        'DejaVuSansCondensed'   => 'DejaVu Sans Condensed',
        'Helvetica'             => 'Helvetica (native)',
        'Arial'                 => 'Arial (native)',
        'Times'                 => 'Times New Roman (native)',
        'Courier'               => 'Courier (native)',
    );
}

/**
 * Génère le HTML d'une mini-card couleur pour un thème (sélecteur visuel)
 * @param PdfBuilderTheme $theme Le thème
 * @return string HTML de la card
 */
function pdfbuilder_render_theme_card($theme)
{
    global $langs;

    $langs->load('pdfbuilder@pdfbuilder');

    $label       = dol_escape_htmltag($theme->label);
    $headerBg    = dol_escape_htmltag($theme->color_header_bg ?: '#4a6fa1');
    $headerTxt   = dol_escape_htmltag($theme->color_header_txt ?: '#ffffff');
    $addressBg   = dol_escape_htmltag($theme->color_address_bg ?: '#f0f4fa');
    $borderColor = dol_escape_htmltag($theme->color_border ?: '#cccccc');

    $defaultBadge = $theme->is_default
        ? '<span class="pdfbuilder-badge-default">'.$langs->trans('PdfBuilderDefault').'</span>'
        : '';
    $inactiveBadge = !$theme->active
        ? '<span class="pdfbuilder-badge-inactive">'.$langs->trans('Disabled').'</span>'
        : '';

    $editUrl      = dol_buildpath('/pdfbuilder/admin/setup.php', 1).'?action=edit&id='.$theme->id.'&token='.newToken();
    $dupUrl       = dol_buildpath('/pdfbuilder/admin/setup.php', 1).'?action=duplicate&id='.$theme->id.'&token='.newToken();
    $delUrl       = dol_buildpath('/pdfbuilder/admin/setup.php', 1).'?action=delete&id='.$theme->id.'&token='.newToken();
    $defUrl       = dol_buildpath('/pdfbuilder/admin/setup.php', 1).'?action=setdefault&id='.$theme->id.'&token='.newToken();

    $html  = '<div class="pdfbuilder-theme-card" style="border-color:'.$borderColor.';">';
    $html .= '<div class="pdfbuilder-theme-preview" style="background:'.$headerBg.'; color:'.$headerTxt.';">';
    $html .= '<span class="pdfbuilder-theme-label">'.dol_escape_htmltag($label).'</span>';
    $html .= $defaultBadge.$inactiveBadge;
    $html .= '</div>';
    $html .= '<div class="pdfbuilder-theme-body" style="background:'.$addressBg.';">';
    $html .= '<div class="pdfbuilder-theme-font">'.dol_escape_htmltag($theme->font_family).' / '.$theme->font_size.'pt</div>';
    $html .= '</div>';
    $html .= '<div class="pdfbuilder-theme-actions">';
    $html .= '<a href="'.$editUrl.'" class="pdfbuilder-action-btn" title="'.$langs->trans('Modify').'">✏️</a>';
    $html .= '<a href="'.$dupUrl.'" class="pdfbuilder-action-btn" title="'.$langs->trans('PdfBuilderDuplicate').'">📋</a>';
    if (!$theme->is_default) {
        $html .= '<a href="'.$defUrl.'" class="pdfbuilder-action-btn" title="'.$langs->trans('PdfBuilderSetDefault').'">⭐</a>';
        $html .= '<a href="'.$delUrl.'" class="pdfbuilder-action-btn pdfbuilder-action-del" title="'.$langs->trans('Delete').'" onclick="return confirm(\''.dol_escape_js($langs->trans('PdfBuilderConfirmDelete', $label)).'\');">🗑️</a>';
    }
    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

/**
 * Retourne le layout actif pour un type de document
 * @param DoliDB $db        Connexion base de données
 * @param string $doc_type  Type de document
 * @return PdfBuilderLayout|null Layout actif ou null si aucun
 */
function pdfbuilder_get_active_layout($db, $doc_type)
{
    if (!class_exists('PdfBuilderLayout')) {
        dol_include_once('/pdfbuilder/class/pdfbuilderlayout.class.php');
    }
    $layout = new PdfBuilderLayout($db);
    if ($layout->fetchDefault($doc_type) > 0) {
        return $layout;
    }
    return null;
}

/**
 * Alias pour pdfbuilder_prepare_head (compatibilité interne)
 * @return array
 */
function pdfbuilderAdminPrepareHead()
{
    return pdfbuilder_prepare_head();
}
