<?php

defined('ABSPATH') || exit;

class WAA_Agent {
    private const MAX_HISTORY_MESSAGES = 24;
    private const CONFIRMATION_TOOLS = [
        'install_plugin',
        'install_theme',
        'activate_plugin',
        'deactivate_plugin',
        'switch_theme',
        'update_site_settings',
        'set_site_icon',
        'update_user_role',
        'security_harden',
        'wordfence_update_settings',
        'wordfence_disconnect_central',
    ];
    private const CONTENT_CONFIRM_STATUSES = ['publish', 'private', 'trash'];

    // After a write tool completes, navigate the user to the relevant wp-admin page.
    // Uses admin_url() so relative paths work regardless of WP installation prefix.
    private const NAVIGATE_MAP = [
        'update_site_settings'  => 'options-general.php',
        'set_site_icon'         => 'options-general.php',
        'install_plugin'        => 'plugins.php',
        'activate_plugin'       => 'plugins.php',
        'deactivate_plugin'     => 'plugins.php',
        'install_theme'         => 'themes.php',
        'switch_theme'          => 'themes.php',
        'update_user_role'      => 'users.php',
        'create_post'           => 'edit.php',
        'update_post'           => 'edit.php',
        'set_post_image'        => 'edit.php',
        'update_woocommerce_settings' => 'admin.php?page=wc-settings',
        'list_woocommerce_products' => 'edit.php?post_type=product',
        'create_woocommerce_product' => 'edit.php?post_type=product',
        'update_woocommerce_product' => 'edit.php?post_type=product',
        'list_woocommerce_orders' => 'admin.php?page=wc-orders',
        'update_woocommerce_order_status' => 'admin.php?page=wc-orders',
        'create_woocommerce_coupon' => 'edit.php?post_type=shop_coupon',
    ];

    public function __construct(
        private readonly WAA_Provider_Base $provider,
        private readonly WAA_Tool_Registry $registry,
        private readonly WAA_Audit_Log     $log
    ) {}

    public static function build_system_prompt(WAA_Provider_Base $provider, WAA_Tool_Registry $registry, WAA_Settings $settings): string {
        $site = [
            'url'      => get_site_url(),
            'title'    => get_bloginfo('name'),
            'wp_ver'   => get_bloginfo('version'),
            'timezone' => get_option('timezone_string') ?: 'UTC',
            'user'     => wp_get_current_user()->display_name,
            'provider' => $provider->get_label(),
        ];

        $base = <<<PROMPT
You are a WordPress admin assistant embedded in the wp-admin panel.
Powered by: {$site['provider']}

Site context:
- URL: {$site['url']}
- Title: {$site['title']}
- WordPress: {$site['wp_ver']}
- Timezone: {$site['timezone']}
- Current user: {$site['user']}

Your job: help administrators configure WordPress through natural language.

Rules:
1. Read current state before modifying (use get_ tools first).
2. For destructive or sensitive site-level actions (installing/activating/deactivating plugins, installing or switching themes, changing user roles, updating site/security settings, changing the site icon), and for content actions that publish, privatize, or trash content, state clearly what you will do and ask for confirmation before using the write tool.
3. Never expose or repeat API keys, passwords, or credentials.
4. If a tool returns an error, explain it and suggest a fix.
5. Be concise. Confirm changes after every successful write.
6. Respond in the same language the user writes in.
7. When asked to change the site icon without a specific URL, call search_icon first, then immediately call set_site_icon with the best matching result — do not list options or ask the user to choose unless no results are found.
8. When asked to write or create a post: use create_simple_post for short news/announcements (1–3 paragraphs, no image); use create_rich_post for anything longer, topic-based, or when the user wants depth. Never ask the user for the title or content; generate them yourself.
9. For very long or highly structured articles (roughly over 1200 words, many sections, or heavy formatting requirements), do not negotiate the request down. Make a best-effort draft and prefer a two-step execution: first call create_rich_post with skip_featured_image=true, status=draft, and word_count_target set near the requested size, then after the draft exists call search_images and set_post_image if an image is still needed. The first create_rich_post call must already contain substantial article content, not just a short stub or outline.
10. You have a search_images tool that searches Pexels for royalty-free photos. When the user asks you to search for images or add a photo to an existing post, call search_images then set_post_image. Do not say you cannot search the web.
11. create_rich_post auto-fetches its own image by default. If the article is especially long or reliability matters more than speed, call create_rich_post with skip_featured_image=true and attach the image afterward in a separate tool step.
12. You can include Mermaid diagrams in post content using the shortcode: [mermaid]flowchart TD\n  A-->B[/mermaid]. Use this for architecture diagrams, flowcharts, timelines, etc. Supported diagram types: flowchart, sequenceDiagram, classDiagram, stateDiagram, erDiagram, gantt, pie, mindmap.
13. If you include Mermaid, output only raw Mermaid syntax inside the shortcode — never wrap it in triple backticks, never mix explanation text into the shortcode, and prefer short conservative diagrams that are likely to parse on Mermaid v11. Use plain `flowchart TD` by default unless another type is clearly needed, and use quoted labels when a node label contains spaces or punctuation.
14. You have a fetch_rss tool with curated tech/science feeds (Ars Technica, TechCrunch, MIT Technology Review, The Verge, Nature, ScienceDaily, NASA, Phys.org, etc.). When asked for latest news or to write a post based on current events, call fetch_rss with 1–2 most relevant presets only — pick by topic (e.g. AI news → techcrunch_ai + mit_review_ai; space → nasa + esa; science → sciencedaily or physorg; general tech → ars_technica). Never fetch all feeds at once.
15. When the user asks about WooCommerce store setup, products, orders, or coupons, prefer the dedicated WooCommerce tools instead of generic WordPress post or settings tools.
16. When the user requests a sports preview, current-event article, or topical draft but leaves some specifics unstated, do not block on missing details. Make reasonable assumptions, write a clearly framed preview-style draft, and proceed with the content tool instead of asking follow-up questions unless the missing detail is essential for a destructive site action.
PROMPT;

        $tool_names = array_column($registry->get_schemas(), 'name');
        if (!empty($tool_names)) {
            $base .= "\n\nRegistered tools — use ONLY these exact names (do not invent others):\n" . implode(', ', $tool_names);
        }

        $model_instructions = $provider->get_model_instructions();
        if ($model_instructions !== '') {
            $base .= "\n\nModel-specific guidance:\n" . $model_instructions;
        }

        $custom_rules = $settings->get_custom_rules();
        if ($custom_rules !== '') {
            $base .= "\n\nCustom rules (set by site administrator):\n" . $custom_rules;
        }

        return $base;
    }

