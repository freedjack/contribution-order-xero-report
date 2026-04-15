<?php
/**
 * Plugin Name: Contribution Order Xero Report
 * Description: Admin report for WooCommerce orders, CiviCRM contributions, and Xero invoice sync status.
 * Version: 1.0.0
 * Author: Local
 */

defined('ABSPATH') || exit;

define('CORX_REPORT_VERSION', '1.0.0');
define('CORX_REPORT_DIR', plugin_dir_path(__FILE__));
define('CORX_REPORT_URL', plugin_dir_url(__FILE__));

if (!class_exists('WP_List_Table')) {
  require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

require_once CORX_REPORT_DIR . 'includes/class-corx-link-builder.php';
require_once CORX_REPORT_DIR . 'includes/class-corx-report-query.php';
require_once CORX_REPORT_DIR . 'includes/class-corx-report-page.php';
require_once CORX_REPORT_DIR . 'includes/class-corx-report-csv.php';
require_once CORX_REPORT_DIR . 'includes/class-corx-report-table.php';

add_action('plugins_loaded', static function () {
  CORX_Report_Page::init();
});
