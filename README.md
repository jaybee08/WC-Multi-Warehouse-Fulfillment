# WC Multi-Warehouse Fulfillment (Interview) v0.1.4

This is the original plugin structure + logic with a safe geocoding improvement:
- Uses Google Geocoding if you set an API key (WooCommerce → Multi-Warehouse Settings)
- Falls back to Nominatim
- Both are biased to Philippines (region/components/countrycodes)

Install:
1) Upload/activate the ZIP as a plugin.
2) WooCommerce → Warehouses: add warehouses (use specific addresses e.g. 'Cebu City, Cebu, Philippines').
3) WooCommerce → Multi-Warehouse Settings: set Google API key (optional).
4) Edit product → Product data → Warehouse Stock: set qty per warehouse.
