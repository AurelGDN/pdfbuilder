-- Copyright (C) 2024 Antigravity Project
-- GPL v2 or later

CREATE TABLE IF NOT EXISTS llx_pdfbuilder_layout (
    rowid         INTEGER      NOT NULL AUTO_INCREMENT,
    entity        INTEGER      NOT NULL DEFAULT 1,
    label         VARCHAR(100) NOT NULL,
    description   TEXT,
    doc_type      VARCHAR(50)  NOT NULL DEFAULT 'invoice',
    paper_format  VARCHAR(20)  DEFAULT 'A4',
    margin_top    DECIMAL(4,1) DEFAULT 10.0,
    margin_left   DECIMAL(4,1) DEFAULT 11.0,
    margin_right  DECIMAL(4,1) DEFAULT 10.0,
    margin_bottom DECIMAL(4,1) DEFAULT 10.0,
    params        TEXT,
    is_default    TINYINT      DEFAULT 0,
    active        TINYINT      DEFAULT 1,
    fk_theme      INTEGER      DEFAULT NULL,
    date_creation DATETIME,
    tms           TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat INTEGER,
    fk_user_modif INTEGER,
    PRIMARY KEY (rowid)
) ENGINE=InnoDB;
