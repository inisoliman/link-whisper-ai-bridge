<?php

class LWAI_Bridge_Client
{
    private $client;
    private $chat_model;
    private $embedding_model;

    public function __construct($api_key, $base_url, $chat_model, $embedding_model, $client = null)
    {
        $this->chat_model = $chat_model;
        $this->embedding_model = $embedding_model;
        $this->client = $client ? $client : new \LWVendor\Orhanerday\OpenAi\OpenAi($api_key);

        if (method_exists($this->client, 'setBaseURL')) {
            $this->client->setBaseURL(LWAI_Bridge_Settings::normalize_base_url($base_url));
        }
    }

    public function chat($opts, $stream = null, $multi = false)
    {
        return $this->client->chat(self::replace_models_in_payload($opts, $this->chat_model, $this->embedding_model), $stream, $multi);
    }

    public function embeddings($opts, $multi = false)
    {
        return $this->client->embeddings(self::replace_models_in_payload($opts, $this->chat_model, $this->embedding_model), $multi);
    }

    public function uploadFile($opts)
    {
        $this->rewrite_upload_file($opts);
        return $this->client->uploadFile($opts);
    }

    public function setTimeout($timeout)
    {
        if (method_exists($this->client, 'setTimeout')) {
            $this->client->setTimeout($timeout);
        }
    }

    public function setConcurrency($concurrency)
    {
        if (method_exists($this->client, 'setConcurrency')) {
            $this->client->setConcurrency($concurrency);
        }
    }

    public function getCURLInfo()
    {
        return method_exists($this->client, 'getCURLInfo') ? $this->client->getCURLInfo() : array();
    }

    public function __call($method, $arguments)
    {
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

    private static function is_embedding_payload($payload)
    {
        if (isset($payload['model']) && strpos((string) $payload['model'], 'embedding') !== false) {
            return true;
        }

        return array_key_exists('input', $payload) && !array_key_exists('messages', $payload);
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
