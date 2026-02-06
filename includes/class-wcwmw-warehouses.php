<?php
if (!defined('ABSPATH')) { exit; }

class WCWMW_Warehouses {
  public static function init() { /* reserved */ }

  public static function table_name() {
    global $wpdb;
    return $wpdb->prefix . 'wcwmw_warehouses';
  }

  public static function get_all($active_only = false) {
    global $wpdb;
    $t = self::table_name();
    if ($active_only) {
      return $wpdb->get_results("SELECT * FROM $t WHERE is_active=1 ORDER BY name ASC", ARRAY_A);
    }
    return $wpdb->get_results("SELECT * FROM $t ORDER BY name ASC", ARRAY_A);
  }

  public static function get($id) {
    global $wpdb;
    $t = self::table_name();
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id), ARRAY_A);
  }

  public static function upsert($data) {
    global $wpdb;
    $t = self::table_name();

    $name = sanitize_text_field($data['name'] ?? '');
    $address = sanitize_textarea_field($data['address'] ?? '');
    $is_active = !empty($data['is_active']) ? 1 : 0;

    $geo = WCWMW_Geo::geocode_address($address);
    $lat = $geo ? $geo['lat'] : null;
    $lng = $geo ? $geo['lng'] : null;

    if (!empty($data['id'])) {
      $wpdb->update($t, [
        'name' => $name,
        'address' => $address,
        'is_active' => $is_active,
        'lat' => $lat,
        'lng' => $lng,
      ], ['id' => (int)$data['id']], ['%s','%s','%d','%f','%f'], ['%d']);
      return (int)$data['id'];
    } else {
      $wpdb->insert($t, [
        'name' => $name,
        'address' => $address,
        'is_active' => $is_active,
        'lat' => $lat,
        'lng' => $lng,
      ], ['%s','%s','%d','%f','%f']);
      return (int)$wpdb->insert_id;
    }
  }

  public static function delete($id) {
    global $wpdb;
    $t = self::table_name();
    $wpdb->delete($t, ['id' => (int)$id], ['%d']);
  }
}
