<?php

defined('ABSPATH') || exit;

class CORX_Report_Table extends WP_List_Table {

  private $query;
  private $filters = [];

  public function __construct(CORX_Report_Query $query, array $filters) {
    parent::__construct([
      'singular' => 'contribution_xero_row',
      'plural' => 'contribution_xero_rows',
      'ajax' => false,
    ]);
    $this->query = $query;
    $this->filters = $filters;
  }

  public function get_columns(): array {
    return [
      'cb' => '<input type="checkbox" />',
      'contribution' => 'Contrib',
      'order_id' => 'Order',
      'order_source' => 'Order source',
      'contact' => 'Civi Contact',
      'receive_date' => 'Created',
      'contribution_status' => 'Contribution Status',
      'order_status' => 'Order Status',
      'amount' => 'Contrib £',
      'needs_update' => 'Xero update',
      'queue_update' => 'Queue',
      'last_sync_date' => 'Last sync',
      'sync_error' => 'Error',
      'xero_invoice' => 'Xero Invoice ID / Status',
    ];
  }

  protected function get_bulk_actions(): array {
    return [
      'corx_queue_update' => __('Queue for Xero update', 'contribution-order-xero-report'),
      'corx_export_csv' => __('Export CSV (selected)', 'contribution-order-xero-report'),
    ];
  }

  public function get_sortable_columns(): array {
    return [
      'contribution' => ['contribution_id', false],
    ];
  }

  protected function process_bulk_action(): void {
    if (!current_user_can('manage_options')) {
      return;
    }
    $action = $this->current_action();
    if ($action !== 'corx_queue_update') {
      return;
    }
    check_admin_referer('bulk-' . $this->_args['plural']);

    $ids = isset($_REQUEST['contribution_xero_row']) ? array_map('intval', (array) wp_unslash($_REQUEST['contribution_xero_row'])) : [];
    $ids = array_values(array_filter($ids));
    if (empty($ids)) {
      CORX_Report_Page::redirectWithQueueNotice('bulk_none', 0, 0);
    }

    $result = $this->query->queueAccountInvoicesForUpdate($ids);
    if ($result['ok'] === 0 && $result['fail'] === 0) {
      CORX_Report_Page::redirectWithQueueNotice('bulk_none', 0, 0);
    }

    $firstErr = (string) ($result['errors'][0] ?? '');
    if ($result['fail'] === 0) {
      CORX_Report_Page::redirectWithQueueNotice('bulk_ok', $result['ok'], 0);
    }
    CORX_Report_Page::redirectWithQueueNotice('bulk_partial', $result['ok'], $result['fail'], $firstErr);
  }

  public function prepare_items(): void {
    $this->process_bulk_action();

    $perPage = 50;
    $currentPage = $this->get_pagenum();
    $orderby = sanitize_key($_GET['orderby'] ?? 'receive_date');
    $order = strtoupper(sanitize_key($_GET['order'] ?? 'DESC'));
    $order = $order === 'ASC' ? 'ASC' : 'DESC';

    $result = $this->query->getRows($this->filters, [
      'orderby' => $orderby,
      'order' => $order,
    ], $perPage, $currentPage);

    // If user is on an out-of-range page after applying filters,
    // fallback to page 1 so rows are visible.
    if (empty($result['rows']) && !empty($result['total']) && $currentPage > 1) {
      $currentPage = 1;
      $result = $this->query->getRows($this->filters, [
        'orderby' => $orderby,
        'order' => $order,
      ], $perPage, $currentPage);
    }

    $columns = $this->get_columns();
    $hidden = [];
    $sortable = $this->get_sortable_columns();
    $primary = 'contribution';
    $this->_column_headers = [$columns, $hidden, $sortable, $primary];

    $this->items = $result['rows'];
    $this->set_pagination_args([
      'total_items' => $result['total'],
      'per_page' => $perPage,
      'total_pages' => (int) ceil($result['total'] / $perPage),
    ]);
  }

  protected function column_default($item, $column_name) {
    return esc_html((string) ($item[$column_name] ?? ''));
  }

  protected function column_cb($item): string {
    $id = (int) ($item['account_invoice_row_id'] ?? 0);
    if ($id <= 0) {
      return '';
    }
    return sprintf(
      '<input type="checkbox" name="%s[]" value="%s" />',
      esc_attr($this->_args['singular']),
      esc_attr((string) $id)
    );
  }

  protected function column_order_id($item): string {
    $orderID = (int) ($item['order_id'] ?? 0);
    if ($orderID <= 0) {
      return '<em>Not found</em>';
    }
    $link = CORX_Link_Builder::orderLink($orderID);
    return '<a href="' . esc_url($link) . '" target="_blank">#' . esc_html((string) $orderID) . '</a>';
  }

