-- Copyright (C) 2024 Antigravity Project
-- GPL v2 or later
-- Migration : ajout colonne params (JSON) sur llx_pdfbuilder_layout

ALTER TABLE llx_pdfbuilder_layout ADD COLUMN IF NOT EXISTS params TEXT AFTER margin_bottom;
