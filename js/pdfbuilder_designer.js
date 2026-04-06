/**
 * PdfBuilderDesigner — Éditeur graphique de layouts PDF
 * Vanilla JS — compatible politique Dolibarr (pas de framework)
 * Scale : 2px = 1mm → A4 = 420 × 594 px dans le canvas
 */
'use strict';

var PdfBuilderDesigner = (function() {

    var SCALE = 2; // px par mm

    // Dimensions A4 en px sur le canvas
    var PAGE_W = 210 * SCALE;
    var PAGE_H = 297 * SCALE;

    var _layout   = null;  // { id, label, paper_format, margin_top/left/right/bottom }
    var _zones    = [];    // array of zone objects (mutable)
    var _selected = null;  // zone id sélectionnée
    var _history  = [];    // pile undo (snapshots JSON)
    var _future   = [];    // pile redo
    var _nextTmpId = -1;   // ID temporaire pour les nouvelles zones (négatif)
    var _dirty    = false; // modifications non sauvegardées

    var _canvas   = null;
    var _propsEl  = null;
    var _layoutEl = null;  // #pdfbd-layout-settings
    var _drag     = null;  // { zone, startX, startY, origX, origY }
    var _resize   = null;  // { zone, handle, startX, startY, origW, origH, origX, origY }

    // =====================================================
    // INIT
    // =====================================================
    function init(layoutData, zonesData, layoutId) {
        _layout = layoutData;
        if (_layout && (!_layout.params || Array.isArray(_layout.params))) _layout.params = {};
        _zones  = zonesData ? JSON.parse(JSON.stringify(zonesData)) : [];
        _zones.forEach(function(z) {
            if (!z.params || Array.isArray(z.params)) z.params = {};
        });
        _canvas = document.getElementById('pdfbd-canvas');
        _propsEl = document.getElementById('pdfbd-props-content');
        _layoutEl = document.getElementById('pdfbd-layout-settings');

        if (!_canvas) return;

        _renderCanvas();
        _bindPalette();
        _bindGlobalEvents();
        _bindImportInput();
        _saveHistory();
        _renderLayoutPanel();
    }

    // =====================================================
    // RENDU DU CANVAS
    // =====================================================
    function _renderCanvas() {
        if (!_canvas || !_layout) return;

        _canvas.innerHTML = '';
        _canvas.style.width  = PAGE_W + 'px';
        _canvas.style.height = PAGE_H + 'px';

        // Guides de marges
        _drawMarginGuides();

        // Zones
        _zones.forEach(function(zone) {
            _createZoneEl(zone);
        });
    }

    function _drawMarginGuides() {
        var ml = _layout.margin_left  * SCALE;
        var mt = _layout.margin_top   * SCALE;
        var mr = _layout.margin_right * SCALE;
        var mb = _layout.margin_bottom * SCALE;

        var guides = [
            { left: ml, top: 0, width: 1, height: PAGE_H },
            { left: PAGE_W - mr, top: 0, width: 1, height: PAGE_H },
            { left: 0, top: mt, width: PAGE_W, height: 1 },
            { left: 0, top: PAGE_H - mb, width: PAGE_W, height: 1 },
        ];

        guides.forEach(function(g) {
            var el = document.createElement('div');
            el.className = 'pdfbd-guide';
            el.style.left   = g.left + 'px';
            el.style.top    = g.top + 'px';
            el.style.width  = g.width + 'px';
            el.style.height = g.height + 'px';
            _canvas.appendChild(el);
        });
    }

    function _createZoneEl(zone) {
        var el = document.createElement('div');
        el.className = 'pdfbd-zone' + (zone.id === _selected ? ' pdfbd-zone-selected' : '');
        el.dataset.id = zone.id;

        // Position : depuis le bord gauche de la page = margin_left + pos_x
        var absX = (_layout.margin_left + zone.pos_x) * SCALE;
        var absY = (_layout.margin_top  + zone.pos_y) * SCALE;

        el.style.left   = absX + 'px';
        el.style.top    = absY + 'px';
        el.style.width  = (zone.width  * SCALE) + 'px';
        el.style.height = (zone.height * SCALE) + 'px';
        el.style.zIndex = zone.z_index || 0;

        // Label
        var label = document.createElement('span');
        label.className = 'pdfbd-zone-label';
        label.textContent = zone.label || zone.zone_type;
        el.appendChild(label);

        // Handles de resize (4 coins)
        ['nw','ne','sw','se'].forEach(function(h) {
            var handle = document.createElement('div');
            handle.className = 'pdfbd-resize-handle pdfbd-resize-' + h;
            handle.dataset.handle = h;
            el.appendChild(handle);
        });

        // Events
        el.addEventListener('mousedown', _onZoneMousedown);

        _canvas.appendChild(el);
        return el;
    }

    function _refreshZoneEl(zone) {
        var el = _canvas.querySelector('[data-id="' + zone.id + '"]');
        if (!el) {
            _createZoneEl(zone);
            return;
        }
        var absX = (_layout.margin_left + zone.pos_x) * SCALE;
        var absY = (_layout.margin_top  + zone.pos_y) * SCALE;
        el.style.left   = absX + 'px';
        el.style.top    = absY + 'px';
        el.style.width  = (zone.width  * SCALE) + 'px';
        el.style.height = (zone.height * SCALE) + 'px';
        el.style.zIndex = zone.z_index || 0;
        var lbl = el.querySelector('.pdfbd-zone-label');
        if (lbl) lbl.textContent = zone.label || zone.zone_type;
        el.className = 'pdfbd-zone' + (zone.id === _selected ? ' pdfbd-zone-selected' : '');
    }

    // =====================================================
    // DRAG & RESIZE
    // =====================================================
    function _onZoneMousedown(e) {
        var el = this;
        var handle = e.target.dataset && e.target.dataset.handle;
        var zoneId = el.dataset.id;
        var zone = _findZone(zoneId);
        if (!zone) return;

        e.preventDefault();
        e.stopPropagation();

        // Sélectionner
        _selectZone(zone.id);

        if (handle) {
            // Resize
            _resize = {
                zone: zone,
                handle: handle,
                startX: e.clientX,
                startY: e.clientY,
                origW: zone.width,
                origH: zone.height,
                origX: zone.pos_x,
                origY: zone.pos_y,
            };
        } else {
            // Drag
            _drag = {
                zone: zone,
                startX: e.clientX,
                startY: e.clientY,
                origX: zone.pos_x,
                origY: zone.pos_y,
            };
        }
    }

    function _onMousemove(e) {
        if (_drag) {
            var dx = (e.clientX - _drag.startX) / SCALE;
            var dy = (e.clientY - _drag.startY) / SCALE;
            _drag.zone.pos_x = Math.max(0, _drag.origX + dx);
            _drag.zone.pos_y = Math.max(0, _drag.origY + dy);
            _refreshZoneEl(_drag.zone);
        }

        if (_resize) {
            var dx = (e.clientX - _resize.startX) / SCALE;
            var dy = (e.clientY - _resize.startY) / SCALE;
            var h  = _resize.handle;
            var z  = _resize.zone;

            if (h === 'se') {
                z.width  = Math.max(5, _resize.origW + dx);
                z.height = Math.max(3, _resize.origH + dy);
            } else if (h === 'sw') {
                z.width  = Math.max(5, _resize.origW - dx);
                z.pos_x  = _resize.origX + (_resize.origW - z.width);
                z.height = Math.max(3, _resize.origH + dy);
            } else if (h === 'ne') {
                z.width  = Math.max(5, _resize.origW + dx);
                z.height = Math.max(3, _resize.origH - dy);
                z.pos_y  = _resize.origY + (_resize.origH - z.height);
            } else if (h === 'nw') {
                z.width  = Math.max(5, _resize.origW - dx);
                z.pos_x  = _resize.origX + (_resize.origW - z.width);
                z.height = Math.max(3, _resize.origH - dy);
                z.pos_y  = _resize.origY + (_resize.origH - z.height);
            }
            _refreshZoneEl(z);
        }
    }

    function _onMouseup(e) {
        if (_drag || _resize) {
            _saveHistory();
            _dirty = true;
        }
        _drag   = null;
        _resize = null;
    }

    // =====================================================
    // PALETTE — GLISSER DEPUIS LA PALETTE
    // =====================================================
    function _bindPalette() {
        var items = document.querySelectorAll('.pdfbd-zone-type');
        items.forEach(function(item) {
            item.addEventListener('dragstart', function(e) {
                e.dataTransfer.setData('zone_type', item.dataset.zoneType);
            });
        });

        if (_canvas) {
            _canvas.addEventListener('dragover', function(e) {
                e.preventDefault();
            });
            _canvas.addEventListener('drop', function(e) {
                e.preventDefault();
                var zoneType = e.dataTransfer.getData('zone_type');
                if (!zoneType) return;

                var rect = _canvas.getBoundingClientRect();
                var absX = (e.clientX - rect.left) / SCALE;
                var absY = (e.clientY - rect.top)  / SCALE;
                var relX = Math.max(0, absX - _layout.margin_left);
                var relY = Math.max(0, absY - _layout.margin_top);

                _addZone(zoneType, relX, relY);
            });
        }
    }

    function _addZone(zoneType, posX, posY) {
        // Dimensions par défaut selon le type
        var defaults = _getZoneDefaults(zoneType);
        var zone = {
            id:           _nextTmpId--,
            zone_type:    zoneType,
            page_context: defaults.page_context || 'body',
            pos_x:        Math.round(posX * 10) / 10,
            pos_y:        Math.round(posY * 10) / 10,
            width:        defaults.w,
            height:       defaults.h,
            z_index:      0,
            params:       defaults.params || {},
            label:        zoneType,
            sort_order:   _zones.length,
        };
        _zones.push(zone);
        _createZoneEl(zone);
        _selectZone(zone.id);
        _saveHistory();
        _dirty = true;
    }

    function _getZoneDefaults(type) {
        var map = {
            logo_main:           { w: 40, h: 20 },
            logo_alt:            { w: 30, h: 15 },
            address_sender:      { w: 80, h: 30, params: { font_size: 9 } },
            address_recipient:   { w: 80, h: 30, params: { font_size: 9 } },
            document_type:       { w: 80, h: 14, params: { font_size: 16, bold: true, style: 'box' } },
            field_ref:           { w: 60, h: 8,  params: { font_size: 10, bold: true } },
            field_date:          { w: 50, h: 8 },
            field_duedate:       { w: 50, h: 8 },
            field_customer_code: { w: 50, h: 8 },
            field_web:           { w: 60, h: 8 },
            field_recipient_vat: { w: 60, h: 8 },
            field_company_ids:   { w: 90, h: 20, params: { font_size: 8 } },
            field_object:        { w: 100, h: 8, params: { bold: true } },
            table_lines:         { w: 188, h: 80, params: { font_size: 9, font_size_header: 8, header_bg: '#4a6fa1', header_txt: '#ffffff', line_height: 5.5, row_alt_enabled: true, row_alt_bg: '#f4f7fa' } },
            table_totals:        { w: 80, h: 20, params: { font_size: 9, line_height: 5.5, label_width: 50 } },
            table_vat_breakdown: { w: 80, h: 15, params: { font_size: 8 } },
            rib_block:           { w: 90, h: 20 },
            qrcode:              { w: 25, h: 25 },
            text_static:         { w: 60, h: 10, params: { text: 'Texte libre', font_size: 9 } },
            text_freetext:       { w: 100, h: 20 },
            separator:           { w: 188, h: 4, params: { color: '#cccccc', line_width: 0.3 } },
            watermark:           { w: 188, h: 40, params: { text: 'BROUILLON', font_size: 40, color: '#dddddd', angle: 45 } },
            signature_block:     { w: 80, h: 30 },
            outstanding:         { w: 80, h: 10 },
            page_footer:         { w: 188, h: 6, page_context: 'footer', params: { text: 'Page {page} / {pages}', font_size: 8, align: 'center' } },
        };
        return map[type] || { w: 50, h: 15 };
    }

    // =====================================================
    // SÉLECTION ET PROPRIÉTÉS
    // =====================================================
    function _selectZone(id) {
        _selected = id;
        // Mise à jour CSS
        document.querySelectorAll('.pdfbd-zone').forEach(function(el) {
            el.classList.toggle('pdfbd-zone-selected', el.dataset.id == id);
        });
        _renderProps();
    }

    function _renderProps() {
        if (!_propsEl) return;

        var zone = _selected !== null ? _findZone(_selected) : null;
        if (!zone) {
            _propsEl.innerHTML = '<p class="pdfbd-props-empty">' + (PDFBD_LANG && PDFBD_LANG.select_zone ? PDFBD_LANG.select_zone : 'Sélectionnez une zone') + '</p>';
            return;
        }

        var params = zone.params || {};
        var html = '<table class="pdfbd-props-table">';
        html += '<tr><td>' + _l('label') + '</td><td><input type="text" class="flat" id="pp-label" value="' + _esc(zone.label) + '"></td></tr>';
        html += '<tr><td>X (mm)</td><td><input type="number" class="flat" id="pp-pos_x" step="0.5" value="' + zone.pos_x + '"></td></tr>';
        html += '<tr><td>Y (mm)</td><td><input type="number" class="flat" id="pp-pos_y" step="0.5" value="' + zone.pos_y + '"></td></tr>';
        html += '<tr><td>W (mm)</td><td><input type="number" class="flat" id="pp-width" step="0.5" value="' + zone.width + '"></td></tr>';
        html += '<tr><td>H (mm)</td><td><input type="number" class="flat" id="pp-height" step="0.5" value="' + zone.height + '"></td></tr>';
        html += '<tr><td>' + _l('font_size') + '</td><td><input type="number" class="flat" id="pp-font_size" value="' + (params.font_size || 9) + '"></td></tr>';
        html += '<tr><td>' + _l('color_font') + '</td><td><input type="color" id="pp-color_font" value="' + (params.color_font || '#333333') + '"></td></tr>';
        html += '<tr><td>' + _l('bg_color') + '</td><td><input type="color" id="pp-bg_color" value="' + (params.bg_color || '#ffffff') + '"></td></tr>';
        html += '<tr><td>' + _l('align') + '</td><td><select id="pp-align" class="flat"><option value="left"' + (params.align === 'left' ? ' selected' : '') + '>' + _l('left') + '</option><option value="center"' + (params.align === 'center' ? ' selected' : '') + '>' + _l('center') + '</option><option value="right"' + (params.align === 'right' ? ' selected' : '') + '>' + _l('right') + '</option></select></td></tr>';

        // Section Typographie
        html += '<tr class="pdfbd-props-section"><td colspan="2">' + _l('section_typo') + '</td></tr>';
        var fontFamilies = ['DejaVuSans','DejaVuSansCondensed','DejaVuSerif','DejaVuSerifCondensed','DejaVuSansMono','Helvetica','Times','Courier'];
        html += '<tr><td>' + _l('font_family') + '</td><td><select id="pp-font_family" class="flat">';
        fontFamilies.forEach(function(f) {
            html += '<option value="' + f + '"' + ((params.font_family || 'DejaVuSans') === f ? ' selected' : '') + '>' + f + '</option>';
        });
        html += '</select></td></tr>';
        html += '<tr><td>' + _l('bold') + '</td><td><input type="checkbox" id="pp-bold"' + (params.bold ? ' checked' : '') + '></td></tr>';
        html += '<tr><td>' + _l('italic') + '</td><td><input type="checkbox" id="pp-italic"' + (params.italic ? ' checked' : '') + '></td></tr>';

        // Section Bordure
        html += '<tr class="pdfbd-props-section"><td colspan="2">' + _l('section_border') + '</td></tr>';
        html += '<tr><td>' + _l('border_style') + '</td><td><select id="pp-border_style" class="flat">';
        [['none', _l('border_none')], ['solid', _l('border_solid')], ['dashed', _l('border_dashed')], ['dotted', _l('border_dotted')]].forEach(function(o) {
            html += '<option value="' + o[0] + '"' + ((params.border_style || 'none') === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
        });
        html += '</select></td></tr>';
        html += '<tr><td>' + _l('border_width') + '</td><td><input type="number" class="flat" id="pp-border_width" step="0.1" min="0" value="' + (params.border_width !== undefined ? params.border_width : 0) + '"></td></tr>';
        html += '<tr><td>' + _l('border_color') + '</td><td><input type="color" id="pp-border_color" value="' + (params.border_color || '#333333') + '"></td></tr>';

        // Section Padding
        html += '<tr class="pdfbd-props-section"><td colspan="2">' + _l('padding') + '</td></tr>';
        html += '<tr><td>' + _l('top_bottom') + '</td><td>' +
                '<input type="number" class="flat" id="pp-padding_top" step="0.1" min="0" style="width:45%; display:inline;" value="' + (params.padding_top || 0) + '"> ' +
                '<input type="number" class="flat" id="pp-padding_bottom" step="0.1" min="0" style="width:45%; display:inline;" value="' + (params.padding_bottom || 0) + '">' +
                '</td></tr>';
        html += '<tr><td>' + _l('left_right') + '</td><td>' +
                '<input type="number" class="flat" id="pp-padding_left" step="0.1" min="0" style="width:45%; display:inline;" value="' + (params.padding_left || 0) + '"> ' +
                '<input type="number" class="flat" id="pp-padding_right" step="0.1" min="0" style="width:45%; display:inline;" value="' + (params.padding_right || 0) + '">' +
                '</td></tr>';

        // Section lignes alternées + poids (table_lines uniquement)
        if (zone.zone_type === 'table_lines') {
            html += '<tr class="pdfbd-props-section"><td colspan="2">' + _l('section_rows') + '</td></tr>';
            html += '<tr><td>' + _l('row_alt_enabled') + '</td><td><input type="checkbox" id="pp-row_alt_enabled"' + (params.row_alt_enabled ? ' checked' : '') + '></td></tr>';
            html += '<tr><td>' + _l('row_alt_bg') + '</td><td><input type="color" id="pp-row_alt_bg" value="' + (params.row_alt_bg || '#f0f4fa') + '"></td></tr>';

            html += '<tr class="pdfbd-props-section"><td colspan="2">' + _l('section_weight') + '</td></tr>';
            html += '<tr><td>' + _l('show_weight') + '</td><td><input type="checkbox" id="pp-show_weight"' + (params.show_weight ? ' checked' : '') + '></td></tr>';
            html += '<tr><td>' + _l('col_weight_width') + '</td><td><input type="number" class="flat" id="pp-col_weight" step="1" min="5" max="40" value="' + (params.col_weight || 15) + '"></td></tr>';
            html += '<tr><td>' + _l('show_price_per_kg') + '</td><td><input type="checkbox" id="pp-show_price_per_kg"' + (params.show_price_per_kg ? ' checked' : '') + '></td></tr>';
        }

        // Option masquer le pays pour les blocs adresse
        if (zone.zone_type === 'address_sender' || zone.zone_type === 'address_recipient') {
            html += '<tr class="pdfbd-props-section"><td colspan="2">' + _l('section_address') + '</td></tr>';
            html += '<tr><td>' + _l('hide_country') + '</td><td><input type="checkbox" id="pp-hide_country"' + (params.hide_country ? ' checked' : '') + '></td></tr>';
        }

        // Champ texte pour text_static et watermark
        if (zone.zone_type === 'text_static' || zone.zone_type === 'watermark') {
            html += '<tr><td>' + _l('text') + '</td><td><input type="text" class="flat" id="pp-text" value="' + _esc(params.text || '') + '"></td></tr>';
        }
        
        // Champ image pour logo_alt et image_bg
        if (zone.zone_type === 'logo_alt' || zone.zone_type === 'image_bg') {
            html += '<tr><td>' + _l('image_path') + '</td><td>' +
                    '<div style="display:flex; gap:5px;">' +
                    '<input type="text" class="flat" id="pp-src" style="flex:1; min-width:0;" value="' + _esc(params.src || '') + '">' +
                    '<button class="butAction" title="Upload" onclick="document.getElementById(\'pp-file\').click()">📁</button>' +
                    '</div>' +
                    '<input type="file" id="pp-file" style="display:none;" accept="image/*">' +
                    '<div id="pp-upload-status" style="font-size:0.85em; color:#666; margin-top:2px;"></div>' +
                    '</td></tr>';
        }

        html += '</table>';
        html += '<div class="pdfbd-props-actions">';
        html += '<button class="butActionDelete" onclick="PdfBuilderDesigner.deleteSelected()">' + _l('delete_zone') + '</button>';
        html += '</div>';

        _propsEl.innerHTML = html;

        // Bind live updates
        var fields = ['label', 'pos_x', 'pos_y', 'width', 'height', 'font_size', 'color_font', 'bg_color', 'align', 'text', 'src',
                      'font_family', 'border_style', 'border_width', 'border_color', 'row_alt_bg',
                      'padding_top', 'padding_bottom', 'padding_left', 'padding_right', 'col_weight'];
        fields.forEach(function(f) {
            var el = document.getElementById('pp-' + f);
            if (!el) return;
            el.addEventListener('input', function() {
                _updateZoneFromProps(zone);
            });
        });
        ['bold', 'italic', 'row_alt_enabled', 'hide_country', 'show_weight', 'show_price_per_kg'].forEach(function(f) {
            var el = document.getElementById('pp-' + f);
            if (!el) return;
            el.addEventListener('change', function() {
                _updateZoneFromProps(zone);
            });
        });

        // Bind upload
        var fileEl = document.getElementById('pp-file');
        if (fileEl) {
            fileEl.addEventListener('change', function() {
                _uploadImage(zone, this);
            });
        }
    }

    function _updateZoneFromProps(zone) {
        var v   = function(id) { var el = document.getElementById('pp-' + id); return el ? el.value : null; };
        var chk = function(id) { var el = document.getElementById('pp-' + id); return el ? el.checked : undefined; };

        if (v('label') !== null)  zone.label  = v('label');
        if (v('pos_x') !== null)  zone.pos_x  = parseFloat(v('pos_x')) || 0;
        if (v('pos_y') !== null)  zone.pos_y  = parseFloat(v('pos_y')) || 0;
        if (v('width') !== null)  zone.width  = parseFloat(v('width')) || 10;
        if (v('height') !== null) zone.height = parseFloat(v('height')) || 5;

        if (!zone.params) zone.params = {};
        if (v('font_size') !== null)  zone.params.font_size  = parseInt(v('font_size')) || 9;
        if (v('color_font') !== null) zone.params.color_font = v('color_font');
        if (v('bg_color') !== null)   zone.params.bg_color   = v('bg_color');
        if (v('align') !== null)      zone.params.align      = v('align');
        if (v('text') !== null)       zone.params.text       = v('text');
        if (v('src') !== null)        zone.params.src        = v('src');

        if (v('font_family') !== null)          zone.params.font_family          = v('font_family');
        if (chk('bold') !== undefined)          zone.params.bold                 = chk('bold');
        if (chk('italic') !== undefined)        zone.params.italic               = chk('italic');
        if (v('border_style') !== null)         zone.params.border_style         = v('border_style');
        if (v('border_width') !== null)         zone.params.border_width         = parseFloat(v('border_width')) || 0;
        if (v('border_color') !== null)         zone.params.border_color         = v('border_color');
        if (chk('row_alt_enabled') !== undefined)   zone.params.row_alt_enabled    = chk('row_alt_enabled');
        if (chk('hide_country') !== undefined)     zone.params.hide_country       = chk('hide_country');
        if (v('row_alt_bg') !== null)              zone.params.row_alt_bg         = v('row_alt_bg');
        if (chk('show_weight') !== undefined)      zone.params.show_weight        = chk('show_weight');
        if (v('col_weight') !== null)              zone.params.col_weight         = parseFloat(v('col_weight')) || 15;
        if (chk('show_price_per_kg') !== undefined) zone.params.show_price_per_kg = chk('show_price_per_kg');

        if (v('padding_top') !== null)    zone.params.padding_top    = parseFloat(v('padding_top')) || 0;
        if (v('padding_bottom') !== null) zone.params.padding_bottom = parseFloat(v('padding_bottom')) || 0;
        if (v('padding_left') !== null)   zone.params.padding_left   = parseFloat(v('padding_left')) || 0;
        if (v('padding_right') !== null)  zone.params.padding_right  = parseFloat(v('padding_right')) || 0;

        _refreshZoneEl(zone);
        _dirty = true;
    }

    /**
     * Upload an image via AJAX
     */
    function _uploadImage(zone, input) {
        if (!input.files || !input.files[0]) return;
        var file = input.files[0];
        var statusEl = document.getElementById('pp-upload-status');
        if (statusEl) statusEl.innerHTML = _l('uploading');

        var formData = new FormData();
        formData.append('action', 'upload_image');
        formData.append('token', PDFBD_TOKEN);
        formData.append('file', file);
        formData.append('subfolder', zone.zone_type === 'image_bg' ? 'backgrounds' : 'logos');

        fetch(PDFBD_AJAX_URL, {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(res) {
            if (res.success) {
                if (statusEl) statusEl.innerHTML = '<span style="color:green;">' + _l('upload_success') + '</span>';
                var srcEl = document.getElementById('pp-src');
                if (srcEl) {
                    srcEl.value = res.path;
                    _updateZoneFromProps(zone);
                    _renderCanvas();
                }
            } else {
                if (statusEl) statusEl.innerHTML = '<span style="color:red;">' + _l('error') + ' ' + (res.error || 'Unknown') + '</span>';
            }
        })
        .catch(function(err) {
            console.error(err);
            if (statusEl) statusEl.innerHTML = '<span style="color:red;">' + _l('error_network') + '</span>';
        });
    }

    // =====================================================
    // UNDO / REDO
    // =====================================================
    function _saveHistory() {
        _history.push(JSON.stringify(_zones));
        if (_history.length > 20) _history.shift();
        _future = [];
    }

    function undo() {
        if (_history.length <= 1) return;
        _future.push(_history.pop());
        _zones = JSON.parse(_history[_history.length - 1]);
        _renderCanvas();
        _selectZone(null);
    }

    function redo() {
        if (_future.length === 0) return;
        var state = _future.pop();
        _history.push(state);
        _zones = JSON.parse(state);
        _renderCanvas();
        _selectZone(null);
    }

    // =====================================================
    // DELETE
    // =====================================================
    function deleteSelected() {
        if (_selected === null) return;
        var idx = _zones.findIndex(function(z) { return z.id == _selected; });
        if (idx === -1) return;
        _zones.splice(idx, 1);
        var el = _canvas.querySelector('[data-id="' + _selected + '"]');
        if (el) el.remove();
        _selected = null;
        _renderProps();
        _saveHistory();
        _dirty = true;
    }

    // =====================================================
    // SAVE
    // =====================================================
    function save() {
        if (!_layout) return;

        var payload = {
            layout: {
                id:            _layout.id,
                label:         _layout.label,
                paper_format:  _layout.paper_format,
                margin_top:    _layout.margin_top,
                margin_left:   _layout.margin_left,
                margin_right:  _layout.margin_right,
                margin_bottom: _layout.margin_bottom,
                is_default:    _layout.is_default || 0,
                params:        _layout.params || {},
            },
            zones: _zones.map(function(z) {
                return {
                    id:           z.id > 0 ? z.id : 0,
                    zone_type:    z.zone_type,
                    page_context: z.page_context,
                    pos_x:        Math.round(z.pos_x * 10) / 10,
                    pos_y:        Math.round(z.pos_y * 10) / 10,
                    width:        Math.round(z.width * 10) / 10,
                    height:       Math.round(z.height * 10) / 10,
                    z_index:      z.z_index || 0,
                    params:       z.params || {},
                    label:        z.label || z.zone_type,
                    sort_order:   z.sort_order || 0,
                };
            }),
        };

        var url = PDFBD_AJAX_URL + '?action=save&layout_id=' + _layout.id + '&token=' + PDFBD_TOKEN;

        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                _dirty = false;
                // Recharger pour avoir les vrais IDs en BDD
                window.location.href = 'designer.php?layout_id=' + _layout.id;
            } else {
                alert((PDFBD_LANG && PDFBD_LANG.save_error ? PDFBD_LANG.save_error : 'Erreur') + ': ' + (data.error || ''));
            }
        })
        .catch(function(err) {
            alert(_l('error_network') + ': ' + err);
        });
    }

    // =====================================================
    // INIT LÉGER (sans layout actif — pour import/templates uniquement)
    // =====================================================
    function bindImportOnly() {
        _bindImportInput();
    }

    // =====================================================
    // EXPORT TEMPLATE
    // =====================================================
    function exportTemplate() {
        if (!_layout) return;

        var tpl = {
            layout: {
                label:         _layout.label,
                doc_type:      _layout.doc_type || 'invoice',
                paper_format:  _layout.paper_format || 'A4',
                margin_top:    _layout.margin_top,
                margin_left:   _layout.margin_left,
                margin_right:  _layout.margin_right,
                margin_bottom: _layout.margin_bottom,
            },
            zones: _zones.map(function(z) {
                return {
                    zone_type:    z.zone_type,
                    page_context: z.page_context || 'body',
                    pos_x:        Math.round(z.pos_x * 10) / 10,
                    pos_y:        Math.round(z.pos_y * 10) / 10,
                    width:        Math.round(z.width * 10) / 10,
                    height:       Math.round(z.height * 10) / 10,
                    z_index:      z.z_index || 0,
                    params:       z.params || {},
                    label:        z.label || z.zone_type,
                    sort_order:   z.sort_order || 0,
                };
            }),
        };

        var json = JSON.stringify(tpl, null, 2);
        var blob = new Blob([json], {type: 'application/json'});
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'pdfbuilder_' + (_layout.label || 'layout').replace(/[^a-z0-9_-]/gi, '_').toLowerCase() + '.json';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    // =====================================================
    // IMPORT TEMPLATE
    // =====================================================
    function importTemplateFile() {
        var fileInput = document.getElementById('pdfbd-import-file');
        if (fileInput) fileInput.click();
    }

    function _bindImportInput() {
        var fileInput = document.getElementById('pdfbd-import-file');
        if (!fileInput) return;
        fileInput.addEventListener('change', function() {
            var file = this.files[0];
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function(e) {
                try {
                    var data = JSON.parse(e.target.result);
                    _doImportTemplate(data);
                } catch (err) {
                    alert(_l('import_error') + ': ' + err.message);
                }
            };
            reader.readAsText(file);
            this.value = ''; // reset pour permettre re-import du même fichier
        });
    }

    function loadPredefinedTemplate(tplName) {
        var baseUrl = (typeof PDFBD_TEMPLATES_URL !== 'undefined') ? PDFBD_TEMPLATES_URL : '';
        if (!baseUrl) return;
        fetch(baseUrl + 'template_' + tplName + '.json')
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(data) {
            _doImportTemplate(data);
        })
        .catch(function(err) {
            alert(_l('import_error') + ': ' + err.message);
        });
    }

    function _doImportTemplate(data) {
        if (!data || !data.layout || !Array.isArray(data.zones)) {
            alert(_l('import_error') + ': format invalide');
            return;
        }

        var url = PDFBD_AJAX_URL + '?action=import_template&token=' + PDFBD_TOKEN;
        fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data),
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            if (resp.success && resp.layout_id) {
                window.location.href = 'designer.php?layout_id=' + resp.layout_id;
            } else {
                alert(_l('import_error') + ': ' + (resp.error || 'Unknown error'));
            }
        })
        .catch(function(err) {
            alert(_l('error_network') + ': ' + err.message);
        });
    }

    function openTemplateLibrary() {
        var modal = document.getElementById('pdfbd-tpl-modal');
        if (modal) modal.style.display = 'flex';
    }

    function closeTemplateLibrary() {
        var modal = document.getElementById('pdfbd-tpl-modal');
        if (modal) modal.style.display = 'none';
    }

    // =====================================================
    // PREVIEW
    // =====================================================
    function preview() {
        if (!_layout) return;

        var url = PDFBD_AJAX_URL + '?action=preview&layout_id=' + _layout.id + '&token=' + PDFBD_TOKEN;
        fetch(url, { method: 'POST' })
        .then(function(r) {
            return r.text().then(function(text) {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Raw response from server:', text);
                    throw new Error('Invalid JSON response');
                }
            });
        })
        .then(function(data) {
            if (data.success && data.pdf_base64) {
                // Convert Base64 to Blob
                var byteCharacters = atob(data.pdf_base64);
                var byteNumbers = new Array(byteCharacters.length);
                for (var i = 0; i < byteCharacters.length; i++) {
                    byteNumbers[i] = byteCharacters.charCodeAt(i);
                }
                var byteArray = new Uint8Array(byteNumbers);
                var blob = new Blob([byteArray], {type: 'application/pdf'});
                
                // Create Object URL and open it
                var fileURL = URL.createObjectURL(blob);
                window.open(fileURL, '_blank');
            } else {
                alert('Erreur prévisualisation: ' + (data.error || 'Check console for details'));
            }
        })
        .catch(function(err) {
            console.error('Preview error:', err);
            alert(_l('error_server') + ': ' + err.message);
        });
    }

    // =====================================================
    // PANNEAU PARAMÈTRES DU DOCUMENT (layout-level)
    // =====================================================
    function _renderLayoutPanel() {
        if (!_layoutEl || !_layout) return;

        var lp = (_layout.params && !Array.isArray(_layout.params)) ? _layout.params : {};
        var dt = _layout.doc_type || 'invoice';
        var bgUrl = (typeof PDFBD_LANG !== 'undefined' && PDFBD_LANG.bg_url_base) ? PDFBD_LANG.bg_url_base : '';
        var bgLink = bgUrl ? bgUrl + '?layout_id=' + _layout.id : '#';

        function chkHtml(id, val, label) {
            return '<tr><td>' + label + '</td><td><input type="checkbox" id="lp-' + id + '"' + (val ? ' checked' : '') + '></td></tr>';
        }

        var html = '<div class="pdfbd-layout-panel">';
        html += '<div class="pdfbd-layout-panel-title" onclick="this.parentNode.classList.toggle(\'collapsed\')">';
        html += '⚙ ' + _l('doc_options') + '</div>';
        html += '<div class="pdfbd-layout-panel-body">';

        // == Mise en page ==
        html += '<table class="pdfbd-props-table">';
        html += '<tr class="pdfbd-props-section"><td colspan="2">' + _l('section_layout') + '</td></tr>';
        html += '<tr><td>' + _l('layout_label') + '</td><td><input type="text" class="flat" id="lp-label" value="' + _esc(_layout.label || '') + '"></td></tr>';
        html += '<tr><td>' + _l('layout_paper') + '</td><td><select id="lp-paper_format" class="flat">';
        ['A4','A3','LETTER'].forEach(function(fmt) {
            html += '<option value="' + fmt + '"' + ((_layout.paper_format || 'A4') === fmt ? ' selected' : '') + '>' + fmt + '</option>';
        });
        html += '</select></td></tr>';
        html += '<tr><td>' + _l('layout_default') + '</td><td><input type="checkbox" id="lp-is_default"' + (_layout.is_default ? ' checked' : '') + '></td></tr>';
        html += '<tr class="pdfbd-props-section"><td colspan="2">' + _l('layout_margins') + '</td></tr>';
        html += '<tr><td>' + _l('top_bottom') + '</td><td>';
        html += '<input type="number" class="flat" id="lp-margin_top" step="0.5" min="0" style="width:45%;display:inline;" value="' + (_layout.margin_top || 10) + '"> ';
        html += '<input type="number" class="flat" id="lp-margin_bottom" step="0.5" min="0" style="width:45%;display:inline;" value="' + (_layout.margin_bottom || 10) + '">';
        html += '</td></tr>';
        html += '<tr><td>' + _l('left_right') + '</td><td>';
        html += '<input type="number" class="flat" id="lp-margin_left" step="0.5" min="0" style="width:45%;display:inline;" value="' + (_layout.margin_left || 11) + '"> ';
        html += '<input type="number" class="flat" id="lp-margin_right" step="0.5" min="0" style="width:45%;display:inline;" value="' + (_layout.margin_right || 10) + '">';
        html += '</td></tr>';

        // == Affichage ==
        html += '<tr class="pdfbd-props-section"><td colspan="2">' + _l('section_display') + '</td></tr>';
        html += chkHtml('show_fold_mark',      lp.show_fold_mark,      _l('show_fold_mark'));
        html += chkHtml('show_line_numbers',   lp.show_line_numbers,   _l('show_line_numbers'));
        html += chkHtml('dash_between_lines',  lp.dash_between_lines,  _l('dash_between_lines'));
        html += chkHtml('add_client_details',  lp.add_client_details,  _l('add_client_details'));
        html += chkHtml('add_target_details',  lp.add_target_details,  _l('add_target_details'));

        // == Mentions / options par type de document ==
        if (dt === 'invoice') {
            html += '<tr class="pdfbd-props-section"><td colspan="2">' + _l('section_mentions') + '</td></tr>';
            html += chkHtml('auto_liquidation', lp.auto_liquidation, _l('auto_liquidation'));
            html += chkHtml('show_lcr',         lp.show_lcr,         _l('show_lcr'));
            html += chkHtml('with_merged_pdf',  lp.with_merged_pdf,  _l('with_merged_pdf'));
        }

        // == Fond du document ==
        html += '<tr class="pdfbd-props-section"><td colspan="2">' + _l('section_bg') + '</td></tr>';
        var bgImageVal = lp.bg_image ? lp.bg_image.split('/').pop() : '—';
        var bgPdfVal   = lp.bg_pdf   ? lp.bg_pdf.split('/').pop()   : '—';
        html += '<tr><td>' + _l('bg_image') + '</td><td><small>' + _esc(bgImageVal) + '</small></td></tr>';
        html += '<tr><td>' + _l('bg_pdf') + '</td><td><small>' + _esc(bgPdfVal) + '</small></td></tr>';
        html += '<tr><td>' + _l('bg_opacity') + '</td><td>';
        html += '<input type="range" id="lp-bg_opacity" min="0.05" max="1" step="0.05" value="' + (lp.bg_opacity || 0.10) + '" style="width:110px;vertical-align:middle;">';
        html += ' <span id="lp-bg_opacity_val">' + (lp.bg_opacity || 0.10) + '</span>';
        html += '</td></tr>';
        if (bgLink !== '#') {
            html += '<tr><td colspan="2"><a href="' + _esc(bgLink) + '" target="_blank" class="pdfbd-bg-manage-link">🖼 ' + _l('manage_backgrounds') + ' →</a></td></tr>';
        }

        html += '</table>';
        html += '</div></div>'; // panel-body + layout-panel

        _layoutEl.innerHTML = html;

        // Bind live updates
        var simpleFields = ['label', 'paper_format', 'bg_opacity'];
        simpleFields.forEach(function(f) {
            var el = document.getElementById('lp-' + f);
            if (!el) return;
            el.addEventListener('input',  function() { _updateLayoutFromProps(); });
            el.addEventListener('change', function() { _updateLayoutFromProps(); });
        });
        // Marges : redessiner le canvas au change (blur)
        var marginFields = ['margin_top', 'margin_bottom', 'margin_left', 'margin_right'];
        marginFields.forEach(function(f) {
            var el = document.getElementById('lp-' + f);
            if (!el) return;
            el.addEventListener('change', function() { _updateLayoutFromProps(); _renderCanvas(); });
        });
        var checkFields = ['is_default', 'show_fold_mark', 'show_line_numbers', 'dash_between_lines',
                           'add_client_details', 'add_target_details',
                           'auto_liquidation', 'show_lcr', 'with_merged_pdf'];
        checkFields.forEach(function(f) {
            var el = document.getElementById('lp-' + f);
            if (!el) return;
            el.addEventListener('change', function() { _updateLayoutFromProps(); });
        });

        // Afficher la valeur de l'opacité en live
        var opEl = document.getElementById('lp-bg_opacity');
        var opValEl = document.getElementById('lp-bg_opacity_val');
        if (opEl && opValEl) {
            opEl.addEventListener('input', function() { opValEl.textContent = this.value; });
        }
    }

    function _updateLayoutFromProps() {
        if (!_layout) return;

        var v   = function(id) { var el = document.getElementById('lp-' + id); return el ? el.value : null; };
        var chk = function(id) { var el = document.getElementById('lp-' + id); return el ? el.checked : undefined; };

        if (v('label') !== null)         _layout.label         = v('label');
        if (v('paper_format') !== null)  _layout.paper_format  = v('paper_format');
        if (chk('is_default') !== undefined) _layout.is_default = chk('is_default') ? 1 : 0;
        if (v('margin_top') !== null)    _layout.margin_top    = parseFloat(v('margin_top')) || 10;
        if (v('margin_bottom') !== null) _layout.margin_bottom = parseFloat(v('margin_bottom')) || 10;
        if (v('margin_left') !== null)   _layout.margin_left   = parseFloat(v('margin_left')) || 11;
        if (v('margin_right') !== null)  _layout.margin_right  = parseFloat(v('margin_right')) || 10;

        if (!_layout.params || Array.isArray(_layout.params)) _layout.params = {};
        var boolFields = ['show_fold_mark', 'show_line_numbers', 'dash_between_lines',
                          'add_client_details', 'add_target_details',
                          'auto_liquidation', 'show_lcr', 'with_merged_pdf'];
        boolFields.forEach(function(f) {
            var val = chk(f);
            if (val !== undefined) _layout.params[f] = val;
        });
        if (v('bg_opacity') !== null) _layout.params.bg_opacity = parseFloat(v('bg_opacity')) || 0.10;

        _dirty = true;
    }

    // =====================================================
    // HELPERS
    // =====================================================
    function _findZone(id) {
        return _zones.find(function(z) { return z.id == id; }) || null;
    }

    function _esc(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function _l(key) {
        return (typeof PDFBD_LANG !== 'undefined' && PDFBD_LANG[key]) ? PDFBD_LANG[key] : key;
    }

    function _bindGlobalEvents() {
        document.addEventListener('mousemove', _onMousemove);
        document.addEventListener('mouseup',   _onMouseup);

        // Clic en dehors des zones → désélectionner
        _canvas.addEventListener('mousedown', function(e) {
            if (e.target === _canvas) {
                _selectZone(null);
            }
        });

        // Avertissement si on quitte sans sauvegarder
        window.addEventListener('beforeunload', function(e) {
            if (_dirty) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        // Touches clavier
        document.addEventListener('keydown', function(e) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;
            if (e.key === 'Delete' || e.key === 'Backspace') {
                deleteSelected();
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 'z') {
                e.preventDefault();
                if (e.shiftKey) redo(); else undo();
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                save();
            }
        });
    }

    // API publique
    return {
        init:                  init,
        save:                  save,
        preview:               preview,
        undo:                  undo,
        redo:                  redo,
        deleteSelected:        deleteSelected,
        addZone:               _addZone,
        renderLayoutPanel:     _renderLayoutPanel,
        exportTemplate:         exportTemplate,
        importTemplateFile:     importTemplateFile,
        loadPredefinedTemplate: loadPredefinedTemplate,
        openTemplateLibrary:    openTemplateLibrary,
        closeTemplateLibrary:   closeTemplateLibrary,
        bindImportOnly:         bindImportOnly,
    };

})();
