<?php
if (!defined('ABSPATH')) { exit; }

final class DHLWC_Constants {
    const OPTION_KEY = 'dhlwc_settings';
    const CRON_HOOK = 'dhlwc_sync_tracking_cron';
    const BARCODE_RETRY_HOOK = 'dhlwc_retry_create_barcode';

    const META_SENT = '_dhlwc_sent';
    const META_RECIPIENT_CREATED = '_dhlwc_recipient_created';
    const META_REFERENCE_ID = '_dhlwc_reference_id';
    const META_PIECE_BARCODE = '_dhlwc_piece_barcode';
    const META_RESPONSE = '_dhlwc_response';
    const META_ERROR = '_dhlwc_error';
    const META_TRACKING_URL = '_dhlwc_tracking_url';
    const META_BARCODE_CREATED = '_dhlwc_barcode_created';
    const META_BARCODE_RESPONSE = '_dhlwc_barcode_response';
    const META_BARCODE_TYPE = '_dhlwc_barcode_type';
    const META_BARCODE_ZPL = '_dhlwc_barcode_zpl';
    const META_BARCODE_VALUE = '_dhlwc_barcode_value';
    const META_SHIPMENT_ID = '_dhlwc_shipment_id';
    const META_INVOICE_ID = '_dhlwc_invoice_id';
    const META_LAST_STAGE = '_dhlwc_last_stage';
    const META_TRACKING_ACTIVE = '_dhlwc_tracking_active';
    const META_STATUS_RESPONSE = '_dhlwc_status_response';
    const META_ORDER_CREATED_AT = '_dhlwc_order_created_at';
    const META_BARCODE_ATTEMPTS = '_dhlwc_barcode_attempts';
}
