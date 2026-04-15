<?php

defined('ABSPATH') || exit;

class CORX_Link_Builder {

  public static function orderLink(int $orderID): string {
    if ($orderID <= 0) {
      return '';
    }
    return admin_url('post.php?post=' . $orderID . '&action=edit');
  }

  public static function contactLink(int $contactID): string {
    if ($contactID <= 0) {
      return '';
    }
    return admin_url('admin.php?page=CiviCRM&q=civicrm/contact/view&reset=1&cid=' . $contactID);
  }

  public static function contributionLink(int $contributionID, int $contactID): string {
    if ($contributionID <= 0) {
      return '';
    }

    if ($contactID > 0) {
      return admin_url('admin.php?page=CiviCRM&q=civicrm/contact/view/contribution&reset=1&id=' . $contributionID . '&cid=' . $contactID . '&action=view');
    }

    return admin_url('admin.php?page=CiviCRM&q=civicrm/contribute/search&reset=1');
  }

  public static function xeroInvoiceLink(string $invoiceID): string {
    $invoiceID = trim($invoiceID);
    if ($invoiceID === '') {
      return '';
    }
    return 'https://go.xero.com/app/invoicing/view/' . rawurlencode($invoiceID);
  }
}
