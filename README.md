# Contribution Order Xero Report

Admin report plugin that links WooCommerce orders, CiviCRM contributions, and Xero sync status.

It adds a report screen under `WooCommerce -> Contribution Xero Report` with filtering, CSV export, and queue actions for Xero updates.

## Required Dependencies

### WordPress Plugins
- `WooCommerce` (report menu is attached under WooCommerce and order data is read from WooCommerce posts/meta)
- `CiviCRM` for WordPress (uses `civicrm_api` and Civi entities)
- `wpcv-woo-civi-integration` (provides `WPCV_WCI()` bootstrap path and Woo/Civi contribution link meta)

### CiviCRM Extensions
- `nz.co.fuzion.civixero` (uses `AccountInvoice` entity with `plugin = xero` for sync status and queueing)

## Notes
- This plugin is an internal admin report and expects the dependencies above to already be configured and connected.
- Without these dependencies active, the report will load but return empty/error data for missing systems.
