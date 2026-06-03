<?php
/**
 * Plugin Name: Link Whisper AI Bridge
 * Description: Routes Link Whisper Premium AI requests through an OpenAI-compatible external provider.
 * Version: 0.1.0
 * Author: Local Bridge
 * Text Domain: lwai-bridge
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LWAI_BRIDGE_VERSION', '0.1.0');
define('LWAI_BRIDGE_FILE', __FILE__);
define('LWAI_BRIDGE_DIR', plugin_dir_path(__FILE__));

require_once LWAI_BRIDGE_DIR . 'includes/class-lwai-bridge-settings.php';
require_once LWAI_BRIDGE_DIR . 'includes/class-lwai-bridge-client.php';
require_once LWAI_BRIDGE_DIR . 'includes/class-lwai-bridge-link-whisper-advisor.php';
require_once LWAI_BRIDGE_DIR . 'includes/class-lwai-bridge-plugin.php';

LWAI_Bridge_Plugin::register();

register_deactivation_hook(__FILE__, array('LWAI_Bridge_Settings', 'restore_link_whisper_key_if_owned'));
