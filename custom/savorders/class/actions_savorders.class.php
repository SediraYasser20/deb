<?php

require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/savorders/class/savorders.class.php');

/**
 * Class Actionssavorders
 */
class Actionssavorders
{
    /**
     * @var array Hook results. Propagated to $hookmanager->resArray for later reuse
     */
    public $results = array();

    /**
     * @var string String displayed by executeHook() immediately after return
     */
    public $resprints;

    /**
     * @var array Errors
     */
    public $errors = array();

    /**
     * Constructor
     */
    public function __construct()
    {
    }

    public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        global $langs, $db, $user, $conf;

        $langs->loadLangs(array('stocks'));
        $langs->load('savorders@savorders');

        $savorders = new savorders($db);

        $tmparray = ['receiptofproduct_valid', 'createdelivery_valid', 'deliveredtosupplier_valid', 'receivedfromsupplier_valid'];

        $ngtmpdebug = GETPOST('ngtmpdebug', 'int');
        if($ngtmpdebug) {
            echo '<pre>';
            print_r($parameters);
            echo '</pre>';
            
            ini_set('display_startup_errors', 1);
            ini_set('display_errors', 1);
            error_reporting(-1);
        }

        if ($object && (in_array('ordercard', explode(':', $parameters['context'])) || in_array('ordersuppliercard', explode(':', $parameters['context']))) && in_array($action, $tmparray)) {

            $error = 0;
            $now = dol_now();

            $savorders_date = '';

            global $savorders_date;

            $tmpdate = dol_mktime(0,0,0, GETPOST('savorders_datemonth','int'), GETPOST('savorders_dateday','int'), GETPOST('savorders_dateyear','int'));
            
            $savorders_date = dol_print_date($tmpdate, 'day');

            $cancel = GETPOST('cancel', 'alpha');

            $novalidaction = str_replace("_valid", "", $action);

            $s = GETPOST('savorders_data', 'array');

            $savorders_sav = $object->array_options["options_savorders_sav"];
            $savorders_status = $object->array_options["options_savorders_status"];

            if(!$savorders_sav || $cancel) return 0;

            $idwarehouse = isset($conf->global->SAVORDERS_ADMIN_IDWAREHOUSE) ? $conf->global->SAVORDERS_ADMIN_IDWAREHOUSE : 0;

            if(($novalidaction == 'receiptofproduct' || $novalidaction == 'deliveredtosupplier') && $idwarehouse <= 0) {
                $error++;
                $action = $novalidaction;
            }

            $commande = $object;

            $nblines = count($commande->lines);

            if($object->element == 'order_supplier') {
                $labelmouve = ($novalidaction == 'deliveredtosupplier') ? $langs->trans('ProductDeliveredToSupplier') : $langs->trans('ProductReceivedFromSupplier');
            } else {
                $labelmouve = ($novalidaction == 'receiptofproduct') ? $langs->trans('ProductReceivedFromCustomer') : $langs->trans('ProductDeliveredToCustomer');
            }

            $origin_element = '';
            $origin_id = null;

            if($object->element == 'order_supplier') {
                $mouvement = ($novalidaction == 'deliveredtosupplier') ? 1 : 0; // 0 : Add / 1 : Delete
            } else {
                $mouvement = ($novalidaction == 'receiptofproduct') ? 0 : 1; // 0 : Add / 1 : Delete
            }

            $texttoadd = '';
            if(isset($object->array_options["options_savorders_history"]))
                $texttoadd = $object->array_options["options_savorders_history"];

            if($novalidaction == 'createdelivery' || $novalidaction == 'receivedfromsupplier') {
                $texttoadd .= '<br>';
            }

            $oneadded = 0;

            if(!$error)
            for ($i = 0; $i < $nblines; $i++) {
                if (empty($commande->lines[$i]->fk_product)) {
                    continue;
                }

                $objprod = new Product($db);
                $objprod->fetch($commande->lines[$i]->fk_product);

                if($objprod->type != Product::TYPE_PRODUCT) continue;

                $tmid = $commande->lines[$i]->fk_product;

                $warehouse  = $s && isset($s[$tmid]) && isset($s[$tmid]['warehouse']) ? $s[$tmid]['warehouse'] : 0;
                $qty        = $s && isset($s[$tmid]) && isset($s[$tmid]['qty']) ? $s[$tmid]['qty'] : $commande->lines[$i]->qty;

                if($novalidaction == 'receiptofproduct' || $novalidaction == 'deliveredtosupplier') {
                    $warehouse = $idwarehouse;
                }

                if(($novalidaction == 'createdelivery') && $warehouse <= 0) {
                    setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("Warehouse")), null, 'errors');
                    $error++;
                }

