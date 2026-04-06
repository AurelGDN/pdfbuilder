<?php
/* Copyright (C) 2024 Antigravity Project - GPL v2 or later */

/**
 * \file       pdfbuilder/class/pdfbuildertools.class.php
 * \ingroup    pdfbuilder
 * \brief      Classe utilitaire — fond PDF, fusion, QR code, fond image
 */

/**
 * Outils PDF avancés pour PDF-builder
 * Centralise : fond d'image, fond PDF (FPDI), QR code, fusion
 */
class PdfBuilderTools
{
    /**
     * Applique un PDF de fond sur toutes les pages d'un PDF TCPDF existant.
     * Utilise FPDI si disponible, sinon fallback silencieux.
     *
     * @param string $generatedPdf Chemin du PDF généré
     * @param string $bgPdfPath    Chemin du PDF de fond
     * @return bool true si succès, false si FPDI indisponible
     */
    public static function applyBackgroundPdf($generatedPdf, $bgPdfPath)
    {
        if (!file_exists($generatedPdf) || !file_exists($bgPdfPath)) {
            dol_syslog('PdfBuilderTools::applyBackgroundPdf files missing', LOG_WARNING);
            return false;
        }

        // Tenter le chargement de FPDI (installé via composer ou livré avec Dolibarr)
        $fpdiClass = null;
        if (class_exists('\\setasign\\Fpdi\\Tcpdf\\Fpdi')) {
            $fpdiClass = '\\setasign\\Fpdi\\Tcpdf\\Fpdi';
        } elseif (class_exists('FPDI')) {
            $fpdiClass = 'FPDI';
        } else {
            // Tenter l'inclusion depuis Dolibarr (certains modules l'incluent)
            $fpdiPaths = array(
                DOL_DOCUMENT_ROOT.'/includes/setasign/fpdi/src/autoload.php',
                DOL_DOCUMENT_ROOT.'/includes/tecnickcom/tcpdf/include/fpdi_bridge.php',
            );
            foreach ($fpdiPaths as $p) {
                if (file_exists($p)) {
                    @include_once $p;
                    break;
                }
            }
            if (class_exists('\\setasign\\Fpdi\\Tcpdf\\Fpdi')) {
                $fpdiClass = '\\setasign\\Fpdi\\Tcpdf\\Fpdi';
            }
        }

        if (!$fpdiClass) {
            dol_syslog('PdfBuilderTools::applyBackgroundPdf FPDI not available', LOG_INFO);
            return false;
        }

        try {
            $fpdi = new $fpdiClass();
            $fpdi->setPrintHeader(false);
            $fpdi->setPrintFooter(false);

            // Importer le PDF de fond (page 1)
            $bgPageCount = $fpdi->setSourceFile($bgPdfPath);
            $bgTemplate  = $fpdi->importPage(1);
            $bgSize      = $fpdi->getTemplateSize($bgTemplate);

            // Importer toutes les pages du PDF généré
            $contentPageCount = $fpdi->setSourceFile($generatedPdf);

            for ($pageNo = 1; $pageNo <= $contentPageCount; $pageNo++) {
                $fpdi->addPage($bgSize['orientation'], array($bgSize['width'], $bgSize['height']));

                // Fond (dessous)
                $fpdi->useTemplate($bgTemplate, 0, 0, $bgSize['width'], $bgSize['height']);

                // Recharger le fond pour le contenu
                $fpdi->setSourceFile($generatedPdf);
                $tpl = $fpdi->importPage($pageNo);
                $fpdi->useTemplate($tpl, 0, 0, $bgSize['width'], $bgSize['height']);

                // Recharger le fond pour la prochaine page
                if ($pageNo < $contentPageCount) {
                    $fpdi->setSourceFile($bgPdfPath);
                    $bgTemplate = $fpdi->importPage(min($pageNo + 1, $bgPageCount));
                }
            }

            // Écraser le fichier
            $fpdi->Output($generatedPdf, 'F');

            dol_syslog('PdfBuilderTools::applyBackgroundPdf success on '.$generatedPdf, LOG_DEBUG);
            return true;
        } catch (Exception $e) {
            dol_syslog('PdfBuilderTools::applyBackgroundPdf error: '.$e->getMessage(), LOG_ERR);
            return false;
        }
    }

