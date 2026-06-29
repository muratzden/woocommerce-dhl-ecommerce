=== ShipPilot for WooCommerce ===
Contributors: muratzden
Tags: woocommerce, shipping, shipment tracking, logistics, barcode
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shipping integration plugin for WooCommerce with shipment creation, label generation, tracking synchronization and customer notifications.

== Description ==

ShipPilot for WooCommerce is an independent shipping integration plugin that enables merchants to create shipments, print shipping labels, synchronize tracking information and automate shipping workflows using supported carrier APIs.

Documentation: https://muratzden.github.io/shippilot-for-woocommerce/

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

This plugin connects to external shipping carrier APIs only when the merchant initiates a shipping operation.

Service provider:
DHL eCommerce / MNG Kargo

Purpose of the connection:

* Shipment creation
* Shipping label generation
* Barcode retrieval
* Shipment tracking synchronization

Data transmitted:

* API authentication credentials
* Recipient name and address
* Shipment information
* Tracking requests

Terms of Service:
https://muratzden.github.io/shippilot-for-woocommerce/terms.html

Privacy Policy:
https://muratzden.github.io/shippilot-for-woocommerce/privacy-policy.html

== Requirements ==

* WordPress 6.0 or newer.
* WooCommerce 7.0 or newer.
* PHP 7.4 or newer.
* Active API credentials for a supported shipping provider.

Currently supported provider:
* DHL eCommerce / MNG Kargo
* Required API subscriptions in Sandbox or Apizone: Identity, Plus Command, Standard Command, Standard Query and Barcode Command.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the ZIP from WordPress Admin > Plugins > Add New.
2. Activate the plugin.
3. Open ShipPilot from the WordPress admin menu.
4. Enter your shipping provider API credentials.
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

= 1.1.9 =
* Fixed WordPress.org review issues.
* Added documentation pages.
* Improved settings sanitization.
* Refactored label rendering.
* Removed inline JavaScript.
* Removed inline CSS.
* Updated external service documentation.
* Added GitHub Pages documentation.

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
