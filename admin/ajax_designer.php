<?php ob_start();
/* Copyright (C) 2024 Antigravity Project - GPL v2 or later */

/**
 * \file       pdfbuilder/admin/ajax_designer.php
 * \ingroup    pdfbuilder
 * \brief      Endpoint AJAX pour l'éditeur graphique de layouts PDF
 *
 * Actions :
 *   save            — Enregistre layout + zones complets
 *   load            — Retourne JSON du layout + zones
 *   preview         — Génère un PDF de prévisualisation et retourne l'URL
 *   delete_zone     — Supprime une zone
 *   duplicate_layout— Duplique un layout complet
 *   import_template — Importe un template JSON
 */

define('NOTOKENRENEWAL', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);

$res = @include_once '../../../main.inc.php';
if (!$res && file_exists($_SERVER['DOCUMENT_ROOT'].'/main.inc.php')) {
    include_once $_SERVER['DOCUMENT_ROOT'].'/main.inc.php';
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
$langs->loadLangs(array('main', 'admin', 'companies', 'bills', 'propale', 'orders', 'pdfbuilder@pdfbuilder'));
dol_include_once('/pdfbuilder/class/pdfbuilderlayout.class.php');
dol_include_once('/pdfbuilder/class/pdfbuilderzone.class.php');

if (!$user->admin) {
    http_response_code(403);
    die(json_encode(array('error' => 'Forbidden')));
}

header('Content-Type: application/json; charset=utf-8');

$action    = GETPOST('action', 'aZ09');
$layout_id = GETPOSTINT('layout_id');

// Vérification token CSRF — la vérification est faite par main.inc.php (comparaison contre $_SESSION['token']).
// verifCsrfToken() n'existe pas dans Dolibarr 23. Si le token est invalide, main.inc.php vide $action.

// ============================================================
// ACTION : save
// ============================================================
if ($action === 'save') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        // Fallback POST
        $body = array(
            'layout' => array(
                'id'           => $layout_id,
                'label'        => GETPOST('label', 'alpha'),
                'paper_format' => GETPOST('paper_format', 'aZ09'),
                'margin_top'   => GETPOST('margin_top', 'int'),
                'margin_left'  => GETPOST('margin_left', 'int'),
                'margin_right' => GETPOST('margin_right', 'int'),
                'margin_bottom'=> GETPOST('margin_bottom', 'int'),
            ),
            'zones' => array(),
        );
    }

    if (empty($body['layout']['id'])) {
        die(json_encode(array('error' => 'Missing layout id')));
    }

    $layout = new PdfBuilderLayout($db);
    if ($layout->fetch((int) $body['layout']['id']) <= 0) {
        die(json_encode(array('error' => 'Layout not found')));
    }

    // Mettre à jour le layout
    if (!empty($body['layout']['label'])) {
        $layout->label = $body['layout']['label'];
    }
    // S3 — vérification entity multicompanies
    if ((int) $layout->entity !== (int) $conf->entity) {
        http_response_code(403);
        die(json_encode(array('error' => 'Forbidden: layout belongs to another entity')));
    }

    if (!empty($body['layout']['paper_format'])) {
        $allowed_formats = array('A4', 'A3', 'LETTER');
        $layout->paper_format = in_array(strtoupper($body['layout']['paper_format']), $allowed_formats)
            ? strtoupper($body['layout']['paper_format'])
            : 'A4';
    }
    if (isset($body['layout']['margin_top']))    { $layout->margin_top    = (float) $body['layout']['margin_top']; }
    if (isset($body['layout']['margin_left']))   { $layout->margin_left   = (float) $body['layout']['margin_left']; }
    if (isset($body['layout']['margin_right']))  { $layout->margin_right  = (float) $body['layout']['margin_right']; }
    if (isset($body['layout']['margin_bottom'])) { $layout->margin_bottom = (float) $body['layout']['margin_bottom']; }
    if (isset($body['layout']['is_default']))    { $layout->is_default    = $body['layout']['is_default'] ? 1 : 0; }
    if (isset($body['layout']['params']) && is_array($body['layout']['params'])) {
        // Filtrage : on n'accepte que des scalaires dans params (sécurité)
        $allowedParams = array(
            'show_fold_mark', 'show_line_numbers', 'dash_between_lines',
            'add_target_details', 'add_client_details',
            'auto_liquidation', 'with_merged_pdf', 'show_lcr',
            'bg_image', 'bg_pdf', 'bg_opacity',
        );
        $existingParams = $layout->getParamsAsArray();
        foreach ($body['layout']['params'] as $k => $v) {
            if (in_array($k, $allowedParams) && (is_scalar($v) || is_null($v))) {
                $existingParams[$k] = $v;
            }
        }
        $layout->setParams($existingParams);
    }

    $ret = $layout->update($user);
    if ($ret < 0) {
        die(json_encode(array('error' => 'Layout update failed: '.$layout->error)));
    }

    // Reconstruire les zones : supprimer les zones supprimées, créer/mettre à jour les autres
    $incoming = is_array($body['zones']) ? $body['zones'] : array();
    $incomingIds = array();

    foreach ($incoming as $zData) {
        if (!empty($zData['id']) && $zData['id'] > 0) {
            // Mise à jour
            $zone = new PdfBuilderZone($db);
            if ($zone->fetch((int) $zData['id']) > 0 && $zone->fk_layout == $layout->id) {
                $zone->zone_type    = !empty($zData['zone_type']) ? $zData['zone_type'] : $zone->zone_type;
                $zone->page_context = !empty($zData['page_context']) ? $zData['page_context'] : 'body';
                $zone->pos_x        = isset($zData['pos_x']) ? (float) $zData['pos_x'] : $zone->pos_x;
                $zone->pos_y        = isset($zData['pos_y']) ? (float) $zData['pos_y'] : $zone->pos_y;
                $zone->width        = isset($zData['width']) ? (float) $zData['width'] : $zone->width;
                $zone->height       = isset($zData['height']) ? (float) $zData['height'] : $zone->height;
                $zone->z_index      = isset($zData['z_index']) ? (int) $zData['z_index'] : $zone->z_index;
                $zone->label        = !empty($zData['label']) ? $zData['label'] : $zone->label;
                $zone->sort_order   = isset($zData['sort_order']) ? (int) $zData['sort_order'] : $zone->sort_order;
                if (isset($zData['params']) && is_array($zData['params'])) {
                    $zone->setParams($zData['params']);
                }
                $zone->update($user);
                $incomingIds[] = (int) $zData['id'];
            }
        } else {
            // Création
            $zone = new PdfBuilderZone($db);
            $zone->fk_layout    = $layout->id;
            $zone->zone_type    = !empty($zData['zone_type']) ? $zData['zone_type'] : 'text_static';
            $zone->page_context = !empty($zData['page_context']) ? $zData['page_context'] : 'body';
            $zone->pos_x        = isset($zData['pos_x']) ? (float) $zData['pos_x'] : 0;
            $zone->pos_y        = isset($zData['pos_y']) ? (float) $zData['pos_y'] : 0;
            $zone->width        = isset($zData['width']) ? (float) $zData['width'] : 50;
            $zone->height       = isset($zData['height']) ? (float) $zData['height'] : 10;
            $zone->z_index      = isset($zData['z_index']) ? (int) $zData['z_index'] : 0;
            $zone->label        = !empty($zData['label']) ? $zData['label'] : $zone->zone_type;
            $zone->sort_order   = isset($zData['sort_order']) ? (int) $zData['sort_order'] : 0;
            if (isset($zData['params']) && is_array($zData['params'])) {
                $zone->setParams($zData['params']);
            }
            $newZoneId = $zone->create($user);
            if ($newZoneId > 0) {
                $incomingIds[] = $newZoneId;
            }
        }
    }

    // Supprimer les zones qui ne sont plus dans l'incoming
    if (!empty($incomingIds)) {
        $existingZones = PdfBuilderZone::fetchByLayout($db, $layout->id);
        if (is_array($existingZones)) {
            foreach ($existingZones as $ez) {
                if (!in_array((int) $ez->id, $incomingIds)) {
                    $ez->delete($user);
                }
            }
        }
    }

    // Retourner le layout mis à jour
    $zones = PdfBuilderZone::fetchByLayout($db, $layout->id);
    die(json_encode(array('success' => true, 'layout_id' => $layout->id, 'zones_count' => count($zones))));
}

