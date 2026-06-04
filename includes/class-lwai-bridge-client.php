<?php

class LWAI_Bridge_Client
{
    private $client;
    private $embedding_client;
    private $last_client;
    private $last_info = array();
    private $chat_model;
    private $embedding_model;
    private $embedding_mode;

    public function __construct($api_key, $base_url, $chat_model, $embedding_model, $client = null, $embedding_api_key = '', $embedding_base_url = '', $embedding_client = null, $embedding_mode = 'auto')
    {
        $this->chat_model = $chat_model;
        $this->embedding_model = $embedding_model;
        $this->embedding_mode = in_array($embedding_mode, array('auto', 'provider', 'local'), true) ? $embedding_mode : 'auto';
        $this->client = $client ? $client : new \LWVendor\Orhanerday\OpenAi\OpenAi($api_key);
        $embedding_api_key = trim((string) $embedding_api_key) !== '' ? $embedding_api_key : $api_key;
        $embedding_base_url = trim((string) $embedding_base_url) !== '' ? $embedding_base_url : $base_url;
        $uses_separate_embedding_client = $embedding_client || $embedding_api_key !== $api_key || LWAI_Bridge_Settings::normalize_base_url($embedding_base_url) !== LWAI_Bridge_Settings::normalize_base_url($base_url);
        $this->embedding_client = $uses_separate_embedding_client ? ($embedding_client ? $embedding_client : new \LWVendor\Orhanerday\OpenAi\OpenAi($embedding_api_key)) : $this->client;
        $this->last_client = $this->client;

        if (method_exists($this->client, 'setBaseURL')) {
            $this->client->setBaseURL(self::base_url_for_openai_client($base_url));
        }
        if (method_exists($this->embedding_client, 'setBaseURL')) {
            $this->embedding_client->setBaseURL(self::base_url_for_openai_client($embedding_base_url));
        }
    }

    public function chat($opts, $stream = null, $multi = false)
    {
        $this->last_client = $this->client;
        return $this->client->chat(self::replace_models_in_payload($opts, $this->chat_model, $this->embedding_model), $stream, $multi);
    }

    public function embeddings($opts, $multi = false)
    {
        if ($this->embedding_mode === 'local') {
            return $this->local_embeddings_response($opts, $multi);
        }

        $this->last_client = $this->embedding_client;
        $response = $this->embedding_client->embeddings(self::replace_models_in_payload($opts, $this->chat_model, $this->embedding_model), $multi);
        $this->last_info = method_exists($this->embedding_client, 'getCURLInfo') ? $this->embedding_client->getCURLInfo() : array();

        if ($this->embedding_mode === 'auto' && $this->embedding_response_failed($response)) {
            return $this->local_embeddings_response($opts, $multi);
        }

        return $response;
    }

    public function uploadFile($opts)
    {
        $this->rewrite_upload_file($opts);
        $client = $this->upload_client_for_opts($opts);
        $this->last_client = $client;
        return $client->uploadFile($opts);
    }

    public function createBatch($opts)
    {
        $client = $this->is_embedding_batch($opts) ? $this->embedding_client : $this->client;
        $this->last_client = $client;
        return $client->createBatch($opts);
    }

    public function setTimeout($timeout)
    {
        if (method_exists($this->client, 'setTimeout')) {
            $this->client->setTimeout($timeout);
        }
        if ($this->embedding_client !== $this->client && method_exists($this->embedding_client, 'setTimeout')) {
            $this->embedding_client->setTimeout($timeout);
        }
    }

    public function setConcurrency($concurrency)
    {
        if (method_exists($this->client, 'setConcurrency')) {
            $this->client->setConcurrency($concurrency);
        }
        if ($this->embedding_client !== $this->client && method_exists($this->embedding_client, 'setConcurrency')) {
            $this->embedding_client->setConcurrency($concurrency);
        }
    }

    public function getCURLInfo()
    {
        if (!empty($this->last_info)) {
            return $this->last_info;
        }

        return method_exists($this->last_client, 'getCURLInfo') ? $this->last_client->getCURLInfo() : array();
    }

    public function __call($method, $arguments)
    {
        $this->last_client = $this->client;
        return call_user_func_array(array($this->client, $method), $arguments);
    }

    public static function replace_models_in_payload($payload, $chat_model, $embedding_model)
    {
        if (!is_array($payload)) {
            return $payload;
        }

        if (isset($payload['model'])) {
            $payload['model'] = self::is_embedding_payload($payload) ? $embedding_model : $chat_model;
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::replace_models_in_payload($value, $chat_model, $embedding_model);
            }
        }