    public function run(string $user_message, array $history = [], ?array $confirmation = null, ?array $workflow = null): Generator {
        if (($confirmation['approved'] ?? false) === true) {
            yield from $this->run_confirmed_action($confirmation);
            return;
        }

        if (($workflow['kind'] ?? '') === 'wizard' && ($workflow['status'] ?? 'collecting') === 'collecting') {
            yield ['type' => 'text_delta', 'content' => $this->build_active_workflow_message($workflow)];
            return;
        }

        $messages      = $this->build_runtime_messages($user_message, $history);
        if (($workflow['kind'] ?? '') === 'wizard') {
            $workflow_context = WAA_Wizard_Registry::summarize_active_workflow($workflow);
            if ($workflow_context !== '') {
                $messages[] = [
                    'role' => 'user',
                    'content' => "Workflow context:\n" . $workflow_context,
                ];
            }
        }
        $iteration     = 0;
        $total_in      = 0;
        $total_out     = 0;
        $start_ms      = (int) (microtime(true) * 1000);

        while ($iteration++ < WAA_MAX_TOOL_ITERATIONS) {
            $llm_started_ms = (int) (microtime(true) * 1000);
            $response = $this->provider->complete(
                $this->system_prompt(),
                $messages,
                $this->registry->get_schemas()
            );
            $llm_duration_ms = (int) (microtime(true) * 1000) - $llm_started_ms;

            // Accumulate token usage
            $call_in   = $response['usage']['input_tokens']  ?? 0;
            $call_out  = $response['usage']['output_tokens'] ?? 0;
            $total_in  += $call_in;
            $total_out += $call_out;

            yield [
                'type'            => 'trace',
                'phase'           => 'llm',
                'iteration'       => $iteration,
                'duration_ms'     => $llm_duration_ms,
                'tool_call_count' => count($response['tool_calls'] ?? []),
                'text_chars'      => strlen((string) ($response['text'] ?? '')),
                'elapsed_ms'      => (int) (microtime(true) * 1000) - $start_ms,
            ];

            // Yield usage snapshot after each LLM call
            yield [
                'type'          => 'usage',
                'input_tokens'  => $total_in,
                'output_tokens' => $total_out,
                'elapsed_ms'    => (int) (microtime(true) * 1000) - $start_ms,
                'cost_usd'      => WAA_Pricing::calculate(
                    $this->provider->get_id(),
                    $this->get_model(),
                    $total_in,
                    $total_out
                ),
            ];

            if (!empty($response['text'])) {
                yield ['type' => 'text_delta', 'content' => $response['text']];
            }

            if ($response['stop_reason'] === 'end_turn' || empty($response['tool_calls'])) {
                break;
            }

            $messages[] = [
                'role'       => 'assistant',
                'content'    => $response['text'],
                'tool_calls' => $response['tool_calls'],
            ];

            foreach ($response['tool_calls'] as $tc) {
                $confirmation = $this->classify_action($tc['name'] ?? '', $tc['input'] ?? []);

                if ($confirmation['requires_confirmation'] ?? false) {
                    yield [
                        'type' => 'confirmation_required',
                        'tool_name' => $tc['name'],
                        'tool_use_id' => $tc['id'],
                        'tool_input' => $tc['input'] ?? [],
                        'message' => $confirmation['summary'] ?? $this->build_confirmation_message($tc['name'], $tc['input'] ?? []),
                        'confirmation' => $confirmation,
                    ];
                    return;
                }

                yield ['type' => 'tool_start', 'tool_name' => $tc['name'], 'tool_use_id' => $tc['id'], 'tool_input' => $tc['input']];

                $tool_started_ms = (int) (microtime(true) * 1000);
                $result = $this->registry->execute($tc['name'], $tc['input']);
                $tool_duration_ms = (int) (microtime(true) * 1000) - $tool_started_ms;

                // Extract internal navigation keys before exposing result to AI/log
                $nav_url = esc_url_raw($result['_navigate_url'] ?? '');
                unset($result['_navigate_url']);

                $this->log->write($tc['name'], $tc['input'], $result, [
                    'provider'      => $this->provider->get_id(),
                    'model'         => $this->get_model(),
                    'input_tokens'  => $call_in,
                    'output_tokens' => $call_out,
                ]);

                yield [
                    'type'         => 'trace',
                    'phase'        => 'tool',
                    'iteration'    => $iteration,
                    'tool_name'    => $tc['name'],
                    'tool_use_id'  => $tc['id'],
                    'duration_ms'  => $tool_duration_ms,
                    'status'       => isset($result['error']) || (($result['success'] ?? null) === false) ? 'error' : 'success',
                    'elapsed_ms'   => (int) (microtime(true) * 1000) - $start_ms,
                ];
                yield ['type' => 'tool_end', 'tool_name' => $tc['name'], 'result' => $result, 'tool_use_id' => $tc['id']];

                // Navigate: prefer dynamic URL from tool result, fall back to static map
                if (!$nav_url) {
                    $nav_path = self::NAVIGATE_MAP[$tc['name']] ?? '';
                    if ($nav_path) $nav_url = admin_url($nav_path);
                }
                if ($nav_url) {
                    yield ['type' => 'navigate', 'url' => $nav_url];
                }

                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $tc['id'],
                    'tool_name'    => $tc['name'],
                    'result'       => $result,
                ];
            }
        }

