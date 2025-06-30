# Agent Instructions for autoemailshipment Module

This module is designed to integrate with Dolibarr ERP/CRM.

## Development Guidelines:

*   **Standard Practices:** Adhere to standard Dolibarr module development practices and coding conventions.
*   **Hooks and Triggers:** The module primarily uses Dolibarr's hook and trigger system. Ensure any new triggers or hooks are correctly registered and implemented as per Dolibarr's core mechanisms.
*   **Language Files:** All user-facing strings should be translatable and added to the language files located in `langs/`. Remember to update both `en_US` and `fr_FR` (and any other supported languages).
*   **Error Handling:** Use `dol_syslog` for logging errors and important events. Provide clear error messages.
*   **Dependencies:** Clearly define any dependencies on other modules or specific Dolibarr versions in the module descriptor (`core/modules/modAutoEmailShipment.class.php`).
*   **Database Changes:** If database schema changes are needed (not applicable for the current version of this module), ensure they are handled correctly in the `init` and `remove` methods of the module descriptor, and that data migration considerations are addressed if necessary.
*   **Security:** Sanitize all inputs and outputs, especially when dealing with file paths, email content, and external data. Use Dolibarr's helper functions where available (e.g., `dol_sanitizeFileName`).
*   **User Experience:** Ensure that any configuration options or user interactions are clear and follow Dolibarr's UI/UX patterns.

## Testing:

*   Thoroughly test the module's functionality after any changes.
*   Specifically test:
    *   Shipment validation trigger.
    *   PDF generation for different shipment types/contents.
    *   Email sending to various recipients.
    *   Correct attachment of the PDF.
    *   Error logging in `dolibarr.log`.
    *   Module activation and deactivation.
    *   Translations in different languages.

This `AGENTS.md` is a living document and should be updated as the module evolves.
