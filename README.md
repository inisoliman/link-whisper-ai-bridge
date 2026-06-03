# Link Whisper AI Bridge

Separate WordPress plugin for routing Link Whisper Premium AI calls to an OpenAI-compatible provider.

## Install

Copy the `link-whisper-ai-bridge` folder into `wp-content/plugins/` beside `link-whisper-premium`, then activate it from WordPress Plugins.

## Configure

Open **Settings > Link Whisper AI Bridge** and set:

- Enable bridge
- Provider Base URL, usually ending in `/v1`
- Provider API key
- Chat model
- Embedding model

The bridge stores the real provider key in its own encrypted option. It only writes a non-secret sentinel key to Link Whisper's OpenAI key option so Link Whisper keeps its AI features enabled.

## Link Whisper Settings Advisor

The bridge settings page includes **مساعد ضبط Link Whisper**.

Use **فحص إعدادات Link Whisper** to generate an Arabic report of suggested Link Whisper settings. Each recommendation explains:

- The Link Whisper option being changed
- The current value
- The recommended value
- Why the change is useful
- Whether the change is safe or should be reviewed

Only selected recommendations are applied. Before applying changes, the bridge stores a backup in `lwai_bridge_last_lw_settings_backup`, and **استرجاع آخر تغييرات المساعد** restores the most recent advisor-applied settings.

The advisor only updates an internal allowlist of Link Whisper options. It does not change WordPress core settings, Google Site Kit settings, API keys, or unrelated plugin options.

## Notes

If Link Whisper AI subscription mode is active, the bridge pauses and shows an admin notice. Disconnect Link Whisper AI inside Link Whisper before using the external provider.

The provider must support OpenAI-compatible chat completions, embeddings, and Files/Batches for full Link Whisper AI coverage.
