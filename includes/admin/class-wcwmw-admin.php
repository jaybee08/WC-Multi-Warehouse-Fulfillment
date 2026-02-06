<?php
if (!defined('ABSPATH')) { exit; }

class WCWMW_Admin {
  public static function init() {
    add_action('admin_menu', [__CLASS__, 'menu']);
    add_action('admin_post_wcwmw_save_warehouse', [__CLASS__, 'handle_save_warehouse']);
    add_action('admin_post_wcwmw_delete_warehouse', [__CLASS__, 'handle_delete_warehouse']);

    add_filter('woocommerce_product_data_tabs', [__CLASS__, 'product_tab']);
    add_action('woocommerce_product_data_panels', [__CLASS__, 'product_tab_panel']);
    add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_product_stock']);
  }

  public static function menu() {
    add_submenu_page(
      'woocommerce',
      __('Warehouses', 'wcwmw'),
      __('Warehouses', 'wcwmw'),
      'manage_woocommerce',
      'wcwmw-warehouses',
      [__CLASS__, 'render_warehouses_page']
    );

    add_submenu_page(
      'woocommerce',
      __('Multi-Warehouse Settings', 'wcwmw'),
      __('Multi-Warehouse Settings', 'wcwmw'),
      'manage_woocommerce',
      'wcwmw-settings',
      [__CLASS__, 'render_settings_page']
    );
  }

  public static function render_settings_page() {
    if (!current_user_can('manage_woocommerce')) return;

    if (isset($_POST['wcwmw_settings_nonce']) && wp_verify_nonce($_POST['wcwmw_settings_nonce'], 'wcwmw_save_settings')) {
      update_option('wcwmw_surcharge_if_not_closest', wc_format_decimal($_POST['wcwmw_surcharge_if_not_closest'] ?? 0));
      update_option('wcwmw_google_maps_api_key', sanitize_text_field($_POST['wcwmw_google_maps_api_key'] ?? ''));
      echo '<div class="notice notice-success"><p>Saved.</p></div>';
    }

    $fee = get_option('wcwmw_surcharge_if_not_closest', 0);
    $key = get_option('wcwmw_google_maps_api_key', '');
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('Multi-Warehouse Settings', 'wcwmw'); ?></h1>
      <form method="post">
        <?php wp_nonce_field('wcwmw_save_settings', 'wcwmw_settings_nonce'); ?>
        <table class="form-table">
          <tr>
            <th scope="row"><label for="wcwmw_google_maps_api_key"><?php esc_html_e('Google Maps API Key (Geocoding)', 'wcwmw'); ?></label></th>
            <td>
              <input type="text" class="regular-text" name="wcwmw_google_maps_api_key" id="wcwmw_google_maps_api_key" value="<?php echo esc_attr($key); ?>" />
              <p class="description"><?php esc_html_e('If set, uses Google Geocoding. Leave blank to use Nominatim fallback.', 'wcwmw'); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="wcwmw_surcharge_if_not_closest"><?php esc_html_e('Surcharge if not closest (optional)', 'wcwmw'); ?></label></th>
            <td>
              <input type="number" step="0.01" min="0" name="wcwmw_surcharge_if_not_closest" id="wcwmw_surcharge_if_not_closest" value="<?php echo esc_attr($fee); ?>" />
              <p class="description"><?php esc_html_e('Applies a fee when the closest warehouse cannot fulfill and another warehouse is used.', 'wcwmw'); ?></p>
            </td>
          </tr>
        </table>
        <?php submit_button(); ?>
      </form>
    </div>
    <?php
  }

