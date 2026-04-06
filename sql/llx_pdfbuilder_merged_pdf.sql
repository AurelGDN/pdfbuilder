-- Copyright (C) 2024 PDF-builder Module - Antigravity Project
-- GPL v2 or later

CREATE TABLE IF NOT EXISTS llx_pdfbuilder_merged_pdf (
    rowid          INTEGER  NOT NULL AUTO_INCREMENT,
    entity         INTEGER  NOT NULL DEFAULT 1,
    doc_type       VARCHAR(50) NOT NULL,
    fk_doc         INTEGER NOT NULL,
    filepath       VARCHAR(255) NOT NULL,
    position       TINYINT UNSIGNED DEFAULT 1 COMMENT '0=before doc, 1=after doc',
    label          VARCHAR(100),
    date_creation  DATETIME,
    fk_user_creat  INTEGER,
    PRIMARY KEY (rowid)
) ENGINE=InnoDB;
