<?php
/* Copyright (C) 2024 Antigravity Project - GPL v2 or later */

/**
 * \file       pdfbuilder/admin/backgrounds.php
 * \ingroup    pdfbuilder
 * \brief      Admin : gestion des fonds de page par layout + fusion PDF globale
 */

$res = 0;
if (!$res && file_exists('../../main.inc.php'))   $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/pdfbuilder/lib/pdfbuilder.lib.php');
dol_include_once('/pdfbuilder/class/pdfbuilderlayout.class.php');

$langs->loadLangs(array('admin', 'pdfbuilder@pdfbuilder'));

if (!$user->admin) accessforbidden();

$action    = GETPOST('action', 'aZ09');
$layoutId  = GETPOSTINT('layout_id');
$uploadType = GETPOST('upload_type', 'alpha'); // bg_image, bg_pdf

// === Upload image/PDF de fond pour un layout ===
if ($action === 'upload' && $layoutId > 0 && !empty($_FILES['uploadfile']['tmp_name'])) {
    $layout = new PdfBuilderLayout($db);
    if ($layout->fetch($layoutId) > 0) {
        $uploadDir = $conf->pdfbuilder->dir_output.'/backgrounds';
        if (!is_dir($uploadDir)) dol_mkdir($uploadDir);

        $allowedExtImage = array('jpg', 'jpeg', 'png', 'gif', 'webp');
        $allowedExtBgPdf = array('pdf');
        $ext = strtolower(pathinfo($_FILES['uploadfile']['name'], PATHINFO_EXTENSION));
        $allowedExt = ($uploadType === 'bg_pdf') ? $allowedExtBgPdf : $allowedExtImage;

        if (!in_array($uploadType, array('bg_image', 'bg_pdf'))) {
            setEventMessages($langs->trans('BadParameters'), null, 'errors');
        } elseif (!in_array($ext, $allowedExt)) {
            setEventMessages($langs->trans('PdfBuilderUploadFormatNotAllowed', $ext), null, 'errors');
        } else {
            $filename = dol_sanitizeFileName($layout->label).'_'.$layoutId.'_'.$uploadType.'_'.dol_sanitizeFileName($_FILES['uploadfile']['name']);
            $dest = $uploadDir.'/'.$filename;
            if (move_uploaded_file($_FILES['uploadfile']['tmp_name'], $dest)) {
                // S1 — vérification MIME après déplacement
                $allowedMimes = ($uploadType === 'bg_pdf')
                    ? array('application/pdf')
                    : array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $dest);
                finfo_close($finfo);
                if (!in_array($mime, $allowedMimes)) {
                    @unlink($dest);
                    setEventMessages($langs->trans('PdfBuilderUploadFormatNotAllowed', $mime), null, 'errors');
                } else {
                    $params = $layout->getParamsAsArray();
                    $params[$uploadType] = $dest;
                    $layout->setParams($params);
                    $layout->update($user);
                    setEventMessages($langs->trans('FileSuccessfullyUploaded'), null, 'mesgs');
                }
            } else {
                setEventMessages($langs->trans('PdfBuilderUploadError'), null, 'errors');
            }
        }
    }
    header('Location: '.$_SERVER['PHP_SELF'].'?layout_id='.$layoutId);
    exit;
}

// === Suppression d'un fichier de fond ===
if ($action === 'removefile' && $layoutId > 0) {
    $layout = new PdfBuilderLayout($db);
    if ($layout->fetch($layoutId) > 0) {
        $field = GETPOST('field', 'alpha');
        if (in_array($field, array('bg_image', 'bg_pdf'))) {
            $params = $layout->getParamsAsArray();
            $filePath = !empty($params[$field]) ? $params[$field] : '';
            if ($filePath && file_exists($filePath)) {
                @unlink($filePath);
            }
            unset($params[$field]);
            $layout->setParams($params);
            $layout->update($user);
            setEventMessages($langs->trans('FileDeleted'), null, 'mesgs');
        }
    }
    header('Location: '.$_SERVER['PHP_SELF'].'?layout_id='.$layoutId);
    exit;
}

// === Mise à jour opacité ===
if ($action === 'setopacity' && $layoutId > 0) {
    $layout = new PdfBuilderLayout($db);
    if ($layout->fetch($layoutId) > 0) {
        $params = $layout->getParamsAsArray();
        $params['bg_opacity'] = min(1.0, max(0.05, (float) GETPOST('bg_opacity', 'alpha')));
        $layout->setParams($params);
        $layout->update($user);
        setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
    }
    header('Location: '.$_SERVER['PHP_SELF'].'?layout_id='.$layoutId);
    exit;
}

