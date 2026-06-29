=== ShipPilot for WooCommerce ===
Contributors: muratzden
Tags: woocommerce, shipping, shipment tracking, logistics, barcode
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shipping integration plugin for WooCommerce with shipment creation, label generation, tracking synchronization and customer notifications.

== Description ==

ShipPilot is an independent WooCommerce shipping integration that supports DHL eCommerce / MNG Kargo services for shipment creation, barcode generation, label printing, tracking synchronization and customer notifications.

Disclaimer:

ShipPilot is an independent plugin and is not affiliated with, endorsed by, sponsored by, or officially associated with DHL Group, DHL eCommerce or MNG Kargo. The names DHL and MNG Kargo are used solely to describe compatibility with supported shipping services.

Main features:

* API connection test
* Recipient creation
* Shipment creation
* Barcode generation
* Reference and shipment barcode support
* A4 and A5 shipping label printing
* ZPL label download
* Shipment tracking synchronization
* WooCommerce customer notification emails
* WooCommerce HPOS compatible

== External Services ==

ShipPilot connects to the DHL eCommerce / MNG Kargo API to create shipments, generate barcodes, retrieve shipping labels and synchronize shipment tracking.

Data sent:

* API credentials configured by the merchant (authentication)
* Recipient name, address and contact information
* Shipment and order information required to create shipments
* Shipment tracking requests

This data is transmitted only when the merchant performs shipping operations.

Service Provider:
DHL eCommerce / MNG Kargo Shipping Services

Terms of Service:
https://www.mngkargo.com.tr/

Privacy Policy:
https://www.mngkargo.com.tr/gizlilik-politikasi

== Requirements ==

* WordPress 6.0 or newer.
* WooCommerce 7.0 or newer.
* PHP 7.4 or newer.
* Active API credentials for DHL eCommerce / MNG Kargo shipping services.
* Required API subscriptions in Sandbox or Apizone: Identity, Plus Command, Standard Command, Standard Query and Barcode Command.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the ZIP from WordPress Admin > Plugins > Add New.
2. Activate the plugin.
3. Open ShipPilot from the WordPress admin menu.
4. Enter environment, customer number, API password, Client ID and Client Secret.
5. Click Token Connection Test.
6. Configure label and email settings.
7. Open a WooCommerce order and use the ShipPilot shipment panel.

== Frequently Asked Questions ==

= Which shipping service accounts are supported? =

ShipPilot currently supports DHL eCommerce / MNG Kargo shipping services. Valid API credentials are required to use the integration.

= Does the plugin add public powered-by links? =

No. The plugin does not add public backlinks or marketing links to the storefront.

= Does barcode creation always return a shipment number? =

No. DHL may return a reference / order barcode ZPL when the final shipment barcode is not yet available. The plugin separates reference barcode and shipment barcode states.

= Can I print labels? =

Yes. The plugin includes an A5/A4 label print screen and can download the original ZPL returned by DHL.

== Changelog ==

= 1.1.8 =
* Hardened WordPress.org metadata.
* Improved A5 landscape label layout.
* Fixed barcode overflow in the right information column.
* Improved PDF/PNG export generation.
* Preserved ZPL download support.

= 1.1.6 =
* Fixed label screen JavaScript controls.

= 1.1.5 =
* Improved A5 landscape print handling.

= 1.1.4 =
* Fixed label footer clipping in generated files.

= 1.1.0 =
* Added A4/A5 label printing module.

= 1.0.0 =
* Initial modular release with DHL API settings, order transfer, barcode support and email notifications.
