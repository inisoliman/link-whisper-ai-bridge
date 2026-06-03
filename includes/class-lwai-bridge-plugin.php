<?php

class LWAI_Bridge_Plugin
{
    private static $instance;
    private $notices = array();

    public static function register()
    {
        add_action('plugins_loaded', array(__CLASS__, 'init'), 20);
    }

    public static function init()
    {
        self::$instance = new self();
        self::$instance->register_hooks();
        self::$instance->patch_link_whisper_client();
    }

    private function register_hooks()
    {
        if (is_admin()) {
            add_action('admin_menu', array($this, 'register_admin_page'));
            add_action('admin_init', array($this, 'register_settings'));
            add_action('admin_notices', array($this, 'render_notices'));
            add_action('wp_ajax_lwai_bridge_test_connection', array($this, 'ajax_test_connection'));
            add_action('wp_ajax_lwai_bridge_scan_lw_settings', array($this, 'ajax_scan_link_whisper_settings'));
            add_action('wp_ajax_lwai_bridge_apply_lw_settings', array($this, 'ajax_apply_link_whisper_settings'));
            add_action('wp_ajax_lwai_bridge_restore_lw_settings', array($this, 'ajax_restore_link_whisper_settings'));
        }
    }

    public function register_admin_page()
    {
        add_options_page(
            __('Link Whisper AI Bridge', 'lwai-bridge'),
            __('Link Whisper AI Bridge', 'lwai-bridge'),
            'manage_options',
            'lwai-bridge',
            array($this, 'render_admin_page')
        );
    }

    public function register_settings()
    {
        register_setting(
            'lwai_bridge',
            LWAI_Bridge_Settings::OPTION,
            array(
                'type' => 'array',
                'sanitize_callback' => array('LWAI_Bridge_Settings', 'sanitize'),
                'default' => LWAI_Bridge_Settings::defaults(),
            )
        );
    }

    public function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = LWAI_Bridge_Settings::get_all();
        $api_key = LWAI_Bridge_Settings::get_api_key($settings);
        $display_key = LWAI_Bridge_Settings::obfuscate_secret($api_key);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Link Whisper AI Bridge', 'lwai-bridge'); ?></h1>
            <p><?php esc_html_e('Use an OpenAI-compatible provider for Link Whisper Premium AI features without editing Link Whisper files.', 'lwai-bridge'); ?></p>

