<?php
/* Copyright (C) 2024 Antigravity Project - GPL v2 or later */
/**
 * \file    pdfbuilder/admin/about.php
 * \ingroup pdfbuilder
 * \brief   Page à propos du module PDF-builder
 */

$res = 0;
if (!$res && file_exists('../../main.inc.php'))   $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/pdfbuilder/lib/pdfbuilder.lib.php');

$langs->loadLangs(array('admin', 'pdfbuilder@pdfbuilder'));

if (!$user->admin) {
    accessforbidden();
}

$wikihelp = 'FR:Module_Pdfbuilder_FR';
llxHeader('', $langs->trans('PdfBuilderAbout'), $wikihelp, '', 0, 0, array('/pdfbuilder/css/pdfbuilder.css'));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('PdfBuilderAbout'), $linkback, 'pdfbuilder@pdfbuilder');

$head = pdfbuilder_prepare_head();
print dol_get_fiche_head($head, 'about', $langs->trans('PdfBuilderAbout'), -1, 'pdfbuilder@pdfbuilder');

print '<div class="pdfbuilder-about">';
print '<h2>PDF-builder <span class="pdfbuilder-version-badge">v1.0.0</span></h2>';
print '<p>'.$langs->trans('PdfBuilderAboutDescription').'</p>';

print '<div class="pdfbuilder-about-grid">';

// Fonctionnalités
print '<div class="pdfbuilder-about-card">';
print '<h3> '.$langs->trans('PdfBuilderAboutFeatures').'</h3>';
print '<ul>';
print '<li>'.$langs->trans('PdfBuilderFeatureThemes').'</li>';
print '<li>'.$langs->trans('PdfBuilderFeatureColors').'</li>';
print '<li>'.$langs->trans('PdfBuilderFeatureColumns').'</li>';
print '<li>'.$langs->trans('PdfBuilderFeatureDocs').'</li>';
print '<li>'.$langs->trans('PdfBuilderFeatureMerge').'</li>';
print '<li>'.$langs->trans('PdfBuilderFeatureFR').'</li>';
print '</ul>';
print '</div>';

// Compatibilité
print '<div class="pdfbuilder-about-card">';
print '<h3> '.$langs->trans('PdfBuilderAboutCompatibility').'</h3>';
print '<ul>';
print '<li>Dolibarr 20.x, 21.x, 22.x, 23.x</li>';
print '<li>PHP 8.0+</li>';
print '<li>FPDF / TCPDF (natif Dolibarr)</li>';
print '<li>Multi-entités</li>';
print '</ul>';
print '</div>';

// À propos
print '<div class="pdfbuilder-about-card">';
print '<h3> '.$langs->trans('PdfBuilderAboutDev').'</h3>';
print '<ul>';
print '<li><a href="https://bergerie-aurelien.com" target="_blank">Antigravity Project</a></li>';
print '<li>GPL v2 or later</li>';
print '<li>Architecture Active Record</li>';
print '<li>Thèmes sauvegardés en BDD</li>';
print '</ul>';
print '</div>';

print '</div>'; // pdfbuilder-about-grid
print '</div>'; // pdfbuilder-about

print dol_get_fiche_end();
llxFooter();
$db->close();
