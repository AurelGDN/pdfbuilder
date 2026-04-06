<?php
/* Copyright (C) 2024 Antigravity Project - GPL v2 or later */
// Options commandes déplacées dans le Designer (panneau Paramètres du document)
$res = @include_once '../../../main.inc.php';
if (!$res) die('Include of main fails');
header('Location: '.dol_buildpath('/pdfbuilder/admin/designer.php', 1));
exit;
