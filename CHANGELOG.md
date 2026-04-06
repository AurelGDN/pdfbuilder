# Changelog — PDFBuilder

All notable changes to this project will be documented in this file.

## [1.0.0-beta1] - 2026-04-06

### Added
- **Graphical Layout Editor**: A full-featured drag-and-drop designer for PDF layouts.
- **Multi-Document Support**: Custom layouts for Invoices, Proposals, Orders, Supplier Invoices, Supplier Orders, and Interventions.
- **Zone-Based Architecture**: 21 types of positionable zones including:
  - Logos (Main/Alt)
  - Addresses (Sender/Recipient)
  - Document Fields (Ref, Date, Due Date, Object)
  - Tables (Product lines, Totals, VAT breakdown)
  - Banking (RIB/IBAN blocks, LCR)
  - Interactive elements (QR codes, Barcodes)
  - Static elements (Text, Separators, Watermarks, Signatures)
- **Template System**: Pre-configured templates (Classic, Modern, Minimal, Business).
- **Import/Export**: Save and load layouts as JSON files.
- **Live Preview**: Generate real-time PDF previews within the designer.
- **French Localization**: Complete translation and support for French fiscal requirements (Auto-liquidation, etc.).

### Technical Details
- **Core**: Built on Dolibarr 18.0+ standards using Active Record DAO.
- **Rendering**: Custom TCPDF renderer with absolute positioning in mm.
- **Database**: New tables `llx_pdfbuilder_layout` and `llx_pdfbuilder_zone`.
- **UI**: Vanilla ES6+ JavaScript designer (no external frameworks).
