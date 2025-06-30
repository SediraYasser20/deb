<?php
require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

class AutoShipmentTriggers extends DolibarrTriggers
{
    public function __construct($db)
    {
        $this->db = $db;
    }

    public function runTrigger($action, $object, $user, $langs, $conf)
    {
        if ($action === 'SHIPPING_VALIDATE') {
            // Generate the PDF using the espadon template
            $result = $object->generateDocument('espadon', $langs);
            if ($result == 1) {
                $pdf_file = $object->last_main_doc;

                // Verify PDF exists and is readable
                if (file_exists($pdf_file) && is_readable($pdf_file)) {
                    // Retrieve client email from thirdparty
                    if (empty($object->thirdparty)) {
                        $object->fetch_thirdparty();
                    }
                    $thirdparty = $object->thirdparty;

                    if (!empty($thirdparty->email)) {
                        // Set up email parameters
                        $subject = "Shipment Confirmation - " . $object->ref;
                        $msg = "Please find your shipment confirmation attached.";
                        $from = !empty($conf->global->MAIN_MAIL_EMAIL_FROM) ? $conf->global->MAIN_MAIL_EMAIL_FROM : 'noreply@yourdomain.com';
                        $to = $thirdparty->email;
                        $filename_list = array($pdf_file);
                        $mimetype_list = array('application/pdf');
                        $mimefilename_list = array(basename($pdf_file));

                        // Send email with PDF attachment
                        $mail = new CMailFile($subject, $to, $from, $msg, $filename_list, $mimetype_list, $mimefilename_list);
                        if ($mail->sendfile()) {
                            dol_syslog("AutoShipment: Email sent successfully to " . $to, LOG_INFO);
                        } else {
                            dol_syslog("AutoShipment: Failed to send email: " . $mail->error, LOG_ERR);
                        }
                    } else {
                        dol_syslog("AutoShipment: No email address for thirdparty ID " . $thirdparty->id, LOG_ERR);
                    }
                } else {
                    dol_syslog("AutoShipment: PDF file not found or unreadable: " . $pdf_file, LOG_ERR);
                }
            } else {
                dol_syslog("AutoShipment: Failed to generate PDF for shipment " . $object->ref, LOG_ERR);
            }
        }
        return 0; // Trigger handled, continue normal execution
    }
}