<?php

defined('ABSPATH') || exit;

class CORX_Report_Page {

  public const PAGE_SLUG = 'contribution-order-xero-report';

  public static function init(): void {
    add_action('admin_init', [self::class, 'maybeStreamCsvExports'], 1);
    add_action('admin_menu', [self::class, 'registerMenu'], 100);
    add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    add_action('admin_notices', [self::class, 'renderAdminNotices']);
  }

  public static function registerMenu(): void {
    if (!current_user_can('manage_options')) {
      return;
    }

    add_submenu_page(
      'woocommerce',
      'Contribution Order Xero Report',
      'Contribution Xero Report',
      'manage_options',
      self::PAGE_SLUG,
      [self::class, 'renderPage']
    );
  }

  public static function enqueueAssets(string $hook): void {
    if (strpos($hook, self::PAGE_SLUG) === false) {
      return;
    }
    wp_enqueue_style(
      'corx-report-admin',
      CORX_REPORT_URL . 'assets/admin.css',
      [],
      CORX_REPORT_VERSION
    );
  }

  public static function renderPage(): void {
    if (!current_user_can('manage_options')) {
      wp_die('Insufficient permissions');
    }

    self::handleQueueInvoiceRequest();

    $filters = self::readFilters();
    $query = new CORX_Report_Query();
    $orderSources = $query->getDistinctOrderSources();
    $table = new CORX_Report_Table($query, $filters);
    $table->prepare_items();
    ?>
    <div class="wrap">
      <h1>Contribution Order Xero Report</h1>
      <form method="get" class="corx-filter-form">
        <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
        <input type="hidden" name="corx_filtered" value="1">
        <div class="corx-filter-row">
          <label>Date from
            <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>">
          </label>
          <label>Date to
            <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>">
          </label>
          <label>Amount greater than
            <input type="number" step="0.01" name="min_amount" value="<?php echo esc_attr($filters['min_amount']); ?>">
          </label>
          <label>Civi contact
            <input type="text" name="contact_search" placeholder="Email or display name" value="<?php echo esc_attr($filters['contact_search']); ?>">
          </label>
        </div>
        <div class="corx-filter-row">
          <label>
            <input type="checkbox" name="present_in_account_invoice" value="1" <?php checked(!empty($filters['present_in_account_invoice'])); ?>>
            Present in account_invoice
          </label>
          <label>Sync date
            <input type="date" name="sync_date" value="<?php echo esc_attr($filters['sync_date']); ?>">
          </label>
          <label>Accounts Update queued
            <select name="needs_update">
              <option value="" <?php selected($filters['needs_update'], ''); ?>>All</option>
              <option value="1" <?php selected($filters['needs_update'], '1'); ?>>Update queued</option>
              <option value="0" <?php selected($filters['needs_update'], '0'); ?>>Up to date</option>
            </select>
          </label>
          <label>Order source
            <select name="order_source">
              <option value=""><?php esc_html_e('All sources', 'contribution-order-xero-report'); ?></option>
              <?php foreach ($orderSources as $src) : ?>
                <option value="<?php echo esc_attr($src); ?>" <?php selected($filters['order_source'], $src); ?>><?php echo esc_html($src); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button class="button button-primary" type="submit">Filter</button>
          <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>">Reset</a>
        </div>
      </form>
      <form id="corx-report-list-form" method="get" class="corx-list-table-form">
        <?php self::renderListFormPreservedFields($filters); ?>
        <?php wp_nonce_field('corx_export_page', 'corx_export_nonce'); ?>
        <p class="corx-export-actions">
          <button type="submit" name="corx_export_page" value="1" class="button"><?php esc_html_e('Export CSV (current page)', 'contribution-order-xero-report'); ?></button>
          <button type="submit" name="corx_export_all" value="1" class="button"><?php esc_html_e('Export CSV (all matching rows)', 'contribution-order-xero-report'); ?></button>
        </p>
        <?php $table->display(); ?>
      </form>
    </div>
    <?php
  }

  /**
   * Hidden fields so bulk actions / pagination keep the same filter context as the current report view.
   */
  private static function renderListFormPreservedFields(array $filters): void {
    echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '">';
    if (!empty($_GET['corx_filtered'])) {
      echo '<input type="hidden" name="corx_filtered" value="1">';
    }
    $textKeys = ['date_from', 'date_to', 'min_amount', 'contact_search', 'needs_update', 'order_source', 'sync_date'];
    foreach ($textKeys as $key) {
      $val = (string) ($filters[$key] ?? '');
      if ($val !== '') {
        echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '">';
      }
    }
    if (!empty($filters['present_in_account_invoice'])) {
      echo '<input type="hidden" name="present_in_account_invoice" value="1">';
    }
    if (!empty($_GET['orderby'])) {
      echo '<input type="hidden" name="orderby" value="' . esc_attr(sanitize_key($_GET['orderby'])) . '">';
    }
    if (!empty($_GET['order'])) {
      $order = strtoupper(sanitize_key($_GET['order'])) === 'ASC' ? 'ASC' : 'DESC';
      echo '<input type="hidden" name="order" value="' . esc_attr($order) . '">';
    }
    if (!empty($_GET['paged'])) {
      $paged = max(1, (int) $_GET['paged']);
      echo '<input type="hidden" name="paged" value="' . esc_attr((string) $paged) . '">';
    }
  }

