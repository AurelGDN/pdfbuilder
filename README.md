# PDF-Builder for Dolibarr 📄

[**Français ci-dessous** ⬇️](#pdf-builder-pour-dolibarr-)

---

## English

### Modern PDF generation for Dolibarr — Fully Graphical, Zero Code

**PDF-Builder** is a powerful, modern module for Dolibarr ERP/CRM that brings true graphical PDF design to your invoices, quotations, purchase orders, interventions, and more — without touching a line of code.

Built on Dolibarr's modern architecture (Active Record DAO, global variable patterns, theme management), it replaces monolithic PDF themes with an intuitive zone-based layout system. Drag, drop, resize, and configure—your PDF documents transform instantly.

#### ✨ Key Features

##### 📐 **Graphical Layout Editor**
- Drag-and-drop zone designer on an A4 canvas
- Real-time visual positioning (pixel-perfect mm precision)
- Undo/redo history with grid snapping
- No coding required—purely visual

##### 🎨 **Rich Design Customization**
- **Colors & Fonts**: HTML5 color pickers, multiple font families (DejaVuSans, Courier, Symbol, etc.)
- **Flexible Layout Zones**: Logo, addresses, document fields, tables, RIB (French banking info), QR codes, barcodes, watermarks
- **Backgrounds & Images**: Custom background images with opacity control
- **PDF Fusion**: Layer custom PDFs (CGV, attachments) automatically

##### 📋 **Dynamic Content Mapping**
- **Smart Field Binding**: Map Dolibarr data fields (invoice #, date, amounts, tax breakdown) to any position
- **Multiple Document Types**: Support for invoices, quotations, purchase orders, interventions, delivery notes
- **Conditional Content**: Show/hide zones based on document state

##### 🇫🇷 **French Localization**
- **Tax Features**: Auto reverse-charge VAT calculation
- **Banking**: LCR payment term display, RIB (bank coordinates) blocks, outstanding amount tracking
- **Compliance**: Legal mentions, signature blocks, document reference numbers

##### 📦 **Template System**
- Pre-built templates (Classic, Modern, Minimal, Corporate)
- Import/export layouts as JSON
- Duplicate and customize existing designs

##### 📊 **Multi-Theme Support**
- Create dozens of themes per document type
- Apply different layouts by company, entity, or business logic
- Default theme fallback

#### 🚀 Quick Start

1. **Download & Install**
   ```bash
   git clone https://github.com/AurelGDN/pdfbuilder.git
   # Place in your Dolibarr installation:
   cp -r pdfbuilder /path/to/dolibarr/htdocs/custom/
   ```

2. **Enable the Module**
   - Log in as administrator
   - Navigate to **Home > Setup > Modules**
   - Search for **PDF-Builder** and enable it

3. **Create Your First Layout**
   - Go to **PDF-Builder > Designer**
   - Click **New Layout**
   - Drag zones from the palette onto the A4 canvas
   - Configure each zone's properties (color, font, content mapping)
   - Click **Save**

4. **Assign to Document Type**
   - In **PDF-Builder > Layouts**, select your layout
   - Set it as default for your document type (invoices, quotes, etc.)

5. **Generate PDFs**
   - Create or open an invoice/quote in Dolibarr
   - Click "Generate PDF" — it now uses your custom layout!

#### 📖 Documentation

- **[Module Architecture](./docs/ARCHITECTURE.md)** — Database schema, class design
- **[Zone Types Reference](./docs/ZONES.md)** — Complete list of zone types and parameters
- **[Customization Guide](./docs/CUSTOMIZATION.md)** — Advanced themes and styling
- **[API Reference](./docs/API.md)** — Programmatic layout creation

#### 🔧 Technical Details

- **Framework**: Dolibarr 18.0+
- **Technologies**: PHP (Active Record), TCPDF (native), JavaScript (vanilla ES6+)
- **Database**: 2 new tables (`llx_pdfbuilder_layout`, `llx_pdfbuilder_zone`)
- **Dependencies**: TCPDF (bundled), FPDI (optional, for PDF fusion)
- **Security**: CSRF protection, input sanitization, permission checks

#### 📋 System Requirements

- **Dolibarr** v18.0 or higher
- **PHP** 7.4+
- **MySQL** 5.7+ or MariaDB 10.2+
- **Modern browser** (Chrome, Firefox, Safari, Edge — ES6 support required)

#### 🐛 Known Limitations

- Border radius not supported by TCPDF (use rectangular zones)
- Dashed/dotted borders render as solid lines in some PDF readers
- Complex nested layouts may impact performance on low-spec servers

#### 📄 License

This module is distributed under the **GNU General Public License v2.0** or later.
See [LICENSE](./LICENSE) for details.

#### 🤝 Contributing

We welcome pull requests, bug reports, and feature suggestions!

- **Report a bug**: [GitHub Issues](https://github.com/AurelGDN/pdfbuilder/issues)
- **Request a feature**: [Discussions](https://github.com/AurelGDN/pdfbuilder/discussions)
- **Submit code**: Fork, create a feature branch, and open a pull request

#### 📧 Support

- **Email**: contact@antigravityproject.com
- **Documentation**: https://pdfbuilder.antigravityproject.com
- **Community Forum**: [Dolibarr Forums](https://www.dolibarr.org/forum.php)

---

## Version

- **Current Release**: v1.0 Beta 1
- **Last Updated**: April 2026

---

---

# PDF-Builder pour Dolibarr 📄

### Génération PDF moderne pour Dolibarr — Entièrement Graphique, Zéro Code

**PDF-Builder** est un module puissant et moderne pour Dolibarr ERP/CRM qui apporte une véritable conception graphique de PDF à vos factures, devis, bons de commande, interventions et bien plus — sans toucher une seule ligne de code.

Conçu sur l'architecture moderne de Dolibarr (DAO Active Record, gestion des variables globales, système de thèmes), il remplace les thèmes PDF monolithiques par un système intuitif de zones positionnables. Glissez, déposez, redimensionnez et configurez — vos documents PDF se transforment instantanément.

#### ✨ Fonctionnalités Principales

##### 📐 **Éditeur de Mise en Page Graphique**
- Concepteur de zones par glisser-déposer sur un canevas A4
- Positionnement visuel en temps réel (précision au millimètre)
- Historique annuler/refaire avec magnétisme grille
- Zéro code — interface entièrement visuelle

##### 🎨 **Personnalisation Riche**
- **Couleurs & Polices** : Sélecteurs HTML5 natifs, multiples familles (DejaVuSans, Courier, Symbol, etc.)
- **Zones Flexibles** : Logo, adresses, champs documents, tableaux, RIB, codes QR, codes-barres, filigranes
- **Fonds & Images** : Images d'arrière-plan personnalisées avec gestion d'opacité
- **Fusion PDF** : Superposez automatiquement des PDF custom (CGV, annexes)

##### 📋 **Liaison Dynamique des Contenus**
- **Binding Intelligent** : Liez les données Dolibarr (n° facture, date, montants, détail TVA) à n'importe quelle position
- **Multiples Types de Documents** : Factures, devis, commandes, interventions, bons de livraison
- **Contenus Conditionnels** : Afficher/masquer les zones selon l'état du document

##### 🇫🇷 **Localisation Française Complète**
- **Gestion Fiscale** : Auto-liquidation de la TVA, ventilation TVA détaillée
- **Bancaire** : Affichage LCR, blocs RIB (coordonnées bancaires), suivi encours client
- **Conformité** : Mentions légales, blocs signature, numérotation documents

##### 📦 **Système de Templates**
- Templates pré-construits (Classique, Moderne, Minimaliste, Corporatif)
- Import/export de mises en page en JSON
- Duplication et personnalisation simple

##### 📊 **Support Multi-Thèmes**
- Créez des dizaines de thèmes par type de document
- Appliquez différentes mises en page par société, entité ou logique métier
- Thème par défaut avec fallback

#### 🚀 Démarrage Rapide

1. **Télécharger & Installer**
   ```bash
   git clone https://github.com/AurelGDN/pdfbuilder.git
   # Placer dans votre installation Dolibarr :
   cp -r pdfbuilder /chemin/vers/dolibarr/htdocs/custom/
   ```

2. **Activer le Module**
   - Connectez-vous en tant qu'administrateur
   - Allez dans **Accueil > Configuration > Modules**
   - Recherchez **PDF-Builder** et activez-le

3. **Créer Votre Première Mise en Page**
   - Rendez-vous dans **PDF-Builder > Concepteur**
   - Cliquez sur **Nouvelle mise en page**
   - Glissez-déposez les zones de la palette sur le canevas A4
   - Configurez les propriétés de chaque zone (couleur, police, contenu)
   - Cliquez sur **Enregistrer**

4. **Affecter à un Type de Document**
   - Dans **PDF-Builder > Mises en Page**, sélectionnez votre mise en page
   - Définissez-la par défaut pour votre type de document (factures, devis, etc.)

5. **Générer les PDF**
   - Créez ou ouvrez une facture/devis dans Dolibarr
   - Cliquez sur « Générer PDF » — elle utilise maintenant votre mise en page custom !

#### 📖 Documentation

- **[Architecture du Module](./docs/ARCHITECTURE.md)** — Schéma BDD, conception des classes
- **[Référence des Zones](./docs/ZONES.md)** — Liste complète des types et paramètres
- **[Guide de Personnalisation](./docs/CUSTOMIZATION.md)** — Thèmes avancés et styles
- **[Référence API](./docs/API.md)** — Création programmée de mises en page

#### 🔧 Détails Techniques

- **Framework** : Dolibarr 18.0+
- **Technologies** : PHP (Active Record), TCPDF (natif), JavaScript (ES6+ vanilla)
- **Base de Données** : 2 nouvelles tables (`llx_pdfbuilder_layout`, `llx_pdfbuilder_zone`)
- **Dépendances** : TCPDF (inclus), FPDI (optionnel, fusion PDF)
- **Sécurité** : Protection CSRF, sanitization inputs, vérification permissions

#### 📋 Configuration Système Requise

- **Dolibarr** v18.0 ou supérieure
- **PHP** 7.4+
- **MySQL** 5.7+ ou MariaDB 10.2+
- **Navigateur moderne** (Chrome, Firefox, Safari, Edge — support ES6 requis)

#### 🐛 Limitations Connues

- Arrondis de bordures non supportés par TCPDF (utiliser des zones rectangulaires)
- Bordures pointillées/tiretées s'affichent en lignes pleines sur certains lecteurs PDF
- Les mises en page complexes imbriquées peuvent impacter les performances sur serveurs bas de gamme

#### 📄 Licence

Ce module est distribué sous la **Licence Publique Générale GNU v2.0** ou ultérieure.
Voir [LICENSE](./LICENSE) pour les détails.

#### 🤝 Contribuer

Nous accueillons les pull requests, rapports de bugs et suggestions de fonctionnalités !

- **Signaler un bug** : [GitHub Issues](https://github.com/AurelGDN/pdfbuilder/issues)
- **Demander une fonctionnalité** : [Discussions](https://github.com/AurelGDN/pdfbuilder/discussions)
- **Soumettre du code** : Fork, créez une branche de fonctionnalité, et ouvrez une pull request

#### 📧 Support

- **Email** : contact@antigravityproject.com
- **Documentation** : https://pdfbuilder.antigravityproject.com
- **Forum Communautaire** : [Forums Dolibarr](https://www.dolibarr.org/forum.php)

---

## Version

- **Version Actuelle** : v1.0 Beta 1
- **Dernière Mise à Jour** : Avril 2026