                $txlabelmovement = '(SAV) '.$objprod->ref .': '. $labelmouve;

                // Fetch the PMP for the product
                $pmp = $objprod->pmp;

                if ($objprod->hasbatch()) {

                    $qty = ($qty > $commande->lines[$i]->qty) ? $commande->lines[$i]->qty : $qty;

                    if($qty)
                    for ($z=0; $z < $qty; $z++) { 
                        $batch = $s && isset($s[$tmid]) && isset($s[$tmid]['batch'][$z]) ? $s[$tmid]['batch'][$z] : '';

                        if(!$batch && $z == 0) {
                            setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("batch_number")), null, 'errors');
                            $error++;
                            break;
                        }

                        if(!$error && $batch) {
                            // Check if batch exists for receiptofproduct
                            if ($novalidaction == 'receiptofproduct') { // Only for receiptofproduct now
                                $lot = new ProductLot($db);
                                $res = $lot->fetch(0, $objprod->id, $batch);
                                if ($res <= 0) {
                                    setEventMessages($langs->trans("BatchDoesNotExist", $batch), null, 'errors');
                                    $error++;
                                    // break; // break will be handled by the if($error) break; after this block
                                }
                            }

                            // *** Start: New/Modified Validation Block for 'createdelivery' ***
                            if (!$error && $batch && $novalidaction == 'createdelivery') {

                                // Validation 1: Serial number (batch) must belong to the selected product.
                                $lot = new ProductLot($db);
                                $res_lot_fetch = $lot->fetch(0, $objprod->id, $batch);
                                if ($res_lot_fetch <= 0) {
                                    setEventMessages($langs->trans("SerialNumberNotForProduct", $batch, $objprod->ref), null, 'errors');
                                    $error++;
                                }

                                // Validation 2: Serial number (batch) must exist in stock in the selected warehouse.
                                if (!$error) { // Proceed only if previous validation passed
                                    // NEW QUERY:
                                    $sql_stock_check = "SELECT SUM(pb.qty) as total_qty FROM " . MAIN_DB_PREFIX . "product_batch pb";
                                    $sql_stock_check .= " INNER JOIN " . MAIN_DB_PREFIX . "product_stock ps ON pb.fk_product_stock = ps.rowid";
                                    $sql_stock_check .= " WHERE ps.fk_product = " . (int)$objprod->id;
                                    $sql_stock_check .= " AND ps.fk_entrepot = " . (int)$warehouse;
                                    $sql_stock_check .= " AND pb.batch = '" . $db->escape($batch) . "';"; // Added semicolon for clarity
                                    
                                    $resql_stock_check = $db->query($sql_stock_check);
                                    if ($resql_stock_check) {
                                        $obj_stock = $db->fetch_object($resql_stock_check);
                                        if (!$obj_stock || $obj_stock->total_qty <= 0) {
                                            $warehouse_obj = new Entrepot($db); 
                                            $warehouse_ref = $warehouse; // Fallback
                                            if ($warehouse_obj->fetch($warehouse) > 0) {
                                                $warehouse_ref = $warehouse_obj->ref;
                                            }
                                            setEventMessages($langs->trans("SerialNumberNotInStockOrZeroQty", $batch, $warehouse_ref), null, 'errors');
                                            $error++;
                                        }
                                    } else {
                                        dol_syslog("SAVORDERS Error checking stock for batch: " . $db->error(), LOG_ERR);
                                        setEventMessages($langs->trans("ErrorCheckingSerialNumberStock", $batch), null, 'errors');
                                        $error++;
                                    }
                                }
                            }
                            // *** End: New/Modified Validation Block ***

                            if ($error) break; // If an error occurred from new validation, stop processing this serial.

                            // Original stock correction logic (should only run if !$error)
                            // The check if ($novalidaction == 'createdelivery') { ... old SQL ... } was part of the block
                            // that is now conditionally skipped for 'createdelivery' or replaced by the new validation.
                            // So, no specific removal needed here if the above structure is correct.

                            // We still need to ensure this only runs if there are no errors from the above blocks.
                            if (!$error) { // This !$error check is critical
                                $result = $objprod->correct_stock_batch(
                                    $user,
                                    $warehouse,
                                    1, // Correcting one unit at a time for batch products
                                    $mouvement,
                                    $txlabelmovement, // label movement
                                    $pmp, // Use PMP as price unit
                                    $d_eatby = '',
                                    $d_sellby = '',
                                    $batch,
                                    $inventorycode = '',
                                    $origin_element,
                                    $origin_id,
                                    $disablestockchangeforsubproduct = 0
                                ); // We do not change value of stock for a correction

                                if($result > 0) {
                                    $this->addLineHistoryToSavCommande($texttoadd, $novalidaction, $objprod, $batch);
                                    $oneadded++;
                                } else {
                                    $error++;
                                    // break; // break will be handled by the if($error) break; after this block
                                }
                            }
                        }
                        if ($error) break; // If an error occurred for this serial, stop processing more serials for this line.
                    }

                } else { // Product does not have batch tracking
                    if(!$error && $qty) { // This is for non-batch products
                        $result = $objprod->correct_stock(
                            $user,
                            $warehouse,
                            $qty,
                            $mouvement,
                            $txlabelmovement,
                            $pmp, // Use PMP as price unit
                            $inventorycode = '',
                            $origin_element,
                            $origin_id,
                            $disablestockchangeforsubproduct = 0
                        ); // We do not change value of stock for a correction

                        if($result > 0) {
                            $this->addLineHistoryToSavCommande($texttoadd, $novalidaction, $objprod);
                            $oneadded++;
                        } else {
                            $error++;
                            // break; // break will be handled by the if($error) break; after this block
                        }
                    }
                }
                if ($error) break; // If an error occurred for this line, stop processing more lines.
            }

            if(!$error && $oneadded) {

                if($object->element == 'order_supplier') {
                    $savorders_status = ($novalidaction == 'deliveredtosupplier') ? $savorders::DELIVERED_SUPPLIER : $savorders::RECEIVED_SUPPLIER;
                } else {
                    if ($novalidaction == 'process_reimbursement') {
                        $savorders_status = $savorders::REIMBURSED;
                    } else {
                        $savorders_status = ($novalidaction == 'receiptofproduct') ? $savorders::RECIEVED_CUSTOMER : $savorders::DELIVERED_CUSTOMER;
                    }
                }

                $texttoadd = str_replace(['<span class="savorders_history_td">', '</span>'], ' ', $texttoadd);

                $extrafieldtxt = '<span class="savorders_history_td">';
                $extrafieldtxt .= $texttoadd;
                $extrafieldtxt .= '</span>';

                $object->array_options["options_savorders_history"] = $extrafieldtxt;
                $object->array_options["options_savorders_status"] = $savorders_status;
                // Add facture_sav to extrafields if reimbursement is processed
                if ($novalidaction == 'process_reimbursement') {
                    $object->array_options['options_facture_sav'] = GETPOST('facture_sav', 'int');
                }
                $result = $object->insertExtraFields();
                if(!$result) $error++;
            }

            if($error){
                setEventMessages($objprod->errors, $object->errors, 'errors');
                header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action='.$novalidaction);
            } else {
                if($oneadded)
                    setEventMessages($langs->trans("RecordCreatedSuccessfully"), null, 'mesgs');
                header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
                exit();
            }

        }
    }

    public function addLineHistoryToSavCommande(&$texttoadd, $novalidaction, $objprod = '', $batch = '')
    {
        global $langs, $savorders_date;

        $contenu = '- '.$savorders_date.': ';

        if($novalidaction == 'receiptofproduct' || $novalidaction == 'receivedfromsupplier') {
            $contenu .= $langs->trans("OrderSavRecieveProduct");
        }
        elseif($novalidaction == 'createdelivery' || $novalidaction == 'deliveredtosupplier') {
            $contenu .= $langs->trans("OrderSavDeliveryProduct");
        }
        elseif($novalidaction == 'process_reimbursement') {
            $contenu .= $langs->trans("OrderSavReimbursementProcessed");
        }

        $contenu .= ' <a target="_blank" href="'.dol_buildpath('/product/card.php?id='.$objprod->id, 1).'">';
        $contenu .= '<b>'.$objprod->ref.'</b>';
        $contenu .= '</a>';

        if($batch) {
            $contenu .=  ' N° <b>'.$batch.'</b>';
        }

        $texttoadd .=  '<div class="savorders_history_txt " title="'.strip_tags($contenu).'">';
        $texttoadd .= $contenu;
        $texttoadd .=  '</div>';
    }

    /**
     * @param   array         	$parameters     Hook metadatas (context, etc...)
     * @param   Commande    	$object         The object to process
     * @param   string          $action         Current action (if set). Generally create or edit or null
     * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
     * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
     */
    public function addMoreActionsButtons($parameters, &$object, &$action = '')
    {
        global $db, $conf, $langs, $confirm, $user;

        $langs->load('admin');
        $langs->load('savorders@savorders');

    // ───────────────────────────────────────────────────────────────────────────
    // Only allow SAV buttons to super‑admins or group 5 members in current entity
    $allowed = $user->admin;

    if (!$allowed) {
        // Better: include entity clause so you only match rows for your current entity
        $sql = "
            SELECT 1 
            FROM ".MAIN_DB_PREFIX."usergroup_user u
            WHERE u.fk_user = ".(int)$user->id."
              AND u.fk_usergroup = 5
              AND u.entity = ".(int)$conf->entity;
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $allowed = true;
        }
    }

    if (! $allowed) {
        // Immediately bail out before touching any $object or printing anything
        return 0;
    }
    // ───────────────────────────────────────────────────────────────────────────


        $form = new Form($db);

        $ngtmpdebug = GETPOST('ngtmpdebug', 'int');
        if($ngtmpdebug) {
            echo '<pre>';
            print_r($parameters);
            echo '</pre>';

            ini_set('display_startup_errors', 1);
            ini_set('display_errors', 1);
            error_reporting(-1);
        }
		
	

        if (in_array('ordercard', explode(':', $parameters['context'])) || in_array('ordersuppliercard', explode(':', $parameters['context']))) {

            $s = GETPOST('savorders_data', 'array');

            $linktogo = $_SERVER["PHP_SELF"].'?id=' . $object->id;

            $tmparray = ['receiptofproduct', 'createdelivery', 'deliveredtosupplier', 'receivedfromsupplier', 'process_reimbursement'];

            if(in_array($action, $tmparray)) {

                ?>
                <script type="text/javascript">
                    $(document).ready(function() {
                        $('html, body').animate({
                            scrollTop: ($("#savorders_formconfirm").offset().top - 80)
                        }, 800);
                    });
                </script>
                <?php

                if($object->element == 'order_supplier') {
                    $title = ($action == 'deliveredtosupplier') ? $langs->trans('ProductDeliveredToSupplier') : $langs->trans('ProductReceivedFromSupplier');
                } else {
                    if ($action == 'process_reimbursement') {
                        $title = $langs->trans('ProcessReimbursement');
                    } else {
                        $title = ($action == 'receiptofproduct') ? $langs->trans('ProductReceivedFromCustomer') : $langs->trans('ProductDeliveredToCustomer');
                    }
                }

                $formproduct = new FormProduct($db);

                $nblines = count($object->lines);
                
                print '<div class="tagtable paddingtopbottomonly centpercent noborderspacing savorders_formconfirm" id="savorders_formconfirm">';
                print_fiche_titre($title, '', $object->picto);

                $idwarehouse = isset($conf->global->SAVORDERS_ADMIN_IDWAREHOUSE) ? $conf->global->SAVORDERS_ADMIN_IDWAREHOUSE : 0;

                if($action == 'receiptofproduct' && $idwarehouse <= 0) {
                    $link = '<a href="'.dol_buildpath('savorders/admin/admin.php', 1).'" target="_blank">'.img_picto('', 'setup', '').' '.$langs->trans("Configuration").'</a>';
                    setEventMessages($langs->trans("ErrorFieldRequired", $langs->trans('SAV').' '.dol_htmlentitiesbr_decode($langs->trans('Warehouse'))).' '.$link, null, 'errors');
                    $error++;
                }

                print '<div class="tagtable paddingtopbottomonly centpercent noborderspacing savorders_formconfirm" id="savorders_formconfirm">';
                print '<form method="POST" action="'.$linktogo.'" class="notoptoleftroright">'."\n";
                print '<input type="hidden" name="action" value="'.$action.'_valid">'."\n";
                print '<input type="hidden" name="token" value="'.(isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : '').'">'."\n";

                $now = dol_now();

                print '<table class="valid centpercent">';
                    
                    print '<tr>';
                    if ($action == 'process_reimbursement') {
                        print '<tr><td colspan="2">'.$langs->trans("SelectInvoiceForReimbursement").'</td></tr>';
                        print '<tr>';
                        print '<td class="fieldrequired">'.$langs->trans("Invoice").'</td>';
                        print '<td>';
                        // Assuming you have a method to get invoices for the client
                        // This is a placeholder, you'll need to implement getInvoicesForClient() or similar
                        $factures = array(); // Replace with actual invoice fetching
                        if (method_exists($object, 'fetch_thirdparty')) {
                            $object->fetch_thirdparty();
                            // Example: $factures = $this->getInvoicesForClient($object->client->id);
                            // For now, let's assume $factures is populated with id => ref
                            // You'll need to implement a proper way to get client invoices
                            $sql_invoices = "SELECT rowid, facnumber FROM ".MAIN_DB_PREFIX."facture WHERE fk_soc = ".$object->fk_soc." AND fk_statut > 0 ORDER BY facnumber DESC";
                            $resql_invoices = $db->query($sql_invoices);
                            if ($resql_invoices) {
                                while ($obj_inv = $db->fetch_object($resql_invoices)) {
                                    $factures[$obj_inv->rowid] = $obj_inv->facnumber;
                                }
                            }
                        }
                        print $form->selectarray('facture_sav', $factures, GETPOST('facture_sav', 'int'), 1);
                        print '</td>';
                        print '</tr>';
                    } else {
                        print '<tr>';
                        print '<td class="left"><b>'.$langs->trans("Product").'</b></td>';
                        print '<td class="left"><b>'.$langs->trans("batch_number").'</b></td>';
                        print '<td class="left"><b>'.$langs->trans("Qty").'</b></td>';

                        if($action == 'createdelivery' || $action == 'receivedfromsupplier') {
                            print '<td class="left">'.$langs->trans("Warehouse").'</td>';
                        }
                        print '</tr>';

                        for ($i = 0; $i < $nblines; $i++) {
                            if (empty($object->lines[$i]->fk_product)) {
                                continue;
                            }

                            $objprod = new Product($db);
                            $objprod->fetch($object->lines[$i]->fk_product);

                            if($objprod->type != Product::TYPE_PRODUCT) continue;

                            $hasbatch = $objprod->hasbatch();

                            $tmid = $object->lines[$i]->fk_product;

                            $warehouse  = $s && isset($s[$tmid]) && isset($s[$tmid]['warehouse']) ? $s[$tmid]['warehouse'] : 0;
                            $qty        = $s && isset($s[$tmid]) && isset($s[$tmid]['qty']) ? $s[$tmid]['qty'] : $object->lines[$i]->qty;

                            print '<tr class="oddeven_">';
                            
                            // Ref Product
                            print '<td class="left width300">'.$objprod->getNomUrl(1).'</td>';

                            // Batch
                            print '<td class="left width300">';
                            if($hasbatch) {
                                for ($z=0; $z < $qty; $z++) { 
                                    $batch = $s && isset($s[$tmid]) && isset($s[$tmid]['batch'][$z]) ? $s[$tmid]['batch'][$z] : '';
                                    print '<input type="text" class="flat width200" name="savorders_data['.$tmid.'][batch]['.$z.']" value="'.$batch.'"/>';
                                }
                            } else {
                                print '-';
                            }
                            print '</td>';

                            $disabled = ($hasbatch) ? 'disabled' : '';

                            $maxqty = ($hasbatch) ? 'max="'.$qty.'"' : '';

                            // Qty
                            print '<td class="left ">';
                            print '<input type="number" class="flat width50" name="savorders_data['.$tmid.'][qty]" value="'.$qty.'" '.$maxqty.' min="1" step="any" '.$disabled.'/>';
                            print '</td>';

                            // Warehouse
                            if($action == 'createdelivery' || $action == 'receivedfromsupplier') {
                                print '<td class="left selectWarehouses">';
                                $formproduct = new FormProduct($db);
                                // Ensure $forcecombo is defined
                                if (!isset($forcecombo)) {
                                    $forcecombo = 0;  // Default value, adjust if necessary
                                }
                                print $formproduct->selectWarehouses($warehouse, 'savorders_data['.$tmid.'][warehouse]', '', 0, 0, 0, '', 0, $forcecombo);
                                print '</td>';
                            }

                            print '</tr>';
                        }
                    }

                    print '<tr><td colspan="'.($action == 'process_reimbursement' ? 2 : 4).'"></td></tr>';
                    print '<tr>';
                        print '<td colspan="'.($action == 'process_reimbursement' ? 2 : 4).'" class="center">';
                        print '<div class="savorders_dateaction">';
                        print '<b>'.$langs->trans('Date').'</b>: ';
                        print $form->selectDate('', 'savorders_date', 0, 0, 0, '', 1, 1);
                        print '</div>';
                        print '</td>';
                    print '</tr>';

                    print '<tr class="valid">';
                    print '<td class="valid center" colspan="'.($action == 'process_reimbursement' ? 2 : 4).'">';
                    // Fix: Correctly handle form submission for Validate and Cancel
                    print '<input type="submit" class="button valignmiddle" name="validate" value="'.$langs->trans("Validate").'">';
                    print '<input type="submit" class="button button-cancel" name="cancel" value="'.$langs->trans("Cancel").'">';
                    print '</td>';
                    print '</tr>'."\n";

                print '</table>';

                print "</form>\n";

                if (!empty($conf->use_javascript_ajax)) {
                    print '<!-- code to disable button to avoid double clic -->';
                    print '<script type="text/javascript">'."\n";
                    print '
                    $(document).ready(function () {
                        $(".confirmvalidatebutton").on("click", function() {
                            console.log("We click on button");
                            $(this).attr("disabled", "disabled");
                            setTimeout(\'$(".confirmvalidatebutton").removeAttr("disabled")\', 3000);
                            $(this).closest("form").submit();
                        });
                        $("td.selectWarehouses select").select2();
                    });
                    ';
                    print '</script>'."\n";
                }

                print '</div>';
                print '<br>';

                return 1;
            }
        }

        if (in_array('ordercard', explode(':', $parameters['context'])) || in_array('ordersuppliercard', explode(':', $parameters['context']))) {

// Already checked $allowed before, so just bail on low‑status
if ($object->statut < 1) return 0;

            $nblines = count($object->lines);

            $savorders_sav = $object->array_options["options_savorders_sav"];
            $savorders_status = $object->array_options["options_savorders_status"];

            if($ngtmpdebug) {
                echo 'nblines : '.$nblines.'<br>';
                echo 'savorders_sav : '.$savorders_sav.'<br>';
                echo 'savorders_status : '.$savorders_status.'<br>';
                echo 'object->element : '.$object->element.'<br>';
            }

            if($savorders_sav && $nblines > 0) {

                print '<div class="inline-block divButAction">';

                if($object->element == 'order_supplier') {
                    if(empty($savorders_status)) {
                        print '<a id="savorders_button" class="savorders butAction badge-status1" href="'.$linktogo.'&action=deliveredtosupplier&token='.newToken().'">' . $langs->trans('ProductDeliveredToSupplier');
                        print '</a>';
                    } 
                    elseif($savorders_status == savorders::DELIVERED_SUPPLIER) {
                        print '<a id="savorders_button" class="savorders butAction badge-status1" href="'.$linktogo.'&action=receivedfromsupplier&token='.newToken().'">' . $langs->trans('ProductReceivedFromSupplier');
                        print '</a>';
                    }
                } else {
                    if(empty($savorders_status)) {
                        print '<a id="savorders_button" class="savorders butAction badge-status1" href="'.$linktogo.'&action=receiptofproduct&token='.newToken().'">' . $langs->trans('ProductReceivedFromCustomer');
                        print '</a>';
                    } 
                    elseif($savorders_status == savorders::RECIEVED_CUSTOMER) {
                        print '<a id="savorders_button" class="savorders butAction badge-status1" href="'.$linktogo.'&action=createdelivery&token='.newToken().'">' . $langs->trans('ProductDeliveredToCustomer');
                        print '</a>';
                    }
                    elseif($savorders_status == savorders::DELIVERED_CUSTOMER) {
                        print '<a id="savorders_button" class="savorders butAction badge-status3" href="'.$linktogo.'&action=process_reimbursement&token='.newToken().'">' . $langs->trans('ProcessReimbursement');
                        print '</a>';
                    }
                }

                print '</div>';

            }

        }

        return 0;
    }
        // ————————————————————————————————————————————————————
    // Override the SAV STATUS extrafield display on the order card
    public function printObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        global $db, $langs, $conf;

        // Only on customer order card
        if (! in_array('ordercard', explode(':', $parameters['context']))) {
            return 0;
        }

        if (isset($parameters['optionals']['savorders_status'])) {
            $status = (int) $object->array_options['options_savorders_status'];
            if ($status === savorders::REIMBURSED) {
                // Load invoice
                $facId = (int) $object->array_options['options_facture_sav'];
                $label = $langs->trans('Reimbursed');  // fallback
                if ($facId > 0) {
                    $fac = new Facture($db);
                    if ($fac->fetch($facId) > 0) {
                        $amt = price($fac->total_ttc).' '.$langs->trans("Currency".$conf->currency);
                        $label = $langs->trans('ClientReimbursedAmount', $amt);
                    }
                }
                // Override what Dolibarr will print for savorders_status
                $parameters['optionals']['savorders_status']['value']
                    = '<span class="badge badge-status4">'.$label.'</span>';
                return 1;
            }
        }
        return 0;
    }


}
