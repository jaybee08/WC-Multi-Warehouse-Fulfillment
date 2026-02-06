<?php
/**
 * Plugin Name: WC Multi-Warehouse Fulfillment (Task)
 * Description: Multi-warehouse inventory + nearest-warehouse allocation at checkout (Google geocoding optional).
 * Version: 0.1.5
 * Author: Jaybee Montejo
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

define('WCWMW_VERSION', '0.1.5');
define('WCWMW_PLUGIN_FILE', __FILE__);
define('WCWMW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCWMW_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WCWMW_PLUGIN_DIR . 'includes/class-wcwmw-db.php';
require_once WCWMW_PLUGIN_DIR . 'includes/class-wcwmw-geo.php';
require_once WCWMW_PLUGIN_DIR . 'includes/class-wcwmw-warehouses.php';
require_once WCWMW_PLUGIN_DIR . 'includes/class-wcwmw-stock.php';
require_once WCWMW_PLUGIN_DIR . 'includes/class-wcwmw-allocation.php';
require_once WCWMW_PLUGIN_DIR . 'includes/admin/class-wcwmw-admin.php';

register_activation_hook(__FILE__, ['WCWMW_DB', 'install']);

add_action('plugins_loaded', function () {
  if (!class_exists('WooCommerce')) return;

  WCWMW_Warehouses::init();
  WCWMW_Stock::init();
  WCWMW_Allocation::init();

  if (is_admin()) {
    WCWMW_Admin::init();
  }
});
