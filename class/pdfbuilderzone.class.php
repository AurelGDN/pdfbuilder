<?php
/* Copyright (C) 2024 Antigravity Project
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       pdfbuilder/class/pdfbuilderzone.class.php
 * \ingroup    pdfbuilder
 * \brief      Classe DAO pour les zones d'un layout PDF (Active Record)
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class PdfBuilderZone
 * Active Record pour la table llx_pdfbuilder_zone
 *
 * Types de zones supportés (zone_type) :
 * logo_main, logo_alt, address_sender, address_recipient,
 * field_ref, field_date, field_duedate, field_object,
 * table_lines, table_totals, table_vat_breakdown,
 * rib_block, qrcode, text_static, text_freetext,
 * image_bg, separator, watermark, signature_block,
 * barcode, outstanding
 */
class PdfBuilderZone extends CommonObject
{
    /** @var string Nom de la table */
    public $table_element = 'pdfbuilder_zone';

    /** @var string Identifiant de l'élément */
    public $element = 'pdfbuilder_zone';

    /** @var int FK vers llx_pdfbuilder_layout */
    public $fk_layout;

    /** @var string Type de zone */
    public $zone_type;

    /** @var string Contexte de page : header | body | footer */
    public $page_context;

    /** @var float Position X en mm depuis la marge gauche */
    public $pos_x;

    /** @var float Position Y en mm depuis le haut de page */
    public $pos_y;

    /** @var float Largeur en mm */
    public $width;

    /** @var float Hauteur en mm */
    public $height;

    /** @var int Ordre d'empilement (z-index) */
    public $z_index;

    /** @var string|null Paramètres JSON (police, couleur, alignement, content_key, etc.) */
    public $params;

    /** @var string Libellé affiché dans l'éditeur */
    public $label;

    /** @var int Actif */
    public $active;

    /** @var int Ordre d'affichage */
    public $sort_order;

    /**
     * Types de zones valides
     */
    const VALID_TYPES = array(
        'logo_main', 'logo_alt',
        'address_sender', 'address_recipient',
        'document_type',
        'field_ref', 'field_date', 'field_duedate', 'field_object',
        'field_recipient_vat', 'field_customer_code', 'field_company_ids',
        'table_lines', 'table_totals', 'table_vat_breakdown',
        'rib_block', 'qrcode',
        'text_static', 'text_freetext',
        'image_bg', 'separator', 'watermark',
        'signature_block', 'barcode', 'outstanding',
        'field_web',
        'page_footer',
    );

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
     * Valeurs par défaut
     */
    private function _setDefaults()
    {
        $this->page_context = 'body';
        $this->pos_x        = 0.0;
        $this->pos_y        = 0.0;
        $this->width        = 50.0;
        $this->height       = 10.0;
        $this->z_index      = 0;
        $this->params       = null;
        $this->active       = 1;
        $this->sort_order   = 0;
    }

