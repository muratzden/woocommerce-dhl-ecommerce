## 1.1.8-beta
- Fixed print label page JavaScript so toolbar/sidebar buttons work again.

## 1.1.8-beta
- Fixed order card button layout.
- Reworked PDF/PNG export canvas layout to prevent overflow and clipping.

## 1.1.8-beta
- PDF indirme gerçek PDF dosyası oluşturacak şekilde ayrıldı.
- PNG indirme gerçek PNG görsel çıktısı oluşturacak şekilde eklendi.
- Yazdır, PDF ve PNG butonları ayrı işlevlere bağlandı.

# Changelog

## 1.1.8-beta
- A5 etiket çıktı ekranı yenilendi.
- Sipariş barkodu yan bilgi alanı taşması düzeltildi.
- Sol kontrol paneli, zoom, görünüm seçenekleri ve A5 yazdırma düzeni eklendi.
- Yazdırma CSS'i A5 portrait kağıda göre optimize edildi.

# Changelog

## 1.1.8-beta
- Added A4 printable DHL label module.
- Added editable label settings: logo, sender name, sender address, sender phone, accent color and note.
- Added order card actions: Etiket Yazdır and ZPL İndir.
- Added browser print page with barcode SVG and ZPL copy support.

## 1.1.8-beta
- DHL createbarcode success response now distinguishes real shipment barcode from reference/order barcode.
- Stores ZPL label output separately.
- Order card now shows barcode type, barcode value and ZPL availability.
- Avoids marking orders as shipped when DHL only returns a reference barcode without shipmentId.

# Changelog

## 1.0.1-beta

- Refactored one-file plugin into modular class structure.
- Added Plus Command `createRecipient` before `createOrder`.
- Kept piece barcode consistent between `createOrder` and `createbarcode`.
- Added optional barcode integration page.
- Added WooCommerce-native email wrapper for customer tracking emails.
- Kept HPOS compatibility declaration.
