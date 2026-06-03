<?php

class LWAI_Bridge_Link_Whisper_Advisor
{
    const BACKUP_OPTION = 'lwai_bridge_last_lw_settings_backup';
    const MISSING_OPTION_SENTINEL = '__lwai_bridge_missing_option__';

    public static function get_allowed_options()
    {
        return array(
            'wpil_selected_language',
            'wpil_2_post_types',
            'wpil_suggestion_limited_post_types',
            'wpil_limit_suggestions_to_post_types',
            'wpil_2_term_types',
            'wpil_2_post_statuses',
            'wpil_use_ai_suggestions',
            'wpil_enable_ai_batch_processing',
            'wpil_selected_ai_batch_processes',
            'wpil_restrict_to_top_ai_suggestions',
            'wpil_disable_ai_anchor_building',
            'wpil_suggestion_relatedness_threshold',
            'wpil_ai_auto_insert_relatedness_threshold',
            'wpil_sitemap_embedding_relatedness_threshold',
            'wpil_force_https_links',
            'wpil_prevent_keyword_cannibalization',
            'wpil_enable_autolink_cron_task',
            'wpil_disable_autolinking_on_post_update',
        );
    }

    public static function get_report()
    {
        $facts = self::collect_facts();
        $recommendations = self::build_recommendations($facts);

        return array(
            'facts' => self::public_facts($facts),
            'recommendations' => $recommendations,
            'restore_available' => self::has_backup(),
            'message_ar' => empty($recommendations)
                ? 'لا توجد تغييرات مقترحة الآن. إعدادات Link Whisper تبدو مناسبة حسب الفحص الحالي.'
                : 'تم إنشاء تقرير التوصيات. اختر ما تريد تطبيقه ثم اضغط زر التطبيق.',
        );
    }