  /**
   * Stream CSV before any admin HTML is sent (avoids saving HTML as the "download").
   */
  public static function maybeStreamCsvExports(): void {
    if (!is_admin()) {
      return;
    }
    if (empty($_REQUEST['page']) || sanitize_key(wp_unslash($_REQUEST['page'])) !== self::PAGE_SLUG) {
      return;
    }
    if (!current_user_can('manage_options')) {
      return;
    }

    if (!empty($_REQUEST['corx_export_page']) || !empty($_REQUEST['corx_export_all'])) {
      if (empty($_REQUEST['corx_export_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['corx_export_nonce'])), 'corx_export_page')) {
        return;
      }
      $filters = self::readFilters();
      $orderby = sanitize_key($_REQUEST['orderby'] ?? 'receive_date');
      $order = strtoupper(sanitize_key($_REQUEST['order'] ?? 'DESC'));
      $order = $order === 'ASC' ? 'ASC' : 'DESC';

      $query = new CORX_Report_Query();

      if (!empty($_REQUEST['corx_export_all'])) {
        $batchSize = 500;
        $sorting = ['orderby' => $orderby, 'order' => $order];
        CORX_Report_Csv::streamDownload(
          'contribution-xero-report-all-' . gmdate('Y-m-d-His') . '.csv',
          static function (int $pageNum) use ($query, $filters, $sorting, $batchSize): array {
            $result = $query->getRows($filters, $sorting, $batchSize, $pageNum);
            return $result['rows'] ?? [];
          }
        );
        exit;
      }

      $paged = max(1, (int) ($_REQUEST['paged'] ?? 1));
      $perPage = 50;
      $result = $query->getRows($filters, [
        'orderby' => $orderby,
        'order' => $order,
      ], $perPage, $paged);

      CORX_Report_Csv::sendDownload(
        'contribution-xero-report-page-' . gmdate('Y-m-d-His') . '.csv',
        $result['rows'] ?? []
      );
      exit;
    }

    $bulkAction = '';
    if (isset($_REQUEST['action']) && (string) wp_unslash($_REQUEST['action']) !== '' && (string) wp_unslash($_REQUEST['action']) !== '-1') {
      $bulkAction = sanitize_key(wp_unslash($_REQUEST['action']));
    } elseif (isset($_REQUEST['action2']) && (string) wp_unslash($_REQUEST['action2']) !== '' && (string) wp_unslash($_REQUEST['action2']) !== '-1') {
      $bulkAction = sanitize_key(wp_unslash($_REQUEST['action2']));
    }
    if ($bulkAction !== 'corx_export_csv') {
      return;
    }

    check_admin_referer('bulk-contribution_xero_rows');

    $ids = isset($_REQUEST['contribution_xero_row']) ? array_map('intval', (array) wp_unslash($_REQUEST['contribution_xero_row'])) : [];
    $ids = array_values(array_filter($ids));
    if (empty($ids)) {
      self::redirectWithQueueNotice('bulk_export_none', 0, 0);
    }

    $query = new CORX_Report_Query();
    $rows = $query->getRowsForAccountInvoiceIds($ids);
    CORX_Report_Csv::sendDownload(
      'contribution-xero-report-selected-' . gmdate('Y-m-d-His') . '.csv',
      $rows
    );
    exit;
  }

  /**
   * Queue one AccountInvoice row via GET (nonce + id) so row actions do not require nested forms.
   */
  private static function handleQueueInvoiceRequest(): void {
    if (empty($_GET['page']) || sanitize_key(wp_unslash($_GET['page'])) !== self::PAGE_SLUG) {
      return;
    }
    if (empty($_GET['corx_queue_invoice_id'])) {
      return;
    }
    $id = (int) $_GET['corx_queue_invoice_id'];
    if ($id <= 0) {
      return;
    }
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'corx_queue_invoice_' . $id)) {
      return;
    }

    $query = new CORX_Report_Query();
    $result = $query->queueAccountInvoicesForUpdate([$id]);
    $back = wp_get_referer();
    if (!$back) {
      $back = admin_url('admin.php?page=' . self::PAGE_SLUG);
    }
    $back = remove_query_arg(['corx_queue_invoice_id', '_wpnonce', 'corx_notice', 'corx_n', 'corx_fail', 'corx_err'], $back);
    $args = [
      'corx_notice' => $result['fail'] > 0 ? 'partial' : 'ok',
      'corx_n' => $result['ok'],
      'corx_fail' => $result['fail'],
    ];
    if ($result['fail'] > 0 && !empty($result['errors'][0])) {
      $args['corx_err'] = rawurlencode(wp_strip_all_tags($result['errors'][0]));
    }
    wp_safe_redirect(add_query_arg($args, $back));
    exit;
  }