        return $payload;
    }

    public static function replace_models_in_jsonl($content, $chat_model, $embedding_model)
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $content);
        $rewritten = array();

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['body']) && is_array($decoded['body'])) {
                $decoded['body'] = self::replace_models_in_payload($decoded['body'], $chat_model, $embedding_model);
                $line = json_encode($decoded);
            }

            $rewritten[] = $line;
        }

        return implode("\n", $rewritten);
    }

    public static function base_url_for_openai_client($base_url)
    {
        $base_url = LWAI_Bridge_Settings::normalize_base_url($base_url);
        return preg_replace('#/v1$#i', '', $base_url);
    }

    private static function is_embedding_payload($payload)
    {
        if (isset($payload['model']) && strpos((string) $payload['model'], 'embedding') !== false) {
            return true;
        }

        return array_key_exists('input', $payload) && !array_key_exists('messages', $payload);
    }

    private function upload_client_for_opts($opts)
    {
        if (empty($opts['file']) || !is_object($opts['file']) || !method_exists($opts['file'], 'getFilename')) {
            return $this->client;
        }

        $path = $opts['file']->getFilename();
        if (!is_string($path) || !is_readable($path)) {
            return $this->client;
        }

        $handle = fopen($path, 'r');
        if (!$handle) {
            return $this->client;
        }

        $line = fgets($handle);
        fclose($handle);
        $decoded = json_decode((string) $line, true);
        if (is_array($decoded) && isset($decoded['url']) && false !== strpos((string) $decoded['url'], '/embeddings')) {
            return $this->embedding_client;
        }

        return $this->client;
    }

    private function is_embedding_batch($opts)
    {
        return is_array($opts) && isset($opts['endpoint']) && false !== strpos((string) $opts['endpoint'], '/embeddings');
    }

    private function embedding_response_failed($response)
    {
        $items = is_array($response) ? $response : array($response);
        if (empty($items)) {
            return true;
        }

        foreach ($items as $item) {
            $decoded = is_string($item) ? json_decode($item) : $item;
            if (empty($decoded) || isset($decoded->error) || !isset($decoded->data) || empty($decoded->data)) {
                return true;
            }
        }

        return false;
    }

    private function local_embeddings_response($opts, $multi = false)
    {
        $payload = self::replace_models_in_payload($opts, $this->chat_model, $this->embedding_model);
        $dimensions = isset($payload['dimensions']) ? (int) $payload['dimensions'] : 0;
        if ($dimensions < 2) {
            $dimensions = class_exists('Wpil_Settings') && method_exists('Wpil_Settings', 'get_ai_dimension_limit') ? (int) Wpil_Settings::get_ai_dimension_limit() : 2048;
        }

        $this->last_info = array(
            'http_code' => 200,
            'url' => 'local://lwai-lexical-embeddings',
            'content_type' => 'application/json; charset=UTF-8',
        );

        if ($multi && isset($payload['message_list']) && is_array($payload['message_list'])) {
            $responses = array();
            foreach ($payload['message_list'] as $index => $item) {
                $responses[$index] = $this->single_local_embedding_response(isset($item['input']) ? $item['input'] : '', $dimensions);
            }
            return $responses;
        }

        return $this->single_local_embedding_response(isset($payload['input']) ? $payload['input'] : '', $dimensions);
    }

    private function single_local_embedding_response($input, $dimensions)
    {
        $text = is_array($input) ? implode(' ', array_map('strval', $input)) : (string) $input;
        $tokens = self::tokenize_for_local_embedding($text);
        $vector = array_fill(0, $dimensions, 0.0);
        $token_count = 0;

        foreach ($tokens as $token) {
            $token_count++;
            $hash = (int) sprintf('%u', crc32($token));
            $index = (int) ($hash % $dimensions);
            $sign = (($hash >> 1) & 1) ? 1.0 : -1.0;
            $vector[$index] += $sign;
        }

        $length = 0.0;
        foreach ($vector as $value) {
            $length += $value * $value;
        }
        $length = sqrt($length);
        if ($length > 0) {
            foreach ($vector as $index => $value) {
                $vector[$index] = round($value / $length, 8);
            }
        }

        return wp_json_encode(array(
            'object' => 'list',
            'data' => array(
                array(
                    'object' => 'embedding',
                    'index' => 0,
                    'embedding' => $vector,
                ),
            ),
            'model' => 'lwai-local-lexical-' . $dimensions,
            'usage' => array(
                'prompt_tokens' => $token_count,
                'total_tokens' => $token_count,
            ),
        ));
    }

    private static function tokenize_for_local_embedding($text)
    {
        $text = function_exists('wp_strip_all_tags') ? wp_strip_all_tags((string) $text) : strip_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = str_replace(array('أ', 'إ', 'آ', 'ى', 'ة'), array('ا', 'ا', 'ا', 'ي', 'ه'), $text);
        preg_match_all('/[\p{L}\p{N}]{2,}/u', $text, $matches);
        return !empty($matches[0]) ? $matches[0] : array();
    }

    private function rewrite_upload_file($opts)
    {
        if (empty($opts['file']) || !is_object($opts['file']) || !method_exists($opts['file'], 'getFilename')) {
            return;
        }

        $path = $opts['file']->getFilename();
        if (!is_string($path) || !is_readable($path) || !is_writable($path)) {
            return;
        }

        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return;
        }

        $rewritten = self::replace_models_in_jsonl($content, $this->chat_model, $this->embedding_model);
        if ($rewritten !== $content) {
            file_put_contents($path, $rewritten);
        }
    }
}