    /**
     * Applique une image de fond semi-transparente sur chaque page.
     * À appeler AVANT le contenu (dans write_file, juste après AddPage).
     *
     * @param TCPDF  $pdf         Instance PDF
     * @param string $imagePath   Chemin de l'image de fond
     * @param float  $opacity     Opacité (0.0 à 1.0)
     * @param float  $pageWidth   Largeur page (mm)
     * @param float  $pageHeight  Hauteur page (mm)
     */
    public static function drawBackgroundImage(&$pdf, $imagePath, $opacity, $pageWidth, $pageHeight)
    {
        if (!$imagePath || !file_exists($imagePath)) return;

        if (method_exists($pdf, 'SetAlpha')) {
            $pdf->SetAlpha($opacity);
        } elseif (method_exists($pdf, 'setAlpha')) {
            $pdf->setAlpha($opacity);
        }

        $pdf->Image($imagePath, 0, 0, $pageWidth, $pageHeight, '', '', '', false, 150, '', false, false, 0, 'CM', false, false);

        if (method_exists($pdf, 'SetAlpha')) {
            $pdf->SetAlpha(1);
        } elseif (method_exists($pdf, 'setAlpha')) {
            $pdf->setAlpha(1);
        }
    }

    /**
     * Dessine un QR code sur le PDF (utilise TCPDF natif write2DBarcode).
     *
     * @param TCPDF  $pdf       Instance PDF
     * @param string $data      Données à encoder (URL, texte, etc.)
     * @param float  $x         Position X en mm
     * @param float  $y         Position Y en mm
     * @param float  $size      Taille du QR code en mm
     * @param string $color     Couleur HEX du QR code
     * @return bool true si dessiné, false si non supporté
     */
    public static function drawQrCode(&$pdf, $data, $x, $y, $size = 25, $color = '#000000')
    {
        if (!$data) return false;

        // TCPDF supporte nativement les QR codes
        if (method_exists($pdf, 'write2DBarcode')) {
            // Parse la couleur
            $hex = ltrim($color, '#');
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));

            $style = array(
                'border'        => false,
                'padding'       => 0,
                'fgcolor'       => array($r, $g, $b),
                'bgcolor'       => array(255, 255, 255),
                'module_width'  => 1,
                'module_height' => 1,
            );

            $pdf->write2DBarcode($data, 'QRCODE,M', $x, $y, $size, $size, $style, 'N');

