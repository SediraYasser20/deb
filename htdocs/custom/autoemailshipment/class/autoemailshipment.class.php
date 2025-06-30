<?php

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/sendings.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT . '/expedition/class/expedition.class.php';
if (DOL_VERSION >= 8) {
    require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
}


class AutoEmailShipmentActions
{
    /**
     * @var DoliDB Database handler.
     */
    public $db;
    /**
     * @var string Error message.
     */
    public $error = '';
    /**
     * @var array Output from trigger.
     */
    public $outputs = array();

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Trigger action for SHIPMENT_VALIDATE
     *
     * @param string $action Action code
     * @param CommonObject $object Object related to the event
     * @param User $user User who triggered the event
     * @param Translate $langs Language object
     * @param Conf $conf Dolibarr configuration object
     * @return int 0 if OK, <0 if KO
     */
    public function runTrigger($action, $object, $user, $langs, $conf)
    {
        global $db, $conf, $langs, $user; // Ensure global scope for these

        if ($action == 'SHIPMENT_VALIDATE' && $object instanceof Expedition) {
            dol_syslog("Trigger autoemailshipment: SHIPMENT_VALIDATE for shipment " . $object->ref, LOG_DEBUG);

            // Ensure $langs is loaded for the module
            $langs->load("autoemailshipment@autoemailshipment");

            // 1. Generate PDF
            $shipment = $object; // $object is the Expedition object
            $modelpdf = 'rouget'; // Standard delivery slip model, or use $conf->global->EXPEDITION_ADDON_PDF

            // Define output directory for PDF
            $outputlangs = $langs;
            if (!empty($conf->global->MAIN_MULTILANGS)) {
                $outputlangs = new Translate("", $conf);
                $outputlangs->setDefaultLang($shipment->thirdparty->default_lang);
            }

            $hidedetails = (isset($conf->global->MAIN_GENERATE_DOCUMENTS_HIDE_DETAILS) && $conf->global->MAIN_GENERATE_DOCUMENTS_HIDE_DETAILS == 1);
            $hidedesc = (isset($conf->global->MAIN_GENERATE_DOCUMENTS_HIDE_DESC) && $conf->global->MAIN_GENERATE_DOCUMENTS_HIDE_DESC == 1);
            $hideref = (isset($conf->global->MAIN_GENERATE_DOCUMENTS_HIDE_REF) && $conf->global->MAIN_GENERATE_DOCUMENTS_HIDE_REF == 1);

            $substitutionarray = array(); // No specific substitutions needed for standard generation for now

            $result = $shipment->generateDocument($modelpdf, $outputlangs, $hidedetails, $hidedesc, $hideref, $substitutionarray);
            if ($result <= 0) {
                $this->error = $shipment->error;
                dol_syslog("autoemailshipment: PDF generation failed for shipment " . $shipment->ref . ". Error: " . $this->error, LOG_ERR);
                return -1;
            }

            $filename = dol_sanitizeFileName($shipment->ref);
            $pdffilepath = $conf->expedition->dir_output . "/" . $filename . "/" . $filename . ".pdf";

            if (!file_exists($pdffilepath)) {
                 // Try with ref.pdf (older naming convention)
                $pdffilepath_alt = $conf->expedition->dir_output . "/" . $shipment->ref . "/" . $shipment->ref . ".pdf";
                if (file_exists($pdffilepath_alt)) {
                    $pdffilepath = $pdffilepath_alt;
                } else {
                    dol_syslog("autoemailshipment: Generated PDF not found at " . $pdffilepath . " or " . $pdffilepath_alt . " for shipment " . $shipment->ref, LOG_ERR);
                    return -2;
                }
            }
            dol_syslog("autoemailshipment: PDF generated successfully: " . $pdffilepath, LOG_INFO);

            // 2. Get client's email
            if ($shipment->thirdparty->email) {
                $recipient_email = $shipment->thirdparty->email;
            } else {
                dol_syslog("autoemailshipment: Client email not found for thirdparty ID " . $shipment->socid . " on shipment " . $shipment->ref, LOG_WARNING);
                // Optionally, do not send email if no recipient, or send to a default admin
                return 0; // Or -3 if this is critical
            }

            // 3. Send email
            $subject = $langs->transnoentities("EmailSubjectDeliveryValidated", $shipment->ref);
            $body = $langs->transnoentities("EmailBodyDeliveryValidated", $shipment->ref);
            $body .= "<br><br>---<br>"; // Add a separator
            // You can add more details to the email body if needed, like company info from $conf

            $mailfile = new CMailFile(
                $subject,
                $recipient_email,
                $conf->global->MAIN_MAIL_SENDER, // Sender email (usually admin or generic company email)
                $body,
                array($pdffilepath), // Attachments
                array(), // MIME types (auto-detected)
                array(), // Filenames for attachments (uses original names)
                '', // Send CC
                '', // Send BCC
                0, // Delivery receipt
                1, // Msg HTML
                $conf->global->MAIN_MAIL_ERRORS_TO // Errors-To address
            );

            if ($mailfile->sendfile()) {
                dol_syslog("autoemailshipment: Email sent successfully to " . $recipient_email . " for shipment " . $shipment->ref, LOG_INFO);
                // Add a follow-up event in Dolibarr calendar/log
                if (class_exists('Events')) { // Check if Events class is available (depends on Dolibarr version)
                    $event = new Commande($db); // Or use a generic CommonObject if more appropriate
                    $event->type_code = 'AC_SHIP'; // Action code for shipment
                    $event->label = $langs->trans("EmailSentForShipment", $shipment->ref);
                    $event->socid = $shipment->socid;
                    $event->contactid = null; // Or try to find a contact
                    $event->datep = dol_now();
                    $event->duree = 0;
                    $event->note = $langs->trans("EmailSentTo", $recipient_email) . "\n" . $langs->trans("Subject") . ": " . $subject;
                    $event->user_owner_id = $user->id; // User who validated the shipment
                    $event->add($user);
                }

            } else {
                $this->error = $mailfile->error;
                dol_syslog("autoemailshipment: Email sending failed for shipment " . $shipment->ref . ". Error: " . $this->error, LOG_ERR);
                return -4;
            }
        }
        return 0;
    }
}

?>
