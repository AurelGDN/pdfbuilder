<?php
/* Copyright (C) 2024 Antigravity Project
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       pdfbuilder/class/pdfbuilderlayout.class.php
 * \ingroup    pdfbuilder
 * \brief      Classe DAO pour les layouts PDF (Active Record)
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class PdfBuilderLayout
 * Active Record pour la table llx_pdfbuilder_layout
 */
class PdfBuilderLayout extends CommonObject
{
    /** @var string Nom de la table */
    public $table_element = 'pdfbuilder_layout';

    /** @var string Identifiant de l'élément */
    public $element = 'pdfbuilder_layout';

    /** @var string Icône */
    public $picto = 'pdfbuilder@pdfbuilder';

    /** @var int Entité (multi-sociétés) */
    public $entity;

    /** @var string Libellé du layout */
    public $label;

    /** @var string Description */
    public $description;

    /** @var string Type de document : invoice, propal, order, etc. */
    public $doc_type;

    /** @var string Format papier : A4, A3, Letter */
    public $paper_format;

    /** @var float Marge supérieure (mm) */
    public $margin_top;

    /** @var float Marge gauche (mm) */
    public $margin_left;

    /** @var float Marge droite (mm) */
    public $margin_right;

    /** @var float Marge inférieure (mm) */
    public $margin_bottom;

    /** @var int Layout par défaut pour ce type de document */
    public $is_default;

    /** @var int Actif */
    public $active;

    /** @var int Lien optionnel vers un thème pdfbuilder_theme */
    public $fk_theme;

    /** @var string|null Options du document sérialisées en JSON */
    public $params = null;

    /** @var string Date de création */
    public $date_creation;

    /** @var int Utilisateur créateur */
    public $fk_user_creat;

    /** @var int Utilisateur modificateur */
    public $fk_user_modif;

    /** @var array Liste des zones attachées (chargées par fetchZones()) */
    public $zones = array();

    /**
     * Retourne les params du layout sous forme de tableau PHP
     * @return array
     */
    public function getParamsAsArray()
    {
        if (is_array($this->params)) {
            return $this->params;
        }
        if (is_string($this->params) && $this->params !== '') {
            $decoded = json_decode($this->params, true);
            return is_array($decoded) ? $decoded : array();
        }
        return array();
    }