// ============================================================
// ACTION : load
// ============================================================
if ($action === 'load') {
    if ($layout_id <= 0) {
        die(json_encode(array('error' => 'Missing layout_id')));
    }

    $layout = new PdfBuilderLayout($db);
    if ($layout->fetch($layout_id) <= 0) {
        die(json_encode(array('error' => 'Layout not found')));
    }

    // S3 — vérification entity multicompanies
    if ((int) $layout->entity !== (int) $conf->entity) {
        http_response_code(403);
        die(json_encode(array('error' => 'Forbidden: layout belongs to another entity')));
    }

    $zones = PdfBuilderZone::fetchByLayout($db, $layout_id);
    if (!is_array($zones)) {
        $zones = array();
    }

    $zonesData = array_map(function($z) {
        return array(
            'id'           => (int) $z->id,
            'zone_type'    => $z->zone_type,
            'page_context' => $z->page_context,
            'pos_x'        => (float) $z->pos_x,
            'pos_y'        => (float) $z->pos_y,
            'width'        => (float) $z->width,
            'height'       => (float) $z->height,
            'z_index'      => (int) $z->z_index,
            'params'       => (object) $z->getParamsAsArray(),
            'label'        => $z->label ?: $z->zone_type,
            'sort_order'   => (int) $z->sort_order,
        );
    }, $zones);

    die(json_encode(array(
        'layout' => array(
            'id'           => (int) $layout->id,
            'label'        => $layout->label,
            'doc_type'     => $layout->doc_type,
            'paper_format' => $layout->paper_format,
            'margin_top'   => (float) $layout->margin_top,
            'margin_left'  => (float) $layout->margin_left,
            'margin_right' => (float) $layout->margin_right,
            'margin_bottom'=> (float) $layout->margin_bottom,
            'is_default'   => (int) $layout->is_default,
            'params'       => (object) $layout->getParamsAsArray(),
        ),
        'zones' => $zonesData,
    )));
}