  public static function render_warehouses_page() {
    if (!current_user_can('manage_woocommerce')) return;

    $editing = null;
    if (!empty($_GET['edit'])) {
      $editing = WCWMW_Warehouses::get((int)$_GET['edit']);
    }

    $warehouses = WCWMW_Warehouses::get_all(false);
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('Warehouses', 'wcwmw'); ?></h1>

      <h2><?php echo $editing ? esc_html__('Edit Warehouse', 'wcwmw') : esc_html__('Add Warehouse', 'wcwmw'); ?></h2>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="wcwmw_save_warehouse" />
        <?php wp_nonce_field('wcwmw_save_warehouse', 'wcwmw_nonce'); ?>
        <input type="hidden" name="id" value="<?php echo esc_attr($editing['id'] ?? ''); ?>" />
        <table class="form-table">
          <tr>
            <th scope="row"><label for="name"><?php esc_html_e('Name', 'wcwmw'); ?></label></th>
            <td><input name="name" id="name" class="regular-text" value="<?php echo esc_attr($editing['name'] ?? ''); ?>" required /></td>
          </tr>
          <tr>
            <th scope="row"><label for="address"><?php esc_html_e('Address', 'wcwmw'); ?></label></th>
            <td><textarea name="address" id="address" class="large-text" rows="3" required><?php echo esc_textarea($editing['address'] ?? ''); ?></textarea></td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('Active', 'wcwmw'); ?></th>
            <td><label><input type="checkbox" name="is_active" value="1" <?php checked(!isset($editing['is_active']) || (int)$editing['is_active'] === 1); ?> /> <?php esc_html_e('Enabled', 'wcwmw'); ?></label></td>
          </tr>
        </table>
        <?php submit_button($editing ? __('Update Warehouse', 'wcwmw') : __('Add Warehouse', 'wcwmw')); ?>
      </form>

      <hr />

      <h2><?php esc_html_e('Existing Warehouses', 'wcwmw'); ?></h2>
      <table class="widefat striped">
        <thead>
          <tr>
            <th><?php esc_html_e('ID', 'wcwmw'); ?></th>
            <th><?php esc_html_e('Name', 'wcwmw'); ?></th>
            <th><?php esc_html_e('Active', 'wcwmw'); ?></th>
            <th><?php esc_html_e('Address', 'wcwmw'); ?></th>
            <th><?php esc_html_e('Lat', 'wcwmw'); ?></th>
            <th><?php esc_html_e('Lng', 'wcwmw'); ?></th>
            <th><?php esc_html_e('Actions', 'wcwmw'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($warehouses)): ?>
            <tr><td colspan="7"><?php esc_html_e('No warehouses yet.', 'wcwmw'); ?></td></tr>
          <?php else: foreach ($warehouses as $w): ?>
            <tr>
              <td><?php echo esc_html($w['id']); ?></td>
              <td><?php echo esc_html($w['name']); ?></td>
              <td><?php echo ((int)$w['is_active'] === 1) ? 'Yes' : 'No'; ?></td>
              <td><?php echo esc_html($w['address']); ?></td>
              <td><?php echo esc_html($w['lat']); ?></td>
              <td><?php echo esc_html($w['lng']); ?></td>
              <td>
                <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page'=>'wcwmw-warehouses','edit'=>$w['id']], admin_url('admin.php'))); ?>"><?php esc_html_e('Edit', 'wcwmw'); ?></a>
                <form style="display:inline" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Delete this warehouse?');">
                  <input type="hidden" name="action" value="wcwmw_delete_warehouse" />
                  <?php wp_nonce_field('wcwmw_delete_warehouse', 'wcwmw_del_nonce'); ?>
                  <input type="hidden" name="id" value="<?php echo esc_attr($w['id']); ?>" />
                  <button class="button button-small"><?php esc_html_e('Delete', 'wcwmw'); ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>

      <p style="margin-top:12px;color:#666;">
        <?php esc_html_e('Tip: use specific addresses like “Cebu City, Cebu, Philippines” for best accuracy.', 'wcwmw'); ?>
      </p>
    </div>
    <?php
  }

  public static function handle_save_warehouse() {
    if (!current_user_can('manage_woocommerce')) wp_die('Forbidden');
    if (empty($_POST['wcwmw_nonce']) || !wp_verify_nonce($_POST['wcwmw_nonce'], 'wcwmw_save_warehouse')) wp_die('Bad nonce');

    $id = WCWMW_Warehouses::upsert($_POST);
    wp_safe_redirect(add_query_arg(['page' => 'wcwmw-warehouses', 'edit' => $id], admin_url('admin.php')));
    exit;
  }

  public static function handle_delete_warehouse() {
    if (!current_user_can('manage_woocommerce')) wp_die('Forbidden');
    if (empty($_POST['wcwmw_del_nonce']) || !wp_verify_nonce($_POST['wcwmw_del_nonce'], 'wcwmw_delete_warehouse')) wp_die('Bad nonce');

    WCWMW_Warehouses::delete((int)$_POST['id']);
    wp_safe_redirect(add_query_arg(['page' => 'wcwmw-warehouses'], admin_url('admin.php')));
    exit;
  }

  public static function product_tab($tabs) {
    $tabs['wcwmw_stock'] = [
      'label'  => __('Warehouse Stock', 'wcwmw'),
      'target' => 'wcwmw_stock_panel',
      'class'  => ['show_if_simple','show_if_variable','show_if_variation'],
      'priority' => 90,
    ];
    return $tabs;
  }

  public static function product_tab_panel() {
    global $post;
    $warehouses = WCWMW_Warehouses::get_all(false);
    ?>
    <div id="wcwmw_stock_panel" class="panel woocommerce_options_panel hidden">
      <div class="options_group">
        <p class="form-field">
          <?php esc_html_e('Set stock per warehouse (stored in custom table).', 'wcwmw'); ?>
        </p>
        <?php if (empty($warehouses)): ?>
          <p><?php esc_html_e('No warehouses yet. Create one under WooCommerce → Warehouses.', 'wcwmw'); ?></p>
        <?php else: foreach ($warehouses as $w):
          $qty = WCWMW_Stock::get_qty((int)$w['id'], (int)$post->ID);
        ?>
          <p class="form-field">
            <label><?php echo esc_html($w['name']); ?> (ID <?php echo esc_html($w['id']); ?>)</label>
            <input type="number" min="0" step="1" name="wcwmw_qty[<?php echo esc_attr($w['id']); ?>]" value="<?php echo esc_attr($qty); ?>" />
          </p>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <?php
  }

  public static function save_product_stock($product) {
    if (empty($_POST['wcwmw_qty']) || !is_array($_POST['wcwmw_qty'])) return;

    $product_id = $product->get_id();
    foreach ($_POST['wcwmw_qty'] as $warehouse_id => $qty) {
      WCWMW_Stock::set_qty((int)$warehouse_id, (int)$product_id, (int)$qty);
    }
  }
}
