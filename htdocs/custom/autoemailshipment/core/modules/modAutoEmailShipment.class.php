<?php

require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

/**
 * Class to describe and enable the Auto Email Shipment module
 */
class modAutoEmailShipment extends DolibarrModules
{
    /**
     * Constructor. Define names, constants, features and prerequisites.
     */
    public function __construct($db)
    {
        global $langs;

        parent::__construct($db);

        $this->numero = 500000; // Must be unique among all modules
        $this->rights_class = 'autoemailshipment'; // Key used to manage permissions
        $this->family = "crm"; // Family of module (crm, stock, project, ...)
        $this->module_position = 500; // Position of the module entry in setup page (0=common, 10=bill_invoice, ...)
        $this->name = preg_replace('/^mod/i', '', get_class($this)); // Module name without 'mod' prefix
        $this->description = $langs->trans("Module to automatically send an email with the delivery slip when a shipment is validated.");
        $this->editor_name = 'Your Name/Company'; // Editor name
        $this->editor_url = 'https://yourwebsite.com'; // Editor URL
        $this->version = '1.0.0';
        $this->const_name = 'MAIN_MODULE_' . strtoupper($this->name); // Name of constant used to enable/disable module
        $this->special = 0; // 0=no, 1=yes (for internal Dolibarr modules)
        $this->picto = 'generic'; // Default picto (for custom modules, can be your own icon name)

        // Module parts (triggers, hooks, menu, etc.)
        $this->module_parts = array(
            'triggers' => 1, // This module has triggers
        );

        // Config page
        $this->config_page_url = array('setup.php@autoemailshipment');

        // Dependencies
        $this->depends = array('modExpedition'); // Depends on the shipment module
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->phpmin = array(7, 0); // Minimum PHP version
        $this->need_dolibarr_version = array(14, 0); // Minimum Dolibarr version (Adjust if necessary, v21.0.1 is higher)

        // Activation / Deactivation
        $this->const = array(); // Constants to add/remove during init/remove
        $this->tabs = array(); // Tabs to add/remove
        $this->rights = array(); // Rights to add/remove
        $this->menu = array(); // Menu entries to add/remove
        $this->cronjobs = array(); // Cron jobs to add/remove
    }

    /**
     * Function called when module is enabled.
     * The init function add constants, boxes, rights and menus (defined in constructor)
     *
     * @param      string $options Options when enabling module ('', 'noboxes')
     * @return     int             1 if OK, 0 if KO
     */
    public function init($options = '')
    {
        $sql = array();
        return $this->_init($sql, $options);
    }

    /**
     * Function called when module is disabled.
     * Remove from database constants, boxes and rights from Dolibarr database.
     * Data not altered.
     *
     * @param      string $options Options when disabling module ('', 'noboxes')
     * @return     int             1 if OK, 0 if KO
     */
    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }
}
?>