// ============================================================
// ACTION : preview
// ============================================================
if ($action === 'preview') {
    if ($layout_id <= 0) {
        die(json_encode(array('error' => 'Missing layout_id')));
    }

    dol_include_once('/pdfbuilder/class/pdfbuilderlayout.class.php');
    dol_include_once('/pdfbuilder/class/pdfbuilderrenderer.class.php');
    dol_include_once('/pdfbuilder/class/pdfbuilder_preview_factory.class.php');
    require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

    $layout = new PdfBuilderLayout($db);
    if ($layout->fetch($layout_id) <= 0) {
        die(json_encode(array('error' => 'Layout not found')));
    }

    $factory = new PdfBuilderPreviewFactory($db);
    $fakeObject = $factory->makeForDocType($layout->doc_type);

    $fmt = strtoupper($layout->paper_format ?: 'A4');
    if ($fmt === 'A3') {
        $pageW = 297; $pageH = 420;
    } elseif ($fmt === 'LETTER') {
        $pageW = 215.9; $pageH = 279.4;
    } else {
        $pageW = 210; $pageH = 297; // A4 par défaut
    }

    $pdf = pdf_getInstance(array($pageW, $pageH));
    if (class_exists('TCPDF')) {
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
    }
    $pdf->SetAutoPageBreak(1, 0);
    $pdf->SetMargins($layout->margin_left, $layout->margin_top, $layout->margin_right);
    $pdf->SetFont('DejaVuSans', '', 9);
    $pdf->AddPage('P', array($pageW, $pageH));

    $renderer = new PdfBuilderRenderer($db, $layout);
    $renderer->render($pdf, $fakeObject, $langs);

    // Retourner le PDF directement en Base64 pour contourner document.php
    $pdfData = $pdf->Output('', 'S');

    if ($pdfData) {
        $base64 = base64_encode($pdfData);

        // Nettoyer tout output parasite accumulé
        if (ob_get_length()) {
            ob_end_clean();
        }

        die(json_encode(array(
            'success'   => true,
            'pdf_base64' => $base64,
            'filename'  => 'preview_'.(int)$layout_id.'.pdf'
        )));
    } else {
        dol_syslog('PdfBuilderDesigner: preview generation failed (empty data)', LOG_ERR);
        die(json_encode(array('error' => 'PDF generation failed')));
    }
}

// ============================================================
// ACTION : delete_zone
// ============================================================
if ($action === 'delete_zone') {
    $zone_id = GETPOSTINT('zone_id');
    if ($zone_id <= 0) {
        die(json_encode(array('error' => 'Missing zone_id')));
    }

    $zone = new PdfBuilderZone($db);
    if ($zone->fetch($zone_id) <= 0) {
        die(json_encode(array('error' => 'Zone not found')));
    }

    if ($zone->fk_layout != $layout_id) {
        die(json_encode(array('error' => 'Zone does not belong to layout')));
    }

    $zone->delete($user);
    die(json_encode(array('success' => true)));
}