            dol_syslog('PdfBuilderTools::drawQrCode drawn at '.$x.','.$y, LOG_DEBUG);
            return true;
        }

        dol_syslog('PdfBuilderTools::drawQrCode TCPDF write2DBarcode not available', LOG_INFO);
        return false;
    }

    /**
     * Dessine un QR code contenant l'URL de la facture (facture en ligne).
     *
     * @param TCPDF   $pdf       Instance PDF
     * @param object   $object    Objet Dolibarr (facture, propal, etc.)
     * @param float   $x         Position X
     * @param float   $y         Position Y
     * @param float   $size      Taille du QR
     * @param string  $color     Couleur HEX
     * @return bool
     */
    public static function drawDocumentQrCode(&$pdf, $object, $x, $y, $size = 22, $color = '#000000')
    {
        global $conf;

        // Construire l'URL publique si disponible
        $url = '';
        if (!empty($conf->global->MAIN_URL_ROOT_QUALIFIEDNAME)) {
            $url = $conf->global->MAIN_URL_ROOT_QUALIFIEDNAME;
        } elseif (!empty($conf->global->MAIN_URL_ROOT)) {
            $url = $conf->global->MAIN_URL_ROOT;
        }

        if ($url && is_object($object)) {
            $url .= '/document.php?modulepart='.$object->element.'&file='.urlencode($object->ref.'/'.$object->ref.'.pdf');
        }

        if (!$url) return false;

        return self::drawQrCode($pdf, $url, $x, $y, $size, $color);
    }

    /**
     * Fusionne plusieurs fichiers PDF en un seul.
     *
     * @param array  $pdfFiles     Liste des chemins PDF à fusionner
     * @param string $outputFile   Chemin du PDF de sortie
     * @return bool true si succès
     */
    public static function mergePdfs($pdfFiles, $outputFile)
    {
        if (empty($pdfFiles)) return false;

        // Vérifier FPDI
        $fpdiClass = null;
        if (class_exists('\\setasign\\Fpdi\\Tcpdf\\Fpdi')) {
            $fpdiClass = '\\setasign\\Fpdi\\Tcpdf\\Fpdi';
        } elseif (class_exists('FPDI')) {
            $fpdiClass = 'FPDI';
        } else {
            $fpdiPaths = array(
                DOL_DOCUMENT_ROOT.'/includes/setasign/fpdi/src/autoload.php',
            );
            foreach ($fpdiPaths as $p) {
                if (file_exists($p)) {
                    @include_once $p;
                    break;
                }
            }
            if (class_exists('\\setasign\\Fpdi\\Tcpdf\\Fpdi')) {
                $fpdiClass = '\\setasign\\Fpdi\\Tcpdf\\Fpdi';
            }
        }

        if (!$fpdiClass) {
            dol_syslog('PdfBuilderTools::mergePdfs FPDI not available', LOG_WARNING);
            return false;
        }

        try {
            $fpdi = new $fpdiClass();
            $fpdi->setPrintHeader(false);
            $fpdi->setPrintFooter(false);

            foreach ($pdfFiles as $pdfFile) {
                if (!file_exists($pdfFile)) continue;

                $pageCount = $fpdi->setSourceFile($pdfFile);
                for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                    $tpl  = $fpdi->importPage($pageNo);
                    $size = $fpdi->getTemplateSize($tpl);
                    $fpdi->addPage($size['orientation'], array($size['width'], $size['height']));
                    $fpdi->useTemplate($tpl, 0, 0, $size['width'], $size['height']);
                }
            }

            $fpdi->Output($outputFile, 'F');

            dol_syslog('PdfBuilderTools::mergePdfs merged '.count($pdfFiles).' files → '.$outputFile, LOG_DEBUG);
            return true;
        } catch (Exception $e) {
            dol_syslog('PdfBuilderTools::mergePdfs error: '.$e->getMessage(), LOG_ERR);
            return false;
        }
    }

    /**
     * Recherche les fichiers PDF à fusionner pour un document donné.
     * Cherche dans le dossier `pdfbuilder/merge/<doc_type>/` et les fichiers
     * attachés au document via l'ECM Dolibarr.
     *
     * @param DoliDB $db        Base données
     * @param object $object    Objet Dolibarr
     * @param string $doc_type  Type de document (invoice, propal, etc.)
     * @return array Liste des chemins PDF à fusionner (après le PDF principal)
     */
    public static function getMergePdfs($db, $object, $doc_type)
    {
        global $conf;

        $pdfs = array();

        // 1. Fichiers dans llx_pdfbuilder_merged_pdf
        $sql = "SELECT filepath FROM ".MAIN_DB_PREFIX."pdfbuilder_merged_pdf";
        $sql .= " WHERE fk_doc = ".((int) $object->id);
        $sql .= " AND doc_type = '".$db->escape($doc_type)."'";
        $sql .= " AND entity = ".((int) $conf->entity);
        $sql .= " ORDER BY position ASC";

        $res = $db->query($sql);
        if ($res) {
            while ($obj = $db->fetch_object($res)) {
                if (file_exists($obj->filepath)) {
                    $pdfs[] = $obj->filepath;
                }
            }
        }

        // 2. Fichiers dans /pdfbuilder/backgrounds/ nommés merge_<doc_type>_*.pdf
        $mergeDir = $conf->pdfbuilder->dir_output.'/merge/'.$doc_type;
        if (is_dir($mergeDir)) {
            $files = glob($mergeDir.'/*.pdf');
            if ($files) {
                sort($files);
                $pdfs = array_merge($pdfs, $files);
            }
        }

        return $pdfs;
    }

    /**
     * Dessine le pli de correspondance (fold mark) pour enveloppes DL.
     * Position standard : 105mm du haut.
     *
     * @param TCPDF $pdf          Instance PDF
     * @param float $margeGauche  Marge gauche en mm
     */
    public static function drawFoldMark(&$pdf, $margeGauche = 11)
    {
        $pdf->SetDrawColor(180, 180, 180);
        $pdf->Line($margeGauche - 5, 105, $margeGauche, 105);
        // Deuxième pli optionnel pour A4 plié en 3 (198mm)
        $pdf->Line($margeGauche - 5, 198, $margeGauche, 198);
    }

    /**
     * Dessine une zone RIB/IBAN dans le pied de page.
     *
     * @param TCPDF     $pdf         Instance PDF
     * @param object    $object      Objet Dolibarr (facture, propale, etc.)
     * @param float     $x           Position X
     * @param float     $y           Position Y
     * @param float     $width       Largeur du bloc
     * @param array     $colorFont   Couleur texte [r,g,b]
     * @param array     $colorBorder Couleur bordure [r,g,b]
     * @param string    $fontFamily  Police
     * @param bool      $hideNumber  Masquer le numéro de compte (afficher BIC/IBAN seulement)
     * @param Translate $outputlangs Langue de sortie
     * @param int       $fontSizeNote Taille de police pour la note
     * @param string    $fontStyleNote Style de police pour la note
     */
    public static function drawBankInfo(&$pdf, $object, $x, $y, $width, $colorFont, $colorBorder, $fontFamily, $hideNumber, $outputlangs, $fontSizeNote = 7, $fontStyleNote = '')
    {
        global $conf, $mysoc, $db;

        // Charger le compte bancaire
        require_once DOL_DOCUMENT_ROOT.'/societe/class/companybankaccount.class.php';
        $bank = new CompanyBankAccount($db);
        
        $iban = '';
        $bic = '';
        $bankName = '';
        $proprio = '';
        $label = '';
        $number = '';

        $bankId = 0;
        if (is_object($object)) {
            // Pour les factures, propales, commandes, on prend le compte rattaché
            if (!empty($object->fk_account)) {
                $bankId = $object->fk_account;
            }
        }

        // --- Tentative 1 : Module BANQUES (Account) ---
        require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
        $acc = new Account($db);
        if ($bankId > 0) {
            $resAcc = $acc->fetch($bankId);
            if ($resAcc > 0) {
                $iban = $acc->iban;
                $bic = $acc->bic;
                $bankName = $acc->bank;
                $proprio = $acc->proprio;
                $label = $acc->label;
                $number = $acc->number;
                dol_syslog("PdfBuilderTools::drawBankInfo Loaded from Account module: id=" . $bankId . " IBAN=" . (empty($iban) ? 'empty' : 'OK'), LOG_DEBUG);
            }
        }

        // --- Tentative 2 : Module SOCIÉTÉ (RIB) ---
        // Si on n'a rien trouvé d'utilisable dans Account, ou si bankId=0
        if (empty($iban) && empty($number)) {
            require_once DOL_DOCUMENT_ROOT.'/societe/class/companybankaccount.class.php';
            $cba = new CompanyBankAccount($db);
            $socid_to_use = ($mysoc && $mysoc->id > 0) ? $mysoc->id : 1;
            
            // Si bankId > 0, on tente de le voir comme un RIB ID (cas rare mais possible)
            if ($bankId > 0) {
                $resCba = $cba->fetch($bankId, '', $socid_to_use, 0);
            } else {
                $resCba = 0;
            }
            
            // Fallback sur le RIB par défaut de l'entité ou de mysoc
            if ($resCba <= 0 || (empty($cba->iban) && empty($cba->number))) {
                $resCba = $cba->fetch(0, '', $socid_to_use, 1);
                if ($resCba <= 0) $resCba = $cba->fetch(0, '', 0, 1);
            }

            if ($resCba > 0) {
                $iban = $cba->iban;
                $bic = $cba->bic;
                $bankName = $cba->bank;
                $proprio = $cba->proprio;
                $label = $cba->label;
                $number = $cba->number;
                dol_syslog("PdfBuilderTools::drawBankInfo Loaded from Company RIB. IBAN=" . (empty($iban) ? 'empty' : 'OK'), LOG_DEBUG);
            }
        }

        // On affiche si on a au moins une info bancaire (IBAN, Numéro ou Nom banque)
        if (empty($iban) && empty($number) && empty($bankName)) {
            dol_syslog("PdfBuilderTools::drawBankInfo No bank account found (checked Account and RIB)", LOG_DEBUG);
            return;
        }

        // Mode de règlement
        $paymentModeLabel = '';
        if (!empty($object->mode_reglement_id)) {
            $sql = "SELECT libelle, code FROM ".MAIN_DB_PREFIX."c_paiement WHERE rowid = ".((int) $object->mode_reglement_id);
            $resql = $db->query($sql);
            if ($resql && $db->num_rows($resql) > 0) {
                $objp = $db->fetch_object($resql);
                $paymentModeLabel = $outputlangs->transnoentities("PaymentType".$objp->code) != "PaymentType".$objp->code ? $outputlangs->transnoentities("PaymentType".$objp->code) : $objp->libelle;
            }
        }

        // --- Vérification fin de page ---
        $pageHeight = $pdf->getPageHeight();
        $marginBot  = $pdf->getBreakMargin();
        $neededSpace = 25 + ($paymentModeLabel ? 4 : 0);
        if ($y + $neededSpace > $pageHeight - $marginBot) {
            dol_syslog("PdfBuilderTools::drawBankInfo Not enough space at Y=" . $y . " (Max=" . ($pageHeight - $marginBot) . ")", LOG_INFO);
        }

        $pdf->SetDrawColor($colorBorder['r'], $colorBorder['g'], $colorBorder['b']);
        $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);
        
        // Affichage Mode de Règlement
        if ($paymentModeLabel) {
            $pdf->SetFont($fontFamily ?: 'DejaVuSans', $fontStyleNote . 'B', $fontSizeNote);
            $pdf->SetXY($x, $y);
            $pdf->Cell($width, 4, $outputlangs->transnoentities('PaymentMode') . ' : ' . $paymentModeLabel, 0, 1, 'L');
            $y += 5;
        }

        $pdf->SetFont($fontFamily ?: 'DejaVuSans', $fontStyleNote . 'B', $fontSizeNote);
        $pdf->SetXY($x, $y);
        $pdf->Cell($width, 4, $outputlangs->transnoentities('BankDetails'), 0, 1, 'L');

        $pdf->SetFont($fontFamily ?: 'DejaVuSans', $fontStyleNote, $fontSizeNote);
        $curY = $y + 4;

        // Nom de la banque
        if ($bankName) {
            $pdf->SetXY($x, $curY);
            $pdf->Cell($width, 3.5, $outputlangs->transnoentities('Bank').' : '.$bankName, 0, 1, 'L');
            $curY += 3.5;
        }

        // Titulaire / Propriétaire
        if ($proprio) {
            $pdf->SetXY($x, $curY);
            $pdf->Cell($width, 3.5, $outputlangs->transnoentities('BankProprio').' : '.$proprio, 0, 1, 'L');
            $curY += 3.5;
        }

        // Intitulé (si différent du proprio)
        if ($label && $label != $proprio) {
            $pdf->SetXY($x, $curY);
            $pdf->Cell($width, 3.5, $outputlangs->transnoentities('Label').' : '.$label, 0, 1, 'L');
            $curY += 3.5;
        }

        // Numéro de compte
        if (!$hideNumber) {
            // Affichage IBAN si présent
            if ($iban) {
                $pdf->SetXY($x, $curY);
                $pdf->Cell($width, 3.5, $outputlangs->transnoentities('IBAN').' : '.$iban, 0, 1, 'L');
                $curY += 3.5;
            }
            // Sinon affichage numéro de compte simple si présent
            elseif ($number) {
                $pdf->SetXY($x, $curY);
                $pdf->Cell($width, 3.5, $outputlangs->transnoentities('BankAccountNumber').' : '.$number, 0, 1, 'L');
                $curY += 3.5;
            }

            if ($bic) {
                $pdf->SetXY($x, $curY);
                $pdf->Cell($width, 3.5, $outputlangs->transnoentities('BIC').' : '.$bic, 0, 1, 'L');
                $curY += 3.5;
            }
        }
    }

    /**
     * Ajoute l'encours client (invoices outstanding) sur la facture.
     *
     * @param TCPDF     $pdf
     * @param object    $object     Facture
     * @param float     $x          Position X
     * @param float     $y          Position Y
     * @param float     $width      Largeur
     * @param array     $colorFont  Couleur texte
     * @param array     $colorHeader Couleur en-tête
     * @param string    $fontFamily Police
     * @param Translate $outputlangs
     * @param int       $fontSizeNote Taille de police pour la note
     * @param string    $fontStyleNote Style de police pour la note
     */
    public static function drawOutstandingBalance(&$pdf, $object, $x, $y, $width, $colorFont, $colorHeader, $fontFamily, $outputlangs, $fontSizeNote = 7, $fontStyleNote = '')
    {
        global $conf;

        if (!getDolGlobalString('PDFBUILDER_INVOICE_WITH_OUTSTANDING')) return;

        $thirdparty = $object->thirdparty;
        if (!is_object($thirdparty)) return;

        $outstandingBills      = $thirdparty->getOutstandingBills('customer');
        $outstandingTotalTTC   = $outstandingBills['opened'];

        if ($outstandingTotalTTC <= 0) return;

        $pdf->SetDrawColor($colorHeader['r'], $colorHeader['g'], $colorHeader['b']);
        $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);
        $pdf->SetFont($fontFamily ?: 'DejaVuSans', $fontStyleNote, $fontSizeNote);

        $pdf->SetXY($x, $y);
        $pdf->Cell($width / 2, 5, $outputlangs->transnoentities('OutstandingBill'), 'LTB', 0, 'L', false);
        $pdf->Cell($width / 2, 5, price($outstandingTotalTTC, 0, $outputlangs, 1, -1, -1, $conf->currency), 'RTB', 0, 'R', false);
    }

    /**
     * Dessine un récapitulatif des totaux ventilés par taux de TVA.
     *
     * @param TCPDF $pdf instance PDF
     * @param object $object Objet métier Dolibarr (facture, propal, order...)
     * @param float $x Position X
     * @param float $y Position Y
     * @param array $colorHeader Couleur de l'entête du tableau
     * @param array $colorFont Couleur du texte
     * @param array $colorBorder Couleur des bordures
     * @param string $fontFamily Police à utiliser
     * @param Translate $outputlangs Objet de traduction
     * @param int $fontSizeTheader Taille police entête
     * @param string $fontStyleTheader Style police entête
     * @param int $fontSizeDesc Taille police données
     * @param string $fontStyleDesc Style police données
     */
    public static function drawVatBreakdown(&$pdf, $object, $x, $y, $colorHeader, $colorHeaderTxt, $colorFont, $colorBorder, $fontFamily, $outputlangs, $fontSizeTheader = 8, $fontStyleTheader = 'B', $fontSizeDesc = 7, $fontStyleDesc = '')
    {
        if (empty($object->lines)) return;

        // Grouping VAT amounts by VAT rate
        $vatArray = array();
        foreach ($object->lines as $line) {
            $rate = (string) $line->tva_tx;
            if (!isset($vatArray[$rate])) {
                $vatArray[$rate] = array('base' => 0, 'tva' => 0);
            }
            $vatArray[$rate]['base'] += $line->total_ht;
            $vatArray[$rate]['tva'] += $line->total_tva;
        }
        
        if (empty($vatArray)) return;
        ksort($vatArray);

        // Header
        $pdf->SetFillColor($colorHeader['r'], $colorHeader['g'], $colorHeader['b']);
        $pdf->SetTextColor($colorHeaderTxt['r'], $colorHeaderTxt['g'], $colorHeaderTxt['b']);
        $pdf->SetFont($fontFamily ?: 'DejaVuSans', $fontStyleTheader, $fontSizeTheader);
        $pdf->SetXY($x, $y);
        $pdf->Cell(25, 5, $outputlangs->transnoentities('VATRate'), 'LTB', 0, 'C', true);
        $pdf->Cell(25, 5, $outputlangs->transnoentities('BaseHT'), 'TB', 0, 'R', true);
        $pdf->Cell(25, 5, $outputlangs->transnoentities('AmountVAT'), 'RTB', 0, 'R', true);
        $y += 5;

        // Lines
        $pdf->SetFont($fontFamily ?: 'DejaVuSans', $fontStyleDesc, $fontSizeDesc);
        $pdf->SetTextColor($colorFont['r'], $colorFont['g'], $colorFont['b']);
        $pdf->SetDrawColor($colorBorder['r'], $colorBorder['g'], $colorBorder['b']);

        foreach ($vatArray as $rate => $amounts) {
            $pdf->SetXY($x, $y);
            $pdf->Cell(25, 4, price($rate, 0, $outputlangs).' %', 'LR', 0, 'C');
            $pdf->Cell(25, 4, price($amounts['base'], 0, $outputlangs), 'R', 0, 'R');
            $pdf->Cell(25, 4, price($amounts['tva'], 0, $outputlangs), 'R', 0, 'R');
            $y += 4;
        }

        // Bottom border
        $pdf->SetXY($x, $y);
        $pdf->Cell(75, 0, '', 'T', 0, 'C');
    }
}
