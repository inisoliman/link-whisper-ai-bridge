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

function lwai_bridge_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$normalized = LWAI_Bridge_Settings::normalize_base_url(' https://provider.example.com/v1/ ');
lwai_bridge_assert($normalized === 'https://provider.example.com/v1', 'Base URL should be trimmed and untrailed.');

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

echo "All bridge self-tests passed.\n";