            <form method="post" action="options.php">
                <?php settings_fields('lwai_bridge'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable bridge', 'lwai-bridge'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(LWAI_Bridge_Settings::OPTION); ?>[enabled]" value="1" <?php checked(!empty($settings['enabled'])); ?>>
                                <?php esc_html_e('Route Link Whisper AI calls through this provider.', 'lwai-bridge'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lwai-bridge-base-url"><?php esc_html_e('Base URL', 'lwai-bridge'); ?></label></th>
                        <td>
                            <input id="lwai-bridge-base-url" class="regular-text" type="url" name="<?php echo esc_attr(LWAI_Bridge_Settings::OPTION); ?>[base_url]" value="<?php echo esc_attr($settings['base_url']); ?>" placeholder="https://provider.example.com/v1">
                            <p class="description"><?php esc_html_e('Use the OpenAI-compatible API base URL, usually ending in /v1.', 'lwai-bridge'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lwai-bridge-api-key"><?php esc_html_e('API key', 'lwai-bridge'); ?></label></th>
                        <td>
                            <input id="lwai-bridge-api-key" class="regular-text" type="password" name="<?php echo esc_attr(LWAI_Bridge_Settings::OPTION); ?>[api_key]" value="<?php echo esc_attr($display_key); ?>" autocomplete="off">
                            <?php if ($api_key !== '') : ?>
                                <label style="display:block;margin-top:8px;">
                                    <input type="checkbox" name="<?php echo esc_attr(LWAI_Bridge_Settings::OPTION); ?>[clear_api_key]" value="1">
                                    <?php esc_html_e('Clear stored API key', 'lwai-bridge'); ?>
                                </label>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lwai-bridge-chat-model"><?php esc_html_e('Chat model', 'lwai-bridge'); ?></label></th>
                        <td>
                            <input id="lwai-bridge-chat-model" class="regular-text" type="text" name="<?php echo esc_attr(LWAI_Bridge_Settings::OPTION); ?>[chat_model]" value="<?php echo esc_attr($settings['chat_model']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lwai-bridge-embedding-model"><?php esc_html_e('Embedding model', 'lwai-bridge'); ?></label></th>
                        <td>
                            <input id="lwai-bridge-embedding-model" class="regular-text" type="text" name="<?php echo esc_attr(LWAI_Bridge_Settings::OPTION); ?>[embedding_model]" value="<?php echo esc_attr($settings['embedding_model']); ?>">
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save Bridge Settings', 'lwai-bridge')); ?>
            </form>

            <hr>
            <h2><?php esc_html_e('Connection Test', 'lwai-bridge'); ?></h2>
            <p><?php esc_html_e('After saving, use this test inside WordPress to confirm chat completions and embeddings both respond.', 'lwai-bridge'); ?></p>
            <button type="button" class="button" id="lwai-bridge-test"><?php esc_html_e('Test Provider', 'lwai-bridge'); ?></button>
            <pre id="lwai-bridge-test-result" style="white-space:pre-wrap;margin-top:12px;"></pre>

            <hr>
            <h2><?php esc_html_e('مساعد ضبط Link Whisper', 'lwai-bridge'); ?></h2>
            <p><?php esc_html_e('يفحص هذا المساعد إعدادات Link Whisper فقط، ثم يعرض تقريرًا عربيًا بتوصيات قابلة للاختيار قبل تطبيق أي تغيير.', 'lwai-bridge'); ?></p>
            <p>
                <button type="button" class="button button-primary" id="lwai-bridge-advisor-scan"><?php esc_html_e('فحص إعدادات Link Whisper', 'lwai-bridge'); ?></button>
                <button type="button" class="button" id="lwai-bridge-advisor-apply" disabled><?php esc_html_e('تطبيق التوصيات المحددة', 'lwai-bridge'); ?></button>
                <button type="button" class="button" id="lwai-bridge-advisor-restore"><?php esc_html_e('استرجاع آخر تغييرات المساعد', 'lwai-bridge'); ?></button>
            </p>
            <div id="lwai-bridge-advisor-result" dir="rtl" style="max-width:980px;margin-top:12px;"></div>
        </div>
        <script>
        (function(){
            var button = document.getElementById('lwai-bridge-test');
            var output = document.getElementById('lwai-bridge-test-result');
            if (!button || !output) {
                return;
            }
            button.addEventListener('click', function(){
                output.textContent = 'Testing...';
                var data = new window.FormData();
                data.append('action', 'lwai_bridge_test_connection');
                data.append('nonce', '<?php echo esc_js(wp_create_nonce('lwai_bridge_test')); ?>');
                window.fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
                    .then(function(response){ return response.json(); })
                    .then(function(json){ output.textContent = JSON.stringify(json.data || json, null, 2); })
                    .catch(function(error){ output.textContent = error.message; });
            });

            var advisorNonce = '<?php echo esc_js(wp_create_nonce('lwai_bridge_advisor')); ?>';
            var scanButton = document.getElementById('lwai-bridge-advisor-scan');
            var applyButton = document.getElementById('lwai-bridge-advisor-apply');
            var restoreButton = document.getElementById('lwai-bridge-advisor-restore');
            var advisorResult = document.getElementById('lwai-bridge-advisor-result');
            var lastReport = null;

            function advisorRequest(action, fields) {
                var data = new window.FormData();
                data.append('action', action);
                data.append('nonce', advisorNonce);
                fields = fields || {};
                Object.keys(fields).forEach(function(key) {
                    data.append(key, fields[key]);
                });

                return window.fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
                    .then(function(response) { return response.json(); })
                    .then(function(json) {
                        if (!json || !json.success) {
                            var message = json && json.data && json.data.message_ar ? json.data.message_ar : 'حدث خطأ أثناء تنفيذ الطلب.';
                            throw new Error(message);
                        }

                        return json.data;
                    });
            }

            function clearAdvisorResult(message) {
                advisorResult.textContent = '';
                if (message) {
                    var paragraph = document.createElement('p');
                    paragraph.textContent = message;
                    advisorResult.appendChild(paragraph);
                }
            }

            function appendTextLine(parent, label, value) {
                var line = document.createElement('p');
                var strong = document.createElement('strong');
                strong.textContent = label;
                line.appendChild(strong);
                line.appendChild(document.createTextNode(value));
                parent.appendChild(line);
            }

            function renderFacts(parent, facts) {
                var box = document.createElement('div');
                box.style.border = '1px solid #dcdcde';
                box.style.background = '#fff';
                box.style.padding = '12px';
                box.style.margin = '0 0 12px';
                appendTextLine(box, 'حالة Link Whisper: ', facts && facts.link_whisper_active ? 'مفعّل' : 'غير ظاهر للمساعد');
                appendTextLine(box, 'حالة الجسر: ', facts && facts.bridge_ready ? 'جاهز للاستخدام' : 'يحتاج حفظ بيانات المزود وتفعيل الجسر');
                appendTextLine(box, 'لغة الموقع: ', facts && facts.site_locale ? facts.site_locale : 'غير معروفة');
                parent.appendChild(box);
            }

            function renderRecommendation(parent, recommendation) {
                var card = document.createElement('div');
                card.style.border = '1px solid #dcdcde';
                card.style.borderRadius = '4px';
                card.style.background = '#fff';
                card.style.padding = '14px';
                card.style.margin = '0 0 10px';

                var label = document.createElement('label');
                label.style.display = 'flex';
                label.style.gap = '10px';
                label.style.alignItems = 'flex-start';

                var checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.className = 'lwai-bridge-advisor-choice';
                checkbox.value = recommendation.id;
                checkbox.checked = !!recommendation.selected_by_default;
                checkbox.style.marginTop = '5px';
                label.appendChild(checkbox);

                var content = document.createElement('div');
                content.style.display = 'block';

                var title = document.createElement('strong');
                title.textContent = recommendation.title_ar + ' ';
                content.appendChild(title);

                var badge = document.createElement('span');
                badge.textContent = recommendation.risk_label_ar;
                badge.style.display = 'inline-block';
                badge.style.padding = '2px 7px';
                badge.style.borderRadius = '999px';
                badge.style.background = recommendation.risk === 'safe' ? '#edfaef' : '#fff8e5';
                badge.style.color = recommendation.risk === 'safe' ? '#0a6f2b' : '#7a5300';
                badge.style.fontSize = '12px';
                content.appendChild(badge);

                var reason = document.createElement('p');
                reason.textContent = recommendation.reason_ar;
                reason.style.margin = '8px 0';
                content.appendChild(reason);

                appendTextLine(content, 'الإعداد: ', recommendation.option_key);
                appendTextLine(content, 'الحالي: ', recommendation.current_label);
                appendTextLine(content, 'المقترح: ', recommendation.recommended_label);

                if (recommendation.requires_rescan) {
                    var rescan = document.createElement('p');
                    rescan.textContent = 'ملاحظة: قد تحتاج بعد التطبيق إلى تشغيل فحص Link Whisper حتى يعيد بناء بيانات الاقتراحات.';
                    rescan.style.color = '#646970';
                    content.appendChild(rescan);
                }

                label.appendChild(content);
                card.appendChild(label);
                parent.appendChild(card);
            }

            function renderReport(report) {
                lastReport = report;
                clearAdvisorResult(report.message_ar || '');
                renderFacts(advisorResult, report.facts || {});

                var recommendations = report.recommendations || [];
                if (!recommendations.length) {
                    applyButton.disabled = true;
                    return;
                }

                recommendations.forEach(function(recommendation) {
                    renderRecommendation(advisorResult, recommendation);
                });
                applyButton.disabled = false;
            }

            function selectedRecommendationIds() {
                var ids = [];
                advisorResult.querySelectorAll('.lwai-bridge-advisor-choice:checked').forEach(function(checkbox) {
                    ids.push(checkbox.value);
                });
                return ids;
            }

            function renderApplied(result) {
                clearAdvisorResult(result.message_ar || 'تم التطبيق.');
                var applied = result.applied || [];
                if (applied.length) {
                    var list = document.createElement('ul');
                    applied.forEach(function(item) {
                        var li = document.createElement('li');
                        li.textContent = item.title_ar + ': ' + item.old_value_ar + ' <- ' + item.new_value_ar;
                        list.appendChild(li);
                    });
                    advisorResult.appendChild(list);
                }
                applyButton.disabled = true;
            }

            if (scanButton && applyButton && restoreButton && advisorResult) {
                scanButton.addEventListener('click', function() {
                    clearAdvisorResult('جاري فحص إعدادات Link Whisper...');
                    applyButton.disabled = true;
                    advisorRequest('lwai_bridge_scan_lw_settings')
                        .then(renderReport)
                        .catch(function(error) { clearAdvisorResult(error.message); });
                });

                applyButton.addEventListener('click', function() {
                    var ids = selectedRecommendationIds();
                    if (!ids.length) {
                        if (lastReport) {
                            renderReport(lastReport);
                        }
                        var warning = document.createElement('p');
                        warning.textContent = 'اختر توصية واحدة على الأقل قبل التطبيق.';
                        warning.style.color = '#b32d2e';
                        advisorResult.insertBefore(warning, advisorResult.firstChild);
                        return;
                    }

                    clearAdvisorResult('جاري تطبيق التوصيات المحددة...');
                    advisorRequest('lwai_bridge_apply_lw_settings', { ids: JSON.stringify(ids) })
                        .then(renderApplied)
                        .catch(function(error) { clearAdvisorResult(error.message); });
                });

                restoreButton.addEventListener('click', function() {
                    clearAdvisorResult('جاري استرجاع آخر نسخة احتياطية...');
                    advisorRequest('lwai_bridge_restore_lw_settings')
                        .then(function(result) {
                            clearAdvisorResult(result.message_ar || 'تم الاسترجاع.');
                            applyButton.disabled = true;
                        })
                        .catch(function(error) { clearAdvisorResult(error.message); });
                });
            }
        }());
        </script>
        <?php
    }

    public function render_notices()
    {
        foreach ($this->notices as $notice) {
            $type = isset($notice['type']) ? $notice['type'] : 'warning';
            $message = isset($notice['message']) ? $notice['message'] : '';
            printf(
                '<div class="notice notice-%1$s"><p>%2$s</p></div>',
                esc_attr($type),
                esc_html($message)
            );
        }
    }

    public function ajax_test_connection()
    {
        check_ajax_referer('lwai_bridge_test', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'lwai-bridge')), 403);
        }

