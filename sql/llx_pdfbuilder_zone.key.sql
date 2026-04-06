-- Copyright (C) 2024 Antigravity Project
-- GPL v2 or later

ALTER TABLE llx_pdfbuilder_zone ADD INDEX idx_pdfbuilder_zone_layout (fk_layout);
ALTER TABLE llx_pdfbuilder_zone ADD INDEX idx_pdfbuilder_zone_type (zone_type);
