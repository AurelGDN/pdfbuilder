-- Indexes for llx_pdfbuilder_merged_pdf
ALTER TABLE llx_pdfbuilder_merged_pdf ADD INDEX idx_pdfbuilder_merged_entity (entity);
ALTER TABLE llx_pdfbuilder_merged_pdf ADD INDEX idx_pdfbuilder_merged_doc (doc_type, fk_doc);
