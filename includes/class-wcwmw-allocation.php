<?php
if (!defined('ABSPATH')) { exit; }

class WCWMW_Allocation {
  
  private static function coords_invalid($lat, $lng) {
    $lat = (float)$lat; $lng = (float)$lng;
    // Treat NULL/empty as invalid, and also guard against "0,0" which happens if NULL was stored with %f.
    if ($lat == 0.0 && $lng == 0.0) return true;
    if ($lat > 90 || $lat < -90) return true;
    if ($lng > 180 || $lng < -180) return true;
    return false;
  }

  private static function ensure_warehouse_coords(&$w) {
    if (empty($w['address'])) return;
    $lat = $w['lat'] ?? null;
    $lng = $w['lng'] ?? null;

    if (!empty($lat) && !empty($lng) && !self::coords_invalid($lat, $lng)) {
      return;
    }

    $geo = WCWMW_Geo::geocode_address($w['address']);
    if (!$geo) return;

    $w['lat'] = $geo['lat'];
    $w['lng'] = $geo['lng'];

    // Persist coordinates so future checkouts don't need to geocode again.
    global $wpdb;
    $t = WCWMW_Warehouses::table_name();
    $wpdb->query($wpdb->prepare(
      "UPDATE $t SET lat=%f, lng=%f WHERE id=%d",
      (float)$geo['lat'], (float)$geo['lng'], (int)$w['id']
    ));
  }

public static function init() {
    add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'attach_allocation_to_line_item'], 10, 4);
    add_action('woocommerce_checkout_process', [__CLASS__, 'ensure_allocation_possible']);
    add_action('woocommerce_reduce_order_stock', [__CLASS__, 'reduce_stock_by_allocation'], 10, 1);

    add_action('woocommerce_cart_calculate_fees', [__CLASS__, 'maybe_add_surcharge']);
    add_action('woocommerce_before_checkout_form', [__CLASS__, 'maybe_show_notice'], 5);

    add_filter('query_vars', function($vars){ $vars[] = 'warehouse'; return $vars; });
    add_action('pre_get_posts', [__CLASS__, 'filter_catalog_by_warehouse']);
  }

  public static function get_customer_shipping_address_string() {
    $fields = [];

    // 1) Prefer WC_Customer shipping values (stable during checkout updates)
    if (function_exists('WC') && WC()->customer) {
      $c = WC()->customer;
      $map = [
        $c->get_shipping_address_1(),
        $c->get_shipping_address_2(),
        $c->get_shipping_city(),
        $c->get_shipping_state(),
        $c->get_shipping_postcode(),
        $c->get_shipping_country(),
      ];
      foreach ($map as $v) {
        $v = trim((string)$v);
        if ($v !== '') $fields[] = $v;
      }
    }

    // 2) Fallback to checkout posted shipping values
    if (empty($fields) && function_exists('WC') && WC()->checkout()) {
      $keys = ['shipping_address_1','shipping_address_2','shipping_city','shipping_state','shipping_postcode','shipping_country'];
      foreach ($keys as $k) {
        $v = WC()->checkout()->get_value($k);
        $v = trim((string)$v);
        if ($v !== '') $fields[] = $v;
      }
    }

    // 3) If no shipping address, use billing values (common when not shipping to different address)
    if (empty($fields) && function_exists('WC') && WC()->checkout()) {
      $keys = ['billing_address_1','billing_address_2','billing_city','billing_state','billing_postcode','billing_country'];
      foreach ($keys as $k) {
        $v = WC()->checkout()->get_value($k);
        $v = trim((string)$v);
        if ($v !== '') $fields[] = $v;
      }
    }

    $addr = implode(', ', array_filter($fields));

    // Ensure at least country is present for better geocoding
    if ($addr !== '' && stripos($addr, 'Philippines') === false && stripos($addr, ', PH') === false && stripos($addr, ' PH') === false) {
      // If Woo uses country code, keep it; geo normalizer expands PH anyway
      $addr .= ', Philippines';
    }

    return $addr;
  }

  public static function ranked_warehouses_for_customer() {
    $warehouses = WCWMW_Warehouses::get_all(true);
    $addr = self::get_customer_shipping_address_string();
    $geo = WCWMW_Geo::geocode_address($addr);

    if (!$geo) return $warehouses;

    $lat = $geo['lat']; $lng = $geo['lng'];
    foreach ($warehouses as &$w) {
      self::ensure_warehouse_coords($w);

      if (!empty($w['lat']) && !empty($w['lng']) && !self::coords_invalid($w['lat'], $w['lng'])) {
        $w['_distance_km'] = WCWMW_Geo::haversine_km($lat, $lng, (float)$w['lat'], (float)$w['lng']);
      } else {
        $w['_distance_km'] = 999999;
      }
    }
    unset($w);

    usort($warehouses, function($a,$b){ return ($a['_distance_km'] <=> $b['_distance_km']); });
    return $warehouses;
  }

  public static function compute_allocations_for_cart() {
    $ranked = self::ranked_warehouses_for_customer();
    $closest_id = !empty($ranked[0]['id']) ? (int)$ranked[0]['id'] : 0;

    $need = [];
    foreach (WC()->cart->get_cart() as $item) {
      $pid = $item['variation_id'] ? $item['variation_id'] : $item['product_id'];
      $need[$pid] = ($need[$pid] ?? 0) + (int)$item['quantity'];
    }

    $alloc = [];
    $non_closest = false;

    foreach ($need as $pid => $qty_needed) {
      $picked = null;
      foreach ($ranked as $w) {
        $w_id = (int)$w['id'];
        $available = WCWMW_Stock::get_qty($w_id, $pid);
        if ($available >= $qty_needed) {
          $picked = [
            'warehouse_id' => $w_id,
            'warehouse_name' => $w['name'],
            'qty' => $qty_needed,
            'was_closest' => ($w_id === $closest_id),
          ];
          if ($w_id !== $closest_id) $non_closest = true;
          break;
        }
      }
      if (!$picked) return null;
      $alloc[$pid] = $picked;
    }

    return [
      'allocations' => $alloc,
      'non_closest_used' => $non_closest,
      'closest_id' => $closest_id,
    ];
  }

  public static function ensure_allocation_possible() {
    if (!WC()->cart) return;
    $computed = self::compute_allocations_for_cart();
    if (!$computed) {
      wc_add_notice(__('Some items are out of stock in all warehouses.', 'wcwmw'), 'error');
      return;
    }
    WC()->session->set('wcwmw_non_closest_used', !empty($computed['non_closest_used']));
  }

  public static function attach_allocation_to_line_item($item, $cart_item_key, $values, $order) {
    if (!WC()->cart) return;
    $computed = self::compute_allocations_for_cart();
    if (!$computed) return;

    $pid = $values['variation_id'] ? $values['variation_id'] : $values['product_id'];
    if (empty($computed['allocations'][$pid])) return;

    $a = $computed['allocations'][$pid];
    $item->add_meta_data('_wcwmw_warehouse_id', (int)$a['warehouse_id'], true);
    $item->add_meta_data('_wcwmw_warehouse_name', sanitize_text_field($a['warehouse_name']), true);
    $item->add_meta_data('_wcwmw_alloc_qty', (int)$a['qty'], true);
    $item->add_meta_data('_wcwmw_was_closest', $a['was_closest'] ? 'yes' : 'no', true);
  }

  public static function reduce_stock_by_allocation($order) {
    if (!is_a($order, 'WC_Order')) return;

    foreach ($order->get_items() as $item) {
      $warehouse_id = (int)$item->get_meta('_wcwmw_warehouse_id');
      $qty = (int)$item->get_meta('_wcwmw_alloc_qty');
      $product = $item->get_product();
      if (!$product) continue;

      $pid = $product->get_id();
      if ($warehouse_id && $qty > 0) {
        WCWMW_Stock::adjust_qty($warehouse_id, $pid, -1 * $qty);
      }
    }
  }

  public static function maybe_add_surcharge($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;
    if (!WC()->session) return;
    $non_closest = WC()->session->get('wcwmw_non_closest_used');
    $fee = (float)get_option('wcwmw_surcharge_if_not_closest', 0);

    if ($non_closest && $fee > 0) {
      $cart->add_fee(__('Additional fulfillment surcharge', 'wcwmw'), $fee, false);
    }
  }

  public static function maybe_show_notice() {
    if (!WC()->session) return;
    $non_closest = WC()->session->get('wcwmw_non_closest_used');
    $fee = (float)get_option('wcwmw_surcharge_if_not_closest', 0);
    if ($non_closest && $fee > 0) {
      wc_print_notice(__('Some items are shipping from a non-closest warehouse due to availability. An additional surcharge was applied.', 'wcwmw'), 'notice');
    }
  }

  public static function filter_catalog_by_warehouse($q) {
    if (is_admin() || !$q->is_main_query()) return;
    if (!($q->is_post_type_archive('product') || $q->is_tax('product_cat') || $q->is_search())) return;

    $warehouse = get_query_var('warehouse');
    if (!$warehouse) return;

    global $wpdb;
    $warehouse_id = (int)$warehouse;
    $t_stock = WCWMW_Stock::table_name();

    add_filter('posts_join', function($join) use ($wpdb, $t_stock, $warehouse_id) {
      return $join . " INNER JOIN $t_stock wcwmw_s ON (wcwmw_s.product_id = {$wpdb->posts}.ID AND wcwmw_s.warehouse_id = $warehouse_id)";
    });
    add_filter('posts_where', function($where) {
      return $where . " AND wcwmw_s.qty > 0";
    });
    add_filter('posts_groupby', function($groupby) use ($wpdb) {
      $gb = trim($groupby);
      if ($gb === '') return "{$wpdb->posts}.ID";
      if (strpos($gb, "{$wpdb->posts}.ID") === false) return $gb . ", {$wpdb->posts}.ID";
      return $gb;
    });
  }
}
