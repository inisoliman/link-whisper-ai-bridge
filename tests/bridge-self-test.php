<?php

$root = dirname(__DIR__);

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data)
    {
        return json_encode($data);
    }
}

require_once $root . '/includes/class-lwai-bridge-settings.php';
require_once $root . '/includes/class-lwai-bridge-client.php';
require_once $root . '/includes/class-lwai-bridge-link-whisper-advisor.php';
require_once $root . '/includes/class-lwai-bridge-plugin.php';

class LWAI_Bridge_Test_Client
{
    public function setBaseURL($url)
    {
    }
}

class LWAI_Bridge_Failing_Embedding_Test_Client extends LWAI_Bridge_Test_Client
{
    public function embeddings($opts, $multi = false)
    {
        return '{"detail":"Not Found"}';
    }

    public function getCURLInfo()
    {
        return array(
            'http_code' => 404,
            'url' => 'https://provider.example.com/v1/embeddings',
        );
    }
}

function lwai_bridge_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$normalized = LWAI_Bridge_Settings::normalize_base_url(' https://provider.example.com/v1/ ');
lwai_bridge_assert($normalized === 'https://provider.example.com/v1', 'Base URL should be trimmed and untrailed.');
$client_base = LWAI_Bridge_Client::base_url_for_openai_client(' https://provider.example.com/v1/ ');
lwai_bridge_assert($client_base === 'https://provider.example.com', 'Client Base URL should remove trailing /v1 because the bundled OpenAI client adds /v1 itself.');

$sanitized = LWAI_Bridge_Settings::sanitize(array(
    'enabled' => 1,
    'base_url' => 'provider.example.com/v1',
    'chat_model' => 'chat-model-x',
    'embedding_mode' => 'local',
    'embedding_base_url' => 'https://embeddings.example.com/v1/',
    'embedding_model' => 'embed-model-y',
    'concurrency' => 200,
));
lwai_bridge_assert($sanitized['concurrency'] === 20, 'Concurrency should be capped for external providers.');
lwai_bridge_assert($sanitized['embedding_mode'] === 'local', 'Embedding mode should be sanitized.');
lwai_bridge_assert($sanitized['embedding_base_url'] === 'https://embeddings.example.com/v1', 'Embedding Base URL should be normalized.');

$chat_payload = array(
    'model' => 'gpt-4o-mini',
    'message_list' => array(
        array(
            'messages' => array(
                array('role' => 'user', 'content' => 'hello'),
            ),
        ),
    ),
);

$replaced_chat = LWAI_Bridge_Client::replace_models_in_payload($chat_payload, 'chat-model-x', 'embed-model-y');
lwai_bridge_assert($replaced_chat['model'] === 'chat-model-x', 'Top-level chat model should be replaced.');
lwai_bridge_assert(!isset($replaced_chat['message_list'][0]['model']), 'Chat message_list items without models should remain model-free.');

$embedding_payload = array(
    'message_list' => array(
        array(
            'model' => 'text-embedding-3-large',
            'input' => 'content',
            'dimensions' => 2048,
        ),
    ),
);

$replaced_embeddings = LWAI_Bridge_Client::replace_models_in_payload($embedding_payload, 'chat-model-x', 'embed-model-y');
lwai_bridge_assert($replaced_embeddings['message_list'][0]['model'] === 'embed-model-y', 'Embedding message_list model should be replaced.');

$batch_line = wp_json_encode(array(
    'custom_id' => 'post_1',
    'body' => array(
        'model' => 'text-embedding-3-large',
        'input' => 'content',
    ),
));
$rewritten = LWAI_Bridge_Client::replace_models_in_jsonl($batch_line, 'chat-model-x', 'embed-model-y');
$decoded = json_decode($rewritten);
lwai_bridge_assert($decoded->body->model === 'embed-model-y', 'Batch embedding model should be replaced in JSONL.');

$local_client = new LWAI_Bridge_Client('key', 'https://provider.example.com/v1', 'chat-model-x', 'embed-model-y', new LWAI_Bridge_Test_Client(), '', '', null, 'local');
$local_embedding = json_decode($local_client->embeddings(array('model' => 'embed-model-y', 'input' => 'alpha beta alpha', 'dimensions' => 8)));
lwai_bridge_assert(isset($local_embedding->data[0]->embedding) && count($local_embedding->data[0]->embedding) === 8, 'Local embeddings should return the requested vector size.');
$local_info = $local_client->getCURLInfo();
lwai_bridge_assert(isset($local_info['url']) && $local_info['url'] === 'local://lwai-lexical-embeddings', 'Local embeddings should expose a diagnostic URL.');

$auto_fallback_client = new LWAI_Bridge_Client('key', 'https://provider.example.com/v1', 'chat-model-x', 'embed-model-y', new LWAI_Bridge_Test_Client(), '', '', new LWAI_Bridge_Failing_Embedding_Test_Client(), 'auto');
$auto_fallback_embedding = json_decode($auto_fallback_client->embeddings(array('model' => 'embed-model-y', 'input' => 'fallback needed', 'dimensions' => 8)));
lwai_bridge_assert(isset($auto_fallback_embedding->data[0]->embedding) && count($auto_fallback_embedding->data[0]->embedding) === 8, 'Auto mode should use local embeddings when provider response has no data field.');
$auto_fallback_info = $auto_fallback_client->getCURLInfo();
lwai_bridge_assert(isset($auto_fallback_info['url']) && $auto_fallback_info['url'] === 'local://lwai-lexical-embeddings', 'Auto fallback should expose the local diagnostic URL.');