    /**
     * Crée une zone en base de données
     * @param User $user Utilisateur courant
     * @param int $notrigger 1 = pas de trigger
     * @return int <0 si erreur, >0 si OK (rowid)
     */
    public function create($user, $notrigger = 0)
    {
        if (!in_array($this->zone_type, self::VALID_TYPES)) {
            $this->error = 'Invalid zone_type: '.$this->zone_type;
            dol_syslog('PdfBuilderZone::create '.$this->error, LOG_ERR);
            return -1;
        }

        $allowed_contexts = array('header', 'body', 'footer');
        if (!in_array($this->page_context, $allowed_contexts)) {
            $this->page_context = 'body';
        }

        $this->db->begin();

        $sql = "INSERT INTO ".MAIN_DB_PREFIX."pdfbuilder_zone (";
        $sql .= "fk_layout, zone_type, page_context, pos_x, pos_y,";
        $sql .= "width, height, z_index, params, label, active, sort_order";
        $sql .= ") VALUES (";
        $sql .= ((int) $this->fk_layout).", ";
        $sql .= "'".$this->db->escape($this->zone_type)."', ";
        $sql .= "'".$this->db->escape($this->page_context)."', ";
        $sql .= ((float) ($this->pos_x ?? 0)).", ";
        $sql .= ((float) ($this->pos_y ?? 0)).", ";
        $sql .= ((float) ($this->width ?? 50)).", ";
        $sql .= ((float) ($this->height ?? 10)).", ";
        $sql .= ((int) ($this->z_index ?? 0)).", ";
        $sql .= ($this->params !== null ? "'".$this->db->escape($this->_normalizeParams())."'" : "NULL").", ";
        $sql .= ($this->label ? "'".$this->db->escape($this->label)."'" : "NULL").", ";
        $sql .= ((int) ($this->active ?? 1)).", ";
        $sql .= ((int) ($this->sort_order ?? 0));
        $sql .= ")";

        $res = $this->db->query($sql);
        if ($res) {
            $this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.'pdfbuilder_zone');
            $this->db->commit();
            dol_syslog('PdfBuilderZone::create id='.$this->id, LOG_DEBUG);
            return $this->id;
        } else {
            $this->db->rollback();
            $this->error = $this->db->lasterror();
            dol_syslog('PdfBuilderZone::create '.$this->error, LOG_ERR);
            return -1;
        }
    }

    /**
     * Charge une zone depuis la base
     * @param int $id ID de la zone
     * @return int <0 si erreur, 0 si non trouvé, 1 si OK
     */
    public function fetch($id)
    {
        $sql = "SELECT * FROM ".MAIN_DB_PREFIX."pdfbuilder_zone WHERE rowid = ".((int) $id);
        $res = $this->db->query($sql);
        if (!$res) {
            $this->error = $this->db->lasterror();
            dol_syslog('PdfBuilderZone::fetch '.$this->error, LOG_ERR);
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
     * Retourne toutes les zones d'un layout, triées par sort_order puis pos_y
     * @param DoliDB $db Base de données
     * @param int $layout_id ID du layout
     * @return PdfBuilderZone[]|int Array d'objets ou <0 si erreur
     */
    public static function fetchByLayout($db, $layout_id)
    {
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."pdfbuilder_zone";
        $sql .= " WHERE fk_layout = ".((int) $layout_id);
        $sql .= " AND active = 1";
        $sql .= " ORDER BY sort_order ASC, pos_y ASC, pos_x ASC";

        $res = $db->query($sql);
        if (!$res) {
            dol_syslog('PdfBuilderZone::fetchByLayout '.$db->lasterror(), LOG_ERR);
            return -1;
        }

        $list = array();
        while ($obj = $db->fetch_object($res)) {
            $zone = new self($db);
            $zone->fetch($obj->rowid);
            $list[] = $zone;
        }
        return $list;
    }

    /**
     * Met à jour une zone
     * @param User $user Utilisateur courant
     * @param int $notrigger 1 = pas de trigger
     * @return int <0 si erreur, >0 si OK
     */
    public function update($user, $notrigger = 0)
    {
        if (!in_array($this->zone_type, self::VALID_TYPES)) {
            $this->error = 'Invalid zone_type: '.$this->zone_type;
            return -1;
        }

        $allowed_contexts = array('header', 'body', 'footer');
        if (!in_array($this->page_context, $allowed_contexts)) {
            $this->page_context = 'body';
        }

        $this->db->begin();

        $sql = "UPDATE ".MAIN_DB_PREFIX."pdfbuilder_zone SET";
        $sql .= " zone_type = '".$this->db->escape($this->zone_type)."'";
        $sql .= ", page_context = '".$this->db->escape($this->page_context)."'";
        $sql .= ", pos_x = ".((float) ($this->pos_x ?? 0));
        $sql .= ", pos_y = ".((float) ($this->pos_y ?? 0));
        $sql .= ", width = ".((float) ($this->width ?? 50));
        $sql .= ", height = ".((float) ($this->height ?? 10));
        $sql .= ", z_index = ".((int) ($this->z_index ?? 0));
        $sql .= ", params = ".($this->params !== null ? "'".$this->db->escape($this->_normalizeParams())."'" : 'NULL');
        $sql .= ", label = ".($this->label ? "'".$this->db->escape($this->label)."'" : 'NULL');
        $sql .= ", active = ".((int) ($this->active ?? 1));
        $sql .= ", sort_order = ".((int) ($this->sort_order ?? 0));
        $sql .= " WHERE rowid = ".((int) $this->id);

        $res = $this->db->query($sql);
        if ($res) {
            $this->db->commit();
            dol_syslog('PdfBuilderZone::update id='.$this->id, LOG_DEBUG);
            return 1;
        } else {
            $this->db->rollback();
            $this->error = $this->db->lasterror();
            dol_syslog('PdfBuilderZone::update '.$this->error, LOG_ERR);
            return -1;
        }
    }

    /**
     * Supprime une zone
     * @param User $user Utilisateur courant
     * @param int $notrigger 1 = pas de trigger
     * @return int <0 si erreur, >0 si OK
     */
    public function delete($user, $notrigger = 0)
    {
        $this->db->begin();

        $sql = "DELETE FROM ".MAIN_DB_PREFIX."pdfbuilder_zone WHERE rowid = ".((int) $this->id);
        $res = $this->db->query($sql);
        if ($res) {
            $this->db->commit();
            dol_syslog('PdfBuilderZone::delete id='.$this->id, LOG_DEBUG);
            return 1;
        } else {
            $this->db->rollback();
            $this->error = $this->db->lasterror();
            dol_syslog('PdfBuilderZone::delete '.$this->error, LOG_ERR);
            return -1;
        }
    }

    /**
     * Retourne les paramètres JSON décodés en tableau associatif
     * @return array
     */
    public function getParamsAsArray()
    {
        if (empty($this->params)) {
            return array();
        }
        $decoded = json_decode($this->params, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Définit les paramètres depuis un tableau (encode en JSON)
     * @param array $params Tableau de paramètres
     */
    public function setParams(array $params)
    {
        $this->params = json_encode($params, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Fusionne des paramètres dans les params existants
     * @param array $params Paramètres à fusionner
     */
    public function mergeParams(array $params)
    {
        $existing = $this->getParamsAsArray();
        $this->setParams(array_merge($existing, $params));
    }

    /**
     * Retourne une valeur de paramètre spécifique
     * @param string $key Clé du paramètre
     * @param mixed $default Valeur par défaut
     * @return mixed
     */
    public function getParam($key, $default = null)
    {
        $params = $this->getParamsAsArray();
        return isset($params[$key]) ? $params[$key] : $default;
    }

    /**
     * S'assure que $this->params est un JSON valide ou null
     * @return string|null JSON normalisé
     */
    private function _normalizeParams()
    {
        if (empty($this->params)) {
            return null;
        }
        // Déjà une string JSON valide ?
        if (is_string($this->params)) {
            $decoded = json_decode($this->params, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->params;
            }
        }
        // Tableau → encode
        if (is_array($this->params)) {
            return json_encode($this->params, JSON_UNESCAPED_UNICODE);
        }
        return null;
    }

    /**
     * Mappe un objet DB vers les propriétés de l'instance
     * @param stdClass $obj Objet retourné par fetch_object()
     */
    private function _loadFromObj($obj)
    {
        $this->id           = $obj->rowid;
        $this->fk_layout    = $obj->fk_layout;
        $this->zone_type    = $obj->zone_type;
        $this->page_context = $obj->page_context;
        $this->pos_x        = $obj->pos_x;
        $this->pos_y        = $obj->pos_y;
        $this->width        = $obj->width;
        $this->height       = $obj->height;
        $this->z_index      = $obj->z_index;
        $this->params       = $obj->params;
        $this->label        = $obj->label;
        $this->active       = $obj->active;
        $this->sort_order   = $obj->sort_order;
    }
}