// ============================================================
// ACTION : duplicate_layout
// ============================================================
if ($action === 'duplicate_layout') {
    $layout = new PdfBuilderLayout($db);
    if ($layout->fetch($layout_id) <= 0) {
        die(json_encode(array('error' => 'Layout not found')));
    }
    $newLabel = GETPOST('label', 'alpha') ?: ($layout->label.' (copie)');
    $newId = $layout->duplicate($user, $newLabel);
    if ($newId > 0) {
        die(json_encode(array('success' => true, 'new_layout_id' => $newId)));
    } else {
        die(json_encode(array('error' => $layout->error)));
    }
}

// ============================================================
// ACTION : import_template
// ============================================================
if ($action === 'import_template') {
    $jsonRaw = file_get_contents('php://input');
    $tpl = json_decode($jsonRaw, true);

    if (empty($tpl) || empty($tpl['layout']) || !isset($tpl['zones'])) {
        die(json_encode(array('error' => 'Invalid template format')));
    }

    $layout = new PdfBuilderLayout($db);
    $layout->entity       = $conf->entity;
    $layout->label        = $db->escape($tpl['layout']['label'] ?? 'Template importé');
    $layout->doc_type     = $tpl['layout']['doc_type'] ?? 'invoice';
    $layout->paper_format = $tpl['layout']['paper_format'] ?? 'A4';
    $layout->margin_top   = (float) ($tpl['layout']['margin_top'] ?? 10);
    $layout->margin_left  = (float) ($tpl['layout']['margin_left'] ?? 11);
    $layout->margin_right = (float) ($tpl['layout']['margin_right'] ?? 10);
    $layout->margin_bottom= (float) ($tpl['layout']['margin_bottom'] ?? 10);
    $layout->is_default   = 0;
    $layout->active       = 1;
    $newId = $layout->create($user);

    if ($newId <= 0) {
        die(json_encode(array('error' => $layout->error)));
    }

    foreach ($tpl['zones'] as $zData) {
        $zone = new PdfBuilderZone($db);
        $zone->fk_layout    = $newId;
        $zone->zone_type    = $zData['zone_type'] ?? 'text_static';
        $zone->page_context = $zData['page_context'] ?? 'body';
        $zone->pos_x        = (float) ($zData['pos_x'] ?? 0);
        $zone->pos_y        = (float) ($zData['pos_y'] ?? 0);
        $zone->width        = (float) ($zData['width'] ?? 50);
        $zone->height       = (float) ($zData['height'] ?? 10);
        $zone->z_index      = (int) ($zData['z_index'] ?? 0);
        $zone->label        = $zData['label'] ?? $zone->zone_type;
        $zone->sort_order   = (int) ($zData['sort_order'] ?? 0);
        if (!empty($zData['params']) && is_array($zData['params'])) {
            $zone->setParams($zData['params']);
        }
        $zone->create($user);
    }

    die(json_encode(array('success' => true, 'layout_id' => $newId)));
}

// ============================================================
// ACTION : upload_image
// ============================================================
if ($action === 'upload_image') {
    require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

    if (empty($_FILES['file']['tmp_name'])) {
        die(json_encode(array('error' => 'No file uploaded')));
    }

    $subfolder = GETPOST('subfolder', 'aZ09') ?: 'logos';
    $upload_dir = DOL_DATA_ROOT . '/pdfbuilder/' . $subfolder;
    if (!is_dir($upload_dir)) {
        dol_mkdir($upload_dir);
    }

    $filename = dol_sanitizeFileName($_FILES['file']['name']);
    $dest = $upload_dir . '/' . $filename;

    $result = dol_move_uploaded_file($_FILES['file']['tmp_name'], $dest, 1, 0, 0, 1);
    if ($result === 1 || $result === 2) {
        die(json_encode(array(
            'success' => true,
            'path'    => 'pdfbuilder/' . $subfolder . '/' . $filename
        )));
    } else {
        dol_syslog('PdfBuilder upload_image failed: '.(is_string($result) ? $result : 'code '.$result), LOG_WARNING);
        die(json_encode(array('error' => is_string($result) ? $result : 'Failed to move uploaded file')));
    }
}

die(json_encode(array('error' => 'Unknown action: '.$action)));
