<?php
if (!defined('ABSPATH')) { exit; }

final class DHLWC_API_Client {
    private $settings;

    public function __construct(array $settings = null) {
        $this->settings = $settings ?: DHLWC_Settings::get();
    }

    public function base_url() {
        return $this->settings['environment'] === 'test'
            ? 'https://testapi.mngkargo.com.tr/mngapi/api'
            : 'https://api.mngkargo.com.tr/mngapi/api';
    }

    public function token($force_refresh = false) {
        if (empty($this->settings['customer_number']) || empty($this->settings['customer_password']) || empty($this->settings['client_id']) || empty($this->settings['client_secret'])) {
            return new WP_Error('dhlwc_missing_settings', 'Shipping customer number, password, Client ID and Client Secret are required.');
        }

        $cache_key = 'dhlwc_token_' . md5($this->settings['environment'] . $this->settings['customer_number'] . $this->settings['client_id']);
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if ($cached) { return $cached; }
        } else {
            delete_transient($cache_key);
        }

        $payload = array(
            'customerNumber' => $this->settings['customer_number'],
            'password' => $this->settings['customer_password'],
            'identityType' => 1,
        );

        $response = $this->request_raw('POST', '/token', $payload, '');
        if (is_wp_error($response)) { return $response; }
        if (empty($response['jwt'])) {
            return new WP_Error('dhlwc_token_error', 'The shipping API token could not be retrieved.', $response);
        }
        set_transient($cache_key, $response['jwt'], 7 * HOUR_IN_SECONDS);
        return $response['jwt'];
    }

    public function request($method, $path, $payload = null) {
        $token = $this->token(false);
        if (is_wp_error($token)) { return $token; }
        return $this->request_raw($method, $path, $payload, $token);
    }

    private function request_raw($method, $path, $payload = null, $token = '') {
        $headers = array(
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'x-ibm-client-id' => $this->settings['client_id'],
            'x-ibm-client-secret' => $this->settings['client_secret'],
        );
        if ($token) { $headers['Authorization'] = 'Bearer ' . $token; }

        $args = array('method' => $method, 'headers' => $headers, 'timeout' => 45);
        if ($payload !== null) {
            $args['body'] = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        $url = $this->base_url() . $path;
        $response = wp_remote_request($url, $args);
        return $this->parse_response($response, $path, $payload);
    }

    private function parse_response($response, $path, $payload) {
        if (is_wp_error($response)) { return $response; }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode((string) $body, true);
        $data = is_array($decoded) ? $decoded : array('raw' => $body);
        if ($code < 200 || $code >= 300 || isset($data['error'])) {
            return new WP_Error('dhlwc_api_error', $this->extract_error_message($data, $code), array('status' => $code, 'body' => $data, 'path' => $path));
        }
        return $data;
    }

    private function extract_error_message($data, $code) {
        if (isset($data['error']['description']) && $data['error']['description']) { return $data['error']['description']; }
        if (isset($data['error']['Description']) && $data['error']['Description']) { return $data['error']['Description']; }
        if (isset($data['error']['message'])) { return $data['error']['message']; }
        if (isset($data['error']['Message'])) { return $data['error']['Message']; }
        if (isset($data['httpMessage'])) { return $data['httpMessage']; }
        return 'Shipping API error: ' . $code;
    }
}
