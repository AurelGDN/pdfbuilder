-- Copyright (C) 2024 Antigravity Project
-- GPL v2 or later

ALTER TABLE llx_pdfbuilder_layout ADD INDEX idx_pdfbuilder_layout_entity (entity);
ALTER TABLE llx_pdfbuilder_layout ADD INDEX idx_pdfbuilder_layout_doc_type (doc_type);