        $settings = LWAI_Bridge_Settings::get_all();
        $api_key = LWAI_Bridge_Settings::get_api_key($settings);
        if ($api_key === '' || $settings['base_url'] === '') {
            wp_send_json_error(array('message' => __('Save a Base URL and API key before testing.', 'lwai-bridge')), 400);
        }

        if (!$this->ensure_openai_vendor()) {
            wp_send_json_error(array('message' => __('Link Whisper OpenAI client was not found.', 'lwai-bridge')), 500);
        }

        $client = new LWAI_Bridge_Client($api_key, $settings['base_url'], $settings['chat_model'], $settings['embedding_model']);
        $chat = $client->chat(array(
            'model' => $settings['chat_model'],
            'messages' => array(
                array('role' => 'system', 'content' => 'Reply with valid JSON only.'),
                array('role' => 'user', 'content' => '{"ping":"pong"}'),
            ),
            'response_format' => array('type' => 'json_object'),
            'temperature' => 0,
        ));
        $embeddings = $client->embeddings(array(
            'model' => $settings['embedding_model'],
            'input' => 'Link Whisper bridge connection test',
        ));

        wp_send_json_success(array(
            'chat' => $this->summarize_provider_response($chat, 'choices'),
            'embeddings' => $this->summarize_provider_response($embeddings, 'data'),
        ));
    }

    public function ajax_scan_link_whisper_settings()
    {
        check_ajax_referer('lwai_bridge_advisor', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message_ar' => 'ليس لديك صلاحية تشغيل هذا الفحص.'), 403);
        }

        wp_send_json_success(LWAI_Bridge_Link_Whisper_Advisor::get_report());
    }

    public function ajax_apply_link_whisper_settings()
    {
        check_ajax_referer('lwai_bridge_advisor', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message_ar' => 'ليس لديك صلاحية تطبيق هذه التغييرات.'), 403);
        }

        $ids = isset($_POST['ids']) ? wp_unslash($_POST['ids']) : array();
        $result = LWAI_Bridge_Link_Whisper_Advisor::apply_recommendations($ids);
        if (empty($result['success'])) {
            wp_send_json_error($result, 400);
        }

        wp_send_json_success($result);
    }

    public function ajax_restore_link_whisper_settings()
    {
        check_ajax_referer('lwai_bridge_advisor', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message_ar' => 'ليس لديك صلاحية استرجاع هذه الإعدادات.'), 403);
        }

        $result = LWAI_Bridge_Link_Whisper_Advisor::restore_last_backup();
        if (empty($result['success'])) {
            wp_send_json_error($result, 400);
        }

        wp_send_json_success($result);
    }

    public function patch_link_whisper_client()
    {
        $settings = LWAI_Bridge_Settings::get_all();
        if (empty($settings['enabled'])) {
            return;
        }

        $api_key = LWAI_Bridge_Settings::get_api_key($settings);
        if ($settings['base_url'] === '' || $api_key === '') {
            $this->add_notice(__('Link Whisper AI Bridge is enabled, but Base URL or API key is missing.', 'lwai-bridge'));
            return;
        }

        if (!class_exists('Wpil_AI') || !class_exists('Wpil_Settings')) {
            $this->add_notice(__('Link Whisper AI Bridge is enabled, but Link Whisper Premium is not active.', 'lwai-bridge'));
            return;
        }

        if (method_exists('Wpil_Settings', 'get_linkwhisper_ai_active') && Wpil_Settings::get_linkwhisper_ai_active()) {
            $this->add_notice(__('Link Whisper AI Bridge is paused because Link Whisper AI subscription mode is active. Disconnect Link Whisper AI to use the external provider.', 'lwai-bridge'));
            return;
        }

        if (!$this->ensure_openai_vendor()) {
            $this->add_notice(__('Link Whisper AI Bridge could not load Link Whisper bundled OpenAI client.', 'lwai-bridge'), 'error');
            return;
        }

        LWAI_Bridge_Settings::sync_link_whisper_key();
        LWAI_Bridge_Settings::sync_link_whisper_models($settings);

        try {
            $client = new LWAI_Bridge_Client($api_key, $settings['base_url'], $settings['chat_model'], $settings['embedding_model']);
            if (class_exists('Wpil_Base') && method_exists('Wpil_Base', 'overTimeLimit')) {
                $client->setTimeout(Wpil_Base::overTimeLimit(5, null, true));
            }
            if (isset(Wpil_AI::$concurrency)) {
                $client->setConcurrency(Wpil_AI::$concurrency);
            }

            $reflection = new ReflectionClass('Wpil_AI');
            $property = $reflection->getProperty('ai');
            $property->setAccessible(true);
            $property->setValue(null, $client);
        } catch (Exception $e) {
            $this->add_notice(sprintf(__('Link Whisper AI Bridge could not patch the AI client: %s', 'lwai-bridge'), $e->getMessage()), 'error');
        }
    }

    private function ensure_openai_vendor()
    {
        if (class_exists('LWVendor\\Orhanerday\\OpenAi\\OpenAi')) {
            return true;
        }

        if (!defined('WP_INTERNAL_LINKING_PLUGIN_DIR')) {
            return false;
        }

        $base = trailingslashit(WP_INTERNAL_LINKING_PLUGIN_DIR) . 'vendor/orhanerday/open-ai/src/';
        if (is_readable($base . 'OpenAi.php') && is_readable($base . 'Url.php')) {
            require_once $base . 'OpenAi.php';
            require_once $base . 'Url.php';
        }

        return class_exists('LWVendor\\Orhanerday\\OpenAi\\OpenAi');
    }

    private function summarize_provider_response($raw, $success_property)
    {
        if (empty($raw)) {
            return array(
                'ok' => false,
                'message' => __('The provider returned an empty response.', 'lwai-bridge'),
            );
        }

        $decoded = json_decode((string) $raw);
        if (isset($decoded->error)) {
            return array(
                'ok' => false,
                'message' => isset($decoded->error->message) ? $decoded->error->message : wp_json_encode($decoded->error),
            );
        }

        return array(
            'ok' => isset($decoded->{$success_property}) && !empty($decoded->{$success_property}),
            'http_code' => null,
        );
    }

    private function add_notice($message, $type = 'warning')
    {
        $this->notices[] = array(
            'message' => $message,
            'type' => $type,
        );
    }
}