    public static function build_recommendations($facts)
    {
        $facts = is_array($facts) ? $facts : array();
        $current = isset($facts['current_options']) && is_array($facts['current_options']) ? $facts['current_options'] : array();
        $recommendations = array();

        if (isset($facts['link_whisper_active']) && !$facts['link_whisper_active']) {
            return $recommendations;
        }

        self::add_recommendation(
            $recommendations,
            'language-arabic',
            'wpil_selected_language',
            'ضبط لغة Link Whisper إلى العربية',
            'اختيار العربية يساعد Link Whisper على فهم بنية النص العربي وتقليل اقتراحات الربط غير الدقيقة.',
            self::option_value($current, 'wpil_selected_language', 'english'),
            'arabic',
            'safe',
            true,
            true
        );

        $post_types = self::recommended_post_types($facts);
        if (!empty($post_types)) {
            self::add_recommendation(
                $recommendations,
                'content-post-types',
                'wpil_2_post_types',
                'تحديد أنواع المحتوى التي يدخلها Link Whisper في التحليل',
                'التركيز على المقالات والصفحات وأنواع المحتوى العامة ذات المحتوى المنشور يجعل الاقتراحات أقرب لمحتوى الموقع الفعلي.',
                self::option_array($current, 'wpil_2_post_types', array('post', 'page')),
                $post_types,
                'safe',
                true,
                true
            );

            self::add_recommendation(
                $recommendations,
                'suggestion-post-types',
                'wpil_suggestion_limited_post_types',
                'حصر الاقتراحات في أنواع المحتوى المناسبة',
                'هذا يمنع اقتراح روابط من أنواع محتوى غير مفيدة للقارئ، ويحافظ على الربط الداخلي داخل المقالات والصفحات الأساسية.',
                self::option_array($current, 'wpil_suggestion_limited_post_types', array('post', 'page')),
                $post_types,
                'safe',
                true,
                true
            );

            self::add_recommendation(
                $recommendations,
                'enable-suggestion-post-type-limit',
                'wpil_limit_suggestions_to_post_types',
                'تفعيل حصر اقتراحات الروابط حسب نوع المحتوى',
                'عند تفعيل هذا الخيار يستخدم Link Whisper القائمة المحددة أعلاه بدل توسيع الاقتراحات إلى محتوى غير مناسب.',
                self::option_int($current, 'wpil_limit_suggestions_to_post_types', 0),
                1,
                'safe',
                true,
                true
            );
        }

        $term_types = self::recommended_term_types($facts);
        if (!empty($term_types)) {
            self::add_recommendation(
                $recommendations,
                'term-types',
                'wpil_2_term_types',
                'تفعيل التصنيفات والوسوم المفيدة',
                'إدخال التصنيفات والوسوم ذات الاستخدام الحقيقي يساعد في فهم موضوعات الموقع بدون فتح الباب لأرشيفات فارغة أو ضعيفة.',
                self::option_array($current, 'wpil_2_term_types', array('category', 'post_tag')),
                $term_types,
                'review',
                false,
                true
            );
        }

        self::add_recommendation(
            $recommendations,
            'published-only',
            'wpil_2_post_statuses',
            'تحليل المحتوى المنشور فقط',
            'الاقتراحات الأفضل يجب أن تعتمد على المحتوى المنشور للزوار، لا المسودات أو المراجعات غير المكتملة.',
            self::option_array($current, 'wpil_2_post_statuses', array('publish')),
            array('publish'),
            'safe',
            true,
            true
        );

        if (!empty($facts['bridge_ready'])) {
            self::add_recommendation(
                $recommendations,
                'enable-ai-suggestions',
                'wpil_use_ai_suggestions',
                'تفعيل اقتراحات الذكاء الاصطناعي',
                'بما أن الجسر متصل بمزودك الخارجي، يمكن استخدام الذكاء الاصطناعي لتحسين ترتيب الاقتراحات حسب المعنى والسياق.',
                self::option_int($current, 'wpil_use_ai_suggestions', 0),
                1,
                'safe',
                true,
                false
            );

            self::add_recommendation(
                $recommendations,
                'enable-ai-batches',
                'wpil_enable_ai_batch_processing',
                'تفعيل معالجة AI على دفعات',
                'مزودك يدعم Files/Batches المتوافقة مع OpenAI، وهذا مناسب للمواقع الكبيرة لأنه يقلل الضغط أثناء تحليل المحتوى.',
                self::option_int($current, 'wpil_enable_ai_batch_processing', 0),
                1,
                'safe',
                true,
                false
            );

            $batch_processes = !empty($facts['has_product_like_content']) ? array(3, 4, 5) : array(4, 5);
            self::add_recommendation(
                $recommendations,
                'ai-batch-processes',
                'wpil_selected_ai_batch_processes',
                'اختيار عمليات AI المناسبة للموقع',
                'التركيز على حساب العلاقات الدلالية واكتشاف الكلمات المفتاحية يناسب موقعًا معرفيًا ودينيًا أكثر من تفعيل تحليل المنتجات.',
                self::option_array($current, 'wpil_selected_ai_batch_processes', array(4, 5)),
                $batch_processes,
                'safe',
                true,
                false
            );

            self::add_recommendation(
                $recommendations,
                'top-ai-suggestions',
                'wpil_restrict_to_top_ai_suggestions',
                'عرض أفضل اقتراحات AI فقط',
                'هذا يقلل الضوضاء ويجعل المراجعة اليدوية أسرع، خصوصًا في المحتوى العقائدي أو التعليمي الذي يحتاج دقة أعلى.',
                self::option_int($current, 'wpil_restrict_to_top_ai_suggestions', 0),
                1,
                'safe',
                true,
                false
            );

            self::add_recommendation(
                $recommendations,
                'ai-anchor-building',
                'wpil_disable_ai_anchor_building',
                'السماح لـ AI بتحسين نص الرابط',
                'ترك بناء نص الرابط مفعّلًا يساعد على اختيار عبارات طبيعية من السياق بدل كلمات عامة أو مكررة.',
                self::option_int($current, 'wpil_disable_ai_anchor_building', 0),
                0,
                'review',
                false,
                false
            );

            self::add_recommendation(
                $recommendations,
                'ai-relatedness-threshold',
                'wpil_suggestion_relatedness_threshold',
                'رفع حد التشابه الدلالي للاقتراحات',
                'قيمة 0.55 تجعل Link Whisper أكثر تحفظًا، وهذا أفضل للمحتوى الديني حتى لا يربط موضوعات متقاربة لفظيًا لكنها مختلفة معنويًا.',
                self::option_decimal($current, 'wpil_suggestion_relatedness_threshold', '0.4500'),
                '0.5500',
                'safe',
                true,
                false
            );

            self::add_recommendation(
                $recommendations,
                'ai-auto-insert-threshold',
                'wpil_ai_auto_insert_relatedness_threshold',
                'رفع حد الربط التلقائي بالذكاء الاصطناعي',
                'قيمة 0.75 تجعل أي إدراج تلقائي أكثر حذرًا. أوصي بمراجعة هذا يدويًا قبل اعتماده لأن طبيعة المحتوى حساسة.',
                self::option_decimal($current, 'wpil_ai_auto_insert_relatedness_threshold', '0.6000'),
                '0.7500',
                'review',
                false,
                false
            );

            self::add_recommendation(
                $recommendations,
                'sitemap-threshold',
                'wpil_sitemap_embedding_relatedness_threshold',
                'ضبط حد تشابه خريطة الموقع',
                'قيمة 0.85 مناسبة كحد محافظ للربط القائم على embeddings داخل بنية الموقع.',
                self::option_decimal($current, 'wpil_sitemap_embedding_relatedness_threshold', '0.8500'),
                '0.8500',
                'safe',
                true,
                false
            );
        }

        self::add_recommendation(
            $recommendations,
            'force-https',
            'wpil_force_https_links',
            'فرض روابط HTTPS',
            'استخدام HTTPS يحافظ على روابط داخلية آمنة ومتسقة مع الموقع الحديث.',
            self::option_int($current, 'wpil_force_https_links', 0),
            1,
            'safe',
            true,
            false
        );

        self::add_recommendation(
            $recommendations,
            'prevent-keyword-cannibalization',
            'wpil_prevent_keyword_cannibalization',
            'منع تزاحم الكلمات المفتاحية',
            'هذا يساعد على عدم توجيه نفس الكلمة أو الموضوع إلى صفحات كثيرة بشكل يضعف وضوح بنية الموقع.',
            self::option_int($current, 'wpil_prevent_keyword_cannibalization', 0),
            1,
            'safe',
            true,
            false
        );

        self::add_recommendation(
            $recommendations,
            'disable-autolink-cron',
            'wpil_enable_autolink_cron_task',
            'إيقاف تشغيل الربط التلقائي المجدول',
            'المراجعة اليدوية أفضل للمحتوى الديني، لذلك لا أوصي بترك إدراج الروابط يعمل تلقائيًا في الخلفية.',
            self::option_int($current, 'wpil_enable_autolink_cron_task', 0),
            0,
            'review',
            false,
            false
        );

        self::add_recommendation(
            $recommendations,
            'disable-autolink-on-save',
            'wpil_disable_autolinking_on_post_update',
            'منع إدراج روابط تلقائيًا عند حفظ المقال',
            'هذا يجعل الكاتب أو المحرر يراجع الروابط قبل نشرها بدل أن تظهر تلقائيًا بمجرد تحديث المحتوى.',
            self::option_int($current, 'wpil_disable_autolinking_on_post_update', 0),
            1,
            'review',
            false,
            false
        );

        return $recommendations;
    }

