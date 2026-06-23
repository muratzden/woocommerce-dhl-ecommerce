# DHL eCommerce for WooCommerce

WooCommerce integration for DHL eCommerce / MNG Kargo APIs in Türkiye.

Current release: `0.9.9-beta`.

## Features

- Create DHL/MNG shipment orders from WooCommerce orders.
- Create barcode labels via Barcode Command API.
- Independent DHL eCommerce admin menu in the WordPress left sidebar.
- Independent DHL eCommerce card on WooCommerce order screens.
- Custom order statuses:
  - Kargoya verilmeye hazır
  - Kargoya teslim edildi
  - Varış şubesinde
  - Dağıtımda
- Automatic tracking sync through WordPress Cron.
- Customer email notifications for shipment stages.
- Delivered shipments automatically move WooCommerce orders to Completed.
- Editable customer email templates.
- Native WooCommerce email wrapper for all DHL customer shipment emails.
- Delayed barcode queue after createOrder to avoid early 20011 barcode failures.
- Plugin screen Settings link.
- HPOS compatibility declaration.

## Required API subscriptions

Your Sandbox or Apizone app must be subscribed to:

- Identity API
- Standard Command API
- Barcode Command API
- Standard Query API

## Setup links

- Sandbox: https://sandbox.mngkargo.com.tr/
- Apizone: https://apizone.mngkargo.com.tr/

## Notes

The plugin does not receive push/webhook events from DHL/MNG. It checks shipment status with `getshipmentstatus` periodically using WordPress Cron.


## Barcode Lifecycle

When a WooCommerce order is sent to DHL eCommerce/MNG, the plugin does not call the Barcode Command API immediately. It schedules barcode creation after a delay because live API logs showed `createOrder` and `createbarcode` were being called within milliseconds, which can trigger `20011` before the order is ready. If DHL still returns `20011`, the plugin retries automatically up to five times.

The plugin also adds DHL reference, shipment number and barcode metadata to WooCommerce order emails through WooCommerce email hooks. DHL shipment emails now use the native WooCommerce email header, footer, styling and order/customer detail hooks, so store logo and colors should be managed from WooCommerce email settings instead of plugin-specific visual settings.
