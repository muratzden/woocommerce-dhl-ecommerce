# DHL eCommerce for WooCommerce

Independent WooCommerce integration for DHL eCommerce / MNG Kargo APIs.

This plugin can prepare recipients, transfer WooCommerce orders, request DHL barcode labels, print A5/A4 reference labels, save ZPL data and synchronize shipment status emails.

> This plugin is an independent integration and is not affiliated with, endorsed by, or sponsored by DHL eCommerce or MNG Kargo.

## Features

- Token connection test
- Plus Command `createRecipient`
- Standard Command `createOrder`
- Barcode Command `createbarcode`
- Reference barcode / shipment barcode distinction
- A5/A4 label print screen
- ZPL copy and download
- WooCommerce-styled customer emails
- Shipment tracking synchronization
- HPOS compatibility declaration

## Installation

1. Upload the plugin folder to `wp-content/plugins/` or install the ZIP from WordPress Admin.
2. Activate the plugin.
3. Open **DHL eCommerce** from the WordPress admin menu.
4. Enter the API credentials.
5. Test the token connection.
6. Configure labels and email templates.

## Security

Do not commit real customer numbers, API passwords, Client Secrets, JWT tokens, API logs, request dumps or response dumps.

## License

GPLv2 or later.
