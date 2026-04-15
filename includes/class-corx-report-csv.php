<?php

defined('ABSPATH') || exit;

/**
 * CSV download for report rows (plain text, no HTML).
 */
class CORX_Report_Csv {

  /**
   * @param array<int, array<string, mixed>> $rows
   */
  public static function sendDownload(string $filename, array $rows): void {
    $filename = sanitize_file_name($filename);
    if ($filename === '') {
      $filename = 'export.csv';
    }

    while (ob_get_level() > 0) {
      ob_end_clean();
    }

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    // UTF-8 BOM for Excel
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    if ($out === false) {
      return;
    }

    fputcsv($out, self::headers());
    foreach ($rows as $row) {
      fputcsv($out, self::rowToValues($row));
    }
    fclose($out);
  }

  /**
   * @return string[]
   */
  private static function headers(): array {
    return [
      'Contribution ID',
      'Contact ID',
      'Contact email',
      'Contact display name',
      'Order ID',
      'Order source',
      'Receive date',
      'Contribution status',
      'Order status',
      'Amount',
      'Currency',
      'Xero update queued',
      'Last sync',
      'Error',
      'Xero invoice ID',
      'Sync status',
      'AccountInvoice row ID',
    ];
  }

  /**
   * @param array<string, mixed> $row
   * @return string[]
   */
  private static function rowToValues(array $row): array {
    $orderId = (int) ($row['order_id'] ?? 0);
    $amount = number_format((float) ($row['total_amount'] ?? 0), 2, '.', '');
    $currency = (string) ($row['currency'] ?? '');
    $amountCell = trim($currency . ' ' . $amount);

    $aiRow = (int) ($row['account_invoice_row_id'] ?? 0);
    $queued = '';
    if ($aiRow <= 0) {
      $queued = 'N/A';
    } else {
      $queued = !empty($row['accounts_needs_update']) ? 'Yes' : 'No';
    }

    $lastSync = trim((string) ($row['last_sync_date'] ?? ''));
    if ($lastSync === '' || $lastSync === '0000-00-00 00:00:00') {
      $lastSync = '';
    } else {
      $lastSync = substr($lastSync, 0, 19);
    }

    $error = CORX_Report_Table::formatErrorDataForDisplay((string) ($row['error_data'] ?? ''), 2000);

    return [
      (string) ((int) ($row['contribution_id'] ?? 0)),
      (string) ((int) ($row['contact_id'] ?? 0)),
      (string) ($row['email'] ?? ''),
      (string) ($row['display_name'] ?? ''),
      $orderId > 0 ? (string) $orderId : '',
      (string) ($row['order_source'] ?? ''),
      (string) ($row['receive_date'] ?? ''),
      (string) ($row['contribution_status'] ?? ''),
      (string) ($row['order_status'] ?? ''),
      $amountCell,
      $currency,
      $queued,
      $lastSync,
      $error,
      trim((string) ($row['accounts_invoice_id'] ?? '')),
      (string) ($row['sync_message'] ?? ''),
      $aiRow > 0 ? (string) $aiRow : '',
    ];
  }
}
