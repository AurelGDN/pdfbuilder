<?php
/* Copyright (C) 2024 Antigravity Project - GPL v2 or later */
/**
 * \file    pdfbuilder/admin/options.php
 * \ingroup pdfbuilder
 * \brief   Redirige vers le designer (onglet supprimé)
 */

$res = 0;
if (!$res && file_exists('../../main.inc.php'))   $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

header('Location: '.dol_buildpath('/pdfbuilder/admin/designer.php', 1));
exit;