    /**
     * Définit les params du layout depuis un tableau PHP
     * @param array $p
     */
    public function setParams(array $p)
    {
        $this->params = json_encode($p, JSON_UNESCAPED_UNICODE);
    }

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
        $this->entity       = 1;
        $this->label        = '';
        $this->doc_type     = 'invoice';
        $this->paper_format = 'A4';
        $this->margin_top   = 10.0;
        $this->margin_left  = 11.0;
        $this->margin_right = 10.0;
        $this->margin_bottom= 10.0;
        $this->is_default   = 0;
        $this->active       = 1;
        $this->fk_theme     = null;
    }

    /**
     * Crée un layout en base de données
     * @param User $user Utilisateur courant
     * @param int $notrigger 1 = pas de trigger
     * @return int <0 si erreur, >0 si OK (rowid)
     */
    public function create($user, $notrigger = 0)
    {
        global $conf;

        $this->db->begin();

        $now = dol_now();

        if ($this->is_default) {
            $sql = "UPDATE ".MAIN_DB_PREFIX."pdfbuilder_layout";
            $sql .= " SET is_default = 0";
            $sql .= " WHERE entity = ".((int) ($this->entity ?: $conf->entity));
            $sql .= " AND doc_type = '".$this->db->escape($this->doc_type)."'";
            $this->db->query($sql);
        }

        $paramsJson = is_array($this->params) ? json_encode($this->params, JSON_UNESCAPED_UNICODE) : (is_string($this->params) ? $this->params : null);

        $sql = "INSERT INTO ".MAIN_DB_PREFIX."pdfbuilder_layout (";
        $sql .= "entity, label, description, doc_type, paper_format,";
        $sql .= "margin_top, margin_left, margin_right, margin_bottom,";
        $sql .= "params, is_default, active, fk_theme, date_creation, fk_user_creat";
        $sql .= ") VALUES (";
        $sql .= ((int) ($this->entity ?: $conf->entity)).", ";
        $sql .= "'".$this->db->escape($this->label)."', ";
        $sql .= ($this->description ? "'".$this->db->escape($this->description)."'" : "NULL").", ";
        $sql .= "'".$this->db->escape($this->doc_type)."', ";
        $sql .= "'".$this->db->escape($this->paper_format ?: 'A4')."', ";
        $sql .= ((float) ($this->margin_top    ?? 10.0)).", ";
        $sql .= ((float) ($this->margin_left   ?? 11.0)).", ";
        $sql .= ((float) ($this->margin_right  ?? 10.0)).", ";
        $sql .= ((float) ($this->margin_bottom ?? 10.0)).", ";
        $sql .= ($paramsJson ? "'".$this->db->escape($paramsJson)."'" : "NULL").", ";
        $sql .= ((int) ($this->is_default ?? 0)).", ";
        $sql .= ((int) ($this->active ?? 1)).", ";
        $sql .= ($this->fk_theme ? ((int) $this->fk_theme) : "NULL").", ";
        $sql .= "'".$this->db->idate($now)."', ";
        $sql .= ((int) $user->id);
        $sql .= ")";

        $res = $this->db->query($sql);
        if ($res) {
            $this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.'pdfbuilder_layout');
            $this->db->commit();
            dol_syslog('PdfBuilderLayout::create id='.$this->id, LOG_DEBUG);
            return $this->id;
        } else {
            $this->db->rollback();
            $this->error = $this->db->lasterror();
            dol_syslog('PdfBuilderLayout::create '.$this->error, LOG_ERR);
            return -1;
        }
    }

    /**
     * Charge un layout depuis la base
     * @param int $id ID du layout
     * @return int <0 si erreur, 0 si non trouvé, 1 si OK
     */
    public function fetch($id)
    {
        $sql = "SELECT * FROM ".MAIN_DB_PREFIX."pdfbuilder_layout WHERE rowid = ".((int) $id);
        $res = $this->db->query($sql);
        if (!$res) {
            $this->error = $this->db->lasterror();
            dol_syslog('PdfBuilderLayout::fetch '.$this->error, LOG_ERR);
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
     * Charge le layout par défaut pour un type de document
     * @param string $doc_type Type de document
     * @param int $entity Entité
     * @return int <0 si erreur, 0 si non trouvé, 1 si OK
     */
    public function fetchDefault($doc_type, $entity = 0)
    {
        global $conf;
        $entity = $entity ?: $conf->entity;

        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."pdfbuilder_layout";
        $sql .= " WHERE entity = ".((int) $entity);
        $sql .= " AND doc_type = '".$this->db->escape($doc_type)."'";
        $sql .= " AND is_default = 1";
        $sql .= " AND active = 1";
        $sql .= " LIMIT 1";

        $res = $this->db->query($sql);
        if (!$res) {
            $this->error = $this->db->lasterror();
            return -1;
        }
        $obj = $this->db->fetch_object($res);
        if (!$obj) {
            return 0;
        }
        return $this->fetch($obj->rowid);
    }

    /**
     * Retourne tous les layouts d'un type de document
     * @param string $doc_type Type de document ('' = tous)
     * @param int $entity Entité
     * @return PdfBuilderLayout[]|int Array d'objets ou <0 si erreur
     */
    public function fetchAll($doc_type = '', $entity = 0)
    {
        global $conf;
        $entity = $entity ?: $conf->entity;

        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."pdfbuilder_layout";
        $sql .= " WHERE entity = ".((int) $entity);
        if ($doc_type) {
            $sql .= " AND doc_type = '".$this->db->escape($doc_type)."'";
        }
        $sql .= " ORDER BY label ASC";

        $res = $this->db->query($sql);
        if (!$res) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        $list = array();
        while ($obj = $this->db->fetch_object($res)) {
            $layout = new self($this->db);
            $layout->fetch($obj->rowid);
            $list[] = $layout;
        }
        return $list;
    }

    /**
     * Met à jour un layout
     * @param User $user Utilisateur courant
     * @param int $notrigger 1 = pas de trigger
     * @return int <0 si erreur, >0 si OK
     */
    public function update($user, $notrigger = 0)
    {
        global $conf;

        $this->db->begin();

        if ($this->is_default) {
            $sql = "UPDATE ".MAIN_DB_PREFIX."pdfbuilder_layout";
            $sql .= " SET is_default = 0";
            $sql .= " WHERE entity = ".((int) $this->entity);
            $sql .= " AND doc_type = '".$this->db->escape($this->doc_type)."'";
            $sql .= " AND rowid != ".((int) $this->id);
            $this->db->query($sql);
        }

        $paramsJson = is_array($this->params) ? json_encode($this->params, JSON_UNESCAPED_UNICODE) : (is_string($this->params) ? $this->params : null);

        $sql = "UPDATE ".MAIN_DB_PREFIX."pdfbuilder_layout SET";
        $sql .= " label = '".$this->db->escape($this->label)."'";
        $sql .= ", description = ".($this->description ? "'".$this->db->escape($this->description)."'" : 'NULL');
        $sql .= ", doc_type = '".$this->db->escape($this->doc_type)."'";
        $sql .= ", paper_format = '".$this->db->escape($this->paper_format ?: 'A4')."'";
        $sql .= ", margin_top = ".((float) ($this->margin_top ?? 10.0));
        $sql .= ", margin_left = ".((float) ($this->margin_left ?? 11.0));
        $sql .= ", margin_right = ".((float) ($this->margin_right ?? 10.0));
        $sql .= ", margin_bottom = ".((float) ($this->margin_bottom ?? 10.0));
        $sql .= ", params = ".($paramsJson ? "'".$this->db->escape($paramsJson)."'" : 'NULL');
        $sql .= ", is_default = ".((int) ($this->is_default ?? 0));
        $sql .= ", active = ".((int) ($this->active ?? 1));
        $sql .= ", fk_theme = ".($this->fk_theme ? ((int) $this->fk_theme) : 'NULL');
        $sql .= ", fk_user_modif = ".((int) $user->id);
        $sql .= " WHERE rowid = ".((int) $this->id);

        $res = $this->db->query($sql);
        if ($res) {
            $this->db->commit();
            dol_syslog('PdfBuilderLayout::update id='.$this->id, LOG_DEBUG);
            return 1;
        } else {
            $this->db->rollback();
            $this->error = $this->db->lasterror();
            dol_syslog('PdfBuilderLayout::update '.$this->error, LOG_ERR);
            return -1;
        }
    }

    /**
     * Supprime un layout et toutes ses zones
     * @param User $user Utilisateur courant
     * @param int $notrigger 1 = pas de trigger
     * @return int <0 si erreur, >0 si OK
     */
    public function delete($user, $notrigger = 0)
    {
        $this->db->begin();

        // Supprimer d'abord toutes les zones associées
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."pdfbuilder_zone WHERE fk_layout = ".((int) $this->id);
        $res = $this->db->query($sql);
        if (!$res) {
            $this->db->rollback();
            $this->error = $this->db->lasterror();
            dol_syslog('PdfBuilderLayout::delete zones '.$this->error, LOG_ERR);
            return -1;
        }

        $sql = "DELETE FROM ".MAIN_DB_PREFIX."pdfbuilder_layout WHERE rowid = ".((int) $this->id);
        $res = $this->db->query($sql);
        if ($res) {
            $this->db->commit();
            dol_syslog('PdfBuilderLayout::delete id='.$this->id, LOG_DEBUG);
            return 1;
        } else {
            $this->db->rollback();
            $this->error = $this->db->lasterror();
            dol_syslog('PdfBuilderLayout::delete '.$this->error, LOG_ERR);
            return -1;
        }
    }

    /**
     * Duplique un layout et toutes ses zones
     * @param User $user Utilisateur courant
     * @param string $newLabel Libellé du nouveau layout
     * @return int <0 si erreur, ID du nouveau layout si OK
     */
    public function duplicate($user, $newLabel = '')
    {
        dol_include_once('/pdfbuilder/class/pdfbuilderzone.class.php');

        $newLayout = new self($this->db);
        $newLayout->entity       = $this->entity;
        $newLayout->label        = $newLabel ?: ($this->label.' (copie)');
        $newLayout->description  = $this->description;
        $newLayout->doc_type     = $this->doc_type;
        $newLayout->paper_format = $this->paper_format;
        $newLayout->margin_top   = $this->margin_top;
        $newLayout->margin_left  = $this->margin_left;
        $newLayout->margin_right = $this->margin_right;
        $newLayout->margin_bottom= $this->margin_bottom;
        $newLayout->is_default   = 0;
        $newLayout->active       = $this->active;
        $newLayout->fk_theme     = $this->fk_theme;
        $newLayout->params       = $this->params;

        $newId = $newLayout->create($user);
        if ($newId < 0) {
            $this->error = $newLayout->error;
            return -1;
        }

        // Dupliquer les zones
        $zones = PdfBuilderZone::fetchByLayout($this->db, $this->id);
        if (is_array($zones)) {
            foreach ($zones as $zone) {
                $newZone = new PdfBuilderZone($this->db);
                $newZone->fk_layout    = $newId;
                $newZone->zone_type    = $zone->zone_type;
                $newZone->page_context = $zone->page_context;
                $newZone->pos_x        = $zone->pos_x;
                $newZone->pos_y        = $zone->pos_y;
                $newZone->width        = $zone->width;
                $newZone->height       = $zone->height;
                $newZone->z_index      = $zone->z_index;
                $newZone->params       = $zone->params;
                $newZone->label        = $zone->label;
                $newZone->active       = $zone->active;
                $newZone->sort_order   = $zone->sort_order;
                $newZone->create($user);
            }
        }

        return $newId;
    }

    /**
     * Définit ce layout comme layout par défaut pour son type de document
     * @param User $user Utilisateur courant
     * @return int <0 si erreur, 1 si OK
     */
    public function setDefault($user)
    {
        $this->db->begin();

        $sql = "UPDATE ".MAIN_DB_PREFIX."pdfbuilder_layout";
        $sql .= " SET is_default = 0";
        $sql .= " WHERE entity = ".((int) $this->entity);
        $sql .= " AND doc_type = '".$this->db->escape($this->doc_type)."'";
        $res = $this->db->query($sql);

        if ($res) {
            $sql = "UPDATE ".MAIN_DB_PREFIX."pdfbuilder_layout";
            $sql .= " SET is_default = 1";
            $sql .= " WHERE rowid = ".((int) $this->id);
            $res = $this->db->query($sql);
        }

        if ($res) {
            $this->is_default = 1;
            $this->db->commit();
            return 1;
        } else {
            $this->db->rollback();
            $this->error = $this->db->lasterror();
            return -1;
        }
    }

    /**
     * Charge les zones attachées à ce layout dans $this->zones
     * @return int Nombre de zones chargées ou <0 si erreur
     */
    public function fetchZones()
    {
        dol_include_once('/pdfbuilder/class/pdfbuilderzone.class.php');

        $zones = PdfBuilderZone::fetchByLayout($this->db, $this->id);
        if (!is_array($zones)) {
            return -1;
        }
        $this->zones = $zones;
        return count($this->zones);
    }

    /**
     * Mappe un objet DB vers les propriétés de l'instance
     * @param stdClass $obj Objet retourné par fetch_object()
     */
    private function _loadFromObj($obj)
    {
        $this->id            = $obj->rowid;
        $this->entity        = $obj->entity;
        $this->label         = $obj->label;
        $this->description   = $obj->description;
        $this->doc_type      = $obj->doc_type;
        $this->paper_format  = $obj->paper_format;
        $this->margin_top    = $obj->margin_top;
        $this->margin_left   = $obj->margin_left;
        $this->margin_right  = $obj->margin_right;
        $this->margin_bottom = $obj->margin_bottom;
        $this->params        = isset($obj->params) ? $obj->params : null;
        $this->is_default    = $obj->is_default;
        $this->active        = $obj->active;
        $this->fk_theme      = $obj->fk_theme;
        $this->date_creation = $this->db->jdate($obj->date_creation);
        $this->fk_user_creat = $obj->fk_user_creat;
        $this->fk_user_modif = $obj->fk_user_modif;
    }
}