  /**
   * After bulk queue actions, return to the report with notice query args (strips bulk request keys).
   */
  public static function redirectWithQueueNotice(string $noticeKey, int $ok, int $fail = 0, string $firstError = ''): void {
    $url = wp_get_referer();
    if (!$url) {
      $url = admin_url('admin.php?page=' . self::PAGE_SLUG);
    }
    $strip = [
      'action', 'action2', '_wpnonce', 'contribution_xero_row',
      'corx_notice', 'corx_n', 'corx_fail', 'corx_err',
      'corx_queue_invoice_id',
      'corx_export_page', 'corx_export_all', 'corx_export_nonce',
    ];
    $url = remove_query_arg($strip, $url);
    $args = [
      'corx_notice' => $noticeKey,
      'corx_n' => $ok,
      'corx_fail' => $fail,
    ];
    if ($firstError !== '') {
      $args['corx_err'] = rawurlencode(wp_strip_all_tags($firstError));
    }
    wp_safe_redirect(add_query_arg($args, $url));
    exit;
  }

  public static function renderAdminNotices(): void {
    if (empty($_GET['corx_notice']) || !self::isReportScreen()) {
      return;
    }
    $notice = sanitize_key(wp_unslash($_GET['corx_notice']));
    $ok = (int) ($_GET['corx_n'] ?? 0);
    $fail = (int) ($_GET['corx_fail'] ?? 0);
    $err = isset($_GET['corx_err']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['corx_err']))) : '';
    $class = 'notice-success';
    $msg = '';
    if ($notice === 'ok' && $ok > 0) {
      $msg = sprintf(
        /* translators: %d: number of rows queued */
        _n('Queued %d invoice for Xero update.', 'Queued %d invoices for Xero update.', $ok, 'contribution-order-xero-report'),
        $ok
      );
    } elseif ($notice === 'partial') {
      $class = 'notice-warning';
      $msg = sprintf(
        /* translators: 1: succeeded count, 2: failed count */
        __('Queued %1$d invoice(s); %2$d failed.', 'contribution-order-xero-report'),
        $ok,
        $fail
      );
      if ($err !== '') {
        $msg .= ' ' . $err;
      }
    } elseif ($notice === 'bulk_ok') {
      if ($ok <= 0) {
        return;
      }
      $msg = sprintf(
        /* translators: %d: number of invoices queued */
        __('Queued %d invoice(s) for Xero update.', 'contribution-order-xero-report'),
        $ok
      );
    } elseif ($notice === 'bulk_partial') {
      $class = 'notice-warning';
      $msg = sprintf(
        /* translators: 1: succeeded count, 2: failed count */
        __('Queued %1$d invoice(s); %2$d failed.', 'contribution-order-xero-report'),
        $ok,
        $fail
      );
      if ($err !== '') {
        $msg .= ' ' . $err;
      }
    } elseif ($notice === 'bulk_none') {
      $class = 'notice-info';
      $msg = __('No rows selected, or none had an account invoice row.', 'contribution-order-xero-report');
    } elseif ($notice === 'bulk_export_none') {
      $class = 'notice-info';
      $msg = __('No rows selected for CSV export.', 'contribution-order-xero-report');
    } else {
      return;
    }
    echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
  }

  private static function isReportScreen(): bool {
    if (!is_admin() || empty($_GET['page'])) {
      return false;
    }
    return sanitize_key(wp_unslash($_GET['page'])) === self::PAGE_SLUG;
  }

  private static function readFilters(): array {
    $isFilteredRequest = isset($_GET['corx_filtered']);
    return [
      'date_from' => sanitize_text_field($_GET['date_from'] ?? ''),
      'date_to' => sanitize_text_field($_GET['date_to'] ?? ''),
      'min_amount' => sanitize_text_field($_GET['min_amount'] ?? ''),
      'contact_search' => sanitize_text_field($_GET['contact_search'] ?? ''),
      'needs_update' => sanitize_text_field($_GET['needs_update'] ?? ''),
      'order_source' => sanitize_text_field($_GET['order_source'] ?? ''),
      'sync_date' => sanitize_text_field($_GET['sync_date'] ?? ''),
      'present_in_account_invoice' => $isFilteredRequest ? (isset($_GET['present_in_account_invoice']) ? 1 : 0) : 1,
    ];
  }
}
