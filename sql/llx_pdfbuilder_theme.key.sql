-- Indexes for llx_pdfbuilder_theme
ALTER TABLE llx_pdfbuilder_theme ADD INDEX idx_pdfbuilder_theme_entity (entity);
ALTER TABLE llx_pdfbuilder_theme ADD INDEX idx_pdfbuilder_theme_doc_type (doc_type);
ALTER TABLE llx_pdfbuilder_theme ADD INDEX idx_pdfbuilder_theme_active (active);
ALTER TABLE llx_pdfbuilder_theme ADD INDEX idx_pdfbuilder_theme_is_default (is_default);