        if ($iteration > WAA_MAX_TOOL_ITERATIONS) {
            yield ['type' => 'text_delta', 'content' => "\n\n_(Maximum tool iterations reached.)_"];
        }
    }

    public function requires_confirmation(string $tool_name, array $tool_input = []): bool {
        return $this->classify_action($tool_name, $tool_input)['requires_confirmation'] ?? false;
    }

    public function classify_action(string $tool_name, array $tool_input = []): array {
        $classification = [
            'tool_name' => $tool_name,
            'action_type' => 'write',
            'risk_level' => 'safe',
            'requires_confirmation' => false,
            'is_async' => false,
            'title' => 'Approve change',
            'summary' => '',
            'impact' => '',
            'current_state' => null,
            'proposed_state' => null,
            'confirm_label' => 'Confirm change',
            'cancel_label' => 'Cancel action',
        ];

        if (in_array($tool_name, ['install_plugin', 'install_theme', 'activate_plugin', 'deactivate_plugin', 'switch_theme', 'update_site_settings', 'set_site_icon', 'update_user_role', 'security_harden', 'wordfence_update_settings', 'wordfence_disconnect_central', 'wordfence_run_scan', 'update_woocommerce_settings', 'create_woocommerce_product', 'update_woocommerce_product', 'update_woocommerce_order_status', 'create_woocommerce_coupon'], true)) {
            return array_merge($classification, $this->classify_site_action($tool_name, $tool_input));
        }

        return match ($tool_name) {
            'create_post', 'create_simple_post', 'create_rich_post'
                => array_merge($classification, $this->classify_content_creation_action($tool_input)),
            'update_post'
                => array_merge($classification, $this->classify_update_post_action($tool_input)),
            'set_post_image'
                => array_merge($classification, $this->classify_set_post_image_action($tool_input)),
            default => $classification,
        };
    }

    public function build_runtime_messages(string $user_message, array $history = []): array {
        $messages = array_merge(
            $this->normalize_history($history),
            [['role' => 'user', 'content' => $user_message]]
        );

        $preview_directive = $this->build_preview_draft_directive($user_message);
        if ($preview_directive !== '') {
            $messages[] = [
                'role' => 'user',
                'content' => $preview_directive,
            ];
        }

        return $messages;
    }

    public function normalize_history(array $history): array {
        $normalized = [];

        foreach ($history as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = (string) ($message['role'] ?? '');

            if ($role === 'user') {
                $content = (string) ($message['content'] ?? '');
                if ($content !== '') {
                    $normalized[] = ['role' => 'user', 'content' => $content];
                }
                continue;
            }

            if ($role === 'assistant') {
                $content = (string) ($message['content'] ?? '');
                $tool_calls = $this->normalize_tool_calls($message['tool_calls'] ?? []);

                if ($content !== '' || $tool_calls !== []) {
                    $normalized[] = [
                        'role' => 'assistant',
                        'content' => $content,
                        'tool_calls' => $tool_calls,
                    ];
                }
                continue;
            }

            if ($role === 'tool') {
                $tool_call_id = (string) ($message['tool_call_id'] ?? '');
                $tool_name    = (string) ($message['tool_name'] ?? '');
                $result       = $message['result'] ?? [];

                if ($tool_call_id !== '' && $tool_name !== '' && is_array($result)) {
                    $normalized[] = [
                        'role' => 'tool',
                        'tool_call_id' => $tool_call_id,
                        'tool_name' => $tool_name,
                        'result' => $result,
                    ];
                }
            }
        }

        $windowed = array_slice($normalized, -self::MAX_HISTORY_MESSAGES);
        while (!empty($windowed) && ($windowed[0]['role'] ?? '') === 'tool') {
            array_shift($windowed);
        }

        return array_values($windowed);
    }

    private function normalize_tool_calls(mixed $tool_calls): array {
        if (!is_array($tool_calls)) {
            return [];
        }

        $normalized = [];

        foreach ($tool_calls as $tool_call) {
            if (!is_array($tool_call)) {
                continue;
            }

            $id = (string) ($tool_call['id'] ?? '');
            $name = (string) ($tool_call['name'] ?? '');
            $input = $tool_call['input'] ?? [];

            if ($id === '' || $name === '' || !is_array($input)) {
                continue;
            }

            $normalized[] = [
                'id' => $id,
                'name' => $name,
                'input' => $input,
            ];
        }

        return $normalized;
    }

    private function build_preview_draft_directive(string $user_message): string {
        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower($user_message)
            : strtolower($user_message);

        if (!$this->looks_like_long_form_request($normalized)) {
            return '';
        }

        if (!$this->looks_like_topical_sports_preview($normalized)) {
            return '';
        }

        if (!$this->is_missing_specific_match_details($normalized)) {
            return '';
        }

        return <<<TEXT
Product behavior override for this turn:
- The user requested a long topical sports article but left the exact match details underspecified.
- Do not ask follow-up questions.
- Create a preview draft immediately.
- Treat this as a clearly labeled preview article based on reasonable assumptions, not a final fact-checked match report.
- Use create_rich_post with status=draft.
- Prefer skip_featured_image=true for the first draft if the article is long, then attach an image afterward.
- The draft must contain substantial body content, not just an outline.
- Use a standard preview structure:
  1. Mở bài
  2. Bối cảnh trận đấu
  3. Hành trình / phong độ hai đội
  4. Cầu thủ nổi bật
  5. Phân tích chiến thuật
  6. Điểm nóng có thể quyết định trận đấu
  7. Dự đoán kịch bản và tỷ số
  8. Kết luận
- Make the title and introduction clearly signal that this is a preview draft based on currently available assumptions.
TEXT;
    }

    private function looks_like_long_form_request(string $message): bool {
        $long_markers = [
            '1200', '1500', '1800', '2000', '2200', '2500', '2800',
            'bài blog dài', 'bài viết dài', 'chi tiết', 'nhiều mục', 'nhiều section',
            'phân tích chuyên sâu', 'không viết ngắn gọn',
        ];

        foreach ($long_markers as $marker) {
            if (str_contains($message, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function looks_like_topical_sports_preview(string $message): bool {
        $sports_markers = [
            'world cup', 'bóng đá', 'trận bóng', 'trận đấu', 'soi kèo',
            'dự đoán tỷ số', 'đội hình', 'bán kết', 'chung kết', 'preview',
        ];

        foreach ($sports_markers as $marker) {
            if (str_contains($message, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function is_missing_specific_match_details(string $message): bool {
        $specific_markers = [
            ' vs ', ' gặp ', 'đội a', 'đội b', 'brazil', 'argentina', 'pháp',
            'đức', 'anh', 'tây ban nha', 'bồ đào nha', 'hà lan', 'croatia',
            'morocco', 'uruguay', 'colombia', 'belgium',
        ];

        foreach ($specific_markers as $marker) {
            if (str_contains($message, $marker)) {
                return false;
            }
        }

        return true;
    }

    private function run_confirmed_action(array $confirmation): Generator {
        $tool_name = (string) ($confirmation['tool_name'] ?? '');
        $tool_use_id = (string) ($confirmation['tool_use_id'] ?? '');
        $tool_input = $confirmation['tool_input'] ?? [];

        if (!$this->requires_confirmation($tool_name, $tool_input) || $tool_use_id === '' || !is_array($tool_input)) {
            yield ['type' => 'error', 'message' => 'The pending action could not be confirmed. Please try again.'];
            return;
        }

        yield ['type' => 'tool_start', 'tool_name' => $tool_name, 'tool_use_id' => $tool_use_id, 'tool_input' => $tool_input];

        $result = $this->registry->execute($tool_name, $tool_input);

        $nav_url = esc_url_raw($result['_navigate_url'] ?? '');
        unset($result['_navigate_url']);

        $this->log->write($tool_name, $tool_input, $result, [
            'provider' => $this->provider->get_id(),
            'model' => $this->get_model(),
            'input_tokens' => 0,
            'output_tokens' => 0,
        ]);

        yield ['type' => 'tool_end', 'tool_name' => $tool_name, 'result' => $result, 'tool_use_id' => $tool_use_id];

        if (!$nav_url) {
            $nav_path = self::NAVIGATE_MAP[$tool_name] ?? '';
            if ($nav_path) {
                $nav_url = admin_url($nav_path);
            }
        }

        if ($nav_url) {
            yield ['type' => 'navigate', 'url' => $nav_url];
        }

        yield ['type' => 'text_delta', 'content' => $this->build_confirmation_success_message($tool_name, $result)];
    }

    private function build_confirmation_message(string $tool_name, array $input): string {
        return (string) ($this->classify_action($tool_name, $input)['summary'] ?? 'This action needs confirmation before it can continue.');
    }

    private function build_active_workflow_message(array $workflow): string {
        $wizard_id = sanitize_key((string) ($workflow['workflowId'] ?? 'workflow'));
        $definition = WAA_Wizard_Registry::get($wizard_id);
        $title = $definition['title'] ?? 'guided workflow';
        $step = sanitize_key((string) ($workflow['currentStep'] ?? ''));

        if ($step !== '') {
            return "A {$title} workflow is still in progress at step `{$step}`. Continue the form, submit it, or cancel it before using free chat.";
        }

        return "A {$title} workflow is still in progress. Continue the form, submit it, or cancel it before using free chat.";
    }

    private function classify_site_action(string $tool_name, array $input): array {
        return match ($tool_name) {
            'install_plugin' => [
                'action_type' => 'extension_install',
                'risk_level' => 'sensitive',
                'requires_confirmation' => true,
                'summary' => sprintf(
                    'This will install plugin `%s`. It will not be activated automatically. Confirm to continue.',
                    (string) ($input['slug'] ?? 'unknown')
                ),
                'impact' => 'Adds new plugin code to this WordPress site, but leaves it inactive until a separate activation step.',
                'proposed_state' => ['plugin_slug' => (string) ($input['slug'] ?? '')],
            ],
            'install_theme' => [
                'action_type' => 'extension_install',
                'risk_level' => 'sensitive',
                'requires_confirmation' => true,
                'summary' => sprintf(
                    'This will install theme `%s`. It will not be activated automatically. Confirm to continue.',
                    (string) ($input['slug'] ?? 'unknown')
                ),
                'impact' => 'Adds new theme code to this WordPress site, but leaves the active theme unchanged until a separate switch step.',
                'proposed_state' => ['theme_slug' => (string) ($input['slug'] ?? '')],
            ],
            'activate_plugin' => [
                'action_type' => 'site_write',
                'risk_level' => 'sensitive',
                'requires_confirmation' => true,
                'summary' => sprintf(
                    'This will activate plugin `%s`. Confirm to continue.',
                    (string) ($input['plugin_file'] ?? 'unknown')
                ),
                'impact' => 'Changes the site runtime by turning on plugin code for future requests.',
            ],
            'deactivate_plugin' => [
                'action_type' => 'site_write',
                'risk_level' => 'destructive',
                'requires_confirmation' => true,
                'summary' => sprintf(
                    'This will deactivate plugin `%s`. Confirm to continue.',
                    (string) ($input['plugin_file'] ?? 'unknown')
                ),
                'impact' => 'Turns off active plugin behavior and may change site features immediately.',
            ],
            'switch_theme' => [
                'action_type' => 'site_write',
                'risk_level' => 'destructive',
                'requires_confirmation' => true,
                'summary' => sprintf(
                    'This will switch the active theme to `%s`. Confirm to continue.',
                    (string) ($input['theme_slug'] ?? 'unknown')
                ),
                'impact' => 'Changes the live front-end theme shown to visitors.',
            ],
            'update_site_settings' => [
                'action_type' => 'site_write',
                'risk_level' => 'sensitive',
                'requires_confirmation' => true,
                'summary' => sprintf(
                    'This will update site settings: %s. Confirm to continue.',
                    $this->format_key_list(array_keys((array) ($input['updates'] ?? [])))
                ),
                'impact' => 'Changes site-wide WordPress settings that may affect visitors, administrators, or integrations.',
            ],
            'set_site_icon' => [
                'action_type' => 'site_write',
                'risk_level' => 'sensitive',
                'requires_confirmation' => true,
                'summary' => sprintf(
                    'This will replace the site icon using `%s`. Confirm to continue.',
                    (string) ($input['image_url'] ?? 'unknown source')
                ),
                'impact' => 'Changes the browser and device icon shown for this site.',
            ],
            'update_user_role' => [
                'action_type' => 'site_write',
                'risk_level' => 'destructive',
                'requires_confirmation' => true,
                'summary' => sprintf(
                    'This will change user `%s` to role `%s`. Confirm to continue.',
                    (string) ($input['user_id'] ?? 'unknown'),
                    (string) ($input['role'] ?? 'unknown')
                ),
                'impact' => 'Changes what this user can access and modify in wp-admin.',
            ],
            'security_harden' => [
                'action_type' => 'site_write',
                'risk_level' => 'destructive',
                'requires_confirmation' => true,
                'summary' => sprintf(
                    'This will apply security hardening changes: %s. Confirm to continue.',
                    $this->format_enabled_flags((array) $input)
                ),
                'impact' => 'Changes site-wide security settings and may disable existing behaviors such as comments, XML-RPC, or registration.',
            ],
            'wordfence_update_settings' => [
                'action_type' => 'site_write',
                'risk_level' => 'sensitive',
                'requires_confirmation' => true,
                'summary' => sprintf(
                    'This will update Wordfence settings: %s. Confirm to continue.',
                    $this->format_key_list(array_keys((array) ($input['settings'] ?? [])))
                ),
                'impact' => 'Changes firewall or security scanner behavior for this site.',
            ],
            'wordfence_disconnect_central' => [
                'action_type' => 'site_write',
                'risk_level' => 'sensitive',
                'requires_confirmation' => true,
                'summary' => 'This will disconnect the site from Wordfence Central. Confirm to continue.',
                'impact' => 'Stops Central management for this site while leaving local protections in place.',
            ],
            'wordfence_run_scan' => [
                'action_type' => 'background_job',
                'risk_level' => 'safe',
                'requires_confirmation' => false,
                'is_async' => true,
                'summary' => '',
                'impact' => 'Starts a background security scan and requires a later follow-up to read results.',
            ],
            'update_woocommerce_settings' => [
                'action_type' => 'commerce_setup',
                'risk_level' => 'sensitive',
                'requires_confirmation' => true,
                'summary' => sprintf(
                    'This will update WooCommerce store settings: %s. Confirm to continue.',
                    $this->format_key_list(array_keys((array) ($input['updates'] ?? [])))
                ),
                'impact' => 'Changes store-wide WooCommerce configuration such as address, currency, tax, coupon, or shipping defaults.',
            ],
            'create_woocommerce_product' => [
                'action_type' => 'commerce_catalog_write',
                'risk_level' => 'sensitive',
                'requires_confirmation' => true,
                'summary' => sprintf(
                    'This will create WooCommerce product `%s` with status `%s`. Confirm to continue.',
                    (string) ($input['name'] ?? 'Untitled product'),
                    (string) ($input['status'] ?? 'draft')
                ),
                'impact' => 'Adds a new product to the store catalog and may expose it on the live storefront.',
                'proposed_state' => array_filter([
                    'name' => (string) ($input['name'] ?? ''),
                    'status' => (string) ($input['status'] ?? 'draft'),
                    'regular_price' => isset($input['regular_price']) ? (string) $input['regular_price'] : '',
                    'stock_quantity' => isset($input['stock_quantity']) ? (int) $input['stock_quantity'] : null,
                ], static fn($value) => $value !== '' && $value !== null),
            ],
            'update_woocommerce_product' => [
                'action_type' => 'commerce_catalog_write',
                'risk_level' => 'sensitive',
                'requires_confirmation' => true,
                'summary' => sprintf(
                    'This will update WooCommerce product `%s`%s. Confirm to continue.',
                    (string) ($input['product_id'] ?? 'unknown'),
                    $this->describe_woocommerce_product_change($input)
                ),
                'impact' => 'Changes live or draft store catalog data such as price, stock, visibility, media, or product copy.',
                'proposed_state' => array_filter([
                    'status' => (string) ($input['status'] ?? ''),
                    'regular_price' => isset($input['regular_price']) ? (string) $input['regular_price'] : '',
                    'sale_price' => isset($input['sale_price']) ? (string) $input['sale_price'] : '',
                    'stock_quantity' => isset($input['stock_quantity']) ? (int) $input['stock_quantity'] : null,
                    'stock_status' => (string) ($input['stock_status'] ?? ''),
                ], static fn($value) => $value !== '' && $value !== null),
            ],
            'update_woocommerce_order_status' => [
                'action_type' => 'commerce_order_write',
                'risk_level' => 'sensitive',
                'requires_confirmation' => true,
                'summary' => sprintf(
                    'This will change WooCommerce order `%s` to status `%s`. Confirm to continue.',
                    (string) ($input['order_id'] ?? 'unknown'),
                    (string) ($input['status'] ?? 'unknown')
                ),
                'impact' => 'Changes customer-facing and fulfillment state for this order.',
            ],
            'create_woocommerce_coupon' => [
                'action_type' => 'commerce_discount_write',
                'risk_level' => 'sensitive',
                'requires_confirmation' => true,
                'summary' => sprintf(
                    'This will create WooCommerce coupon `%s` with a `%s` discount. Confirm to continue.',
                    (string) ($input['code'] ?? 'unknown'),
                    (string) ($input['discount_type'] ?? 'unknown')
                ),
                'impact' => 'Creates a new discount code customers may use at checkout.',
            ],
            default => [],
        };
    }

    private function classify_content_creation_action(array $input): array {
        $requires_confirmation = $this->content_status_requires_confirmation($input['status'] ?? 'draft');
        $target = $this->describe_post_creation_target($input);

        return [
            'action_type' => 'content_write',
            'risk_level' => $requires_confirmation ? 'sensitive' : 'safe',
            'requires_confirmation' => $requires_confirmation,
            'summary' => $requires_confirmation
                ? sprintf(
                    'This will %s `%s`. Confirm to continue.',
                    $target,
                    (string) ($input['title'] ?? 'Untitled post')
                )
                : '',
            'impact' => $requires_confirmation
                ? 'Changes public or protected site content visibility when this content is saved.'
                : 'Saves content without changing live site visibility.',
            'proposed_state' => [
                'title' => (string) ($input['title'] ?? ''),
                'status' => (string) ($input['status'] ?? 'draft'),
            ],
        ];
    }

    private function classify_update_post_action(array $input): array {
        $requires_confirmation = $this->update_post_requires_confirmation($input);
        $target = $this->describe_content_target($input);

        return [
            'action_type' => 'content_write',
            'risk_level' => $requires_confirmation ? 'sensitive' : 'safe',
            'requires_confirmation' => $requires_confirmation,
            'summary' => $requires_confirmation
                ? sprintf(
                    'This will update %s `%s`%s. Confirm to continue.',
                    $target,
                    (string) ($input['post_id'] ?? 'unknown'),
                    $this->describe_update_post_change($input)
                )
                : '',
            'impact' => $requires_confirmation
                ? sprintf('Changes the content or visibility of a %s.', $target)
                : 'Updates draft-safe content without affecting live site visibility.',
            'current_state' => ['target' => $target],
            'proposed_state' => array_filter([
                'status' => (string) ($input['status'] ?? ''),
                'title' => !empty($input['title']) ? 'updated' : '',
                'content' => !empty($input['content']) ? 'updated' : '',
            ]),
        ];
    }

    private function classify_set_post_image_action(array $input): array {
        $requires_confirmation = $this->set_post_image_requires_confirmation($input);
        $target = $this->describe_content_target($input);

        return [
            'action_type' => 'content_media_write',
            'risk_level' => $requires_confirmation ? 'sensitive' : 'safe',
            'requires_confirmation' => $requires_confirmation,
            'summary' => $requires_confirmation
                ? sprintf(
                    'This will %s on %s `%s`. Confirm to continue.',
                    $this->describe_post_image_change($input),
                    $target,
                    (string) ($input['post_id'] ?? 'unknown')
                )
                : '',
            'impact' => $requires_confirmation
                ? sprintf('Changes the visible media presentation of a %s.', $target)
                : 'Updates media on draft-safe content only.',
            'current_state' => ['target' => $target],
            'proposed_state' => [
                'mode' => (string) ($input['mode'] ?? 'featured'),
                'attachment_id' => (int) ($input['attachment_id'] ?? 0),
            ],
        ];
    }

    private function build_confirmation_success_message(string $tool_name, array $result): string {
        if (!empty($result['error']) || (($result['success'] ?? true) === false)) {
            return 'I tried to run the confirmed action, but it failed.';
        }

        return match ($tool_name) {
            'install_plugin' => sprintf(
                'Confirmed. Plugin `%s` has been installed and is ready to activate.',
                (string) ($result['plugin_file'] ?? ($result['name'] ?? 'unknown'))
            ),
            'install_theme' => sprintf(
                'Confirmed. Theme `%s` has been installed and is ready to activate.',
                (string) ($result['theme_slug'] ?? ($result['name'] ?? 'unknown'))
            ),
            'activate_plugin' => sprintf(
                'Confirmed. The plugin `%s` is now active.',
                (string) ($result['plugin'] ?? 'unknown')
            ),
            'deactivate_plugin' => sprintf(
                'Confirmed. The plugin `%s` has been deactivated.',
                (string) ($result['plugin'] ?? 'unknown')
            ),
            'switch_theme' => sprintf(
                'Confirmed. The active theme is now `%s`.',
                (string) ($result['active_theme'] ?? 'unknown')
            ),
            'update_site_settings' => sprintf(
                'Confirmed. I updated site settings: %s.',
                $this->format_key_list(array_keys((array) ($result['results'] ?? [])))
            ),
            'set_site_icon' => 'Confirmed. The site icon has been updated.',
            'update_user_role' => sprintf(
                'Confirmed. User `%s` now has role `%s`.',
                (string) ($result['user_id'] ?? 'unknown'),
                (string) ($result['new_role'] ?? 'unknown')
            ),
            'security_harden' => sprintf(
                'Confirmed. Applied security hardening changes: %s.',
                $this->format_list((array) ($result['applied'] ?? []), 'requested changes')
            ),
            'wordfence_update_settings' => sprintf(
                'Confirmed. Updated Wordfence settings: %s.',
                $this->format_key_list(array_keys((array) ($result['updated'] ?? [])))
            ),
            'wordfence_disconnect_central' => 'Confirmed. The site is now disconnected from Wordfence Central.',
            'update_woocommerce_settings' => sprintf(
                'Confirmed. I updated WooCommerce settings: %s.',
                $this->format_key_list(array_keys((array) ($result['results'] ?? [])))
            ),
            'create_woocommerce_product' => sprintf(
                'Confirmed. WooCommerce product `%s` has been created.',
                (string) ($result['product']['id'] ?? 'unknown')
            ),
            'update_woocommerce_product' => sprintf(
                'Confirmed. WooCommerce product `%s` has been updated.',
                (string) ($result['product']['id'] ?? ($result['product_id'] ?? 'unknown'))
            ),
            'update_woocommerce_order_status' => sprintf(
                'Confirmed. WooCommerce order `%s` is now `%s`.',
                (string) ($result['order_id'] ?? 'unknown'),
                (string) ($result['new_status'] ?? 'updated')
            ),
            'create_woocommerce_coupon' => sprintf(
                'Confirmed. WooCommerce coupon `%s` has been created.',
                (string) ($result['code'] ?? 'unknown')
            ),
            'create_post', 'create_simple_post', 'create_rich_post' => sprintf(
                'Confirmed. The post `%s` is now `%s`.',
                (string) ($result['post_id'] ?? 'unknown'),
                (string) ($result['status'] ?? 'saved')
            ),
            'update_post' => sprintf(
                'Confirmed. Post `%s` has been updated.',
                (string) ($result['post_id'] ?? 'unknown')
            ),
            'set_post_image' => sprintf(
                'Confirmed. The image for post `%s` has been updated.',
                (string) ($result['post_id'] ?? 'unknown')
            ),
            default => 'Confirmed. The requested action has been completed.',
        };
    }

    private function content_status_requires_confirmation(mixed $status): bool {
        $status = sanitize_key((string) $status);
        if ($status === '') {
            $status = 'draft';
        }

        return in_array($status, self::CONTENT_CONFIRM_STATUSES, true);
    }

    private function update_post_requires_confirmation(array $input): bool {
        if ($this->content_status_requires_confirmation($input['status'] ?? '')) {
            return true;
        }

        $post_id = (int) ($input['post_id'] ?? 0);
        if ($post_id <= 0) {
            return false;
        }

        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            return false;
        }

        if (!$this->post_status_is_live($post->post_status)) {
            return false;
        }

        return !empty($input['title']) || !empty($input['content']) || array_key_exists('status', $input);
    }

    private function set_post_image_requires_confirmation(array $input): bool {
        $post_id = (int) ($input['post_id'] ?? 0);
        if ($post_id <= 0) {
            return false;
        }

        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            return false;
        }

        return $this->post_status_is_live($post->post_status);
    }

    private function post_status_is_live(string $status): bool {
        return in_array(sanitize_key($status), ['publish', 'private'], true);
    }

    private function describe_post_image_change(array $input): string {
        $mode = sanitize_key((string) ($input['mode'] ?? 'featured'));

        return match ($mode) {
            'prepend' => 'insert an image at the top of the content',
            'append' => 'insert an image at the bottom of the content',
            default => 'replace the featured image',
        };
    }

    private function describe_woocommerce_product_change(array $input): string {
        $changes = [];

        if (array_key_exists('name', $input)) {
            $changes[] = 'name';
        }
        if (array_key_exists('status', $input)) {
            $changes[] = 'status';
        }
        if (array_key_exists('regular_price', $input) || array_key_exists('sale_price', $input)) {
            $changes[] = 'pricing';
        }
        if (array_key_exists('stock_quantity', $input) || array_key_exists('stock_status', $input)) {
            $changes[] = 'stock';
        }
        if (array_key_exists('image_url', $input)) {
            $changes[] = 'featured image';
        }
        if (array_key_exists('description', $input) || array_key_exists('short_description', $input)) {
            $changes[] = 'content';
        }

        if ($changes === []) {
            return '';
        }

        return ' (' . $this->format_list($changes, 'product details') . ')';
    }

    private function describe_content_target(array $input): string {
        $post_id = (int) ($input['post_id'] ?? 0);
        if ($post_id <= 0) {
            return 'post';
        }

        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            return 'post';
        }

        $type = $post->post_type === 'page' ? 'page' : 'post';
        $status = sanitize_key($post->post_status);

        return match ($status) {
            'publish' => 'live ' . $type,
            'private' => 'private ' . $type,
            default => $type,
        };
    }

    private function describe_post_creation_target(array $input): string {
        $status = sanitize_key((string) ($input['status'] ?? 'draft'));
        if ($status === '') {
            $status = 'draft';
        }

        return match ($status) {
            'publish' => 'publish post',
            'private' => 'create a private post',
            'trash' => 'move content to trash',
            default => 'save content',
        };
    }

    private function describe_update_post_change(array $input): string {
        $status = sanitize_key((string) ($input['status'] ?? ''));

        return match ($status) {
            'publish' => ' and publish it',
            'private' => ' and make it private',
            'trash' => ' and move it to trash',
            default => '',
        };
    }

    private function format_key_list(array $keys): string {
        $clean = array_values(array_filter(array_map(
            static fn($key): string => sanitize_text_field((string) $key),
            $keys
        )));

        return $this->format_list($clean, 'selected settings');
    }

    private function format_enabled_flags(array $input): string {
        $enabled = [];

        foreach ($input as $key => $value) {
            if ($value) {
                $enabled[] = sanitize_text_field((string) $key);
            }
        }

        return $this->format_list($enabled, 'requested hardening options');
    }

    private function format_list(array $items, string $fallback): string {
        $items = array_values(array_filter($items, static fn($item): bool => (string) $item !== ''));

        if ($items === []) {
            return $fallback;
        }

        return '`' . implode('`, `', $items) . '`';
    }

    private function get_model(): string {
        // Provider stores model internally; expose via reflection or settings lookup
        return (new WAA_Settings())->get_model();
    }

    private function system_prompt(): string {
        return self::build_system_prompt($this->provider, $this->registry, new WAA_Settings());
    }
}