// === Upload PDF de fusion ===
$docTypes = array('invoice', 'propal', 'order', 'supplier_invoice', 'supplier_order', 'fichinter');
if ($action === 'uploadmerge' && !empty($_FILES['mergefile']['tmp_name'])) {
    $dtype = GETPOST('doc_type', 'alpha');
    if (in_array($dtype, $docTypes)) {
        $mergeBaseDir = $conf->pdfbuilder->dir_output.'/merge';
        $mergeDir = $mergeBaseDir.'/'.$dtype;
        if (!is_dir($mergeDir)) dol_mkdir($mergeDir);
        $mergeExt = strtolower(pathinfo($_FILES['mergefile']['name'], PATHINFO_EXTENSION));
        if ($mergeExt !== 'pdf') {
            setEventMessages($langs->trans('PdfBuilderUploadFormatNotAllowed', $mergeExt), null, 'errors');
        } else {
            $dest = $mergeDir.'/'.dol_sanitizeFileName($_FILES['mergefile']['name']);
            if (move_uploaded_file($_FILES['mergefile']['tmp_name'], $dest)) {
                // S2 — vérification MIME après déplacement
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $dest);
                finfo_close($finfo);
                if ($mime !== 'application/pdf') {
                    @unlink($dest);
                    setEventMessages($langs->trans('PdfBuilderUploadFormatNotAllowed', $mime), null, 'errors');
                } else {
                    setEventMessages($langs->trans('PdfBuilderMergePdfAdded'), null, 'mesgs');
                }
            }
        }
    }
    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}

// === Suppression PDF de fusion ===
if ($action === 'removemerge') {
    $dtype = GETPOST('doc_type', 'alpha');
    $fname = GETPOST('fname', 'alpha');
    if (in_array($dtype, $docTypes) && $fname) {
        $mergeBaseDir = $conf->pdfbuilder->dir_output.'/merge';
        $filePath = $mergeBaseDir.'/'.$dtype.'/'.basename($fname);
        if (file_exists($filePath)) @unlink($filePath);
        setEventMessages($langs->trans('FileDeleted'), null, 'mesgs');
    }
    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}

// === AFFICHAGE ===
$title = 'PDF-builder — '.$langs->trans('PDFBuilderBackgrounds');
llxHeader('', $title, '');

$head = pdfbuilder_prepare_head();
print dol_get_fiche_head($head, 'backgrounds', 'PDF-builder', -1, 'pdfbuilder@pdfbuilder');

// --- Section 1 : Fonds de page par layout ---
print '<h2 class="pdfbuilder-section-title">'.$langs->trans('PDFBuilderBackgrounds').'</h2>';
print '<p>'.$langs->trans('PDFBuilderBackgroundsDesc').'</p>';

$layoutObj = new PdfBuilderLayout($db);
$allLayouts = $layoutObj->fetchAll('', $conf->entity);
if (!is_array($allLayouts)) $allLayouts = array();

