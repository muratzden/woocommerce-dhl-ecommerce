# ShipPilot for WooCommerce

ShipPilot for WooCommerce is a shipping management plugin for WooCommerce that helps merchants create shipments, generate shipping labels, retrieve tracking information, and automate shipping workflows directly from the WordPress administration panel.

## Features

- Shipment creation
- Shipping label generation
- Tracking number management
- Shipment status synchronization
- Configurable shipping settings
- Secure API communication
- Translation ready
- WordPress coding standards
- Extensible developer architecture

## Requirements

* WordPress 6.5 or higher
* WooCommerce 9.0 or higher
* PHP 8.0 or higher

## Installation

1. Download the latest release.
2. Upload the `shippilot-for-woocommerce` folder to the `/wp-content/plugins/` directory.
3. Activate the plugin from **Plugins → Installed Plugins**.
4. Open **WooCommerce → ShipPilot**.
5. Configure your shipping provider credentials in the plugin settings.
6. Save the settings and test the connection.

## Configuration

The plugin provides configuration options for:

* API credentials
* Shipping provider settings
* Default shipment options
* Tracking preferences
* Order automation

## External Services

This plugin communicates with third-party shipping provider APIs in order to:

* Create shipments
* Generate shipping labels
* Retrieve shipment status
* Obtain tracking information

The plugin only transmits the information required to perform shipping operations.

This plugin communicates with third-party shipping provider APIs to create shipments, generate shipping labels, and retrieve shipment tracking information.

Only the information required to perform shipping operations is transmitted.

Users should review the Terms of Service and Privacy Policy of the shipping provider they choose to use.

## Developer Information

The plugin follows the standard WordPress plugin structure and is designed to remain maintainable and extensible.

Main directories:

```text
assets/
includes/
languages/
templates/
```

Future releases will continue improving architecture, performance, and extensibility while maintaining backward compatibility whenever possible.

## Roadmap

### Version 1.1

* Improved settings interface
* Additional shipping options
* Better error handling

### Version 1.2

* Complete architecture refactoring
* Modular service structure
* Improved code organization
* Additional carrier modules
* Enhanced developer hooks
* Performance optimizations

## Contributing

Bug reports, feature requests, and pull requests are welcome.

Please open an issue before submitting large changes.

## License

This project is licensed under the GNU General Public License v2.0 or later (GPL-2.0-or-later). See the LICENSE file for details.

## Author

**Murat Özden**

Repository:
https://github.com/muratzden/shippilot-for-woocommerce

## Support

If you encounter a bug or would like to request a feature, please open an issue in this repository.

---

© Murat Özden. All rights reserved.