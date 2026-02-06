<?php
if (!defined('ABSPATH')) { exit; }

class WCWMW_Geo {
  /**
   * Geocode address -> ['lat' => float, 'lng' => float]
   *
   * - Uses Google Geocoding if API key is set (WooCommerce → Multi-Warehouse Settings)
   * - Falls back to Nominatim
   * - Both are biased to Philippines to reduce ambiguity
   * - Retries with simplified address candidates if the full string fails
   */
  public static function geocode_address($address) {
    $address = trim((string)$address);
    if ($address === '') return null;

    // Normalize some common PH formats
    $address = self::normalize_address($address);

    $cache_key = 'wcwmw_geo_' . md5($address);
    $cached = get_transient($cache_key);
    if ($cached && is_array($cached)) return $cached;

    $candidates = self::candidate_addresses($address);

    $google_key = trim((string)get_option('wcwmw_google_maps_api_key', ''));
    if ($google_key !== '') {
      foreach ($candidates as $cand) {
        $out = self::geocode_google($cand, $google_key);
        if ($out) {
          set_transient($cache_key, $out, 30 * DAY_IN_SECONDS);
          return $out;
        }
      }
    }

    foreach ($candidates as $cand) {
      $out = self::geocode_nominatim($cand);
      if ($out) {
        set_transient($cache_key, $out, 7 * DAY_IN_SECONDS);
        return $out;
      }
    }

    return null;
  }

  private static function normalize_address($address) {
    $a = trim((string)$address);

    // Expand country code if present
    $a = preg_replace('/\bPH\b/i', 'Philippines', $a);

    // Ensure country present for better matches
    if (stripos($a, 'Philippines') === false) {
      $a = rtrim($a, ", ") . ", Philippines";
    }

    // Collapse repeated spaces/commas
    $a = preg_replace('/\s+/', ' ', $a);
    $a = preg_replace('/\s*,\s*/', ', ', $a);
    $a = preg_replace('/,\s*,+/', ', ', $a);

    return trim($a, " ,");
  }

  private static function candidate_addresses($address) {
    $c = [];
    $address = trim($address);
    if ($address === '') return $c;

    $c[] = $address;

    // Remove postcode-only tokens (e.g., 6000)
    $no_post = preg_replace('/\b\d{4,6}\b/', '', $address);
    $no_post = trim(preg_replace('/\s*,\s*/', ', ', $no_post), " ,");
    if ($no_post && $no_post !== $address) $c[] = $no_post;

    // Try last 3 and last 2 comma-separated parts (city/region/country)
    $parts = array_values(array_filter(array_map('trim', explode(',', $address))));
    if (count($parts) >= 3) {
      $last3 = implode(', ', array_slice($parts, -3));
      if ($last3 && !in_array($last3, $c, true)) $c[] = $last3;
    }
    if (count($parts) >= 2) {
      $last2 = implode(', ', array_slice($parts, -2));
      if ($last2 && !in_array($last2, $c, true)) $c[] = $last2;
    }

    // Dedupe
    $out = [];
    foreach ($c as $x) {
      $x = trim($x);
      if ($x !== '' && !in_array($x, $out, true)) $out[] = $x;
    }
    return $out;
  }

  private static function geocode_google($address, $api_key) {
    $url = add_query_arg([
      'address'    => $address,
      'key'        => $api_key,
      // Bias results to Philippines (improves Cebu/Manila city-level strings)
      'region'     => 'ph',
      'components' => 'country:PH',
    ], 'https://maps.googleapis.com/maps/api/geocode/json');

    $res = wp_remote_get($url, ['timeout' => 10]);
    if (is_wp_error($res)) return null;

    $body = wp_remote_retrieve_body($res);
    $data = json_decode($body, true);

    if (!is_array($data) || empty($data['status']) || $data['status'] !== 'OK') return null;
    if (empty($data['results'][0]['geometry']['location'])) return null;

    $loc = $data['results'][0]['geometry']['location'];
    if (!isset($loc['lat']) || !isset($loc['lng'])) return null;

    return ['lat' => (float)$loc['lat'], 'lng' => (float)$loc['lng']];
  }

  private static function geocode_nominatim($address) {
    $url = add_query_arg([
      'format'       => 'json',
      'limit'        => 1,
      'q'            => $address,
      'countrycodes' => 'ph',
    ], 'https://nominatim.openstreetmap.org/search');

    $res = wp_remote_get($url, [
      'timeout' => 10,
      'headers' => [
        'User-Agent' => 'WCWMW Interview Plugin (contact: example@example.com)'
      ],
    ]);

    if (is_wp_error($res)) return null;

    $body = wp_remote_retrieve_body($res);
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data[0]['lat']) || empty($data[0]['lon'])) return null;

    return ['lat' => (float)$data[0]['lat'], 'lng' => (float)$data[0]['lon']];
  }

  public static function haversine_km($lat1, $lng1, $lat2, $lng2) {
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng/2) * sin($dLng/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
  }
}
