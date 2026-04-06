-- Copyright (C) 2024 Antigravity Project
-- GPL v2 or later

CREATE TABLE IF NOT EXISTS llx_pdfbuilder_zone (
    rowid        INTEGER      NOT NULL AUTO_INCREMENT,
    fk_layout    INTEGER      NOT NULL,
    zone_type    VARCHAR(50)  NOT NULL,
    page_context VARCHAR(10)  DEFAULT 'body',
    pos_x        DECIMAL(5,2) DEFAULT 0,
    pos_y        DECIMAL(5,2) DEFAULT 0,
    width        DECIMAL(5,2) DEFAULT 50,
    height       DECIMAL(5,2) DEFAULT 10,
    z_index      TINYINT      DEFAULT 0,
    params       TEXT,
    label        VARCHAR(100),
    active       TINYINT      DEFAULT 1,
    sort_order   SMALLINT     DEFAULT 0,
    PRIMARY KEY (rowid)
) ENGINE=InnoDB;
