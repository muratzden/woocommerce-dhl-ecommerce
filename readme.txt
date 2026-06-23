=== DHL eCommerce for WooCommerce ===
Contributors: muratzden
Tags: woocommerce, dhl, mng kargo, shipping, ecommerce
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.9.9-beta
License: GPLv2 or later

WooCommerce integration for DHL eCommerce / MNG Kargo APIs in Türkiye.

== Description ==

Create DHL/MNG shipment orders, create barcode labels, sync shipment status and notify customers with native WooCommerce-style emails.

== Changelog ==

= 0.9.9-beta =
* Reworked DHL customer shipment emails to use WooCommerce email header, footer, styling, order details and customer details.
* Editable DHL subject and body templates remain available.

= 0.9.8-beta =
* Delayed automatic barcode creation after createOrder.
* Added barcode retry counter and max retry protection for 20011.

= 0.9.6-beta =
* Added automatic shipment tracking synchronization.
* Added customer shipment stage emails.
* Added custom WooCommerce shipment statuses.
* Delivered shipments now move to Completed.
* Added plugin screen Settings link.
