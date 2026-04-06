<?php
/* Copyright (C) 2024 Antigravity Project
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

/**
 * \file       pdfbuilder/class/pdfbuilder.class.php
 * \ingroup    pdfbuilder
 * \brief      Classe DAO pour les thèmes PDF-builder (Active Record)
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class PdfBuilderTheme
 * Active Record pour la table llx_pdfbuilder_theme
 */
class PdfBuilderTheme extends CommonObject
{
    /** @var string Nom de la table */
    public $table_element = 'pdfbuilder_theme';

    /** @var string Identifiant de l'élément */
    public $element = 'pdfbuilder_theme';

    /** @var string Icône du module */
    public $picto = 'pdfbuilder@pdfbuilder';

    // === Propriétés mappées sur la table ===

    /** @var int Entité (multi-sociétés) */
    public $entity;

    /** @var string Libellé du thème */
    public $label;

    /** @var string Description */
    public $description;

    /** @var string Type de document : invoice, propal, order, etc. */
    public $doc_type;

    /** @var string Famille de police FPDF */
    public $font_family;

    /** @var int Taille de police de base */
    public $font_size;
    public $font_size_ref;
    public $font_style_ref;
    public $font_size_address;
    public $font_style_address;
    public $font_size_theader;
    public $font_style_theader;
    public $font_size_desc;
    public $font_style_desc;
    public $font_size_note;
    public $font_style_note;
    public $font_size_footer;
    public $font_style_footer;
    public $font_style_freetext;
    public $font_size_sender;
    public $font_style_sender;
    public $font_size_recipient;
    public $font_style_recipient;
    public $address_block_width;
    public $hide_situation;

    // --- Couleurs ---
    /** @var string Couleur principale du texte (HEX #xxxxxx) */
    public $color_font;
    public $color_font_sender;
    public $color_font_recipient;

    /** @var string Couleur des bordures */
    public $color_border;

    /** @var string Fond des entêtes de colonnes */
    public $color_header_bg;

    /** @var string Texte des entêtes de colonnes */
    public $color_header_txt;

    /** @var string Fond bloc adresse émetteur */
    public $color_address_bg;

    /** @var string Fond bloc adresse destinataire */
    public $color_address_bg2;

    /** @var string Couleur QR code */
    public $color_qrcode;

    // --- Bordures ---
    /** @var string Style de bordure : solid | dashed */
    public $border_style;

    /** @var int Rayon des angles en mm */
    public $border_radius;

    // --- Fond ---
    /** @var float Opacité de l'image de fond (0.00 -> 1.00) */
    public $bg_opacity;

    /** @var string Chemin image de fond */
    public $bg_image;

    /** @var string Chemin PDF de fond */
    public $bg_pdf;

    // --- Logo ---
    /** @var string Chemin logo alternatif */
    public $logo_alt;

    /** @var int Afficher logo alternatif */
    public $show_logo_alt;

    /** @var int Masquer logo principal */
    public $hide_main_logo;

    /** @var float Hauteur du logo (mm) */
    public $logo_height;

    /** @var float Espacement sous l'en-tête (mm) */
    public $header_spacing;

    /** @var int Afficher le code client */
    public $show_customer_code;

    // --- Mise en page ---
    /** @var int Inverser émetteur/destinataire */
    public $reverse_address;

    /** @var string Format papier : A4, A3, Letter... */
    public $paper_format;

    /** @var float Marge supérieure (mm) */
    public $margin_top;

    /** @var float Marge gauche (mm) */
    public $margin_left;

    /** @var float Marge droite (mm) */
    public $margin_right;

    /** @var float Marge inférieure (mm) */
    public $margin_bottom;

    // --- Largeurs colonnes ---
    public $col_width_number;
    public $col_width_ref;
    public $col_width_desc;
    public $col_width_img;
    public $col_width_vat;
    public $col_width_puht;
    public $col_width_qty;
    public $col_width_unit;
    public $col_width_discount;
    public $col_width_total;

    // --- Affichage colonnes ---
    public $show_line_numbers;
    public $show_ref_column;
    public $show_pictures;
    public $hide_vat_column;
    public $hide_puht;
    public $hide_qty;
    public $hide_discount;
    public $show_unit;
    public $show_line_ttc;
    public $hide_desc_long;

    // --- Pied de page ---
    public $show_fold_mark;
    public $no_repeat_header;
    public $show_vat_breakdown;
    public $hide_rib;
    public $show_bon_accord;
    public $show_signature;
    public $show_total_weight;

    // --- Texte libre ---
    public $freetext_height;
    public $freetext_fontsize;
    public $note_public;

    // --- Statut ---
    /** @var int Thème par défaut pour ce type de document */
    public $is_default;

    /** @var int Actif */
    public $active;

    /** @var string Date de création */
    public $date_creation;

