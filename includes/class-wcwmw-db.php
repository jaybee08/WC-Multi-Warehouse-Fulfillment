<?php
if (!defined('ABSPATH')) { exit; }

class WCWMW_DB {
  public static function install() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $t_wh = $wpdb->prefix . 'wcwmw_warehouses';
    $t_stock = $wpdb->prefix . 'wcwmw_stock';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql1 = "CREATE TABLE $t_wh (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      name VARCHAR(190) NOT NULL,
      address TEXT NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      lat DECIMAL(10,7) NULL,
      lng DECIMAL(10,7) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY  (id),
      KEY is_active (is_active)
    ) $charset_collate;";

    $sql2 = "CREATE TABLE $t_stock (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      warehouse_id BIGINT(20) UNSIGNED NOT NULL,
      product_id BIGINT(20) UNSIGNED NOT NULL,
      qty INT(11) NOT NULL DEFAULT 0,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY wh_product (warehouse_id, product_id),
      KEY product_id (product_id),
      KEY warehouse_id (warehouse_id)
    ) $charset_collate;";

    dbDelta($sql1);
    dbDelta($sql2);

    add_option('wcwmw_surcharge_if_not_closest', 0);
    add_option('wcwmw_google_maps_api_key', '');
  }
}
