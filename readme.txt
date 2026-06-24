=== DHL eCommerce for WooCommerce ===
Contributors: muratzden
Tags: woocommerce, dhl, mng kargo, shipping, shipment tracking
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Independent WooCommerce integration for DHL eCommerce / MNG Kargo APIs.

== Description ==

DHL eCommerce for WooCommerce transfers WooCommerce orders to DHL eCommerce / MNG Kargo APIs, prepares recipients, creates shipment orders, optionally creates barcode labels, prints A5/A4 reference labels and synchronizes shipment tracking status emails.

This plugin is an independent WooCommerce integration. It is not affiliated with, endorsed by, or sponsored by DHL eCommerce or MNG Kargo.

Main features:

* DHL token connection test.
* Plus Command createRecipient support.
* Standard Command createOrder support.
* Barcode Command createbarcode support.
* Reference / shipment barcode distinction.
* Printable A5/A4 label screen.
* ZPL copy and ZPL download.
* Customer shipment status emails using WooCommerce email styling.
* Tracking status synchronization.
* WooCommerce HPOS compatibility declaration.

== Requirements ==

* WordPress 6.0 or newer.
* WooCommerce 7.0 or newer.
* PHP 7.4 or newer.
* Active DHL eCommerce / MNG Kargo API credentials.
* Required API subscriptions in Sandbox or Apizone: Identity, Plus Command, Standard Command, Standard Query and Barcode Command.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the ZIP from WordPress Admin > Plugins > Add New.
2. Activate the plugin.
3. Open DHL eCommerce from the WordPress admin left menu.
4. Enter environment, customer number, API password, Client ID and Client Secret.
5. Click Token Connection Test.
6. Configure label and email settings.
7. Open a WooCommerce order and use the DHL eCommerce card.

== Frequently Asked Questions ==

= Does this plugin require a DHL eCommerce / MNG Kargo account? =

Yes. The plugin requires valid customer credentials and API application credentials.

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