    /** @var int Utilisateur créateur */
    public $fk_user_creat;

    /** @var int Utilisateur modificateur */
    public $fk_user_modif;

    /**
     * Constructeur
     * @param DoliDB $db Base de données
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->_setDefaults();
    }

    /**
     * Valeurs par défaut pour un nouveau thème
     */
    private function _setDefaults()
    {
        $this->entity           = 1;
        $this->label            = '';
        $this->doc_type         = 'invoice';
        $this->font_family      = 'DejaVuSans';
        $this->font_size        = 9;
        $this->font_size_ref    = 9;
        $this->font_style_ref   = '';
        $this->font_size_address= 9;
        $this->font_style_address= '';
        $this->font_size_theader= 8;
        $this->font_style_theader= 'B';
        $this->font_size_desc   = 9;
        $this->font_style_desc  = '';
        $this->font_size_note   = 8;
        $this->font_style_note  = '';
        $this->font_size_footer = 7;
        $this->font_style_footer= '';
        $this->color_font       = '#333333';
        $this->color_border     = '#cccccc';
        $this->color_header_bg  = '#4a6fa1';
        $this->color_header_txt = '#ffffff';
        $this->color_address_bg = '#f0f4fa';
        $this->color_address_bg2 = '#f0f4fa';
        $this->color_qrcode     = '#000000';
        $this->border_style     = 'solid';
        $this->border_radius    = 3;
        $this->bg_opacity       = 0.10;
        $this->paper_format     = 'A4';
        $this->margin_top       = 10.0;
        $this->margin_left      = 11.0;
        $this->margin_right     = 10.0;
        $this->margin_bottom    = 10.0;
        $this->col_width_number = 8.0;
        $this->col_width_ref    = 22.0;
        $this->col_width_img    = 20.0;
        $this->col_width_vat    = 13.0;
        $this->col_width_puht   = 19.0;
        $this->col_width_qty    = 13.0;
        $this->col_width_unit   = 11.0;
        $this->col_width_discount = 12.0;
        $this->col_width_total  = 22.0;
        $this->freetext_height  = 12.0;
        $this->freetext_fontsize = 7;
        $this->note_public      = '';
        $this->logo_height     = 18.0;
        $this->header_spacing  = 2.0;
        $this->show_customer_code = 0;
        $this->font_size_sender    = 9;
        $this->font_style_sender   = '';
        $this->font_size_recipient = 9;
        $this->font_style_recipient= '';
        $this->address_block_width = 93.0;
        $this->hide_situation      = 0;
        $this->show_total_weight   = 0;
        $this->color_font          = '#333333';
        $this->color_font_sender   = '';
        $this->color_font_recipient= '';
        $this->show_total_weight   = 0;
        $this->active              = 1;
        $this->is_default       = 0;
    }