    public static function apply_recommendations($ids)
    {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return array(
                'success' => false,
                'message_ar' => 'لا يمكن تطبيق التوصيات خارج بيئة ووردبريس.',
                'applied' => array(),
            );
        }

        $ids = self::normalize_ids($ids);
        if (empty($ids)) {
            return array(
                'success' => false,
                'message_ar' => 'لم يتم اختيار أي توصية للتطبيق.',
                'applied' => array(),
            );
        }

        $report = self::get_report();
        $by_id = array();
        foreach ($report['recommendations'] as $recommendation) {
            $by_id[$recommendation['id']] = $recommendation;
        }

        $selected = array();
        foreach ($ids as $id) {
            if (isset($by_id[$id]) && self::is_allowed_option($by_id[$id]['option_key'])) {
                $selected[] = $by_id[$id];
            }
        }

        if (empty($selected)) {
            return array(
                'success' => false,
                'message_ar' => 'لم يتم العثور على توصيات صالحة للتطبيق.',
                'applied' => array(),
            );
        }

        $backup = array(
            'created_at' => time(),
            'options' => array(),
        );
        foreach ($selected as $recommendation) {
            $option_key = $recommendation['option_key'];
            if (isset($backup['options'][$option_key])) {
                continue;
            }

            $old_value = get_option($option_key, self::MISSING_OPTION_SENTINEL);
            $backup['options'][$option_key] = array(
                'had_option' => $old_value !== self::MISSING_OPTION_SENTINEL,
                'value' => $old_value,
            );
        }

