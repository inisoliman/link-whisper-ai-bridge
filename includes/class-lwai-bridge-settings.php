<?php

class LWAI_Bridge_Settings
{
    const OPTION = 'lwai_bridge_settings';
    const PREVIOUS_WPIL_KEY_OPTION = 'lwai_bridge_previous_wpil_open_ai_api_key';
    const LINK_WHISPER_SENTINEL_KEY = 'sk-lwai-bridge-enabled';

    public static function defaults()
    {
        return array(
            'enabled' => 0,
            'base_url' => '',
            'api_key' => '',
            'chat_model' => 'gpt-4o-mini',
            'embedding_mode' => 'auto',
            'embedding_base_url' => '',
            'embedding_api_key' => '',
            'embedding_model' => 'text-embedding-3-large',
            'concurrency' => 5,
        );
    }

    public static function get_all()
    {
        $stored = function_exists('get_option') ? get_option(self::OPTION, array()) : array();
        return array_merge(self::defaults(), is_array($stored) ? $stored : array());
    }

    public static function sanitize($input)
    {
        $old = self::get_all();
        $input = is_array($input) ? $input : array();

        $settings = self::defaults();
        $settings['enabled'] = empty($input['enabled']) ? 0 : 1;
        $settings['base_url'] = self::normalize_base_url(isset($input['base_url']) ? $input['base_url'] : '');
        $settings['chat_model'] = self::sanitize_model(isset($input['chat_model']) ? $input['chat_model'] : $old['chat_model'], 'gpt-4o-mini');
        $settings['embedding_mode'] = self::sanitize_embedding_mode(isset($input['embedding_mode']) ? $input['embedding_mode'] : $old['embedding_mode']);
        $settings['embedding_base_url'] = self::normalize_base_url(isset($input['embedding_base_url']) ? $input['embedding_base_url'] : $old['embedding_base_url']);
        $settings['embedding_model'] = self::sanitize_model(isset($input['embedding_model']) ? $input['embedding_model'] : $old['embedding_model'], 'text-embedding-3-large');
        $settings['concurrency'] = self::sanitize_concurrency(isset($input['concurrency']) ? $input['concurrency'] : $old['concurrency']);

        $raw_key = isset($input['api_key']) ? trim((string) $input['api_key']) : '';
        if (!empty($input['clear_api_key'])) {
            $settings['api_key'] = '';
        } elseif ($raw_key !== '' && strpos($raw_key, '***') === false) {
            $settings['api_key'] = self::encrypt_secret($raw_key);
        } else {
            $settings['api_key'] = isset($old['api_key']) ? $old['api_key'] : '';
        }

        $raw_embedding_key = isset($input['embedding_api_key']) ? trim((string) $input['embedding_api_key']) : '';
        if (!empty($input['clear_embedding_api_key'])) {
            $settings['embedding_api_key'] = '';
        } elseif ($raw_embedding_key !== '' && strpos($raw_embedding_key, '***') === false) {
            $settings['embedding_api_key'] = self::encrypt_secret($raw_embedding_key);
        } else {
            $settings['embedding_api_key'] = isset($old['embedding_api_key']) ? $old['embedding_api_key'] : '';
        }

        if (empty($settings['enabled'])) {
            self::restore_link_whisper_key_if_owned(self::get_api_key($old));
        }

        return $settings;
    }

    public static function normalize_base_url($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        return rtrim($url, "/ \t\n\r\0\x0B");
    }

    public static function get_api_key($settings = null)
    {
        if ($settings === null) {
            $settings = self::get_all();
        }

        if (empty($settings['api_key'])) {
            return '';
        }

        return self::decrypt_secret($settings['api_key']);
    }

    public static function get_embedding_api_key($settings = null)
    {
        if ($settings === null) {
            $settings = self::get_all();
        }

        if (empty($settings['embedding_api_key'])) {
            return self::get_api_key($settings);
        }

        return self::decrypt_secret($settings['embedding_api_key']);
    }

    public static function get_embedding_base_url($settings = null)
    {
        if ($settings === null) {
            $settings = self::get_all();
        }

        return !empty($settings['embedding_base_url']) ? $settings['embedding_base_url'] : $settings['base_url'];
    }

    public static function obfuscate_secret($secret)
    {
        $secret = (string) $secret;
        if ($secret === '') {
            return '';
        }

        return str_repeat('*', 24) . substr($secret, -4);
    }

    public static function encrypt_secret($value)
    {
        $value = (string) $value;
        if ($value === '' || !function_exists('openssl_encrypt')) {
            return $value;
        }

        $method = 'aes-256-ctr';
        $iv_length = openssl_cipher_iv_length($method);
        $iv = openssl_random_pseudo_bytes($iv_length);
        $encrypted = openssl_encrypt($value, $method, self::crypto_key(), 0, $iv);

        return $encrypted ? 'enc:' . base64_encode($iv . $encrypted) : $value;
    }

