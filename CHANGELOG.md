# Changelog

## 0.9.9-beta
- Reworked DHL customer shipment emails to use the native WooCommerce email wrapper.
- DHL emails now inherit WooCommerce header, footer, logo, colors, order details and customer details.
- Removed the need for plugin-specific visual email wrapper settings; editable DHL subject/body fields remain.

## 0.9.8-beta
- Delayed automatic barcode creation after createOrder.
- Added barcode retry counter and max retry protection for `20011`.
- Added shared branded HTML email wrapper for all DHL customer emails.
- Added editable logo URL, colors, footer and contact fields for emails.

## 0.9.7-beta
- Added automatic barcode creation after DHL order creation.
- Added delayed barcode retry for DHL/MNG `20011` responses.
- Manual barcode action now creates the DHL order first when needed.
- Added DHL shipment and barcode metadata to WooCommerce order emails.

## 0.9.6-beta
- Added automatic shipment tracking cron.
- Added customer emails by shipment stage.
- Added Settings link on Plugins screen.

## 0.9.5-beta
- Improved barcode reference lifecycle and error handling.

## 0.9.4-beta
- Added Sandbox and Apizone setup links.
