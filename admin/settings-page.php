<?php defined('ABSPATH') || exit;

$settings     = new WAA_Settings();
$provider     = $settings->get_provider();
$model        = $settings->get_model();
$custom_rules = $settings->get_custom_rules();
$disabled     = $settings->get_disabled_tools();
$pricing      = WAA_Pricing::all_for_js();

$active_tab = sanitize_key($_GET['tab'] ?? 'provider');

// Build tool list for Tools tab
$all_schemas  = WAA_REST_API::build_registry()->get_schemas(); // no disabled filter here — show all

// Load knowledge-base docs for Docs tab
$kb_dir  = WAA_PLUGIN_DIR . 'knowledge-base/';
$kb_docs = [];
foreach (glob($kb_dir . '*.md') as $path) {
    $filename = basename($path);
    if (str_starts_with($filename, '.')) continue;
    $raw_name  = preg_replace('/^\d+-/', '', pathinfo($filename, PATHINFO_FILENAME));
    $label     = ucwords(str_replace('-', ' ', $raw_name));
    $kb_docs[] = ['file' => $filename, 'label' => $label, 'content' => file_get_contents($path)];
}
usort($kb_docs, fn($a, $b) => strcmp($a['file'], $b['file']));

// Build navigate map for Prompt tab
$navigate_map = [
    'update_site_settings' => 'options-general.php',
    'set_site_icon'        => 'options-general.php',
    'install_plugin'       => 'plugins.php',
    'activate_plugin'      => 'plugins.php',
    'deactivate_plugin'    => 'plugins.php',
    'install_theme'        => 'themes.php',
    'switch_theme'         => 'themes.php',
    'update_user_role'     => 'users.php',
    'create_post'          => 'edit.php',
    'update_post'          => 'edit.php',
    'set_post_image'       => 'edit.php',
];
?>
<div class="wrap">
    <h1>WP Admin Agent — Settings</h1>

    <?php if (isset($_GET['saved'])): ?>
        <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper" style="margin-bottom:0">
        <?php foreach (['provider' => 'Provider & Keys', 'media' => 'Media & Images', 'prompt' => 'System Prompt', 'tools' => 'Tools (' . count($all_schemas) . ')', 'docs' => '📖 Docs'] as $slug => $label): ?>
            <a href="?page=wp-admin-agent&tab=<?php echo $slug; ?>"
               class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($label); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;margin-top:0">

        <!-- LEFT: Tab content inside form -->
        <div style="flex:1;min-width:380px">
            <form method="post" id="waa-settings-form" style="background:#fff;border:1px solid #c3c4c7;border-top:none;padding:20px">
                <?php wp_nonce_field('waa_settings'); ?>

                <!-- ══════════ TAB: PROVIDER ══════════ -->
                <?php if ($active_tab === 'provider'): ?>
                <input type="hidden" name="tab" value="provider">
                <table class="form-table" role="presentation">

                    <tr>
                        <th><label for="waa_provider">AI Provider</label></th>
                        <td>
                            <select id="waa_provider" name="waa_provider" onchange="waaOnProviderChange(this.value)">
                                <option value="anthropic" <?php selected($provider,'anthropic'); ?>>Anthropic (Claude)</option>
                                <option value="gemini"    <?php selected($provider,'gemini'); ?>>Google Gemini</option>
                                <option value="ollama"    <?php selected($provider,'ollama'); ?>>Ollama (Local)</option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="waa_model">Model</label></th>
                        <td>
                            <div style="display:flex;gap:8px;align-items:center">
                                <select id="waa_model" name="waa_model" onchange="waaOnModelChange(this.value)">
                                    <?php foreach ($pricing[$provider] ?? [] as $id => $info): ?>
                                        <option value="<?php echo esc_attr($id); ?>" <?php selected($model,$id); ?>>
                                            <?php echo esc_html($info['label']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" id="waa-refresh-models" class="button button-small"
                                        onclick="waaRefreshOllamaModels()"
                                        <?php echo $provider !== 'ollama' ? 'style="display:none"' : ''; ?>>
                                    ↺ Refresh
                                </button>
                            </div>
                            <div id="waa-model-info" style="margin-top:6px;font-size:12px;color:#666"></div>
                        </td>
                    </tr>

                    <tr id="row-anthropic" <?php echo $provider !== 'anthropic' ? 'style="display:none"' : ''; ?>>
                        <th><label for="waa_api_key">Anthropic API Key</label></th>
                        <td>
                            <input type="password" id="waa_api_key" name="waa_api_key" class="regular-text"
                                   value="<?php echo $settings->get_api_key() ? '••••••••' : ''; ?>"
                                   placeholder="Enter your Anthropic API key" autocomplete="off">
                            <p class="description">
                                <?php if ($settings->get_api_key()): ?>
                                    <span style="color:green">✓ Key set.</span> Leave blank to keep.
                                <?php else: ?>
                                    <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>

                    <tr id="row-gemini" <?php echo $provider !== 'gemini' ? 'style="display:none"' : ''; ?>>
                        <th><label for="waa_gemini_key">Gemini API Key</label></th>
                        <td>
                            <input type="password" id="waa_gemini_key" name="waa_gemini_key" class="regular-text"
                                   value="<?php echo $settings->get_gemini_api_key() ? '••••••••' : ''; ?>"
                                   placeholder="Enter your Gemini API key" autocomplete="off">
                            <p class="description">
                                <?php if ($settings->get_gemini_api_key()): ?>
                                    <span style="color:green">✓ Key set.</span> Leave blank to keep.
                                <?php else: ?>
                                    <a href="https://aistudio.google.com/app/apikey" target="_blank">aistudio.google.com</a> — free tier available
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>

                    <tr id="row-ollama" <?php echo $provider !== 'ollama' ? 'style="display:none"' : ''; ?>>
                        <th><label for="waa_ollama_url">Ollama URL</label></th>
                        <td>
                            <input type="text" id="waa_ollama_url" name="waa_ollama_url" class="regular-text"
                                   value="<?php echo esc_attr($settings->get_ollama_url()); ?>">
                            <p class="description">
                                Same machine: <code>http://localhost:11434</code><br>
                                Docker/wp-env local: <code>http://host.docker.internal:11434</code><br>
                                Private network / another host: <code>http://your-ollama-host:11434</code>
                            </p>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="button" id="waa-test-btn" class="button button-secondary">Test Connection</button>
                    <span id="waa-test-result" style="margin-left:10px;font-weight:500"></span>
                </p>
                <?php submit_button('Save Settings'); ?>

                <!-- ══════════ TAB: MEDIA ══════════ -->
                <?php elseif ($active_tab === 'media'): ?>
                <input type="hidden" name="tab" value="media">

                <h3 style="margin-top:0">Media &amp; Images</h3>
                <p class="description" style="margin-bottom:16px">
                    Configure API keys for image search integrations. These enable the <code>search_images</code> tool so the AI can find and import royalty-free photos directly into the Media Library.
                </p>

                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="waa_pexels_key">Pexels API Key</label></th>
                        <td>
                            <input type="password" id="waa_pexels_key" name="waa_pexels_key" class="regular-text"
                                   value="<?php echo $settings->get_pexels_api_key() ? '••••••••' : ''; ?>"
                                   placeholder="Enter your Pexels API key" autocomplete="off">
                            <p class="description">
                                <?php if ($settings->get_pexels_api_key()): ?>
                                    <span style="color:green">✓ Key set.</span> Leave blank to keep. —
                                <?php endif; ?>
                                Free at <a href="https://www.pexels.com/api/" target="_blank">pexels.com/api</a> (200 req/hour).
                                Enables <code>search_images</code> tool for royalty-free photos with attribution.
                            </p>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="button" id="waa-test-pexels-btn" class="button button-secondary">Test Pexels</button>
                    <span id="waa-pexels-result" style="margin-left:10px;font-weight:500"></span>
                </p>
                <?php submit_button('Save Media Settings'); ?>

                <!-- ══════════ TAB: PROMPT ══════════ -->
                <?php elseif ($active_tab === 'prompt'): ?>
                <input type="hidden" name="tab" value="prompt">

                <h3 style="margin-top:0">System Prompt</h3>
                <p class="description" style="margin-bottom:16px">
                    The runtime prompt is rebuilt on every request. It includes live WordPress context, registered tools, provider-specific guidance, and your custom rules.
                    Confirmation policy and async safety are enforced by runtime code even if the model responds imperfectly.
                </p>

                <!-- Base prompt preview (read-only) -->
                <details style="margin-bottom:20px">
                    <summary style="cursor:pointer;font-weight:600;padding:8px 0;color:#2271b1">
                        View full runtime prompt preview ▾
                    </summary>
                    <pre style="background:#f6f7f7;border:1px solid #e2e4e7;padding:12px;font-size:12px;white-space:pre-wrap;max-height:260px;overflow-y:auto;margin-top:8px"><?php
$provider_obj = WAA_Provider_Factory::make($settings);
$prompt_preview = WAA_Agent::build_system_prompt(
    $provider_obj,
    WAA_REST_API::build_registry($settings->get_disabled_tools()),
    $settings
);
echo esc_html($prompt_preview);
?></pre>
                </details>

                <!-- Model-specific instructions (per-provider, read-only) -->
                <h4 style="margin-bottom:6px">Prompt layers <span style="font-weight:400;color:#666;font-size:12px">(how the final prompt is assembled)</span></h4>
                <ol style="margin:0 0 20px 18px;line-height:1.7">
                    <li><strong>Base runtime prompt</strong> — site context, safety rules, content/media guidance, and registered tool names.</li>
                    <li><strong>Model-specific guidance</strong> — provider rules from <code><?php echo esc_html(get_class($provider_obj)); ?></code>.</li>
                    <li><strong>Custom rules</strong> — your editable instructions, appended last.</li>
                </ol>

                <!-- Custom rules (editable) -->
                <h4 style="margin-bottom:6px">Custom rules <span style="font-weight:400;color:#666;font-size:12px">(appended last, editable)</span></h4>
                <textarea name="waa_custom_rules" rows="8" style="width:100%;font-family:monospace;font-size:13px"
                          placeholder="Add extra instructions here, e.g.:
- Always respond in formal Vietnamese.
- When creating short announcements, prefer create_simple_post.
- When writing current-event posts, use fetch_rss before drafting.
- When replacing content images, keep the existing post title unchanged unless I explicitly ask."
                ><?php echo esc_textarea($custom_rules); ?></textarea>
                <p class="description">Plain text. One rule per line recommended. Best for tone, defaults, editorial policy, and tool preferences. Runtime confirmation rules still apply even if you ask the model to be more aggressive.</p>

                <!-- Auto-navigate map -->
                <h4 style="margin-top:20px;margin-bottom:8px">Auto-navigate map <span style="font-weight:400;color:#666;font-size:12px">(best-effort redirect after successful write actions)</span></h4>
                <table class="widefat striped" style="font-size:12px">
                    <thead><tr><th>Tool</th><th>Navigates to</th></tr></thead>
                    <tbody>
                    <?php foreach ($navigate_map as $tool => $path): ?>
                        <tr>
                            <td><code><?php echo esc_html($tool); ?></code></td>
                            <td><a href="<?php echo esc_url(admin_url($path)); ?>" target="_blank"><?php echo esc_html($path); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php submit_button('Save Prompt Settings'); ?>

                <!-- ══════════ TAB: TOOLS ══════════ -->
                <?php elseif ($active_tab === 'tools'): ?>
                <input type="hidden" name="tab" value="tools">

                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                    <div>
                        <h3 style="margin:0">Available Tools</h3>
                        <p class="description" style="margin:4px 0 0">
                            <?php echo count($all_schemas); ?> tools registered.
                            Disabled tools are hidden from the AI — it cannot call them.
                        </p>
                    </div>
                    <div style="display:flex;gap:8px">
                        <button type="button" class="button button-small" onclick="waaToggleAll(true)">Enable all</button>
                        <button type="button" class="button button-small" onclick="waaToggleAll(false)">Disable all</button>
                    </div>
                </div>

                <table class="widefat" id="waa-tools-table">
                    <thead>
                        <tr>
                            <th style="width:36px">On</th>
                            <th style="width:200px">Tool name</th>
                            <th>Description</th>
                            <th style="width:80px">Schema</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($all_schemas as $schema):
                        $name      = $schema['name'];
                        $is_on     = !in_array($name, $disabled, true);
                        $schema_js = esc_attr(wp_json_encode($schema['input_schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    ?>
                        <tr id="tool-row-<?php echo esc_attr($name); ?>" class="<?php echo $is_on ? '' : 'waa-tool-disabled'; ?>">
                            <td>
                                <input type="checkbox" name="waa_tool_<?php echo esc_attr($name); ?>"
                                       value="1" <?php checked($is_on); ?>
                                       onchange="waaToolToggle('<?php echo esc_js($name); ?>', this.checked)">
                            </td>
                            <td><code style="font-size:12px"><?php echo esc_html($name); ?></code></td>
                            <td style="font-size:13px;color:<?php echo $is_on ? '#111' : '#999'; ?>">
                                <?php echo esc_html($schema['description']); ?>
                            </td>
                            <td>
                                <button type="button" class="button button-small"
                                        onclick="waaShowSchema('<?php echo esc_js($name); ?>', this)"
                                        data-schema="<?php echo $schema_js; ?>">
                                    JSON ▾
                                </button>
                            </td>
                        </tr>
                        <tr id="schema-row-<?php echo esc_attr($name); ?>" style="display:none">
                            <td colspan="4" style="padding:0">
                                <pre id="schema-pre-<?php echo esc_attr($name); ?>"
                                     style="background:#f6f7f7;margin:0;padding:10px 16px;font-size:11px;overflow-x:auto"></pre>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <h4 style="margin-top:24px;margin-bottom:6px">Debug mode <span style="font-weight:400;color:#666;font-size:12px">(tool call log in chat)</span></h4>
                <select name="waa_debug_mode">
                    <?php foreach (['off' => 'Off', 'compact' => 'Compact — errors only', 'full' => 'Full — show inputs & outputs'] as $val => $label): ?>
                        <option value="<?php echo $val; ?>" <?php selected($settings->get_debug_mode(), $val); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description" style="margin-top:4px">Controls tool execution detail shown in the chat widget. "Full" is useful for debugging.</p>

                <?php submit_button('Save Tool Settings'); ?>

                <!-- ══════════ TAB: DOCS ══════════ -->
                <?php elseif ($active_tab === 'docs'): ?>
                <div id="waa-docs-wrap" style="display:flex;gap:0;min-height:500px">

                    <!-- Sidebar: file tree -->
                    <div id="waa-docs-tree" style="width:200px;flex-shrink:0;border-right:1px solid #e2e4e7;padding:12px 0">
                        <?php foreach ($kb_docs as $i => $doc): ?>
                        <button type="button"
                                class="waa-doc-link <?php echo $i === 0 ? 'waa-doc-active' : ''; ?>"
                                data-index="<?php echo $i; ?>"
                                onclick="waaShowDoc(<?php echo $i; ?>)">
                            📄 <?php echo esc_html($doc['label']); ?>
                        </button>
                        <?php endforeach; ?>
                        <?php if (empty($kb_docs)): ?>
                            <p style="padding:12px;color:#999;font-size:12px">No docs found in <code>knowledge-base/</code></p>
                        <?php endif; ?>
                    </div>

                    <!-- Content pane -->
                    <div id="waa-docs-content" style="flex:1;padding:20px 24px;overflow-y:auto;max-height:75vh"></div>

                </div>

                <?php endif; ?>

            </form>
        </div>

        <!-- RIGHT: Pricing + Stats (always visible) -->
        <div style="min-width:280px;margin-top:0">
            <div style="background:#fff;border:1px solid #c3c4c7;padding:16px">
                <h3 style="margin-top:0">Pricing <span style="font-size:12px;font-weight:400;color:#666">(USD / 1M tokens)</span></h3>
                <div id="waa-pricing-table"></div>

                <h3 style="margin-top:20px">Usage (last 30 days)</h3>
                <div id="waa-stats-panel"><em style="color:#999;font-size:13px">Loading…</em></div>

                <h3 style="margin-top:20px">MCP Endpoint</h3>
                <p style="font-size:12px;color:#555;margin:0">
                    Connect any MCP client (Claude Desktop, Claude Code) to:<br>
                    <code style="word-break:break-all"><?php echo esc_html(rest_url('wp-admin-agent/v1/mcp')); ?></code>
                </p>
            </div>
        </div>

    </div>
</div>

<style>
.waa-pricing-table { border-collapse:collapse; width:100%; font-size:12px; }
.waa-pricing-table th { text-align:left; padding:4px 8px; background:#f6f7f7; border:1px solid #e2e4e7; }
.waa-pricing-table td { padding:4px 8px; border:1px solid #e2e4e7; white-space:nowrap; }
.waa-pricing-table tr.active td { background:#f0f6fc; font-weight:600; }
.waa-free { color:#00a32a; font-weight:600; }
.waa-stat-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:12px; }
.waa-stat-card { background:#f6f7f7; border:1px solid #e2e4e7; border-radius:6px; padding:10px 12px; }
.waa-stat-card .value { font-size:20px; font-weight:700; color:#2271b1; }
.waa-stat-card .label { font-size:11px; color:#666; margin-top:2px; }
.waa-tool-disabled td { opacity:.5; }
#waa-tools-table code { background:#f0f0f0; padding:2px 6px; border-radius:3px; }
/* Docs tab */
.waa-doc-link { display:block; width:100%; text-align:left; background:none; border:none; padding:8px 16px; font-size:13px; cursor:pointer; color:#1d2327; border-left:3px solid transparent; line-height:1.4; }
.waa-doc-link:hover { background:#f6f7f7; }
.waa-doc-link.waa-doc-active { background:#f0f6fc; border-left-color:#2271b1; color:#2271b1; font-weight:600; }
#waa-docs-content { font-size:14px; line-height:1.7; color:#1d2327; }
#waa-docs-content .waa-doc-h { border-bottom:1px solid #e2e4e7; padding-bottom:6px; margin-top:24px; margin-bottom:12px; }
#waa-docs-content h1.waa-doc-h { font-size:22px; }
#waa-docs-content h2.waa-doc-h { font-size:18px; }
#waa-docs-content h3.waa-doc-h { font-size:15px; border-bottom:none; }
#waa-docs-content .waa-doc-p { margin:6px 0; }
#waa-docs-content .waa-doc-code { background:#f6f7f7; border:1px solid #e2e4e7; border-radius:4px; padding:12px 16px; overflow-x:auto; font-size:12px; line-height:1.6; margin:12px 0; }
#waa-docs-content code { background:#f0f0f0; padding:1px 5px; border-radius:3px; font-size:12px; }
#waa-docs-content li { margin:3px 0 3px 20px; list-style:disc; }
#waa-docs-content strong { color:#1d2327; }
.waa-doc-table { border-collapse:collapse; width:100%; font-size:13px; margin:12px 0; }
.waa-doc-table th { background:#f6f7f7; text-align:left; padding:6px 10px; border:1px solid #e2e4e7; font-weight:600; }
.waa-doc-table td { padding:6px 10px; border:1px solid #e2e4e7; }
</style>

<script>
const WAA_PRICING      = <?php echo wp_json_encode($pricing); ?>;
let currentProvider    = <?php echo wp_json_encode($provider); ?>;
let currentModel       = <?php echo wp_json_encode($model); ?>;

// ── Provider tab ─────────────────────────────────────────────────────────────

function waaOnProviderChange(p) {
    currentProvider = p;
    ['anthropic','gemini','ollama'].forEach(id => {
        const row = document.getElementById('row-' + id);
        if (row) {
            row.style.display = (p === id) ? '' : 'none';
        }
    });
    const refreshButton = document.getElementById('waa-refresh-models');
    if (refreshButton) {
        refreshButton.style.display = p === 'ollama' ? '' : 'none';
    }
    const sel    = document.getElementById('waa_model');
    const models = WAA_PRICING[p] ?? {};
    if (!sel) {
        return;
    }
    sel.innerHTML = Object.entries(models)
        .map(([v, m]) => `<option value="${v}">${m.label}</option>`).join('');
    currentModel = sel.value;
    waaBuildPricingTable();
    waaUpdateModelInfo();
}

function waaOnModelChange(m) {
    currentModel = m;
    waaBuildPricingTable();
    waaUpdateModelInfo();
}

function waaUpdateModelInfo() {
    const info = WAA_PRICING[currentProvider]?.[currentModel];
    const el   = document.getElementById('waa-model-info');
    if (!el) {
        return;
    }
    if (!info) { el.textContent = ''; return; }
    const ctx  = info.ctx >= 1000000 ? (info.ctx/1000000).toFixed(1)+'M' : (info.ctx/1000)+'K';
    el.innerHTML = info.free
        ? `Context: ${ctx} tokens · <span class="waa-free">Free (local)</span>`
        : `Context: ${ctx} tokens · $${info.in}/M in · $${info.out}/M out`;
}

async function waaRefreshOllamaModels() {
    const btn = document.getElementById('waa-refresh-models');
    btn.textContent = '…'; btn.disabled = true;
    try {
        const res  = await fetch(waaData.restUrl + 'ollama-models', { headers: { 'X-WP-Nonce': waaData.nonce } });
        const data = await res.json();
        if (data.error) { alert('Ollama error: ' + data.error); return; }
        const fetched = {};
        for (const [id, label] of Object.entries(data.models)) {
            fetched[id] = { label, ctx: 128000, in: 0, out: 0, free: true };
        }
        WAA_PRICING.ollama = fetched;
        const sel = document.getElementById('waa_model');
        sel.innerHTML = Object.entries(fetched).map(([v, m]) => `<option value="${v}">${m.label}</option>`).join('');
        currentModel = sel.value;
        waaBuildPricingTable(); waaUpdateModelInfo();
    } catch(e) { alert('Could not reach Ollama: ' + e.message); }
    finally { btn.textContent = '↺ Refresh'; btn.disabled = false; }
}

// Test connection
document.getElementById('waa-test-btn')?.addEventListener('click', async function() {
    const el = document.getElementById('waa-test-result');
    el.style.color = '#666'; el.textContent = 'Testing…';
    // Send current form values so the test uses live selections, not only saved DB values
    const body = {
        provider:   document.getElementById('waa_provider')?.value  ?? '',
        model:      document.getElementById('waa_model')?.value     ?? '',
        api_key:    document.getElementById('waa_api_key')?.value   ?? '',
        gemini_key: document.getElementById('waa_gemini_key')?.value ?? '',
        ollama_url: document.getElementById('waa_ollama_url')?.value ?? '',
    };
    try {
        const res  = await fetch(waaData.restUrl + 'test-connection', {
            method: 'POST',
            headers: { 'X-WP-Nonce': waaData.nonce, 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        const data = await res.json();
        if (data.success) {
            el.style.color = 'green';
            el.textContent = `✅ ${data.provider} · ${data.model} · "${data.reply}"`;
        } else {
            el.style.color = '#d63638';
            el.textContent = '❌ ' + (data.error ?? `HTTP ${res.status}`);
        }
    } catch(e) { el.style.color = '#d63638'; el.textContent = '❌ ' + e.message; }
});

// ── Media tab ─────────────────────────────────────────────────────────────────

document.getElementById('waa-test-pexels-btn')?.addEventListener('click', async function() {
    const el  = document.getElementById('waa-pexels-result');
    const key = document.getElementById('waa_pexels_key')?.value ?? '';
    if (!key || key === '••••••••') {
        el.style.color = '#d63638'; el.textContent = '❌ Enter an API key first.'; return;
    }
    el.style.color = '#666'; el.textContent = 'Testing…';
    try {
        const res  = await fetch('https://api.pexels.com/v1/search?query=test&per_page=1', {
            headers: { Authorization: key },
        });
        if (res.ok) {
            const data = await res.json();
            el.style.color = 'green';
            el.textContent = `✅ Pexels OK — ${data.total_results?.toLocaleString() ?? '?'} results available.`;
        } else {
            el.style.color = '#d63638';
            el.textContent = `❌ Pexels error: HTTP ${res.status}`;
        }
    } catch(e) { el.style.color = '#d63638'; el.textContent = '❌ ' + e.message; }
});

// ── Tools tab ─────────────────────────────────────────────────────────────────

function waaToolToggle(name, enabled) {
    const row = document.getElementById('tool-row-' + name);
    if (!row) return;
    row.classList.toggle('waa-tool-disabled', !enabled);
    const descCell = row.cells[2];
    if (descCell) descCell.style.color = enabled ? '#111' : '#999';
}

function waaToggleAll(enabled) {
    document.querySelectorAll('#waa-tools-table input[type=checkbox]').forEach(cb => {
        cb.checked = enabled;
        waaToolToggle(cb.name.replace('waa_tool_', ''), enabled);
    });
}

function waaShowSchema(name, btn) {
    const row = document.getElementById('schema-row-' + name);
    const pre = document.getElementById('schema-pre-' + name);
    if (!row || !pre) return;
    const isVisible = row.style.display !== 'none';
    row.style.display = isVisible ? 'none' : '';
    btn.textContent   = isVisible ? 'JSON ▾' : 'JSON ▴';
    if (!isVisible && !pre.textContent) {
        try { pre.textContent = JSON.stringify(JSON.parse(btn.dataset.schema), null, 2); }
        catch { pre.textContent = btn.dataset.schema; }
    }
}

// ── Pricing + Stats (always) ──────────────────────────────────────────────────

function waaBuildPricingTable() {
    const panel = document.getElementById('waa-pricing-table');
    if (!panel) {
        return;
    }
    const models = WAA_PRICING[currentProvider] ?? {};
    let html = '<table class="waa-pricing-table"><thead><tr><th>Model</th><th>Input</th><th>Output</th><th>Context</th></tr></thead><tbody>';
    for (const [id, m] of Object.entries(models)) {
        const active = id === currentModel ? ' class="active"' : '';
        const ctx    = m.ctx >= 1000000 ? (m.ctx/1000000).toFixed(1)+'M' : (m.ctx/1000)+'K';
        const price  = m.free ? '<td class="waa-free" colspan="2">Free</td>' : `<td>$${m.in}/M</td><td>$${m.out}/M</td>`;
        html += `<tr${active}><td>${m.label}</td>${price}<td>${ctx}</td></tr>`;
    }
    html += '</tbody></table>';
    panel.innerHTML = html;
}

async function waaLoadStats() {
    const panel = document.getElementById('waa-stats-panel');
    if (!panel) {
        return;
    }
    try {
        const res  = await fetch(waaData.restUrl + 'stats?period=30', { headers: { 'X-WP-Nonce': waaData.nonce } });
        const data = await res.json();
        const t    = data.totals ?? {};
        const totalIn  = parseInt(t.total_input  ?? 0);
        const totalOut = parseInt(t.total_output ?? 0);
        const fmtT = n => n >= 1000000 ? (n/1000000).toFixed(2)+'M' : n >= 1000 ? (n/1000).toFixed(1)+'K' : String(n||0);
        const fmtC = c => c === 0 ? 'Free' : c < 0.01 ? `$${c.toFixed(6)}` : `$${c.toFixed(4)}`;
        let html = `<div class="waa-stat-grid">
            <div class="waa-stat-card"><div class="value">${t.total_calls||0}</div><div class="label">Tool calls</div></div>
            <div class="waa-stat-card"><div class="value">${fmtC(data.total_cost||0)}</div><div class="label">Est. cost</div></div>
            <div class="waa-stat-card"><div class="value">${fmtT(totalIn)}</div><div class="label">Input tokens</div></div>
            <div class="waa-stat-card"><div class="value">${fmtT(totalOut)}</div><div class="label">Output tokens</div></div>
        </div>`;
        if (data.by_model?.length) {
            html += '<table class="waa-pricing-table" style="margin-top:8px"><thead><tr><th>Model</th><th>Calls</th><th>Tokens</th><th>Cost</th></tr></thead><tbody>';
            for (const r of data.by_model) {
                const tok = fmtT(parseInt(r.input_tokens)+parseInt(r.output_tokens));
                html += `<tr><td>${r.model||r.provider}</td><td>${r.calls}</td><td>${tok}</td><td>${fmtC(parseFloat(r.cost_usd||0))}</td></tr>`;
            }
            html += '</tbody></table>';
        }
        panel.innerHTML = html || '<em style="color:#999;font-size:13px">No data yet.</em>';
    } catch {
        panel.innerHTML = '<em style="color:#d63638">Could not load stats.</em>';
    }
}

// ── Docs tab ──────────────────────────────────────────────────────────────────

const WAA_DOCS = <?php echo wp_json_encode(array_map(fn($d) => ['label' => $d['label'], 'content' => $d['content']], $kb_docs)); ?>;

function waaShowDoc(index) {
    document.querySelectorAll('.waa-doc-link').forEach((b, i) =>
        b.classList.toggle('waa-doc-active', i === index)
    );
    const doc = WAA_DOCS[index];
    const panel = document.getElementById('waa-docs-content');
    if (!doc || !panel) return;
    panel.innerHTML = waaMarkdown(doc.content);
}

// Minimal Markdown → HTML renderer
function waaMarkdown(md) {
    // Escape HTML first
    const esc = s => s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const lines = md.split('\n');
    let html = '', inCode = false, codeLines = [], codeLang = '', inTable = false, tableRows = [];

    const flushTable = () => {
        if (!tableRows.length) return;
        let t = '<table class="waa-doc-table">';
        tableRows.forEach((row, i) => {
            const cells = row.split('|').filter((_, ci, a) => ci > 0 && ci < a.length - 1);
            t += '<tr>' + cells.map(c => i === 0 ? `<th>${inlineFormat(c.trim())}</th>` : `<td>${inlineFormat(c.trim())}</td>`).join('') + '</tr>';
        });
        html += t + '</table>';
        tableRows = []; inTable = false;
    };

    const inlineFormat = s => s
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/\*([^*]+)\*/g, '<em>$1</em>')
        .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];

        // Code fence
        if (line.startsWith('```')) {
            if (!inCode) { inCode = true; codeLang = line.slice(3).trim(); codeLines = []; }
            else {
                html += `<pre class="waa-doc-code"><code>${esc(codeLines.join('\n'))}</code></pre>`;
                inCode = false; codeLines = []; codeLang = '';
            }
            continue;
        }
        if (inCode) { codeLines.push(line); continue; }

        // Table rows
        if (line.startsWith('|')) {
            if (!inTable) inTable = true;
            if (!/^\|[-| ]+\|$/.test(line)) tableRows.push(line);
            continue;
        } else if (inTable) { flushTable(); }

        // Headings
        if (/^#{1,6} /.test(line)) {
            const lvl = line.match(/^(#+)/)[1].length;
            html += `<h${lvl} class="waa-doc-h">${inlineFormat(esc(line.slice(lvl + 1)))}</h${lvl}>`;
            continue;
        }
        // HR
        if (/^---+$/.test(line.trim())) { html += '<hr style="border:none;border-top:1px solid #e2e4e7;margin:16px 0">'; continue; }
        // List
        if (/^[-*] /.test(line)) { html += `<li>${inlineFormat(esc(line.slice(2)))}</li>`; continue; }
        if (/^\d+\. /.test(line)) { html += `<li>${inlineFormat(esc(line.replace(/^\d+\. /, '')))}</li>`; continue; }
        // Blank
        if (line.trim() === '') { html += '<br>'; continue; }
        // Paragraph
        html += `<p class="waa-doc-p">${inlineFormat(esc(line))}</p>`;
    }
    if (inTable) flushTable();
    return html;
}

if (WAA_DOCS.length && document.getElementById('waa-docs-content')) waaShowDoc(0);

// Init
waaBuildPricingTable();
waaUpdateModelInfo();
waaLoadStats();
</script>