  protected function column_order_source($item): string {
    $src = trim((string) ($item['order_source'] ?? ''));
    if ($src === '') {
      return '<em>—</em>';
    }
    return esc_html($src);
  }

  protected function column_contact($item): string {
    $contactID = (int) ($item['contact_id'] ?? 0);
    $email = (string) ($item['email'] ?? '');
    if ($contactID <= 0) {
      return esc_html($email ?: 'Unknown');
    }
    $label = $email !== '' ? $email : ('Contact #' . $contactID);
    return '<a href="' . esc_url(CORX_Link_Builder::contactLink($contactID)) . '" target="_blank">' . esc_html($label) . '</a>';
  }

  protected function column_contribution($item): string {
    $contributionID = (int) ($item['contribution_id'] ?? 0);
    $contactID = (int) ($item['contact_id'] ?? 0);
    if ($contributionID <= 0) {
      return '<em>Unknown</em>';
    }
    return '<a href="' . esc_url(CORX_Link_Builder::contributionLink($contributionID, $contactID)) . '" target="_blank">' . esc_html((string) $contributionID) . '</a>';
  }

  protected function column_contribution_status($item): string {
    return esc_html((string) ($item['contribution_status'] ?? 'Unknown'));
  }

  protected function column_order_status($item): string {
    return esc_html((string) ($item['order_status'] ?: 'Unknown'));
  }

  protected function column_amount($item): string {
    $amount = number_format((float) ($item['total_amount'] ?? 0), 2);
    $currency = (string) ($item['currency'] ?? '');
    return esc_html(trim($currency . ' ' . $amount));
  }

  protected function column_needs_update($item): string {
    if (empty($item['account_invoice_row_id'])) {
      return '<em>N/A</em>';
    }
    return !empty($item['accounts_needs_update']) ? '<span class="corx-badge corx-warning">Update queued</span>' : '<span class="corx-badge corx-ok">Up to date</span>';
  }

  protected function column_queue_update($item): string {
    $id = (int) ($item['account_invoice_row_id'] ?? 0);
    if ($id <= 0) {
      return '<em>—</em>';
    }
    $url = add_query_arg(
      [
        'page' => CORX_Report_Page::PAGE_SLUG,
        'corx_queue_invoice_id' => $id,
        '_wpnonce' => wp_create_nonce('corx_queue_invoice_' . $id),
      ],
      admin_url('admin.php')
    );
    return '<a class="button button-small" href="' . esc_url($url) . '">' . esc_html__('Queue', 'contribution-order-xero-report') . '</a>';
  }

  protected function column_last_sync_date($item): string {
    $raw = trim((string) ($item['last_sync_date'] ?? ''));
    if ($raw === '' || $raw === '0000-00-00 00:00:00') {
      return '<em>—</em>';
    }
    return esc_html(substr($raw, 0, 19));
  }

  protected function column_sync_error($item): string {
    $text = self::formatErrorDataForDisplay((string) ($item['error_data'] ?? ''));
    if ($text === '') {
      return '<em>—</em>';
    }
    return esc_html($text);
  }

  /**
   * Normalize error_data (JSON or plain text) for display with a max length.
   */
  public static function formatErrorDataForDisplay(string $errorData, int $maxLen = 120): string {
    $errorData = trim($errorData);
    if ($errorData === '') {
      return '';
    }
    $decoded = json_decode($errorData, true);
    if (is_array($decoded)) {
      if (!empty($decoded['error']) && is_string($decoded['error'])) {
        $errorData = $decoded['error'];
      } elseif (!empty($decoded['error_message']) && is_string($decoded['error_message'])) {
        $errorData = $decoded['error_message'];
      } else {
        $errorData = wp_json_encode($decoded);
      }
    }
    $errorData = trim((string) $errorData);
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
      if (mb_strlen($errorData) > $maxLen) {
        return rtrim(mb_substr($errorData, 0, $maxLen - 1)) . '…';
      }
      return $errorData;
    }
    if (strlen($errorData) > $maxLen) {
      return rtrim(substr($errorData, 0, $maxLen - 1)) . '…';
    }
    return $errorData;
  }

  protected function column_xero_invoice($item): string {
    $invoiceID = trim((string) ($item['accounts_invoice_id'] ?? ''));
    $state = (string) ($item['sync_state'] ?? 'not_synced');
    $message = (string) ($item['sync_message'] ?? 'Not synced');

    if ($invoiceID !== '') {
      $link = CORX_Link_Builder::xeroInvoiceLink($invoiceID);
      return '<a href="' . esc_url($link) . '" target="_blank">' . esc_html($invoiceID) . '</a><br><span class="corx-badge corx-ok">' . esc_html($message) . '</span>';
    }

    $class = $state === 'error' ? 'corx-error' : 'corx-muted';
    return '<span class="' . esc_attr($class) . '">' . esc_html($message) . '</span>';
  }
}