    public static function decrypt_secret($value)
    {
        $value = (string) $value;
        if ($value === '' || strpos($value, 'enc:') !== 0 || !function_exists('openssl_decrypt')) {
            return $value;
        }

        $raw = base64_decode(substr($value, 4), true);
        if ($raw === false) {
            return '';
        }

        $method = 'aes-256-ctr';
        $iv_length = openssl_cipher_iv_length($method);
        $iv = substr($raw, 0, $iv_length);
        $encrypted = substr($raw, $iv_length);
        if (!$encrypted || strlen($iv) !== $iv_length) {
            return '';
        }

        $decrypted = openssl_decrypt($encrypted, $method, self::crypto_key(), 0, $iv);
        return $decrypted ? $decrypted : '';
    }

    public static function sync_link_whisper_key()
    {
        if (!function_exists('get_option') || !function_exists('update_option') || !class_exists('Wpil_Toolbox')) {
            return false;
        }

        $existing = get_option('wpil_open_ai_api_key', '');
        $existing_decrypted = self::decrypt_link_whisper_secret($existing);
        if ($existing !== '' && $existing_decrypted !== self::LINK_WHISPER_SENTINEL_KEY && !get_option(self::PREVIOUS_WPIL_KEY_OPTION, '')) {
            update_option(self::PREVIOUS_WPIL_KEY_OPTION, $existing, false);
        }

        update_option('wpil_open_ai_api_key', Wpil_Toolbox::encrypt(self::LINK_WHISPER_SENTINEL_KEY), false);
        update_option('wpil_select_ai_provider', 'openai', false);
        return true;
    }

    public static function sync_link_whisper_models($settings)
    {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return false;
        }

        $settings = array_merge(self::defaults(), is_array($settings) ? $settings : array());
        $models = get_option('wpil_chat_gpt_api', array());
        $models = is_array($models) ? $models : array();
        $placeholder_chat_model = self::link_whisper_placeholder_chat_model();
        foreach (array('suggestion-scoring', 'post-summarizing', 'product-detecting', 'keyword-detecting') as $process) {
            $models[$process] = $placeholder_chat_model;
        }
        $models['create-post-embeddings'] = $settings['embedding_model'];

        update_option('wpil_chat_gpt_api', $models, false);

        if ($settings['embedding_mode'] !== 'provider') {
            update_option('wpil_enable_ai_batch_processing', '0', false);
        }

        return true;
    }

    public static function restore_link_whisper_key_if_owned($bridge_key = '')
    {
        if (!function_exists('get_option') || !function_exists('update_option') || !function_exists('delete_option') || !class_exists('Wpil_Toolbox')) {
            return false;
        }

        $existing = get_option('wpil_open_ai_api_key', '');
        $existing_decrypted = self::decrypt_link_whisper_secret($existing);
        if ($existing_decrypted !== self::LINK_WHISPER_SENTINEL_KEY) {
            return false;
        }

        $previous = get_option(self::PREVIOUS_WPIL_KEY_OPTION, '');
        if ($previous !== '') {
            update_option('wpil_open_ai_api_key', $previous, false);
            delete_option(self::PREVIOUS_WPIL_KEY_OPTION);
        } else {
            delete_option('wpil_open_ai_api_key');
        }

        return true;
    }

    private static function sanitize_model($model, $fallback)
    {
        $model = trim((string) $model);
        return $model === '' ? $fallback : preg_replace('/[^A-Za-z0-9._:\/-]/', '', $model);
    }

    private static function sanitize_embedding_mode($mode)
    {
        $mode = trim((string) $mode);
        return in_array($mode, array('auto', 'provider', 'local'), true) ? $mode : 'auto';
    }

    private static function link_whisper_placeholder_chat_model()
    {
        if (class_exists('Wpil_AI') && method_exists('Wpil_AI', 'get_supported_chat_models')) {
            $supported = Wpil_AI::get_supported_chat_models();
            if (is_array($supported) && isset($supported['gpt-4.1'])) {
                return 'gpt-4.1';
            }
        }

        return 'gpt-4o-mini';
    }

    private static function sanitize_concurrency($value)
    {
        $value = (int) $value;
        if ($value < 1) {
            return 1;
        }

        if ($value > 20) {
            return 20;
        }

        return $value;
    }

    private static function decrypt_link_whisper_secret($value)
    {
        if ($value === '' || !class_exists('Wpil_Toolbox')) {
            return '';
        }

        return Wpil_Toolbox::decrypt($value);
    }

    private static function crypto_key()
    {
        if (function_exists('wp_salt')) {
            return hash('sha256', wp_salt('auth') . '|lwai_bridge', true);
        }

        $salt = defined('AUTH_KEY') ? AUTH_KEY : 'lwai-bridge-local-test-key';
        return hash('sha256', $salt . '|lwai_bridge', true);
    }
}