        update_option(self::BACKUP_OPTION, $backup, false);

        $applied = array();
        foreach ($selected as $recommendation) {
            $option_key = $recommendation['option_key'];
            update_option($option_key, $recommendation['recommended'], false);
            $applied[] = array(
                'id' => $recommendation['id'],
                'option_key' => $option_key,
                'title_ar' => $recommendation['title_ar'],
                'old_value_ar' => $recommendation['current_label'],
                'new_value_ar' => $recommendation['recommended_label'],
            );
        }

        return array(
            'success' => true,
            'message_ar' => 'تم تطبيق التوصيات المحددة وحفظ نسخة احتياطية قبل التعديل.',
            'applied' => $applied,
        );
    }

    public static function restore_last_backup()
    {
        if (!function_exists('get_option') || !function_exists('update_option') || !function_exists('delete_option')) {
            return array(
                'success' => false,
                'message_ar' => 'لا يمكن استرجاع النسخة الاحتياطية خارج بيئة ووردبريس.',
            );
        }

        $backup = get_option(self::BACKUP_OPTION, array());
        if (empty($backup['options']) || !is_array($backup['options'])) {
            return array(
                'success' => false,
                'message_ar' => 'لا توجد نسخة احتياطية محفوظة من مساعد الضبط.',
            );
        }

        foreach ($backup['options'] as $option_key => $entry) {
            if (!self::is_allowed_option($option_key)) {
                continue;
            }

            if (empty($entry['had_option'])) {
                delete_option($option_key);
            } else {
                update_option($option_key, isset($entry['value']) ? $entry['value'] : '', false);
            }
        }

        delete_option(self::BACKUP_OPTION);

        return array(
            'success' => true,
            'message_ar' => 'تم استرجاع آخر إعدادات حفظها مساعد الضبط.',
        );
    }

    private static function collect_facts()
    {
        $settings = class_exists('LWAI_Bridge_Settings') ? LWAI_Bridge_Settings::get_all() : array();
        $api_key = class_exists('LWAI_Bridge_Settings') ? LWAI_Bridge_Settings::get_api_key($settings) : '';
        $bridge_ready = !empty($settings['enabled']) && !empty($settings['base_url']) && $api_key !== '';
        $post_types = self::detect_post_types();
        $taxonomies = self::detect_taxonomies();

        return array(
            'link_whisper_active' => class_exists('Wpil_Settings') || defined('WP_INTERNAL_LINKING_PLUGIN_DIR'),
            'bridge_ready' => $bridge_ready,
            'site_locale' => function_exists('get_locale') ? get_locale() : '',
            'post_types' => $post_types,
            'taxonomies' => $taxonomies,
            'has_product_like_content' => self::has_product_like_content($post_types),
            'current_options' => self::get_current_options(),
        );
    }

    private static function public_facts($facts)
    {
        return array(
            'link_whisper_active' => !empty($facts['link_whisper_active']),
            'bridge_ready' => !empty($facts['bridge_ready']),
            'site_locale' => isset($facts['site_locale']) ? $facts['site_locale'] : '',
            'post_types' => isset($facts['post_types']) ? $facts['post_types'] : array(),
            'taxonomies' => isset($facts['taxonomies']) ? $facts['taxonomies'] : array(),
        );
    }

    private static function get_current_options()
    {
        $defaults = self::option_defaults();
        if (!function_exists('get_option')) {
            return $defaults;
        }

        $current = array();
        foreach ($defaults as $option_key => $default) {
            $current[$option_key] = get_option($option_key, $default);
        }

        return $current;
    }

    private static function option_defaults()
    {
        return array(
            'wpil_selected_language' => 'english',
            'wpil_2_post_types' => array('post', 'page'),
            'wpil_suggestion_limited_post_types' => array('post', 'page'),
            'wpil_limit_suggestions_to_post_types' => 0,
            'wpil_2_term_types' => array('category', 'post_tag'),
            'wpil_2_post_statuses' => array('publish'),
            'wpil_use_ai_suggestions' => 0,
            'wpil_enable_ai_batch_processing' => 0,
            'wpil_selected_ai_batch_processes' => array(4, 5),
            'wpil_restrict_to_top_ai_suggestions' => 0,
            'wpil_disable_ai_anchor_building' => 0,
            'wpil_suggestion_relatedness_threshold' => '0.4500',
            'wpil_ai_auto_insert_relatedness_threshold' => '0.6000',
            'wpil_sitemap_embedding_relatedness_threshold' => '0.8500',
            'wpil_force_https_links' => 0,
            'wpil_prevent_keyword_cannibalization' => 0,
            'wpil_enable_autolink_cron_task' => 0,
            'wpil_disable_autolinking_on_post_update' => 0,
        );
    }

    private static function detect_post_types()
    {
        if (!function_exists('get_post_types')) {
            return array(
                'post' => array('label' => 'Posts', 'count' => 0),
                'page' => array('label' => 'Pages', 'count' => 0),
            );
        }

        $objects = get_post_types(array('public' => true), 'objects');
        $post_types = array();
        foreach ($objects as $name => $object) {
            if ($name === 'attachment') {
                continue;
            }

            $count = 0;
            if (function_exists('wp_count_posts')) {
                $counts = wp_count_posts($name);
                if (is_object($counts) && isset($counts->publish)) {
                    $count = (int) $counts->publish;
                }
            }

            $post_types[$name] = array(
                'label' => isset($object->labels->name) ? $object->labels->name : $name,
                'count' => $count,
            );
        }

        return $post_types;
    }

    private static function detect_taxonomies()
    {
        if (!function_exists('get_taxonomies')) {
            return array(
                'category' => array('label' => 'Categories', 'count' => 0),
                'post_tag' => array('label' => 'Tags', 'count' => 0),
            );
        }

        $objects = get_taxonomies(array('public' => true), 'objects');
        $taxonomies = array();
        foreach ($objects as $name => $object) {
            if (in_array($name, array('nav_menu', 'link_category', 'post_format'), true)) {
                continue;
            }

            $count = 0;
            if (function_exists('wp_count_terms')) {
                $term_count = wp_count_terms($name, array('hide_empty' => false));
                if (!function_exists('is_wp_error') || !is_wp_error($term_count)) {
                    $count = (int) $term_count;
                }
            }

            $taxonomies[$name] = array(
                'label' => isset($object->labels->name) ? $object->labels->name : $name,
                'count' => $count,
            );
        }

        return $taxonomies;
    }

    private static function recommended_post_types($facts)
    {
        $post_types = isset($facts['post_types']) && is_array($facts['post_types']) ? $facts['post_types'] : array();
        $recommended = array();
        foreach ($post_types as $name => $details) {
            if (self::is_product_like_type($name)) {
                continue;
            }

            $count = isset($details['count']) ? (int) $details['count'] : 0;
            if ($count > 0 || in_array($name, array('post', 'page'), true)) {
                $recommended[] = $name;
            }
        }

        return array_values(array_unique($recommended));
    }

    private static function recommended_term_types($facts)
    {
        $taxonomies = isset($facts['taxonomies']) && is_array($facts['taxonomies']) ? $facts['taxonomies'] : array();
        $recommended = array();
        foreach ($taxonomies as $name => $details) {
            $count = isset($details['count']) ? (int) $details['count'] : 0;
            if ($count > 0 || in_array($name, array('category', 'post_tag'), true)) {
                $recommended[] = $name;
            }
        }

        return array_values(array_unique($recommended));
    }

    private static function has_product_like_content($post_types)
    {
        if (!is_array($post_types)) {
            return false;
        }

        foreach ($post_types as $name => $details) {
            if (self::is_product_like_type($name) && !empty($details['count'])) {
                return true;
            }
        }

        return false;
    }

    private static function is_product_like_type($name)
    {
        return in_array($name, array('product', 'download'), true);
    }

    private static function add_recommendation(&$recommendations, $id, $option_key, $title_ar, $reason_ar, $current, $recommended, $risk, $selected_by_default, $requires_rescan)
    {
        if (!self::is_allowed_option($option_key) || self::values_equal($current, $recommended)) {
            return;
        }

        $recommendations[] = array(
            'id' => $id,
            'option_key' => $option_key,
            'title_ar' => $title_ar,
            'reason_ar' => $reason_ar,
            'current' => $current,
            'recommended' => $recommended,
            'current_label' => self::value_label_ar($current),
            'recommended_label' => self::value_label_ar($recommended),
            'risk' => $risk,
            'risk_label_ar' => self::risk_label_ar($risk),
            'selected_by_default' => (bool) $selected_by_default,
            'requires_rescan' => (bool) $requires_rescan,
        );
    }

    private static function is_allowed_option($option_key)
    {
        return in_array($option_key, self::get_allowed_options(), true);
    }

    private static function normalize_ids($ids)
    {
        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            if (is_array($decoded)) {
                $ids = $decoded;
            } else {
                $ids = array($ids);
            }
        }

        $ids = is_array($ids) ? $ids : array();
        $normalized = array();
        foreach ($ids as $id) {
            $id = preg_replace('/[^a-z0-9_-]/i', '', (string) $id);
            if ($id !== '') {
                $normalized[] = $id;
            }
        }

        return array_values(array_unique($normalized));
    }

    private static function option_value($current, $key, $default)
    {
        return array_key_exists($key, $current) ? $current[$key] : $default;
    }

    private static function option_int($current, $key, $default)
    {
        return (int) self::option_value($current, $key, $default);
    }

    private static function option_decimal($current, $key, $default)
    {
        return number_format((float) self::option_value($current, $key, $default), 4, '.', '');
    }

    private static function option_array($current, $key, $default)
    {
        $value = self::option_value($current, $key, $default);
        if (!is_array($value)) {
            $value = array_filter(array_map('trim', explode(',', (string) $value)));
        }

        return array_values($value);
    }

    private static function values_equal($left, $right)
    {
        if (is_array($left) || is_array($right)) {
            $left = is_array($left) ? $left : array($left);
            $right = is_array($right) ? $right : array($right);
            $left = array_map('strval', $left);
            $right = array_map('strval', $right);
            sort($left);
            sort($right);
            return $left === $right;
        }

        if (is_numeric($left) && is_numeric($right)) {
            return abs((float) $left - (float) $right) < 0.0001;
        }

        return (string) $left === (string) $right;
    }

    private static function value_label_ar($value)
    {
        if (is_array($value)) {
            return empty($value) ? 'لا شيء' : implode(', ', array_map('strval', $value));
        }

        if ($value === 1 || $value === '1') {
            return 'مفعّل';
        }

        if ($value === 0 || $value === '0') {
            return 'معطّل';
        }

        if ($value === '' || $value === null) {
            return 'فارغ';
        }

        return (string) $value;
    }

    private static function risk_label_ar($risk)
    {
        if ($risk === 'safe') {
            return 'آمن';
        }

        if ($risk === 'advanced') {
            return 'متقدم';
        }

        return 'يحتاج مراجعة';
    }

    private static function has_backup()
    {
        if (!function_exists('get_option')) {
            return false;
        }

        $backup = get_option(self::BACKUP_OPTION, array());
        return !empty($backup['options']) && is_array($backup['options']);
    }
}
