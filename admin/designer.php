<?php
/* Copyright (C) 2024 Antigravity Project - GPL v2 or later */

/**
 * \file       pdfbuilder/admin/designer.php
 * \ingroup    pdfbuilder
 * \brief      Éditeur graphique de layout PDF (canvas drag & drop)
 */

$res = @include_once '../../../main.inc.php';
if (!$res && file_exists($_SERVER['DOCUMENT_ROOT'].'/main.inc.php')) {
    include_once $_SERVER['DOCUMENT_ROOT'].'/main.inc.php';
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/pdfbuilder/lib/pdfbuilder.lib.php');
dol_include_once('/pdfbuilder/class/pdfbuilderlayout.class.php');
dol_include_once('/pdfbuilder/class/pdfbuilderzone.class.php');

$langs->loadLangs(array('admin', 'pdfbuilder@pdfbuilder'));

if (!$user->admin) {
    accessforbidden();
}

// Auto-migration : colonne params sur llx_pdfbuilder_layout (installations existantes)
$res_col = $db->query("SHOW COLUMNS FROM ".MAIN_DB_PREFIX."pdfbuilder_layout LIKE 'params'");
if ($res_col && $db->num_rows($res_col) == 0) {
    $db->query("ALTER TABLE ".MAIN_DB_PREFIX."pdfbuilder_layout ADD COLUMN params TEXT AFTER margin_bottom");
    dol_syslog('pdfbuilder designer.php: added params column to llx_pdfbuilder_layout', LOG_DEBUG);
}

$action    = GETPOST('action', 'aZ09');
$layout_id = GETPOSTINT('layout_id');
$doc_type  = GETPOST('doc_type', 'aZ09') ?: 'invoice';

// ============================================================
// ACTIONS
// ============================================================
if ($action === 'create_layout' && !empty(GETPOST('label', 'alpha'))) {
    $layout = new PdfBuilderLayout($db);
    $layout->entity       = $conf->entity;
    $layout->label        = GETPOST('label', 'alpha');
    $layout->doc_type     = GETPOST('doc_type', 'aZ09') ?: 'invoice';
    $layout->paper_format = GETPOST('paper_format', 'aZ09') ?: 'A4';
    $layout->is_default   = GETPOSTINT('is_default') ? 1 : 0;
    $layout->active       = 1;
    $newId = $layout->create($user);
    if ($newId > 0) {
        header('Location: designer.php?layout_id='.$newId.'&token='.newToken());
        exit;
    }
}

if ($action === 'delete_layout' && $layout_id > 0) {
    $layout = new PdfBuilderLayout($db);
    if ($layout->fetch($layout_id) > 0) {
        $layout->delete($user);
    }
    header('Location: designer.php?token='.newToken());
    exit;
}

// Layout courant
$currentLayout = null;
$currentZones  = array();
if ($layout_id > 0) {
    $currentLayout = new PdfBuilderLayout($db);
    if ($currentLayout->fetch($layout_id) <= 0) {
        $currentLayout = null;
        $layout_id = 0;
    } else {
        $currentZones = PdfBuilderZone::fetchByLayout($db, $layout_id);
        if (!is_array($currentZones)) {
            $currentZones = array();
        }
    }
}

// Tous les layouts pour la barre latérale
$allLayouts = (new PdfBuilderLayout($db))->fetchAll('', $conf->entity);
if (!is_array($allLayouts)) {
    $allLayouts = array();
}

// ============================================================
// AFFICHAGE
// ============================================================
$page_name  = $langs->trans('PdfBuilderDesigner');
$backtourl  = dol_buildpath('/pdfbuilder/admin/setup.php', 1);

llxHeader('', $page_name, '');

$head = pdfbuilder_prepare_head();
print dol_get_fiche_head($head, 'designer', $langs->trans('ModulePdfBuilderName'), -1, 'pdfbuilder@pdfbuilder');

// Sérialization JSON pour le JS
$layoutJson = $currentLayout ? json_encode(array(
    'id'           => (int) $currentLayout->id,
    'label'        => $currentLayout->label,
    'doc_type'     => $currentLayout->doc_type,
    'paper_format' => $currentLayout->paper_format,
    'margin_top'   => (float) $currentLayout->margin_top,
    'margin_left'  => (float) $currentLayout->margin_left,
    'margin_right' => (float) $currentLayout->margin_right,
    'margin_bottom'=> (float) $currentLayout->margin_bottom,
    'is_default'   => (int) $currentLayout->is_default,
    'params'       => (object) $currentLayout->getParamsAsArray(),
), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) : 'null';

$zonesJson = json_encode(array_map(function($z) {
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
}, $currentZones), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP);

$ajaxUrl = dol_buildpath('/pdfbuilder/admin/ajax_designer.php', 1);
$token   = currentToken(); // AJAX calls with NOTOKENRENEWAL use $_SESSION['token'], not $_SESSION['newtoken']
?>

<div class="pdfbd-wrap">

  <!-- === BARRE DES LAYOUTS === -->
  <div class="pdfbd-topbar">
    <div class="pdfbd-topbar-left">
      <strong><?php echo $langs->trans('PdfBuilderDesigner'); ?></strong>
      <select id="pdfbd-layout-select" onchange="if(this.value)window.location='designer.php?layout_id='+this.value">
        <option value=""><?php echo $langs->trans('PdfBuilderSelectLayout'); ?></option>
        <?php foreach ($allLayouts as $lay): ?>
          <option value="<?php echo (int)$lay->id; ?>" <?php echo $layout_id == $lay->id ? 'selected' : ''; ?>>
            <?php echo dol_escape_htmltag($lay->label); ?> (<?php echo dol_escape_htmltag($lay->doc_type); ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="pdfbd-topbar-right">
      <?php if ($currentLayout): ?>
        <button class="butAction" onclick="PdfBuilderDesigner.save()">💾 <?php echo $langs->trans('Save'); ?></button>
        <button class="butAction" onclick="PdfBuilderDesigner.undo()">↩ <?php echo $langs->trans('Undo'); ?></button>
        <button class="butAction" onclick="PdfBuilderDesigner.redo()">↪ <?php echo $langs->trans('Redo'); ?></button>
        <button class="butAction" onclick="PdfBuilderDesigner.preview()">👁 <?php echo $langs->trans('Preview'); ?></button>
        <button class="butAction" onclick="PdfBuilderDesigner.exportTemplate()" title="<?php echo dol_escape_htmltag($langs->trans('PdfBuilderExportTemplate')); ?>">⬇ <?php echo $langs->trans('PdfBuilderExportTemplate'); ?></button>
        <a class="butActionDelete" href="designer.php?action=delete_layout&layout_id=<?php echo $layout_id; ?>&token=<?php echo newToken(); ?>"
           onclick="return confirm('<?php echo dol_escape_js($langs->trans('PdfBuilderConfirmDeleteLayout')); ?>')">
           🗑 <?php echo $langs->trans('Delete'); ?>
        </a>
      <?php endif; ?>
      <button class="butAction" onclick="PdfBuilderDesigner.openTemplateLibrary()">📚 <?php echo $langs->trans('PdfBuilderTemplates'); ?></button>
      <button class="butAction" onclick="PdfBuilderDesigner.importTemplateFile()">⬆ <?php echo $langs->trans('PdfBuilderImportTemplate'); ?></button>
      <input type="file" id="pdfbd-import-file" accept=".json,application/json" style="display:none">
      <button class="butActionNew" onclick="document.getElementById('pdfbd-new-layout-modal').style.display='flex'">
        + <?php echo $langs->trans('PdfBuilderNewLayout'); ?>
      </button>
    </div>
  </div>

  <?php if ($currentLayout): ?>

  <!-- === EDITOR === -->
  <div class="pdfbd-editor">

    <!-- Palette -->
    <div class="pdfbd-palette" id="pdfbd-palette">
      <div class="pdfbd-palette-title"><?php echo $langs->trans('PdfBuilderZoneTypes'); ?></div>
      <?php
      $zoneTypes = array(
          'logo_main'          => '🖼 '.$langs->trans('PdfBuilderZoneLogo'),
          'logo_alt'           => '🖼 '.$langs->trans('PdfBuilderZoneLogoAlt'),
          'address_sender'     => '📬 '.$langs->trans('PdfBuilderZoneAddressSender'),
          'address_recipient'  => '📫 '.$langs->trans('PdfBuilderZoneAddressRecipient'),
          'document_type'      => '📋 '.$langs->trans('PdfBuilderZoneDocumentType'),
          'field_ref'          => '🔢 '.$langs->trans('PdfBuilderZoneFieldRef'),
          'field_date'         => '📅 '.$langs->trans('PdfBuilderZoneFieldDate'),
          'field_duedate'      => '📅 '.$langs->trans('PdfBuilderZoneFieldDueDate'),
          'field_recipient_vat'=> '🔢 '.$langs->trans('PdfBuilderZoneFieldRecipientVat'),
          'field_customer_code'=> '🔢 '.$langs->trans('PdfBuilderZoneFieldCustomerCode'),
          'field_company_ids'  => '🏢 '.$langs->trans('PdfBuilderZoneFieldCompanyIds'),
          'field_web'          => '🌐 '.$langs->trans('PdfBuilderZoneFieldWeb'),
          'field_object'       => '📝 '.$langs->trans('PdfBuilderZoneFieldObject'),
          'table_lines'        => '📋 '.$langs->trans('PdfBuilderZoneTableLines'),
          'table_totals'       => '💰 '.$langs->trans('PdfBuilderZoneTableTotals'),
          'table_vat_breakdown'=> '📊 '.$langs->trans('PdfBuilderZoneVatBreakdown'),
          'rib_block'          => '🏦 '.$langs->trans('PdfBuilderZoneRib'),
          'qrcode'             => '📱 '.$langs->trans('PdfBuilderZoneQrCode'),
          'text_static'        => '✏ '.$langs->trans('PdfBuilderZoneTextStatic'),
          'text_freetext'      => '📄 '.$langs->trans('PdfBuilderZoneFreetext'),
          'separator'          => '— '.$langs->trans('PdfBuilderZoneSeparator'),
          'watermark'          => '💧 '.$langs->trans('PdfBuilderZoneWatermark'),
          'signature_block'    => '✍ '.$langs->trans('PdfBuilderZoneSignature'),
          'page_footer'        => '📑 '.$langs->trans('PdfBuilderZonePageFooter'),
      );
      foreach ($zoneTypes as $type => $label): ?>
        <div class="pdfbd-zone-type" draggable="true"
             data-zone-type="<?php echo dol_escape_htmltag($type); ?>"
             title="<?php echo dol_escape_htmltag($label); ?>">
          <?php echo $label; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Canvas A4 -->
    <div class="pdfbd-canvas-wrap">
      <div class="pdfbd-page-label">A4 — <?php echo dol_escape_htmltag($currentLayout->paper_format ?: 'A4'); ?></div>
      <div id="pdfbd-canvas" class="pdfbd-canvas"
           data-margin-top="<?php echo (float)$currentLayout->margin_top; ?>"
           data-margin-left="<?php echo (float)$currentLayout->margin_left; ?>"
           data-margin-right="<?php echo (float)$currentLayout->margin_right; ?>"
           data-margin-bottom="<?php echo (float)$currentLayout->margin_bottom; ?>">
        <!-- Guides de marges (générés par JS) -->
        <!-- Zones injectées par JS -->
      </div>
      <div class="pdfbd-canvas-hint"><?php echo $langs->trans('PdfBuilderDragHint'); ?></div>
    </div>

    <!-- Panneau propriétés -->
    <div class="pdfbd-props" id="pdfbd-props">
      <div class="pdfbd-props-title"><?php echo $langs->trans('PdfBuilderProperties'); ?></div>
      <div id="pdfbd-props-content">
        <p class="pdfbd-props-empty"><?php echo $langs->trans('PdfBuilderSelectZone'); ?></p>
      </div>
      <div id="pdfbd-layout-settings">
        <!-- Rendu par JS : PdfBuilderDesigner._renderLayoutPanel() -->
      </div>
    </div>

  </div>

  <?php else: ?>
  <div class="info"><?php echo $langs->trans('PdfBuilderSelectOrCreateLayout'); ?></div>
  <?php endif; ?>

</div><!-- /pdfbd-wrap -->

<!-- Modal Nouveau layout -->
<div id="pdfbd-new-layout-modal" class="pdfbd-modal" style="display:none">
  <div class="pdfbd-modal-content">
    <h3><?php echo $langs->trans('PdfBuilderNewLayout'); ?></h3>
    <form method="POST" action="designer.php">
      <input type="hidden" name="action" value="create_layout">
      <input type="hidden" name="token" value="<?php echo newToken(); ?>">
      <table class="border centpercent">
        <tr>
          <td><?php echo $langs->trans('Label'); ?></td>
          <td><input type="text" name="label" class="flat" style="width:200px" required></td>
        </tr>
        <tr>
          <td><?php echo $langs->trans('PdfBuilderDocType'); ?></td>
          <td>
            <select name="doc_type" class="flat">
              <?php foreach (pdfbuilder_get_doc_types() as $code => $lbl): ?>
                <option value="<?php echo dol_escape_htmltag($code); ?>"><?php echo dol_escape_htmltag($lbl); ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
        <tr>
          <td><?php echo $langs->trans('PdfBuilderPaperFormat'); ?></td>
          <td>
            <select name="paper_format" class="flat">
              <option value="A4">A4 (210×297)</option>
              <option value="A3">A3 (297×420)</option>
              <option value="LETTER">Letter</option>
            </select>
          </td>
        </tr>
        <tr>
          <td><?php echo $langs->trans('PdfBuilderSetAsDefault'); ?></td>
          <td><input type="checkbox" name="is_default" value="1"></td>
        </tr>
      </table>
      <div style="text-align:right; margin-top:10px">
        <button type="button" class="butAction" onclick="document.getElementById('pdfbd-new-layout-modal').style.display='none'"><?php echo $langs->trans('Cancel'); ?></button>
        <button type="submit" class="butActionNew"><?php echo $langs->trans('Create'); ?></button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Bibliothèque de templates -->
<div id="pdfbd-tpl-modal" class="pdfbd-modal" style="display:none">
  <div class="pdfbd-modal-content" style="max-width:640px">
    <h3><?php echo $langs->trans('PdfBuilderTemplates'); ?></h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:16px 0">

      <?php
      $templates = array(
          'classic'  => array('label' => $langs->trans('PdfBuilderTemplateClassic'),  'desc' => $langs->trans('PdfBuilderTemplateClassicDesc'),  'icon' => '📄'),
          'modern'   => array('label' => $langs->trans('PdfBuilderTemplateModern'),   'desc' => $langs->trans('PdfBuilderTemplateModernDesc'),   'icon' => '🎨'),
          'minimal'  => array('label' => $langs->trans('PdfBuilderTemplateMinimal'),  'desc' => $langs->trans('PdfBuilderTemplateMinimalDesc'),  'icon' => '⬜'),
          'business' => array('label' => $langs->trans('PdfBuilderTemplateBusiness'), 'desc' => $langs->trans('PdfBuilderTemplateBusinessDesc'), 'icon' => '🏢'),
      );
      foreach ($templates as $key => $tpl):
      ?>
      <div style="border:1px solid #ccc;border-radius:4px;padding:14px;text-align:center">
        <div style="font-size:2em;margin-bottom:6px"><?php echo $tpl['icon']; ?></div>
        <strong><?php echo dol_escape_htmltag($tpl['label']); ?></strong>
        <?php if ($tpl['desc'] !== 'PdfBuilderTemplate'.ucfirst($key).'Desc'): ?>
        <p style="font-size:0.85em;color:#666;margin:6px 0"><?php echo dol_escape_htmltag($tpl['desc']); ?></p>
        <?php endif; ?>
        <button class="butActionNew" style="margin-top:8px"
                onclick="PdfBuilderDesigner.closeTemplateLibrary();PdfBuilderDesigner.loadPredefinedTemplate('<?php echo dol_escape_js($key); ?>')">
          <?php echo $langs->trans('PdfBuilderLoadTemplate'); ?>
        </button>
      </div>
      <?php endforeach; ?>

    </div>
    <div style="text-align:right;margin-top:10px">
      <button class="butAction" onclick="PdfBuilderDesigner.closeTemplateLibrary()"><?php echo $langs->trans('Cancel'); ?></button>
    </div>
  </div>
</div>

<!-- Injection données JS -->
<script>
var PDFBD_LAYOUT        = <?php echo $layoutJson; ?>;
var PDFBD_ZONES         = <?php echo $zonesJson; ?>;
var PDFBD_AJAX_URL      = '<?php echo dol_escape_js($ajaxUrl); ?>';
var PDFBD_TOKEN         = '<?php echo dol_escape_js($token); ?>';
var PDFBD_LAYOUT_ID     = <?php echo (int)$layout_id; ?>;
var PDFBD_TEMPLATES_URL = '<?php echo dol_escape_js(dol_buildpath('/pdfbuilder/data/templates/', 1)); ?>';
var PDFBD_LANG = {
    save_ok:              '<?php echo dol_escape_js($langs->trans('Saved')); ?>',
    save_error:           '<?php echo dol_escape_js($langs->trans('Error')); ?>',
    delete_zone:          '<?php echo dol_escape_js($langs->trans('Delete')); ?>',
    label:                '<?php echo dol_escape_js($langs->trans('Label')); ?>',
    font_size:            '<?php echo dol_escape_js($langs->trans('PdfBuilderFontSize')); ?>',
    color_font:           '<?php echo dol_escape_js($langs->trans('PdfBuilderColorFont')); ?>',
    bg_color:             '<?php echo dol_escape_js($langs->trans('PdfBuilderColorBg')); ?>',
    align:                '<?php echo dol_escape_js($langs->trans('Alignment')); ?>',
    select_zone:          '<?php echo dol_escape_js($langs->trans('PdfBuilderSelectZone')); ?>',
    section_typo:         '<?php echo dol_escape_js($langs->trans('PdfBuilderSectionTypo')); ?>',
    font_family:          '<?php echo dol_escape_js($langs->trans('PdfBuilderFontFamily')); ?>',
    bold:                 '<?php echo dol_escape_js($langs->trans('PdfBuilderBold')); ?>',
    italic:               '<?php echo dol_escape_js($langs->trans('PdfBuilderItalic')); ?>',
    section_border:       '<?php echo dol_escape_js($langs->trans('PdfBuilderSectionBorder')); ?>',
    border_style:         '<?php echo dol_escape_js($langs->trans('PdfBuilderBorderStyle')); ?>',
    border_none:          '<?php echo dol_escape_js($langs->trans('PdfBuilderBorderNone')); ?>',
    border_solid:         '<?php echo dol_escape_js($langs->trans('PdfBuilderBorderSolid')); ?>',
    border_dashed:        '<?php echo dol_escape_js($langs->trans('PdfBuilderBorderDashed')); ?>',
    border_dotted:        '<?php echo dol_escape_js($langs->trans('PdfBuilderBorderDotted')); ?>',
    border_width:         '<?php echo dol_escape_js($langs->trans('PdfBuilderBorderWidth')); ?>',
    border_color:         '<?php echo dol_escape_js($langs->trans('PdfBuilderBorderColor')); ?>',
    section_rows:         '<?php echo dol_escape_js($langs->trans('PdfBuilderSectionRows')); ?>',
    row_alt_enabled:      '<?php echo dol_escape_js($langs->trans('PdfBuilderRowAltEnabled')); ?>',
    row_alt_bg:           '<?php echo dol_escape_js($langs->trans('PdfBuilderRowAltBg')); ?>',
    doc_options:          '<?php echo dol_escape_js($langs->trans('PdfBuilderDocOptions')); ?>',
    section_display:      '<?php echo dol_escape_js($langs->trans('PdfBuilderSectionDisplay')); ?>',
    section_mentions:     '<?php echo dol_escape_js($langs->trans('PdfBuilderSectionMentions')); ?>',
    section_bg:           '<?php echo dol_escape_js($langs->trans('PdfBuilderSectionBgDoc')); ?>',
    section_layout:       '<?php echo dol_escape_js($langs->trans('PdfBuilderSectionLayout')); ?>',
    no_repeat_header:     '<?php echo dol_escape_js($langs->trans('PdfBuilderNoRepeatHeader')); ?>',
    show_fold_mark:       '<?php echo dol_escape_js($langs->trans('PdfBuilderShowFoldMark')); ?>',
    show_line_numbers:    '<?php echo dol_escape_js($langs->trans('PdfBuilderShowLineNumbers')); ?>',
    show_pictures:        '<?php echo dol_escape_js($langs->trans('PdfBuilderShowPictures')); ?>',
    dash_between_lines:   '<?php echo dol_escape_js($langs->trans('PdfBuilderDashBetweenLines')); ?>',
    show_vat_breakdown:   '<?php echo dol_escape_js($langs->trans('PdfBuilderShowVatBreakdown')); ?>',
    add_target_details:   '<?php echo dol_escape_js($langs->trans('PdfBuilderAddTargetDetails')); ?>',
    section_address:      '<?php echo dol_escape_js($langs->trans('PdfBuilderSectionAddress')); ?>',
    hide_country:         '<?php echo dol_escape_js($langs->trans('PdfBuilderHideCountry')); ?>',
    add_client_details:   '<?php echo dol_escape_js($langs->trans('PdfBuilderAddClientDetails')); ?>',
    auto_liquidation:     '<?php echo dol_escape_js($langs->trans('PdfBuilderAutoLiquidation')); ?>',
    with_outstanding:     '<?php echo dol_escape_js($langs->trans('PdfBuilderWithOutstanding')); ?>',
    with_merged_pdf:      '<?php echo dol_escape_js($langs->trans('PdfBuilderWithMergedPdf')); ?>',
    show_bon_accord:      '<?php echo dol_escape_js($langs->trans('PdfBuilderShowBonAccord')); ?>',
    show_signature:       '<?php echo dol_escape_js($langs->trans('PdfBuilderShowSignature')); ?>',
    show_lcr:             '<?php echo dol_escape_js($langs->trans('PdfBuilderShowLcrMention')); ?>',
    bg_image:             '<?php echo dol_escape_js($langs->trans('PDFBuilderBgImage')); ?>',
    bg_pdf:               '<?php echo dol_escape_js($langs->trans('PDFBuilderBgPdf')); ?>',
    bg_opacity:           '<?php echo dol_escape_js($langs->trans('PDFBuilderBgOpacity')); ?>',
    manage_backgrounds:   '<?php echo dol_escape_js($langs->trans('PdfBuilderManageBackgrounds')); ?>',
    layout_label:         '<?php echo dol_escape_js($langs->trans('Label')); ?>',
    layout_paper:         '<?php echo dol_escape_js($langs->trans('PdfBuilderPaperFormat')); ?>',
    layout_default:       '<?php echo dol_escape_js($langs->trans('PdfBuilderIsDefault')); ?>',
    layout_margins:       '<?php echo dol_escape_js($langs->trans('PdfBuilderMargins')); ?>',
    top_bottom:           '<?php echo dol_escape_js($langs->trans('PdfBuilderTopBottom')); ?>',
    left_right:           '<?php echo dol_escape_js($langs->trans('PdfBuilderLeftRight')); ?>',
    image_path:           '<?php echo dol_escape_js($langs->trans('PdfBuilderImagePath')); ?>',
    bg_url_base:          '<?php echo dol_escape_js(dol_buildpath('/pdfbuilder/admin/backgrounds.php', 1)); ?>',
    left:                 '<?php echo dol_escape_js($langs->trans('Left')); ?>',
    center:               '<?php echo dol_escape_js($langs->trans('Center')); ?>',
    right:                '<?php echo dol_escape_js($langs->trans('Right')); ?>',
    padding:              '<?php echo dol_escape_js($langs->trans('PdfBuilderPadding')); ?>',
    text:                 '<?php echo dol_escape_js($langs->trans('PdfBuilderText')); ?>',
    uploading:            '<?php echo dol_escape_js($langs->trans('PdfBuilderUploading')); ?>',
    upload_success:       '<?php echo dol_escape_js($langs->trans('PdfBuilderUploadSuccess')); ?>',
    error_network:        '<?php echo dol_escape_js($langs->trans('PdfBuilderErrorNetwork')); ?>',
    error_server:         '<?php echo dol_escape_js($langs->trans('PdfBuilderErrorServer')); ?>',
    import_error:         '<?php echo dol_escape_js($langs->trans('PdfBuilderImportError')); ?>',
    import_success:       '<?php echo dol_escape_js($langs->trans('PdfBuilderImportSuccess')); ?>',
    template_library:     '<?php echo dol_escape_js($langs->trans('PdfBuilderTemplateLibrary')); ?>',
    load_template:        '<?php echo dol_escape_js($langs->trans('PdfBuilderLoadTemplate')); ?>',
    tpl_classic:          '<?php echo dol_escape_js($langs->trans('PdfBuilderTemplateClassic')); ?>',
    tpl_modern:           '<?php echo dol_escape_js($langs->trans('PdfBuilderTemplateModern')); ?>',
    tpl_minimal:          '<?php echo dol_escape_js($langs->trans('PdfBuilderTemplateMinimal')); ?>',
    tpl_business:         '<?php echo dol_escape_js($langs->trans('PdfBuilderTemplateBusiness')); ?>',
    section_weight:       '<?php echo dol_escape_js($langs->trans('PdfBuilderSectionWeight')); ?>',
    show_weight:          '<?php echo dol_escape_js($langs->trans('PdfBuilderShowWeight')); ?>',
    col_weight_width:     '<?php echo dol_escape_js($langs->trans('PdfBuilderColWeightWidth')); ?>',
    show_price_per_kg:    '<?php echo dol_escape_js($langs->trans('PdfBuilderShowPricePerKg')); ?>',
};
</script>

<link rel="stylesheet" href="<?php echo dol_buildpath('/pdfbuilder/css/pdfbuilder_designer.css', 1); ?>">
<script src="<?php echo dol_buildpath('/pdfbuilder/js/pdfbuilder_designer.js', 1); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($currentLayout): ?>
    PdfBuilderDesigner.init(PDFBD_LAYOUT, PDFBD_ZONES, PDFBD_LAYOUT_ID);
    <?php else: ?>
    // Pas de layout actif — on branche quand même le file input pour l'import
    PdfBuilderDesigner.bindImportOnly();
    <?php endif; ?>
});
</script>

<?php
print dol_get_fiche_end();
llxFooter();
$db->close();