    /**
     * Crée un thème en base de données
     * @param User $user Utilisateur courant
     * @param int $notrigger 1 = pas de trigger
     * @return int <0 si erreur, >0 si OK (rowid)
     */
    public function create($user, $notrigger = 0)
    {
        global $conf;

        $this->db->begin();

        $now = dol_now();

        // Si ce thème est marqué par défaut, enlever le flag des autres
        if ($this->is_default) {
            $sql = "UPDATE ".MAIN_DB_PREFIX."pdfbuilder_theme";
            $sql .= " SET is_default = 0";
            $sql .= " WHERE entity = ".((int) $this->entity);
            $sql .= " AND doc_type = '".$this->db->escape($this->doc_type)."'";
            $this->db->query($sql);
        }

        $sql = "INSERT INTO ".MAIN_DB_PREFIX."pdfbuilder_theme (";
        $sql .= "entity, label, description, doc_type, font_family, font_size,";
        $sql .= "font_size_ref, font_style_ref, font_size_address, font_style_address, font_size_theader, font_style_theader,";
        $sql .= "font_size_desc, font_style_desc, font_size_note, font_style_note, font_size_footer, font_style_footer, font_style_freetext,";
        $sql .= "color_font, color_border, color_header_bg, color_header_txt,";
        $sql .= "color_address_bg, color_address_bg2, color_qrcode,";
        $sql .= "border_style, border_radius, bg_opacity,";
        $sql .= "bg_image, bg_pdf, logo_alt, show_logo_alt, hide_main_logo, logo_height, header_spacing, show_customer_code, reverse_address,";
        $sql .= "paper_format, margin_top, margin_left, margin_right, margin_bottom,";
        $sql .= "col_width_number, col_width_ref, col_width_desc, col_width_img,";
        $sql .= "col_width_vat, col_width_puht, col_width_qty, col_width_unit,";
        $sql .= "col_width_discount, col_width_total,";
        $sql .= "show_line_numbers, show_ref_column, show_pictures, hide_vat_column,";
        $sql .= "hide_puht, hide_qty, hide_discount, show_unit, show_line_ttc, hide_desc_long,";
        $sql .= "show_fold_mark, no_repeat_header, show_vat_breakdown, hide_rib, show_bon_accord, show_signature, show_total_weight,";
        $sql .= "font_size_sender, font_style_sender, font_size_recipient, font_style_recipient, address_block_width,";
        $sql .= "color_font, color_font_sender, color_font_recipient,";
        $sql .= "freetext_height, freetext_fontsize, note_public, is_default, active,";
        $sql .= "date_creation, fk_user_creat";
        $sql .= ") VALUES (";
        $sql .= ((int) ($this->entity ?: $conf->entity)).", ";
        $sql .= "'".$this->db->escape($this->label)."', ";
        $sql .= ($this->description ? "'".$this->db->escape($this->description)."'" : "NULL").", ";
        $sql .= "'".$this->db->escape($this->doc_type)."', ";
        $sql .= "'".$this->db->escape($this->font_family ?: 'DejaVuSans')."', ";
        $sql .= ((int) ($this->font_size ?: 9)).", ";
        $sql .= ((int) ($this->font_size_ref ?: 9)).", ";
        $sql .= "'".$this->db->escape($this->font_style_ref ?: '')."', ";
        $sql .= ((int) ($this->font_size_address ?: 9)).", ";
        $sql .= "'".$this->db->escape($this->font_style_address ?: '')."', ";
        $sql .= ((int) ($this->font_size_theader ?: 8)).", ";
        $sql .= "'".$this->db->escape($this->font_style_theader ?: '')."', ";
        $sql .= ((int) ($this->font_size_desc ?: 9)).", ";
        $sql .= "'".$this->db->escape($this->font_style_desc ?: '')."', ";
        $sql .= ((int) ($this->font_size_note ?: 8)).", ";
        $sql .= "'".$this->db->escape($this->font_style_note ?: '')."', ";
        $sql .= ((int) ($this->font_size_footer ?: 7)).", ";
        $sql .= "'".$this->db->escape($this->font_style_footer ?: '')."', ";
        $sql .= "'".$this->db->escape($this->font_style_freetext ?: '')."', ";
        $sql .= "'".$this->db->escape($this->color_font ?: '#333333')."', ";
        $sql .= "'".$this->db->escape($this->color_border ?: '#cccccc')."', ";
        $sql .= "'".$this->db->escape($this->color_header_bg ?: '#4a6fa1')."', ";
        $sql .= "'".$this->db->escape($this->color_header_txt ?: '#ffffff')."', ";
        $sql .= "'".$this->db->escape($this->color_address_bg ?: '#f0f4fa')."', ";
        $sql .= "'".$this->db->escape($this->color_address_bg2 ?: '#f0f4fa')."', ";
        $sql .= "'".$this->db->escape($this->color_qrcode ?: '#000000')."', ";
        $sql .= "'".$this->db->escape($this->border_style ?: 'solid')."', ";
        $sql .= ((int) ($this->border_radius ?? 3)).", ";
        $sql .= ((float) ($this->bg_opacity ?? 0.10)).", ";
        $sql .= ($this->bg_image ? "'".$this->db->escape($this->bg_image)."'" : "NULL").", ";
        $sql .= ($this->bg_pdf   ? "'".$this->db->escape($this->bg_pdf)."'"   : "NULL").", ";
        $sql .= ($this->logo_alt ? "'".$this->db->escape($this->logo_alt)."'" : "NULL").", ";
        $sql .= ((int) ($this->show_logo_alt ?? 0)).", ";
        $sql .= ((int) ($this->hide_main_logo ?? 0)).", ";
        $sql .= ((float) ($this->logo_height ?? 18.0)).", ";
        $sql .= ((float) ($this->header_spacing ?? 2.0)).", ";
        $sql .= ((int) ($this->show_customer_code ?? 0)).", ";
        $sql .= ((int) ($this->reverse_address ?? 0)).", ";
        $sql .= "'".$this->db->escape($this->paper_format ?: 'A4')."', ";
        $sql .= ((float) ($this->margin_top   ?? 10.0)).", ";
        $sql .= ((float) ($this->margin_left  ?? 11.0)).", ";
        $sql .= ((float) ($this->margin_right ?? 10.0)).", ";
        $sql .= ((float) ($this->margin_bottom ?? 10.0)).", ";
        $sql .= ((float) ($this->col_width_number   ?? 8.0)).", ";
        $sql .= ((float) ($this->col_width_ref      ?? 22.0)).", ";
        $sql .= ((float) ($this->col_width_desc     ?? 0.0)).", ";
        $sql .= ((float) ($this->col_width_img      ?? 20.0)).", ";
        $sql .= ((float) ($this->col_width_vat      ?? 13.0)).", ";
        $sql .= ((float) ($this->col_width_puht     ?? 19.0)).", ";
        $sql .= ((float) ($this->col_width_qty      ?? 13.0)).", ";
        $sql .= ((float) ($this->col_width_unit     ?? 11.0)).", ";
        $sql .= ((float) ($this->col_width_discount ?? 12.0)).", ";
        $sql .= ((float) ($this->col_width_total    ?? 22.0)).", ";
        $sql .= ((int) ($this->show_line_numbers ?? 0)).", ";
        $sql .= ((int) ($this->show_ref_column   ?? 0)).", ";
        $sql .= ((int) ($this->show_pictures     ?? 0)).", ";
        $sql .= ((int) ($this->hide_vat_column   ?? 0)).", ";
        $sql .= ((int) ($this->hide_puht         ?? 0)).", ";
        $sql .= ((int) ($this->hide_qty          ?? 0)).", ";
        $sql .= ((int) ($this->hide_discount     ?? 0)).", ";
        $sql .= ((int) ($this->show_unit         ?? 0)).", ";
        $sql .= ((int) ($this->show_line_ttc     ?? 0)).", ";
        $sql .= ((int) ($this->hide_desc_long    ?? 0)).", ";
        $sql .= ((int) ($this->show_fold_mark    ?? 0)).", ";
        $sql .= ((int) ($this->no_repeat_header  ?? 0)).", ";
        $sql .= ((int) ($this->show_vat_breakdown ?? 0)).", ";
        $sql .= ((int) ($this->hide_rib          ?? 0)).", ";
        $sql .= ((int) ($this->show_bon_accord   ?? 0)).", ";
        $sql .= ((int) ($this->show_signature    ?? 0)).", ";
        $sql .= ((int) ($this->show_total_weight ?? 0)).", ";
        $sql .= ((int) ($this->font_size_sender    ?? 9)).", ";
        $sql .= "'".$this->db->escape($this->font_style_sender   ?? '')."', ";
        $sql .= ((int) ($this->font_size_recipient ?? 9)).", ";
        $sql .= "'".$this->db->escape($this->font_style_recipient ?? '')."', ";
        $sql .= ((int) ($this->hide_situation ?? 0)).", ";
        $sql .= ((int) ($this->show_total_weight ?? 0)).", ";
        $sql .= ((float) ($this->address_block_width ?? 93.0)).", ";
        $sql .= "'".$this->db->escape($this->color_font ?? '#333333')."', ";
        $sql .= "'".$this->db->escape($this->color_font_sender ?? '')."', ";
        $sql .= "'".$this->db->escape($this->color_font_recipient ?? '')."', ";
        $sql .= ((float) ($this->freetext_height   ?? 12.0)).", ";
        $sql .= ((int)   ($this->freetext_fontsize ?? 7)).", ";
        $sql .= ($this->note_public ? "'".$this->db->escape($this->note_public)."'" : "NULL").", ";
        $sql .= ((int) ($this->is_default ?? 0)).", ";
        $sql .= ((int) ($this->active ?? 1)).", ";
        $sql .= "'".$this->db->idate($now)."', ";
        $sql .= ((int) $user->id);
        $sql .= ")";

        $res = $this->db->query($sql);
        if ($res) {
            $this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.'pdfbuilder_theme');
            $this->db->commit();
            dol_syslog('PdfBuilderTheme::create id='.$this->id, LOG_DEBUG);
            return $this->id;
        } else {
            $this->db->rollback();
            $this->error = $this->db->lasterror();
            dol_syslog('PdfBuilderTheme::create '.$this->error, LOG_ERR);
            return -1;
        }
    }

    /**
     * Charge un thème depuis la base
     * @param int $id ID du thème
     * @return int <0 si erreur, 0 si non trouvé, 1 si OK
     */
    public function fetch($id)
    {
        $sql = "SELECT * FROM ".MAIN_DB_PREFIX."pdfbuilder_theme WHERE rowid = ".((int) $id);
        $res = $this->db->query($sql);
        if (!$res) {
            $this->error = $this->db->lasterror();
            return -1;
        }
        $obj = $this->db->fetch_object($res);
        if (!$obj) {
            return 0;
        }
        $this->_loadFromObj($obj);
        return 1;
    }

    /**
     * Met à jour un thème
     * @param User $user Utilisateur courant
     * @param int $notrigger 1 = pas de trigger
     * @return int <0 si erreur, >0 si OK
     */
    public function update($user, $notrigger = 0)
    {
        $this->db->begin();

        // Enlever is_default des autres si ce thème devient défaut
        if ($this->is_default) {
            $sql = "UPDATE ".MAIN_DB_PREFIX."pdfbuilder_theme";
            $sql .= " SET is_default = 0";
            $sql .= " WHERE entity = ".((int) $this->entity);
            $sql .= " AND doc_type = '".$this->db->escape($this->doc_type)."'";
            $sql .= " AND rowid != ".((int) $this->id);
            $this->db->query($sql);
        }

        $sql = "UPDATE ".MAIN_DB_PREFIX."pdfbuilder_theme SET";
        $sql .= " label = '".$this->db->escape($this->label)."'";
        $sql .= ", description = ".($this->description ? "'".$this->db->escape($this->description)."'" : 'NULL');
        $sql .= ", doc_type = '".$this->db->escape($this->doc_type)."'";
        $sql .= ", font_family = '".$this->db->escape($this->font_family ?: 'DejaVuSans')."'";
        $sql .= ", font_size = ".((int) ($this->font_size ?: 9));
        $sql .= ", font_size_ref = ".((int) ($this->font_size_ref ?: 9));
        $sql .= ", font_style_ref = '".$this->db->escape($this->font_style_ref)."'";
        $sql .= ", font_size_address = ".((int) ($this->font_size_address ?: 9));
        $sql .= ", font_style_address = '".$this->db->escape($this->font_style_address)."'";
        $sql .= ", font_size_theader = ".((int) ($this->font_size_theader ?: 8));
        $sql .= ", font_style_theader = '".$this->db->escape($this->font_style_theader)."'";
        $sql .= ", font_size_desc = ".((int) ($this->font_size_desc ?: 9));
        $sql .= ", font_style_desc = '".$this->db->escape($this->font_style_desc)."'";
        $sql .= ", font_size_note = ".((int) ($this->font_size_note ?: 8));
        $sql .= ", font_style_note = '".$this->db->escape($this->font_style_note)."'";
        $sql .= ", font_size_footer = ".((int) ($this->font_size_footer ?: 7));
        $sql .= ", font_style_footer = '".$this->db->escape($this->font_style_footer)."'";
        $sql .= ", font_style_freetext = '".$this->db->escape($this->font_style_freetext)."'";
        $sql .= ", color_font = '".$this->db->escape($this->color_font)."'";
        $sql .= ", color_border = '".$this->db->escape($this->color_border)."'";
        $sql .= ", color_header_bg = '".$this->db->escape($this->color_header_bg)."'";
        $sql .= ", color_header_txt = '".$this->db->escape($this->color_header_txt)."'";
        $sql .= ", color_address_bg = '".$this->db->escape($this->color_address_bg)."'";
        $sql .= ", color_address_bg2 = '".$this->db->escape($this->color_address_bg2)."'";
        $sql .= ", color_qrcode = '".$this->db->escape($this->color_qrcode)."'";
        $sql .= ", border_style = '".$this->db->escape($this->border_style)."'";
        $sql .= ", border_radius = ".((int) ($this->border_radius ?? 3));
        $sql .= ", bg_opacity = ".((float) ($this->bg_opacity ?? 0.10));
        $sql .= ", bg_image = ".($this->bg_image ? "'".$this->db->escape($this->bg_image)."'" : 'NULL');
        $sql .= ", bg_pdf = ".($this->bg_pdf ? "'".$this->db->escape($this->bg_pdf)."'" : 'NULL');
        $sql .= ", logo_alt = ".($this->logo_alt ? "'".$this->db->escape($this->logo_alt)."'" : 'NULL');
        $sql .= ", show_logo_alt = ".((int) ($this->show_logo_alt ?? 0));
        $sql .= ", hide_main_logo = ".((int) ($this->hide_main_logo ?? 0));
        $sql .= ", logo_height = ".((float) ($this->logo_height ?? 18.0));
        $sql .= ", header_spacing = ".((float) ($this->header_spacing ?? 2.0));
        $sql .= ", show_customer_code = ".((int) ($this->show_customer_code ?? 0));
        $sql .= ", reverse_address = ".((int) ($this->reverse_address ?? 0));
        $sql .= ", paper_format = '".$this->db->escape($this->paper_format ?: 'A4')."'";
        $sql .= ", margin_top = ".((float) ($this->margin_top ?? 10.0));
        $sql .= ", margin_left = ".((float) ($this->margin_left ?? 11.0));
        $sql .= ", margin_right = ".((float) ($this->margin_right ?? 10.0));
        $sql .= ", margin_bottom = ".((float) ($this->margin_bottom ?? 10.0));
        $sql .= ", col_width_number = ".((float) ($this->col_width_number ?? 8.0));
        $sql .= ", col_width_ref = ".((float) ($this->col_width_ref ?? 22.0));
        $sql .= ", col_width_desc = ".((float) ($this->col_width_desc ?? 0.0));
        $sql .= ", col_width_img = ".((float) ($this->col_width_img ?? 20.0));
        $sql .= ", col_width_vat = ".((float) ($this->col_width_vat ?? 13.0));
        $sql .= ", col_width_puht = ".((float) ($this->col_width_puht ?? 19.0));
        $sql .= ", col_width_qty = ".((float) ($this->col_width_qty ?? 13.0));
        $sql .= ", col_width_unit = ".((float) ($this->col_width_unit ?? 11.0));
        $sql .= ", col_width_discount = ".((float) ($this->col_width_discount ?? 12.0));
        $sql .= ", col_width_total = ".((float) ($this->col_width_total ?? 22.0));
        $sql .= ", show_line_numbers = ".((int) ($this->show_line_numbers ?? 0));
        $sql .= ", show_ref_column = ".((int) ($this->show_ref_column ?? 0));
        $sql .= ", show_pictures = ".((int) ($this->show_pictures ?? 0));
        $sql .= ", hide_vat_column = ".((int) ($this->hide_vat_column ?? 0));
        $sql .= ", hide_puht = ".((int) ($this->hide_puht ?? 0));
        $sql .= ", hide_qty = ".((int) ($this->hide_qty ?? 0));
        $sql .= ", hide_discount = ".((int) ($this->hide_discount ?? 0));
        $sql .= ", show_unit = ".((int) ($this->show_unit ?? 0));
        $sql .= ", show_line_ttc = ".((int) ($this->show_line_ttc ?? 0));
        $sql .= ", hide_desc_long = ".((int) ($this->hide_desc_long ?? 0));
        $sql .= ", show_fold_mark = ".((int) ($this->show_fold_mark ?? 0));
        $sql .= ", no_repeat_header = ".((int) ($this->no_repeat_header ?? 0));
        $sql .= ", show_vat_breakdown = ".((int) ($this->show_vat_breakdown ?? 0));
        $sql .= ", hide_rib = ".((int) ($this->hide_rib ?? 0));
        $sql .= ", show_bon_accord = ".((int) ($this->show_bon_accord ?? 0));
        $sql .= ", show_signature = ".((int) ($this->show_signature ?? 0));
        $sql .= ", show_total_weight = ".((int) ($this->show_total_weight ?? 0));
        $sql .= ", font_size_sender = ".((int) ($this->font_size_sender ?? 9));
        $sql .= ", font_style_sender = '".$this->db->escape($this->font_style_sender ?? '')."'";
        $sql .= ", font_size_recipient = ".((int) ($this->font_size_recipient ?? 9));
        $sql .= ", font_style_recipient = '".$this->db->escape($this->font_style_recipient ?? '')."'";
        $sql .= ", hide_situation = ".((int) ($this->hide_situation ?? 0));
        $sql .= ", show_total_weight = ".((int) ($this->show_total_weight ?? 0));
        $sql .= ", address_block_width = ".((float) ($this->address_block_width ?? 93.0));
        $sql .= ", color_font = '".$this->db->escape($this->color_font ?? '#333333')."'";
        $sql .= ", color_font_sender = '".$this->db->escape($this->color_font_sender ?? '')."'";
        $sql .= ", color_font_recipient = '".$this->db->escape($this->color_font_recipient ?? '')."'";
        $sql .= ", freetext_height = ".((float) ($this->freetext_height ?? 12.0));
        $sql .= ", freetext_fontsize = ".((int) ($this->freetext_fontsize ?? 7));
        $sql .= ", note_public = ".($this->note_public ? "'".$this->db->escape($this->note_public)."'" : 'NULL');
        $sql .= ", is_default = ".((int) ($this->is_default ?? 0));
        $sql .= ", active = ".((int) ($this->active ?? 1));
        $sql .= ", fk_user_modif = ".((int) $user->id);
        $sql .= " WHERE rowid = ".((int) $this->id);

        $res = $this->db->query($sql);
        if ($res) {
            $this->db->commit();
            dol_syslog('PdfBuilderTheme::update id='.$this->id, LOG_DEBUG);
            return 1;
        } else {
            $this->db->rollback();
            $this->error = $this->db->lasterror();
            dol_syslog('PdfBuilderTheme::update '.$this->error, LOG_ERR);
            return -1;
        }
    }

    /**
     * Supprime un thème
     * @param User $user Utilisateur courant
     * @param int $notrigger 1 = pas de trigger
     * @return int <0 si erreur, >0 si OK
     */
    public function delete($user, $notrigger = 0)
    {
        $this->db->begin();
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."pdfbuilder_theme WHERE rowid = ".((int) $this->id);
        $res = $this->db->query($sql);
        if ($res) {
            $this->db->commit();
            dol_syslog('PdfBuilderTheme::delete id='.$this->id, LOG_DEBUG);
            return 1;
        } else {
            $this->db->rollback();
            $this->error = $this->db->lasterror();
            dol_syslog('PdfBuilderTheme::delete '.$this->error, LOG_ERR);
            return -1;
        }
    }

    /**
     * Récupère tous les thèmes (filtrés par type de document optionnel)
     * @param string|null $doc_type Filtre type de document
     * @param int $entity Entité
     * @return array|int Tableau d'objets PdfBuilderTheme ou -1 si erreur
     */
    public function fetchAll($doc_type = null, $entity = 0)
    {
        global $conf;

        $ent = $entity ?: $conf->entity;

        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."pdfbuilder_theme";
        $sql .= " WHERE entity = ".((int) $ent);
        if ($doc_type) {
            $sql .= " AND doc_type = '".$this->db->escape($doc_type)."'";
        }
        $sql .= " ORDER BY is_default DESC, label ASC";

        $res = $this->db->query($sql);
        if (!$res) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        $list = array();
        while ($obj = $this->db->fetch_object($res)) {
            $t = new PdfBuilderTheme($this->db);
            $t->fetch($obj->rowid);
            $list[] = $t;
        }
        return $list;
    }

    /**
     * Récupère le thème actif par défaut pour un type de document
     * @param DoliDB $db Connexion base de données
     * @param string $doc_type Type de document
     * @param int $entity Entité
     * @return PdfBuilderTheme|null Thème actif ou null
     */
    public static function getActive($db, $doc_type, $entity = 0)
    {
        global $conf;

        $ent = $entity ?: $conf->entity;

        // 1. Chercher le thème par défaut
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."pdfbuilder_theme";
        $sql .= " WHERE entity = ".((int) $ent);
        $sql .= " AND doc_type = '".$db->escape($doc_type)."'";
        $sql .= " AND active = 1";
        $sql .= " AND is_default = 1";
        $sql .= " LIMIT 1";

        $res = $db->query($sql);
        if ($res) {
            $obj = $db->fetch_object($res);
            if ($obj) {
                $t = new PdfBuilderTheme($db);
                $t->fetch($obj->rowid);
                return $t;
            }
        }

        // 2. Fallback : premier thème actif
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."pdfbuilder_theme";
        $sql .= " WHERE entity = ".((int) $ent);
        $sql .= " AND doc_type = '".$db->escape($doc_type)."'";
        $sql .= " AND active = 1";
        $sql .= " ORDER BY rowid ASC LIMIT 1";

        $res = $db->query($sql);
        if ($res) {
            $obj = $db->fetch_object($res);
            if ($obj) {
                $t = new PdfBuilderTheme($db);
                $t->fetch($obj->rowid);
                return $t;
            }
        }

        // 3. Aucun thème → retourner un thème avec les valeurs par défaut
        $t = new PdfBuilderTheme($db);
        return $t;
    }

    /**
     * Duplique le thème courant
     * @param User $user Utilisateur courant
     * @return int ID du nouveau thème ou -1 si erreur
     */
    public function duplicate($user)
    {
        $copy = clone $this;
        $copy->id       = 0;
        $copy->label    = $this->label.' (copie)';
        $copy->is_default = 0;
        return $copy->create($user);
    }

    /**
     * Convertit une couleur HEX en tableau RGB pour FPDF
     * @param string $hex Couleur HEX (#rrggbb ou rrggbb)
     * @return array [r, g, b] dans l'intervalle 0-255
     */
    public function hex2rgb($hex)
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
     * Charge les propriétés depuis un objet stdClass (résultat SQL)
     * @param stdClass $obj
     */
    private function _loadFromObj($obj)
    {
        $this->id                = $obj->rowid;
        $this->entity            = $obj->entity;
        $this->label             = $obj->label;
        $this->description       = $obj->description;
        $this->doc_type          = $obj->doc_type;
        $this->font_family       = $obj->font_family;
        $this->font_size         = $obj->font_size;
        $this->font_size_ref     = $obj->font_size_ref ?? 9;
        $this->font_style_ref    = $obj->font_style_ref ?? '';
        $this->font_size_address = $obj->font_size_address ?? 9;
        $this->font_style_address= $obj->font_style_address ?? '';
        $this->font_size_theader = $obj->font_size_theader ?? 8;
        $this->font_style_theader= $obj->font_style_theader ?? 'B';
        $this->font_size_desc    = $obj->font_size_desc ?? 9;
        $this->font_style_desc   = $obj->font_style_desc ?? '';
        $this->font_size_note    = $obj->font_size_note ?? 8;
        $this->font_style_note   = $obj->font_style_note ?? '';
        $this->font_size_footer  = $obj->font_size_footer ?? 7;
        $this->font_style_footer = $obj->font_style_footer ?? '';
        $this->font_style_freetext = $obj->font_style_freetext ?? '';
        $this->font_size_sender    = $obj->font_size_sender ?? 9;
        $this->font_style_sender   = $obj->font_style_sender ?? '';
        $this->font_size_recipient = $obj->font_size_recipient ?? 9;
        $this->font_style_recipient= $obj->font_style_recipient ?? '';
        $this->hide_situation      = (int) ($obj->hide_situation ?? 0);
        $this->show_total_weight   = (int) ($obj->show_total_weight ?? 0);
        $this->address_block_width = (float) ($obj->address_block_width ?? 93.0);
        $this->color_font        = $obj->color_font ?? '#333333';
        $this->color_font_sender = $obj->color_font_sender ?? '';
        $this->color_font_recipient = $obj->color_font_recipient ?? '';
        $this->color_border      = $obj->color_border;
        $this->color_header_bg   = $obj->color_header_bg;
        $this->color_header_txt  = $obj->color_header_txt;
        $this->color_address_bg  = $obj->color_address_bg;
        $this->color_address_bg2 = $obj->color_address_bg2;
        $this->color_qrcode      = $obj->color_qrcode;
        $this->border_style      = $obj->border_style;
        $this->border_radius     = $obj->border_radius;
        $this->bg_opacity        = $obj->bg_opacity;
        $this->bg_image          = $obj->bg_image;
        $this->bg_pdf            = $obj->bg_pdf;
        $this->logo_alt          = $obj->logo_alt;
        $this->show_logo_alt     = $obj->show_logo_alt;
        $this->hide_main_logo    = $obj->hide_main_logo;
        $this->logo_height       = (float) $obj->logo_height;
        $this->header_spacing    = (float) $obj->header_spacing;
        $this->show_customer_code = (int) $obj->show_customer_code;
        $this->reverse_address   = $obj->reverse_address;
        $this->paper_format      = $obj->paper_format;
        $this->margin_top        = $obj->margin_top;
        $this->margin_left       = $obj->margin_left;
        $this->margin_right      = $obj->margin_right;
        $this->margin_bottom     = $obj->margin_bottom;
        $this->col_width_number  = $obj->col_width_number;
        $this->col_width_ref     = $obj->col_width_ref;
        $this->col_width_desc    = $obj->col_width_desc;
        $this->col_width_img     = $obj->col_width_img;
        $this->col_width_vat     = $obj->col_width_vat;
        $this->col_width_puht    = $obj->col_width_puht;
        $this->col_width_qty     = $obj->col_width_qty;
        $this->col_width_unit    = $obj->col_width_unit;
        $this->col_width_discount = $obj->col_width_discount;
        $this->col_width_total   = $obj->col_width_total;
        $this->show_line_numbers = $obj->show_line_numbers;
        $this->show_ref_column   = $obj->show_ref_column;
        $this->show_pictures     = $obj->show_pictures;
        $this->hide_vat_column   = $obj->hide_vat_column;
        $this->hide_puht         = $obj->hide_puht;
        $this->hide_qty          = $obj->hide_qty;
        $this->hide_discount     = $obj->hide_discount;
        $this->show_unit         = $obj->show_unit;
        $this->show_line_ttc     = $obj->show_line_ttc;
        $this->hide_desc_long    = $obj->hide_desc_long;
        $this->show_fold_mark    = $obj->show_fold_mark;
        $this->no_repeat_header  = $obj->no_repeat_header;
        $this->show_vat_breakdown = $obj->show_vat_breakdown;
        $this->hide_rib          = $obj->hide_rib;
        $this->show_bon_accord   = $obj->show_bon_accord;
        $this->show_signature    = $obj->show_signature;
        $this->show_total_weight = $obj->show_total_weight;
        $this->freetext_height   = $obj->freetext_height;
        $this->freetext_fontsize = $obj->freetext_fontsize;
        $this->note_public       = $obj->note_public;
        $this->is_default        = $obj->is_default;
        $this->active            = $obj->active;
        $this->date_creation     = $this->db->jdate($obj->date_creation);
        $this->fk_user_creat     = $obj->fk_user_creat;
        $this->fk_user_modif     = $obj->fk_user_modif;
    }

    /**
     * Retourne le libellé du statut
     * @param int $mode 0=libellé, 1=picto+libellé
     * @return string HTML
     */
    public function getLibStatut($mode = 0)
    {
        return $this->LibStatut($this->active, $mode);
    }

    /**
     * @param int $status Valeur du statut
     * @param int $mode 0=libellé
     * @return string HTML
     */
    public function LibStatut($status, $mode = 0)
    {
        global $langs;
        if ($status == 1) {
            return img_picto($langs->trans('Enabled'), 'statut4').' '.$langs->trans('Enabled');
        } else {
            return img_picto($langs->trans('Disabled'), 'statut5').' '.$langs->trans('Disabled');
        }
    }
}
