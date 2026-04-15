<?php

defined('ABSPATH') || exit;

class CORX_Report_Query {

  /**
   * WooCommerce order meta: contribution source label (WPCV_Woo_Civi_Source::$meta_key).
   */
  private const ORDER_SOURCE_META_KEY = '_order_source';
  private const ORDER_CREATED_VIA_META_KEY = '_created_via';
  private const MAX_FILTER_LOOKUP_RESULTS = 5000;

  private $wpdb;

  public function __construct() {
    global $wpdb;
    $this->wpdb = $wpdb;
  }

  /**
   * Set accounts_needs_update on AccountInvoice rows (queues them for the Xero sync job).
   *
   * @param array $accountInvoiceRowIds AccountInvoice.id values (not contribution IDs).
   * @return array{ok: int, fail: int, errors: string[]}
   */
  public function queueAccountInvoicesForUpdate(array $accountInvoiceRowIds): array {
    if (!$this->bootstrapCivi()) {
      return [
        'ok' => 0,
        'fail' => count($accountInvoiceRowIds),
        'errors' => ['CiviCRM could not be bootstrapped.'],
      ];
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $accountInvoiceRowIds))));
    $ok = 0;
    $fail = 0;
    $errors = [];

    foreach ($ids as $id) {
      if ($id <= 0) {
        continue;
      }
      $result = $this->api('AccountInvoice', 'create', [
        'version' => 3,
        'id' => $id,
        'accounts_needs_update' => 1,
      ]);
      if (!empty($result['is_error'])) {
        $fail++;
        $errors[] = 'AccountInvoice ' . $id . ': ' . (string) ($result['error_message'] ?? 'API error');
      } else {
        $ok++;
      }
    }

    return ['ok' => $ok, 'fail' => $fail, 'errors' => $errors];
  }

  public function getRows(array $filters, array $sorting, int $perPage, int $pageNum): array {
    if (!$this->bootstrapCivi()) {
      return ['rows' => [], 'total' => 0];
    }

    $offset = max(0, ($pageNum - 1) * $perPage);
    $baseParams = $this->buildContributionParams($filters);
    $baseParams['options'] = [
      'sort' => $this->buildSort($sorting),
      'offset' => $offset,
      'limit' => $perPage,
    ];

    $countParams = $this->buildContributionParams($filters);
    $countResult = $this->api('Contribution', 'getcount', $countParams);
    $count = $this->extractCount($countResult);

    $contributionResult = $this->api('Contribution', 'get', $baseParams);
    if (!empty($contributionResult['is_error']) || empty($contributionResult['values'])) {
      return ['rows' => [], 'total' => $count];
    }

    $contributions = array_values($contributionResult['values']);
    $contributionIDs = array_values(array_unique(array_map(static function ($row) {
      return (int) ($row['id'] ?? 0);
    }, $contributions)));
    $contactIDs = array_values(array_unique(array_map(static function ($row) {
      return (int) ($row['contact_id'] ?? 0);
    }, $contributions)));

    $statusLabels = $this->getContributionStatusLabels();
    $contactMap = $this->getContactMap($contactIDs);
    $invoiceMap = $this->getAccountInvoiceMap($contributionIDs);

    $rows = [];
    foreach ($contributions as $contribution) {
      $contributionID = (int) ($contribution['id'] ?? 0);
      $contactID = (int) ($contribution['contact_id'] ?? 0);
      $contact = $contactMap[$contactID] ?? ['display_name' => '', 'email' => ''];
      $invoice = $invoiceMap[$contributionID] ?? [];
      $statusID = (int) ($contribution['contribution_status_id'] ?? 0);

      $rows[] = [
        'contribution_id' => $contributionID,
        'contact_id' => $contactID,
        'receive_date' => (string) ($contribution['receive_date'] ?? ''),
        'total_amount' => (float) ($contribution['total_amount'] ?? 0),
        'currency' => (string) ($contribution['currency'] ?? ''),
        'invoice_id' => (string) ($contribution['invoice_id'] ?? ''),
        'contribution_status_id' => $statusID,
        'contribution_status' => (string) ($statusLabels[$statusID] ?? 'Unknown'),
        'display_name' => (string) ($contact['display_name'] ?? ''),
        'email' => (string) ($contact['email'] ?? ''),
        'account_invoice_row_id' => (int) ($invoice['id'] ?? 0),
        'accounts_invoice_id' => (string) ($invoice['accounts_invoice_id'] ?? ''),
        'accounts_needs_update' => (int) ($invoice['accounts_needs_update'] ?? 0),
        'error_data' => (string) ($invoice['error_data'] ?? ''),
        'accounts_status_id' => (int) ($invoice['accounts_status_id'] ?? 0),
        'last_sync_date' => (string) ($invoice['last_sync_date'] ?? ''),
      ];
    }

    $rows = $this->attachWooOrderData($rows);
    $normalized = array_map([$this, 'normalizeRow'], $rows);
    $normalized = $this->fillOrderSource($normalized);
    return [
      'rows' => $normalized,
      'total' => $count,
    ];
  }

  /**
   * Build report rows for specific AccountInvoice row IDs (same enrichment as getRows).
   * Order matches $accountInvoiceRowIds (skips unknown IDs).
   *
   * @param array<int, int> $accountInvoiceRowIds
   * @return array<int, array<string, mixed>>
   */
  public function getRowsForAccountInvoiceIds(array $accountInvoiceRowIds): array {
    if (!$this->bootstrapCivi()) {
      return [];
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $accountInvoiceRowIds))));
    if (empty($ids)) {
      return [];
    }

    $invResult = $this->api('AccountInvoice', 'get', [
      'version' => 3,
      'sequential' => 1,
      'plugin' => 'xero',
      'id' => ['IN' => $ids],
      'return' => ['id', 'contribution_id', 'accounts_invoice_id', 'accounts_needs_update', 'error_data', 'accounts_status_id', 'last_sync_date'],
      'options' => ['limit' => 0],
    ]);
    if (!empty($invResult['is_error']) || empty($invResult['values'])) {
      return [];
    }

    $invoiceById = [];
    foreach ($invResult['values'] as $inv) {
      $aid = (int) ($inv['id'] ?? 0);
      if ($aid > 0) {
        $invoiceById[$aid] = $inv;
      }
    }

    $contributionIds = [];
    foreach ($ids as $aid) {
      if (!empty($invoiceById[$aid]['contribution_id'])) {
        $contributionIds[] = (int) $invoiceById[$aid]['contribution_id'];
      }
    }
    $contributionIds = array_values(array_unique(array_filter($contributionIds)));
    if (empty($contributionIds)) {
      return [];
    }

    $contribResult = $this->api('Contribution', 'get', [
      'version' => 3,
      'sequential' => 1,
      'id' => ['IN' => $contributionIds],
      'return' => ['id', 'contact_id', 'receive_date', 'total_amount', 'currency', 'invoice_id', 'contribution_status_id'],
      'options' => ['limit' => 0],
    ]);
    if (!empty($contribResult['is_error']) || empty($contribResult['values'])) {
      return [];
    }

    $contribById = [];
    foreach ($contribResult['values'] as $c) {
      $cid = (int) ($c['id'] ?? 0);
      if ($cid > 0) {
        $contribById[$cid] = $c;
      }
    }

    $contactIDs = array_values(array_unique(array_filter(array_map(static function ($row) {
      return (int) ($row['contact_id'] ?? 0);
    }, $contribResult['values']))));

    $statusLabels = $this->getContributionStatusLabels();
    $contactMap = $this->getContactMap($contactIDs);

    $rows = [];
    foreach ($ids as $aid) {
      $invoice = $invoiceById[$aid] ?? null;
      if ($invoice === null) {
        continue;
      }
      $contributionID = (int) ($invoice['contribution_id'] ?? 0);
      $contribution = $contribById[$contributionID] ?? null;
      if ($contribution === null) {
        continue;
      }
      $contactID = (int) ($contribution['contact_id'] ?? 0);
      $contact = $contactMap[$contactID] ?? ['display_name' => '', 'email' => ''];
      $statusID = (int) ($contribution['contribution_status_id'] ?? 0);

      $rows[] = [
        'contribution_id' => $contributionID,
        'contact_id' => $contactID,
        'receive_date' => (string) ($contribution['receive_date'] ?? ''),
        'total_amount' => (float) ($contribution['total_amount'] ?? 0),
        'currency' => (string) ($contribution['currency'] ?? ''),
        'invoice_id' => (string) ($contribution['invoice_id'] ?? ''),
        'contribution_status_id' => $statusID,
        'contribution_status' => (string) ($statusLabels[$statusID] ?? 'Unknown'),
        'display_name' => (string) ($contact['display_name'] ?? ''),
        'email' => (string) ($contact['email'] ?? ''),
        'account_invoice_row_id' => (int) ($invoice['id'] ?? 0),
        'accounts_invoice_id' => (string) ($invoice['accounts_invoice_id'] ?? ''),
        'accounts_needs_update' => (int) ($invoice['accounts_needs_update'] ?? 0),
        'error_data' => (string) ($invoice['error_data'] ?? ''),
        'accounts_status_id' => (int) ($invoice['accounts_status_id'] ?? 0),
        'last_sync_date' => (string) ($invoice['last_sync_date'] ?? ''),
      ];
    }

    $rows = $this->attachWooOrderData($rows);
    $normalized = array_map([$this, 'normalizeRow'], $rows);
    return $this->fillOrderSource($normalized);
  }

  /**
   * Distinct non-empty order source values for filter dropdowns.
   *
   * @return string[]
   */
  public function getDistinctOrderSources(): array {
    $sql = "
      SELECT DISTINCT meta_key, meta_value
      FROM {$this->wpdb->postmeta}
      WHERE meta_key IN (%s, %s)
      AND meta_value <> ''
      LIMIT %d
    ";
    $prepared = $this->wpdb->prepare($sql, self::ORDER_SOURCE_META_KEY, self::ORDER_CREATED_VIA_META_KEY, self::MAX_FILTER_LOOKUP_RESULTS);
    $results = $this->wpdb->get_results($prepared, ARRAY_A);
    if (!is_array($results)) {
      return [];
    }
    $out = [];
    foreach ($results as $row) {
      $key = (string) ($row['meta_key'] ?? '');
      $val = trim((string) ($row['meta_value'] ?? ''));
      if ($val === '') {
        continue;
      }
      if ($key === self::ORDER_SOURCE_META_KEY && strtolower($val) !== 'shop') {
        $out[$val] = true;
        continue;
      }
      if ($key === self::ORDER_CREATED_VIA_META_KEY) {
        $mapped = $this->normalizeCreatedViaToSource($val);
        if ($mapped !== '') {
          $out[$mapped] = true;
        }
      }
    }
    if (empty($out)) {
      $out['Shop'] = true;
    }
    $out = array_keys($out);
    natcasesort($out);
    $out = array_values($out);
    return $out;
  }

  private function buildContributionParams(array $filters): array {
    $params = [
      'version' => 3,
      'sequential' => 1,
      'return' => ['id', 'contact_id', 'receive_date', 'total_amount', 'currency', 'invoice_id', 'contribution_status_id'],
    ];
    if (!empty($filters['date_from'])) {
      $params['receive_date'] = ['>=' => $filters['date_from'] . ' 00:00:00'];
    }
    if (!empty($filters['date_to'])) {
      $params['receive_date']['<='] = $filters['date_to'] . ' 23:59:59';
    }
    if ($filters['min_amount'] !== '') {
      $params['total_amount'] = ['>=' => (float) $filters['min_amount']];
    }

    $contactIDs = $this->resolveContactIds($filters['contact_search'] ?? '');
    if ($contactIDs !== null) {
      if (empty($contactIDs)) {
        $params['id'] = -1;
        return $params;
      }
      $params = $this->applyContributionIdConstraint($params, $this->resolveContributionIdsByContacts($contactIDs));
    }

    $contributionIDs = $this->resolveAccountInvoiceFilteredContributionIds($filters);
    if ($contributionIDs !== null) {
      if (empty($contributionIDs)) {
        $params['id'] = -1;
        return $params;
      }
      $params = $this->applyContributionIdConstraint($params, $contributionIDs);
    }

    $orderSource = trim((string) ($filters['order_source'] ?? ''));
    if ($orderSource !== '') {
      $sourceContributionIds = $this->resolveContributionIdsByOrderSource($orderSource);
      if (empty($sourceContributionIds)) {
        $params['id'] = -1;
        return $params;
      }
      $params = $this->applyContributionIdConstraint($params, $sourceContributionIds);
    }

    return $params;
  }

  /**
   * Contribution IDs whose linked Woo order has the given _order_source meta.
   *
   * @return int[]
   */
  private function resolveContributionIdsByOrderSource(string $source): array {
    $source = trim($source);
    if ($source === '') {
      return [];
    }
    $sql = "
      SELECT DISTINCT pm_civ.meta_value AS contribution_id
      FROM {$this->wpdb->postmeta} pm_civ
      LEFT JOIN {$this->wpdb->postmeta} pm_src
        ON pm_src.post_id = pm_civ.post_id
        AND pm_src.meta_key = %s
      LEFT JOIN {$this->wpdb->postmeta} pm_created
        ON pm_created.post_id = pm_civ.post_id
        AND pm_created.meta_key = %s
      WHERE pm_civ.meta_key = '_woocommerce_civicrm_contribution_id'
      AND (
        pm_src.meta_value = %s
        OR (
          %s = 'Direct'
          AND pm_created.meta_value = 'checkout'
        )
        OR (
          %s = 'Web admin'
          AND pm_created.meta_value = 'admin'
        )
      )
    ";
    $prepared = $this->wpdb->prepare(
      $sql,
      self::ORDER_SOURCE_META_KEY,
      self::ORDER_CREATED_VIA_META_KEY,
      $source,
      $source,
      $source
    );
    $col = $this->wpdb->get_col($prepared);
    if (!is_array($col)) {
      return [];
    }
    $ids = [];
    foreach ($col as $v) {
      $ids[] = (int) $v;
    }
    return array_values(array_unique(array_filter($ids)));
  }

  private function resolveContributionIdsByContacts(array $contactIDs): array {
    if (empty($contactIDs)) {
      return [];
    }
    $result = $this->api('Contribution', 'get', [
      'version' => 3,
      'sequential' => 1,
      'contact_id' => ['IN' => $contactIDs],
      'return' => ['id'],
      'options' => ['limit' => self::MAX_FILTER_LOOKUP_RESULTS],
    ]);
    if (!empty($result['is_error']) || empty($result['values'])) {
      return [];
    }
    $ids = [];
    foreach ($result['values'] as $row) {
      $ids[] = (int) ($row['id'] ?? 0);
    }
    return array_values(array_unique(array_filter($ids)));
  }

  private function applyContributionIdConstraint(array $params, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids)) {
      $params['id'] = -1;
      return $params;
    }

    if (!isset($params['id'])) {
      $params['id'] = ['IN' => $ids];
      return $params;
    }

    if (is_array($params['id']) && isset($params['id']['IN']) && is_array($params['id']['IN'])) {
      $intersection = array_values(array_intersect($params['id']['IN'], $ids));
      $params['id'] = empty($intersection) ? -1 : ['IN' => $intersection];
      return $params;
    }

    if (is_numeric($params['id'])) {
      $single = (int) $params['id'];
      $params['id'] = in_array($single, $ids, true) ? $single : -1;
      return $params;
    }

    $params['id'] = ['IN' => $ids];
    return $params;
  }

  private function extractCount($countResult): int {
    if (is_numeric($countResult)) {
      return (int) $countResult;
    }
    if (is_array($countResult)) {
      if (isset($countResult['is_error']) && (int) $countResult['is_error'] === 1) {
        return 0;
      }
      if (isset($countResult['count']) && is_numeric($countResult['count'])) {
        return (int) $countResult['count'];
      }
      if (isset($countResult['values']) && is_numeric($countResult['values'])) {
        return (int) $countResult['values'];
      }
    }
    return 0;
  }

  private function buildSort(array $sorting): string {
    $order = strtoupper((string) ($sorting['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
    $orderby = (string) ($sorting['orderby'] ?? 'receive_date');
    return $orderby === 'contribution_id' ? ('id ' . $order) : ('receive_date ' . $order);
  }

  private function resolveContactIds(string $searchTerm): ?array {
    $searchTerm = trim($searchTerm);
    if ($searchTerm === '') {
      return null;
    }

    $ids = [];
    $displayResult = $this->api('Contact', 'get', [
      'version' => 3,
      'sequential' => 1,
      'return' => ['id'],
      'display_name' => ['LIKE' => '%' . $searchTerm . '%'],
      'options' => ['limit' => self::MAX_FILTER_LOOKUP_RESULTS],
    ]);
    if (empty($displayResult['is_error']) && !empty($displayResult['values'])) {
      foreach ($displayResult['values'] as $row) {
        $ids[] = (int) $row['id'];
      }
    }

    $emailResult = $this->api('Email', 'get', [
      'version' => 3,
      'sequential' => 1,
      'return' => ['contact_id'],
      'email' => ['LIKE' => '%' . $searchTerm . '%'],
      'options' => ['limit' => self::MAX_FILTER_LOOKUP_RESULTS],
    ]);
    if (empty($emailResult['is_error']) && !empty($emailResult['values'])) {
      foreach ($emailResult['values'] as $row) {
        $ids[] = (int) $row['contact_id'];
      }
    }

    return array_values(array_unique(array_filter($ids)));
  }

  private function resolveAccountInvoiceFilteredContributionIds(array $filters): ?array {
    $filterByPresence = !empty($filters['present_in_account_invoice']);
    $filterByNeedsUpdate = in_array((string) ($filters['needs_update'] ?? ''), ['0', '1'], true);
    $filterSyncDate = trim((string) ($filters['sync_date'] ?? ''));
    $filterBySyncDate = $filterSyncDate !== '';
    if (!$filterByPresence && !$filterByNeedsUpdate && !$filterBySyncDate) {
      return null;
    }

    if ($filterBySyncDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterSyncDate)) {
      return [];
    }

    // Use DAO SQL instead of AccountInvoice API3 date filters: range operators on
    // last_sync_date are not applied reliably for this entity in all Civi versions.
    if (!class_exists('CRM_Core_DAO')) {
      return [];
    }

    $sql = 'SELECT DISTINCT contribution_id FROM civicrm_account_invoice WHERE plugin = %1 AND contribution_id IS NOT NULL';
    $daoParams = [1 => ['xero', 'String']];
    $idx = 2;
    if ($filterByNeedsUpdate) {
      $sql .= ' AND accounts_needs_update = %' . $idx;
      $daoParams[$idx] = [(int) $filters['needs_update'], 'Integer'];
      $idx++;
    }
    if ($filterBySyncDate) {
      $sql .= ' AND DATE(last_sync_date) = %' . $idx;
      $daoParams[$idx] = [$filterSyncDate, 'String'];
    }

    $dao = \CRM_Core_DAO::executeQuery($sql, $daoParams);
    $ids = [];
    while ($dao->fetch()) {
      $cid = (int) ($dao->contribution_id ?? 0);
      if ($cid > 0) {
        $ids[] = $cid;
      }
    }

    return array_values(array_unique($ids));
  }

  private function getContactMap(array $contactIDs): array {
    if (empty($contactIDs)) {
      return [];
    }
    $result = $this->api('Contact', 'get', [
      'version' => 3,
      'sequential' => 1,
      'id' => ['IN' => $contactIDs],
      'return' => ['id', 'display_name', 'email'],
      'options' => ['limit' => 0],
    ]);
    if (!empty($result['is_error']) || empty($result['values'])) {
      return [];
    }

    $map = [];
    foreach ($result['values'] as $row) {
      $map[(int) $row['id']] = [
        'display_name' => (string) ($row['display_name'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
      ];
    }
    return $map;
  }

  private function getContributionStatusLabels(): array {
    $result = $this->api('Contribution', 'getoptions', [
      'version' => 3,
      'field' => 'contribution_status_id',
    ]);
    if (!empty($result['is_error']) || empty($result['values'])) {
      return [];
    }
    return $result['values'];
  }

  private function getAccountInvoiceMap(array $contributionIDs): array {
    if (empty($contributionIDs)) {
      return [];
    }

    $result = $this->api('AccountInvoice', 'get', [
      'version' => 3,
      'sequential' => 1,
      'plugin' => 'xero',
      'contribution_id' => ['IN' => $contributionIDs],
      'return' => ['id', 'contribution_id', 'accounts_invoice_id', 'accounts_needs_update', 'error_data', 'accounts_status_id', 'last_sync_date'],
      'options' => ['limit' => 0, 'sort' => 'id DESC'],
    ]);
    if (!empty($result['is_error']) || empty($result['values'])) {
      return [];
    }

    $map = [];
    foreach ($result['values'] as $row) {
      $cid = (int) ($row['contribution_id'] ?? 0);
      if ($cid <= 0 || isset($map[$cid])) {
        continue;
      }
      $map[$cid] = $row;
    }
    return $map;
  }

  private function bootstrapCivi(): bool {
    if (function_exists('WPCV_WCI')) {
      $wpcv = call_user_func('WPCV_WCI');
      if (is_object($wpcv) && method_exists($wpcv, 'boot_civi')) {
        return (bool) $wpcv->boot_civi();
      }
    }
    if (!defined('CIVICRM_UF') && function_exists('civicrm_initialize')) {
      call_user_func('civicrm_initialize');
    }
    return function_exists('civicrm_api');
  }

  private function api(string $entity, string $action, array $params) {
    try {
      return call_user_func('civicrm_api', $entity, $action, $params);
    } catch (\Throwable $e) {
      return ['is_error' => 1, 'error_message' => $e->getMessage(), 'values' => []];
    }
  }

  private function attachWooOrderData(array $rows): array {
    if (empty($rows)) {
      return $rows;
    }

    $contributionIDs = array_values(array_unique(array_map(static function ($row) {
      return (int) $row['contribution_id'];
    }, $rows)));

    if (empty($contributionIDs)) {
      return $rows;
    }

    $placeholders = implode(',', array_fill(0, count($contributionIDs), '%s'));
    $sql = "
      SELECT pm.meta_value AS contribution_id, pm.post_id AS order_id, p.post_status
      FROM {$this->wpdb->postmeta} pm
      LEFT JOIN {$this->wpdb->posts} p ON p.ID = pm.post_id
      WHERE pm.meta_key = '_woocommerce_civicrm_contribution_id'
      AND pm.meta_value IN ({$placeholders})
    ";
    $prepared = $this->wpdb->prepare($sql, array_map('strval', $contributionIDs));
    $mappings = $this->wpdb->get_results($prepared, ARRAY_A);

    $orderMap = [];
    foreach ($mappings as $mapping) {
      $orderMap[(int) $mapping['contribution_id']] = [
        'order_id_from_meta' => (int) $mapping['order_id'],
        'order_status' => (string) $mapping['post_status'],
      ];
    }

    foreach ($rows as &$row) {
      $cid = (int) $row['contribution_id'];
      $row['order_id_from_meta'] = $orderMap[$cid]['order_id_from_meta'] ?? 0;
      $row['order_status'] = $orderMap[$cid]['order_status'] ?? '';
    }
    
    unset($row);

    return $rows;
  }

  private function normalizeRow(array $row): array {
    $orderID = (int) ($row['order_id_from_meta'] ?? 0);

    if ($orderID <= 0 && !empty($row['invoice_id']) && preg_match('/^(\d+)_woocommerce$/', (string) $row['invoice_id'], $matches)) {
      $orderID = (int) $matches[1];
    }

    if ($orderID > 0 && empty($row['order_status'])) {
      $status = get_post_status($orderID);
      $row['order_status'] = $status ?: '';
    }

    $syncState = 'not_synced';
    $syncMessage = 'Not synced';
    $xeroID = (string) ($row['accounts_invoice_id'] ?? '');
    $hasAI = !empty($row['account_invoice_row_id']);
    $hasError = !empty($row['error_data']);
    $needsUpdate = !empty($row['accounts_needs_update']);

    if ($xeroID !== '') {
      $syncState = 'synced';
      $syncMessage = 'Synced';
    } elseif ($hasError) {
      $syncState = 'error';
      $syncMessage = 'Error';
    } elseif ($hasAI && $needsUpdate) {
      $syncState = 'needs_update';
      $syncMessage = 'Update queued';
    } elseif (!$hasAI) {
      $syncState = 'no_account_invoice';
      $syncMessage = 'No account_invoice row';
    }

    $row['order_id'] = $orderID;
    $row['order_status'] = $this->formatOrderStatus((string) ($row['order_status'] ?? ''));
    $row['sync_state'] = $syncState;
    $row['sync_message'] = $syncMessage;
    return $row;
  }

  private function formatOrderStatus(string $status): string {
    if ($status === '') {
      return '';
    }
    $status = str_replace('wc-', '', $status);
    return ucwords(str_replace('-', ' ', $status));
  }

  /**
   * Attach _order_source from postmeta for each resolved order_id.
   *
   * @param array<int, array<string, mixed>> $rows
   * @return array<int, array<string, mixed>>
   */
  private function fillOrderSource(array $rows): array {
    $orderIds = [];
    foreach ($rows as $row) {
      $oid = (int) ($row['order_id'] ?? 0);
      if ($oid > 0) {
        $orderIds[$oid] = true;
      }
    }
    $orderIds = array_keys($orderIds);
    if (empty($orderIds)) {
      foreach ($rows as $i => $row) {
        $rows[$i]['order_source'] = '';
      }
      return $rows;
    }

    $placeholders = implode(',', array_fill(0, count($orderIds), '%d'));
    $sql = "
      SELECT post_id, meta_key, meta_value
      FROM {$this->wpdb->postmeta}
      WHERE meta_key IN (%s, %s)
      AND post_id IN ({$placeholders})
    ";
    $args = array_merge([self::ORDER_SOURCE_META_KEY, self::ORDER_CREATED_VIA_META_KEY], $orderIds);
    $prepared = $this->wpdb->prepare($sql, $args);
    $results = $this->wpdb->get_results($prepared, ARRAY_A);
    $byOrderSource = [];
    $byOrderCreatedVia = [];
    if (is_array($results)) {
      foreach ($results as $r) {
        $pid = (int) ($r['post_id'] ?? 0);
        if ($pid <= 0) {
          continue;
        }
        $metaKey = (string) ($r['meta_key'] ?? '');
        $metaVal = (string) ($r['meta_value'] ?? '');
        if ($metaKey === self::ORDER_SOURCE_META_KEY && !isset($byOrderSource[$pid])) {
          $byOrderSource[$pid] = $metaVal;
        } elseif ($metaKey === self::ORDER_CREATED_VIA_META_KEY && !isset($byOrderCreatedVia[$pid])) {
          $byOrderCreatedVia[$pid] = $metaVal;
        }
      }
    }

    foreach ($rows as $i => $row) {
      $oid = (int) ($row['order_id'] ?? 0);
      if ($oid <= 0) {
        $rows[$i]['order_source'] = '';
        continue;
      }
      $source = trim((string) ($byOrderSource[$oid] ?? ''));
      if ($source === '' || strtolower($source) === 'shop') {
        $source = $this->normalizeCreatedViaToSource((string) ($byOrderCreatedVia[$oid] ?? ''));
      }
      if ($source === '') {
        $source = 'Shop';
      }
      $rows[$i]['order_source'] = $source;
    }
    return $rows;
  }

  private function normalizeCreatedViaToSource(string $createdVia): string {
    $createdVia = trim(strtolower($createdVia));
    if ($createdVia === 'checkout') {
      return 'Direct';
    }
    if ($createdVia === 'admin') {
      return 'Web admin';
    }
    if ($createdVia === '') {
      return '';
    }
    return ucwords(str_replace(['-', '_'], ' ', $createdVia));
  }
}