if (empty($allLayouts)) {
    print '<div class="opacitymedium">'.$langs->trans('NoThemeDefined').'</div>';
} else {
    foreach ($allLayouts as $lay) {
        $lp = $lay->getParamsAsArray();
        $docLabel = ucfirst(str_replace('_', ' ', $lay->doc_type));
        $isOpen = ($layoutId && $lay->id == $layoutId);

        print '<div class="pdfbuilder-bg-theme-card" style="border:1px solid #ccc; border-radius:8px; padding:16px; margin:12px 0; background:#fafbfc;">';
        print '<h3 style="margin:0 0 10px;">'.dol_escape_htmltag($lay->label).' <span style="font-weight:normal;color:#888;font-size:12px;">('. $docLabel.')</span>';
        print ' <a href="'.dol_buildpath('/pdfbuilder/admin/designer.php', 1).'?layout_id='.$lay->id.'" style="font-size:11px;font-weight:normal;">'.dol_escape_htmltag($langs->trans('PdfBuilderDesigner')).' →</a>';
        print '</h3>';

        print '<table class="noborder centpercent">';

        // Image de fond
        print '<tr class="oddeven"><td style="width:200px;"><strong>'.$langs->trans('PDFBuilderBgImage').'</strong></td><td>';
        if (!empty($lp['bg_image']) && file_exists($lp['bg_image'])) {
            $ext = strtolower(pathinfo($lp['bg_image'], PATHINFO_EXTENSION));
            if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp'))) {
                print '<img src="'.DOL_URL_ROOT.'/viewimage.php?modulepart=pdfbuilder&file='.urlencode(str_replace($conf->pdfbuilder->dir_output.'/', '', $lp['bg_image'])).'" style="max-height:80px;border-radius:4px;margin-right:8px;" />';
            }
            print '<span style="color:#555;">'.basename($lp['bg_image']).'</span> ';
            print '<a href="?action=removefile&layout_id='.$lay->id.'&field=bg_image&token='.newToken().'" class="button buttongen" onclick="return confirm(\'Supprimer l\\\'image de fond ?\')"><span class="fas fa-trash"></span></a>';
        } else {
            print '<form method="POST" enctype="multipart/form-data" action="'.$_SERVER['PHP_SELF'].'" style="display:inline;">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" name="action" value="upload">';
            print '<input type="hidden" name="layout_id" value="'.$lay->id.'">';
            print '<input type="hidden" name="upload_type" value="bg_image">';
            print '<input type="file" name="uploadfile" accept="image/*" style="margin-right:6px;">';
            print '<button type="submit" class="button buttongen">Upload</button>';
            print '</form>';
        }
        print '</td></tr>';

        // PDF de fond
        print '<tr class="oddeven"><td><strong>'.$langs->trans('PDFBuilderBgPdf').'</strong></td><td>';
        if (!empty($lp['bg_pdf']) && file_exists($lp['bg_pdf'])) {
            print '<span class="fas fa-file-pdf" style="color:#e74c3c;margin-right:4px;"></span> '.basename($lp['bg_pdf']).' ';
            print '<a href="?action=removefile&layout_id='.$lay->id.'&field=bg_pdf&token='.newToken().'" class="button buttongen" onclick="return confirm(\'Supprimer le PDF de fond ?\')"><span class="fas fa-trash"></span></a>';
        } else {
            print '<form method="POST" enctype="multipart/form-data" action="'.$_SERVER['PHP_SELF'].'" style="display:inline;">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" name="action" value="upload">';
            print '<input type="hidden" name="layout_id" value="'.$lay->id.'">';
            print '<input type="hidden" name="upload_type" value="bg_pdf">';
            print '<input type="file" name="uploadfile" accept="application/pdf" style="margin-right:6px;">';
            print '<button type="submit" class="button buttongen">Upload</button>';
            print '</form>';
        }
        print '</td></tr>';

        // Opacité
        print '<tr class="oddeven"><td><strong>'.$langs->trans('PDFBuilderBgOpacity').'</strong></td><td>';
        $opacityVal = (float) (!empty($lp['bg_opacity']) ? $lp['bg_opacity'] : 0.10);
        print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline;">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="setopacity">';
        print '<input type="hidden" name="layout_id" value="'.$lay->id.'">';
        print '<input type="range" name="bg_opacity" min="0.05" max="1" step="0.05" value="'.$opacityVal.'" style="width:200px;vertical-align:middle;" oninput="this.nextElementSibling.textContent=this.value">';
        print ' <span>'.$opacityVal.'</span>';
        print ' <button type="submit" class="button buttongen" style="margin-left:6px;">OK</button>';
        print '</form>';
        print '</td></tr>';

        print '</table>';
        print '</div>';
    }
}

print dol_get_fiche_end();

// --- Section 2 : Fusion PDF (globale par type de document) ---
print '<br>';
print '<h2 class="pdfbuilder-section-title">'.$langs->trans('PDFBuilderMergePdf').'</h2>';
print '<p>'.$langs->trans('PDFBuilderMergePdfDesc').'</p>';
print '<div class="info">'.$langs->trans('PDFBuilderMergeInfo').'</div>';

$mergeBaseDir = $conf->pdfbuilder->dir_output.'/merge';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Type').'</th>';
print '<th>'.$langs->trans('Files').'</th>';
print '<th>'.$langs->trans('Actions').'</th>';
print '</tr>';

foreach ($docTypes as $dtype) {
    $mergeDir = $mergeBaseDir.'/'.$dtype;
    $files = is_dir($mergeDir) ? glob($mergeDir.'/*.pdf') : array();
    if (!is_array($files)) $files = array();
    $docLabel = ucfirst(str_replace('_', ' ', $dtype));

    print '<tr class="oddeven">';
    print '<td><strong>'.dol_escape_htmltag($docLabel).'</strong></td>';
    print '<td>';
    if (!empty($files)) {
        foreach ($files as $f) {
            print '<span class="fas fa-file-pdf" style="color:#e74c3c;margin-right:4px;"></span> '.dol_escape_htmltag(basename($f));
            print ' <a href="?action=removemerge&doc_type='.urlencode($dtype).'&fname='.urlencode(basename($f)).'&token='.newToken().'" onclick="return confirm(\'Supprimer ?\')"><span class="fas fa-trash" style="color:#c00;"></span></a>';
            print '<br>';
        }
    } else {
        print '<span class="opacitymedium">'.$langs->trans('NoFiles').'</span>';
    }
    print '</td>';
    print '<td>';
    print '<form method="POST" enctype="multipart/form-data" action="'.$_SERVER['PHP_SELF'].'" style="display:inline;">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="uploadmerge">';
    print '<input type="hidden" name="doc_type" value="'.$dtype.'">';
    print '<input type="file" name="mergefile" accept="application/pdf" style="margin-right:6px;">';
    print '<button type="submit" class="button buttongen">+ '.$langs->trans('Add').'</button>';
    print '</form>';
    print '</td>';
    print '</tr>';
}
print '</table>';

llxFooter();
$db->close();
