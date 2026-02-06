<?php
if (!defined('ABSPATH')) { exit; }

class WCWMW_Stock {
  public static function init() {
    add_filter('woocommerce_add_to_cart_validation', [__CLASS__, 'validate_add_to_cart'], 10, 5);
    add_action('woocommerce_check_cart_items', [__CLASS__, 'validate_cart']);
  }

  public static function table_name() {
    global $wpdb;
    return $wpdb->prefix . 'wcwmw_stock';
  }

  public static function get_qty($warehouse_id, $product_id) {
    global $wpdb;
    $t = self::table_name();
    $qty = $wpdb->get_var($wpdb->prepare("SELECT qty FROM $t WHERE warehouse_id=%d AND product_id=%d", $warehouse_id, $product_id));
    return is_null($qty) ? 0 : (int)$qty;
  }

  public static function set_qty($warehouse_id, $product_id, $qty) {
    global $wpdb;
    $t = self::table_name();
    $warehouse_id = (int)$warehouse_id;
    $product_id = (int)$product_id;
    $qty = (int)$qty;

    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE warehouse_id=%d AND product_id=%d", $warehouse_id, $product_id));
    if ($existing) {
      $wpdb->update($t, ['qty' => $qty], ['id' => (int)$existing], ['%d'], ['%d']);
    } else {
      $wpdb->insert($t, ['warehouse_id' => $warehouse_id, 'product_id' => $product_id, 'qty' => $qty], ['%d','%d','%d']);
    }
  }

  public static function adjust_qty($warehouse_id, $product_id, $delta) {
    global $wpdb;
    $t = self::table_name();
    $warehouse_id = (int)$warehouse_id;
    $product_id = (int)$product_id;
    $delta = (int)$delta;

    $wpdb->query($wpdb->prepare(
      "INSERT INTO $t (warehouse_id, product_id, qty) VALUES (%d, %d, %d)
       ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)",
      $warehouse_id, $product_id, $delta
    ));
  }

  public static function total_active_qty($product_id) {
    global $wpdb;
    $t = self::table_name();
    $tw = WCWMW_Warehouses::table_name();
    $sum = $wpdb->get_var($wpdb->prepare(
      "SELECT SUM(s.qty) FROM $t s
       INNER JOIN $tw w ON w.id = s.warehouse_id
       WHERE w.is_active=1 AND s.product_id=%d",
       (int)$product_id
    ));
    return (int)($sum ?: 0);
  }

  public static function validate_add_to_cart($passed, $product_id, $quantity, $variation_id = 0, $variations = []) {
    $id = $variation_id ? $variation_id : $product_id;
    $available = self::total_active_qty($id);
    if ($available < (int)$quantity) {
      wc_add_notice(__('Not enough stock available for this item.', 'wcwmw'), 'error');
      return false;
    }
    return $passed;
  }

  public static function validate_cart() {
    if (!WC()->cart) return;

    $needed = [];
    foreach (WC()->cart->get_cart() as $item) {
      $pid = $item['variation_id'] ? $item['variation_id'] : $item['product_id'];
      $needed[$pid] = ($needed[$pid] ?? 0) + (int)$item['quantity'];
    }

    foreach ($needed as $pid => $qty) {
      $available = self::total_active_qty($pid);
      if ($available < $qty) {
        wc_add_notice(sprintf(__('Not enough stock for product ID %d. Available: %d.', 'wcwmw'), $pid, $available), 'error');
      }
    }
  }
}