$successful_summary = LWAI_Bridge_Plugin::summarize_provider_response(
    '{"choices":[{"message":{"content":"pong"}}]}',
    'choices',
    array('http_code' => 200, 'url' => 'https://provider.example.com/v1/chat/completions')
);
lwai_bridge_assert($successful_summary['ok'] === true, 'Successful provider summary should be ok.');
lwai_bridge_assert($successful_summary['http_code'] === 200, 'Provider summary should include HTTP code.');
lwai_bridge_assert($successful_summary['url'] === 'https://provider.example.com/v1/chat/completions', 'Provider summary should include the requested URL.');

$error_summary = LWAI_Bridge_Plugin::summarize_provider_response(
    '{"error":{"message":"invalid model","type":"invalid_request_error"}}',
    'choices',
    array('http_code' => 400)
);
lwai_bridge_assert($error_summary['ok'] === false, 'Provider error summary should not be ok.');
lwai_bridge_assert(strpos($error_summary['message'], 'invalid model') !== false, 'Provider error summary should expose the provider message.');

$invalid_json_summary = LWAI_Bridge_Plugin::summarize_provider_response('<html>Forbidden</html>', 'choices', array('http_code' => 403));
lwai_bridge_assert($invalid_json_summary['ok'] === false, 'Invalid JSON provider summary should not be ok.');
lwai_bridge_assert($invalid_json_summary['http_code'] === 403, 'Invalid JSON summary should include HTTP code.');
lwai_bridge_assert(isset($invalid_json_summary['response_preview']), 'Invalid JSON summary should include a response preview.');

$allowed_options = LWAI_Bridge_Link_Whisper_Advisor::get_allowed_options();
lwai_bridge_assert(!empty($allowed_options), 'Advisor should expose an option allowlist.');
foreach ($allowed_options as $option_key) {
    lwai_bridge_assert(strpos($option_key, 'wpil_') === 0, 'Advisor allowlist should only contain Link Whisper option keys.');
    lwai_bridge_assert($option_key !== 'wpil_open_ai_api_key', 'Advisor must not manage API key options.');
}

$advisor_facts = array(
    'link_whisper_active' => true,
    'bridge_ready' => true,
    'site_locale' => 'ar',
    'post_types' => array(
        'post' => array('label' => 'مقالات', 'count' => 25),
        'page' => array('label' => 'صفحات', 'count' => 8),
    ),
    'taxonomies' => array(
        'category' => array('label' => 'تصنيفات', 'count' => 7),
        'post_tag' => array('label' => 'وسوم', 'count' => 12),
    ),
    'has_product_like_content' => false,
    'current_options' => array(
        'wpil_selected_language' => 'english',
        'wpil_2_post_types' => array('post'),
        'wpil_suggestion_limited_post_types' => array('post'),
        'wpil_2_term_types' => array('category'),
        'wpil_use_ai_suggestions' => 0,
        'wpil_enable_ai_batch_processing' => 0,
        'wpil_selected_ai_batch_processes' => array(3),
        'wpil_restrict_to_top_ai_suggestions' => 0,
        'wpil_disable_ai_anchor_building' => 1,
        'wpil_suggestion_relatedness_threshold' => '0.4500',
        'wpil_ai_auto_insert_relatedness_threshold' => '0.6000',
        'wpil_sitemap_embedding_relatedness_threshold' => '0.7000',
        'wpil_force_https_links' => 0,
        'wpil_prevent_keyword_cannibalization' => 0,
        'wpil_enable_autolink_cron_task' => 1,
    ),
);
$recommendations = LWAI_Bridge_Link_Whisper_Advisor::build_recommendations($advisor_facts);
lwai_bridge_assert(count($recommendations) >= 8, 'Advisor should generate useful Link Whisper recommendations.');
foreach ($recommendations as $recommendation) {
    lwai_bridge_assert(isset($recommendation['title_ar']) && preg_match('/[\x{0600}-\x{06FF}]/u', $recommendation['title_ar']), 'Recommendation titles should be Arabic.');
    lwai_bridge_assert(isset($recommendation['reason_ar']) && preg_match('/[\x{0600}-\x{06FF}]/u', $recommendation['reason_ar']), 'Recommendation reasons should be Arabic.');
    lwai_bridge_assert(in_array($recommendation['option_key'], $allowed_options, true), 'Recommendations should only target allowlisted Link Whisper options.');
    lwai_bridge_assert(stripos($recommendation['option_key'], 'api') === false, 'Recommendations must not target API options.');
}

$fallback_batch_facts = $advisor_facts;
$fallback_batch_facts['embedding_mode'] = 'auto';
$fallback_batch_facts['current_options']['wpil_enable_ai_batch_processing'] = 1;
$fallback_batch_recommendations = LWAI_Bridge_Link_Whisper_Advisor::build_recommendations($fallback_batch_facts);
$found_disable_batch = false;
$found_enable_batch = false;
foreach ($fallback_batch_recommendations as $recommendation) {
    if ($recommendation['option_key'] === 'wpil_enable_ai_batch_processing' && (int) $recommendation['recommended'] === 0) {
        $found_disable_batch = true;
    }
    if ($recommendation['option_key'] === 'wpil_enable_ai_batch_processing' && (int) $recommendation['recommended'] === 1) {
        $found_enable_batch = true;
    }
}
lwai_bridge_assert($found_disable_batch, 'Advisor should disable Link Whisper batch processing when embedding fallback may be used.');
lwai_bridge_assert(!$found_enable_batch, 'Advisor should not enable Link Whisper batch processing in embedding fallback modes.');

echo "All bridge self-tests passed.\n";
