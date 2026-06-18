<?php

class RestApiSafetyTest extends WP_UnitTestCase {
    private WAA_REST_API $api;

    public function setUp(): void {
        parent::setUp();

        $this->api = new WAA_REST_API();
        do_action('rest_api_init');
    }

    public function tearDown(): void {
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_settings_route_rejects_users_without_manage_options(): void {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $request = new WP_REST_Request('GET', '/wp-admin-agent/v1/settings');
        $response = rest_get_server()->dispatch($request);

        $this->assertSame(403, $response->get_status());
        $this->assertSame('rest_forbidden', $response->get_data()['code']);
    }

    public function test_settings_route_returns_rate_limited_when_threshold_is_hit(): void {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);
        set_transient("waa_rate_{$user_id}", WAA_RATE_LIMIT, MINUTE_IN_SECONDS);

        $request = new WP_REST_Request('GET', '/wp-admin-agent/v1/settings');
        $response = rest_get_server()->dispatch($request);

        $this->assertSame(429, $response->get_status());
        $this->assertSame('rate_limited', $response->get_data()['code']);
    }

    public function test_inline_history_takes_precedence_over_persisted_conversation_messages(): void {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $persisted_history = [
            ['role' => 'user', 'content' => 'persisted'],
        ];
        $inline_history = [
            ['role' => 'user', 'content' => 'inline'],
        ];

        $wpdb->insert(WAA_TABLE_CONVERSATIONS, [
            'user_id' => $user_id,
            'title' => 'Fixture conversation',
            'messages' => wp_json_encode($persisted_history),
        ]);

        $resolved = $this->api->resolve_history(
            ['history' => $inline_history],
            (int) $wpdb->insert_id
        );

        $this->assertSame($inline_history, $resolved);
    }

    public function test_persisted_history_is_used_when_inline_history_is_missing(): void {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $persisted_history = [
            ['role' => 'user', 'content' => 'persisted'],
            ['role' => 'assistant', 'content' => 'reply'],
        ];

        $wpdb->insert(WAA_TABLE_CONVERSATIONS, [
            'user_id' => $user_id,
            'title' => 'Persisted only',
            'messages' => wp_json_encode($persisted_history),
        ]);

        $resolved = $this->api->resolve_history([], (int) $wpdb->insert_id);

        $this->assertSame($persisted_history, $resolved);
    }

    public function test_restored_conversation_history_can_continue_runtime_loop_with_fake_provider(): void {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $persisted_history = [
            ['role' => 'user', 'content' => 'List installed plugins'],
            [
                'role' => 'assistant',
                'content' => 'Checking installed plugins first.',
                'tool_calls' => [
                    ['id' => 'fake_tc_list_plugins', 'name' => 'list_plugins', 'input' => []],
                ],
            ],
            [
                'role' => 'tool',
                'tool_call_id' => 'fake_tc_list_plugins',
                'tool_name' => 'list_plugins',
                'result' => ['plugins' => []],
            ],
        ];

        $wpdb->insert(WAA_TABLE_CONVERSATIONS, [
            'user_id' => $user_id,
            'title' => 'Persisted only',
            'messages' => wp_json_encode([
                'messages' => [],
                'history' => $persisted_history,
                'usage' => [],
            ]),
        ]);

        $resolved = $this->api->resolve_history([], (int) $wpdb->insert_id);
        $agent = new WAA_Agent(
            new WAA_Provider_Fake('runtime-v1'),
            new WAA_Tool_Registry(),
            new WAA_Audit_Log()
        );

        $events = iterator_to_array($agent->run('Continue from the saved conversation.', $resolved));
        $text_events = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === 'text_delta'));

        $this->assertNotEmpty($text_events);
        $this->assertSame(
            'I checked the installed plugins and preserved the current state.',
            $text_events[0]['content']
        );
    }

    public function test_conversation_payload_decoder_supports_enveloped_and_legacy_rows(): void {
        $legacy = $this->api->decode_conversation_payload(wp_json_encode([
            ['role' => 'user', 'content' => 'legacy'],
        ]));
        $enveloped = $this->api->decode_conversation_payload(wp_json_encode([
            'messages' => [['role' => 'user', 'content' => 'saved']],
            'history' => [['role' => 'assistant', 'content' => 'history']],
            'usage' => ['input_tokens' => 5],
            'meta' => ['archived' => true],
        ]));

        $this->assertSame([['role' => 'user', 'content' => 'legacy']], $legacy['messages']);
        $this->assertSame([], $legacy['history']);
        $this->assertSame(['archived' => false], $legacy['meta']);
        $this->assertSame([['role' => 'user', 'content' => 'saved']], $enveloped['messages']);
        $this->assertSame([['role' => 'assistant', 'content' => 'history']], $enveloped['history']);
        $this->assertSame(['input_tokens' => 5], $enveloped['usage']);
        $this->assertSame(['archived' => true], $enveloped['meta']);
    }

    public function test_list_conversations_hides_archived_sessions_by_default(): void {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $wpdb->insert(WAA_TABLE_CONVERSATIONS, [
            'user_id' => $user_id,
            'title' => 'Visible session',
            'messages' => wp_json_encode([
                'messages' => [],
                'history' => [],
                'usage' => [],
                'meta' => ['archived' => false],
            ]),
        ]);
        $wpdb->insert(WAA_TABLE_CONVERSATIONS, [
            'user_id' => $user_id,
            'title' => 'Archived session',
            'messages' => wp_json_encode([
                'messages' => [],
                'history' => [],
                'usage' => [],
                'meta' => ['archived' => true],
            ]),
        ]);

        $request = new WP_REST_Request('GET', '/wp-admin-agent/v1/conversations');
        $response = rest_get_server()->dispatch($request);
        $titles = array_map(static fn($row) => $row->title, $response->get_data());

        $this->assertSame(200, $response->get_status());
        $this->assertContains('Visible session', $titles);
        $this->assertNotContains('Archived session', $titles);
    }

    public function test_update_conversation_preserves_existing_fields_when_partial_payload_is_sent(): void {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $wpdb->insert(WAA_TABLE_CONVERSATIONS, [
            'user_id' => $user_id,
            'title' => 'Original title',
            'messages' => wp_json_encode([
                'messages' => [['role' => 'user', 'content' => 'old display']],
                'history' => [['role' => 'assistant', 'content' => 'old history']],
                'usage' => ['input_tokens' => 3],
            ]),
        ]);
        $conversation_id = (int) $wpdb->insert_id;

        $request = new WP_REST_Request('POST', '/wp-admin-agent/v1/conversations/' . $conversation_id);
        $request->set_body(wp_json_encode([
            'messages' => [['role' => 'user', 'content' => 'new display']],
        ]));
        $request->set_header('content-type', 'application/json');

        $response = rest_get_server()->dispatch($request);
        $stored = $wpdb->get_var($wpdb->prepare(
            "SELECT messages FROM " . WAA_TABLE_CONVERSATIONS . " WHERE id = %d",
            $conversation_id
        ));
        $decoded = $this->api->decode_conversation_payload($stored);

        $this->assertSame(200, $response->get_status());
        $this->assertSame([['role' => 'user', 'content' => 'new display']], $decoded['messages']);
        $this->assertSame([['role' => 'assistant', 'content' => 'old history']], $decoded['history']);
        $this->assertSame(['input_tokens' => 3], $decoded['usage']);
    }

    public function test_create_conversation_derives_title_from_first_user_message_when_title_is_missing(): void {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $request = new WP_REST_Request('POST', '/wp-admin-agent/v1/conversations');
        $request->set_body(wp_json_encode([
            'messages' => [
                ['role' => 'user', 'content' => 'Need help reviewing plugin updates before deployment'],
            ],
            'history' => [],
            'usage' => [],
        ]));
        $request->set_header('content-type', 'application/json');

        $response = rest_get_server()->dispatch($request);
        $title = $wpdb->get_var($wpdb->prepare(
            "SELECT title FROM " . WAA_TABLE_CONVERSATIONS . " WHERE id = %d",
            (int) $response->get_data()['id']
        ));

        $this->assertSame(201, $response->get_status());
        $this->assertSame('Need help reviewing plugin updates before deployment', $title);
    }

    public function test_update_conversation_keeps_specific_title_stable_when_title_is_omitted(): void {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $wpdb->insert(WAA_TABLE_CONVERSATIONS, [
            'user_id' => $user_id,
            'title' => 'Plugin rollout checklist',
            'messages' => wp_json_encode([
                'messages' => [['role' => 'user', 'content' => 'old display']],
                'history' => [['role' => 'user', 'content' => 'old history']],
                'usage' => [],
            ]),
        ]);
        $conversation_id = (int) $wpdb->insert_id;

        $request = new WP_REST_Request('POST', '/wp-admin-agent/v1/conversations/' . $conversation_id);
        $request->set_body(wp_json_encode([
            'messages' => [['role' => 'user', 'content' => 'Need help reviewing plugin updates before deployment']],
        ]));
        $request->set_header('content-type', 'application/json');

        $response = rest_get_server()->dispatch($request);
        $title = $wpdb->get_var($wpdb->prepare(
            "SELECT title FROM " . WAA_TABLE_CONVERSATIONS . " WHERE id = %d",
            $conversation_id
        ));

        $this->assertSame(200, $response->get_status());
        $this->assertSame('Plugin rollout checklist', $title);
    }

    public function test_update_conversation_upgrades_generic_title_from_saved_messages_when_title_is_omitted(): void {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $wpdb->insert(WAA_TABLE_CONVERSATIONS, [
            'user_id' => $user_id,
            'title' => 'New conversation',
            'messages' => wp_json_encode([
                'messages' => [],
                'history' => [],
                'usage' => [],
            ]),
        ]);
        $conversation_id = (int) $wpdb->insert_id;

        $request = new WP_REST_Request('POST', '/wp-admin-agent/v1/conversations/' . $conversation_id);
        $request->set_body(wp_json_encode([
            'messages' => [
                ['role' => 'user', 'content' => 'Audit the live session persistence before release'],
            ],
        ]));
        $request->set_header('content-type', 'application/json');

        $response = rest_get_server()->dispatch($request);
        $title = $wpdb->get_var($wpdb->prepare(
            "SELECT title FROM " . WAA_TABLE_CONVERSATIONS . " WHERE id = %d",
            $conversation_id
        ));

        $this->assertSame(200, $response->get_status());
        $this->assertSame('Audit the live session persistence before release', $title);
    }

    public function test_update_conversation_auto_renames_low_signal_title_from_tool_history(): void {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $wpdb->insert(WAA_TABLE_CONVERSATIONS, [
            'user_id' => $user_id,
            'title' => 'Install WooCommerce',
            'messages' => wp_json_encode([
                'messages' => [['role' => 'user', 'content' => 'Install WooCommerce']],
                'history' => [['role' => 'user', 'content' => 'Install WooCommerce']],
                'usage' => [],
                'meta' => ['archived' => false],
            ]),
        ]);
        $conversation_id = (int) $wpdb->insert_id;

        $request = new WP_REST_Request('POST', '/wp-admin-agent/v1/conversations/' . $conversation_id);
        $request->set_body(wp_json_encode([
            'history' => [
                ['role' => 'user', 'content' => 'Install WooCommerce'],
                [
                    'role' => 'assistant',
                    'content' => 'Installing the plugin now.',
                    'tool_calls' => [
                        ['id' => 'tc_install', 'name' => 'install_plugin', 'input' => ['slug' => 'woocommerce']],
                    ],
                ],
                [
                    'role' => 'tool',
                    'tool_call_id' => 'tc_install',
                    'tool_name' => 'install_plugin',
                    'result' => ['plugin_file' => 'woocommerce/woocommerce.php', 'success' => true],
                ],
            ],
        ]));
        $request->set_header('content-type', 'application/json');

        $response = rest_get_server()->dispatch($request);
        $title = $wpdb->get_var($wpdb->prepare(
            "SELECT title FROM " . WAA_TABLE_CONVERSATIONS . " WHERE id = %d",
            $conversation_id
        ));

        $this->assertSame(200, $response->get_status());
        $this->assertSame('Install Woocommerce', $title);
    }

    public function test_agent_normalize_history_filters_invalid_entries_and_limits_context_window(): void {
        $history = [];

        for ($i = 1; $i <= 30; $i++) {
            $history[] = ['role' => 'tool', 'tool_call_id' => 'orphan_' . $i, 'tool_name' => 'list_plugins', 'result' => []];
            $history[] = ['role' => 'user', 'content' => 'Prompt ' . $i];
            $history[] = ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'tc_' . $i, 'name' => 'list_plugins', 'input' => []],
                ['id' => '', 'name' => 'invalid', 'input' => []],
            ]];
            $history[] = ['role' => 'tool', 'tool_call_id' => 'tc_' . $i, 'tool_name' => 'list_plugins', 'result' => ['page' => $i]];
            $history[] = ['role' => 'assistant', 'content' => 'Reply ' . $i, 'tool_calls' => 'invalid'];
            $history[] = ['role' => 'assistant', 'content' => '', 'tool_calls' => []];
        }

        $agent = new WAA_Agent(
            new WAA_Provider_Fake('runtime-v1'),
            new WAA_Tool_Registry(),
            new WAA_Audit_Log()
        );

        $normalized = $agent->normalize_history($history);

        $this->assertCount(24, $normalized);
        $this->assertNotSame('tool', $normalized[0]['role']);
        $this->assertSame('user', $normalized[0]['role']);
        $this->assertSame('Prompt 26', $normalized[0]['content']);
        $this->assertSame('assistant', $normalized[1]['role']);
        $this->assertSame([
            ['id' => 'tc_26', 'name' => 'list_plugins', 'input' => []],
        ], $normalized[1]['tool_calls']);
        $this->assertSame('tool', $normalized[2]['role']);
        $this->assertSame(['page' => 26], $normalized[2]['result']);
        $this->assertSame('Reply 30', $normalized[array_key_last($normalized)]['content']);
    }

    public function test_agent_normalize_history_drops_orphaned_tool_messages_after_long_context_trimming(): void {
        $history = [];

        for ($i = 1; $i <= 15; $i++) {
            $history[] = ['role' => 'user', 'content' => 'Prompt ' . $i];
            $history[] = ['role' => 'assistant', 'content' => 'Reply ' . $i, 'tool_calls' => []];
        }

        $history[] = ['role' => 'assistant', 'content' => 'Checking plugins', 'tool_calls' => [
            ['id' => 'tc_old', 'name' => 'list_plugins', 'input' => []],
        ]];
        $history[] = ['role' => 'tool', 'tool_call_id' => 'tc_old', 'tool_name' => 'list_plugins', 'result' => ['page' => 1]];

        for ($i = 16; $i <= 26; $i++) {
            $history[] = ['role' => 'user', 'content' => 'Prompt ' . $i];
            $history[] = ['role' => 'assistant', 'content' => 'Reply ' . $i, 'tool_calls' => []];
        }

        $history[] = ['role' => 'user', 'content' => 'Latest question'];

        $agent = new WAA_Agent(
            new WAA_Provider_Fake('runtime-v1'),
            new WAA_Tool_Registry(),
            new WAA_Audit_Log()
        );

        $normalized = $agent->normalize_history($history);

        $this->assertCount(23, $normalized);
        $this->assertSame('user', $normalized[0]['role']);
        $this->assertSame('Prompt 16', $normalized[0]['content']);
        $this->assertNotContains('tc_old', array_map(
            static fn(array $message): string => (string) ($message['tool_call_id'] ?? ''),
            $normalized
        ));
        $this->assertSame('Reply 26', $normalized[count($normalized) - 2]['content']);
        $this->assertSame('Latest question', $normalized[count($normalized) - 1]['content']);
    }

    public function test_agent_requires_confirmation_before_running_destructive_tool(): void {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $tool = new TestConfirmationTool();
        $registry = new WAA_Tool_Registry();
        $registry->register($tool);
        $agent = new WAA_Agent(
            new TestConfirmationProvider([
                'stop_reason' => 'tool_use',
                'text' => 'I am ready to deactivate the plugin.',
                'tool_calls' => [
                    ['id' => 'tc_confirm', 'name' => 'deactivate_plugin', 'input' => ['plugin_file' => 'hello-dolly/hello.php']],
                ],
                'usage' => ['input_tokens' => 3, 'output_tokens' => 2],
            ]),
            $registry,
            new TestAuditLog()
        );

        $events = iterator_to_array($agent->run('Deactivate Hello Dolly'));
        $confirmation = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === 'confirmation_required'));

        $this->assertCount(1, $confirmation);
        $this->assertSame('deactivate_plugin', $confirmation[0]['tool_name']);
        $this->assertSame(0, $tool->executions);
        $this->assertEmpty(array_filter($events, static fn(array $event): bool => $event['type'] === 'tool_end'));
    }

    public function test_registry_exposes_core_woocommerce_tools(): void {
        $registry = WAA_REST_API::build_registry();
        $tool_names = array_column($registry->get_schemas(), 'name');

        $this->assertContains('get_woocommerce_status', $tool_names);
        $this->assertContains('update_woocommerce_settings', $tool_names);
        $this->assertContains('list_woocommerce_products', $tool_names);
        $this->assertContains('create_woocommerce_product', $tool_names);
        $this->assertContains('update_woocommerce_product', $tool_names);
        $this->assertContains('list_woocommerce_orders', $tool_names);
        $this->assertContains('update_woocommerce_order_status', $tool_names);
        $this->assertContains('create_woocommerce_coupon', $tool_names);
    }

    public function test_agent_requires_confirmation_for_woocommerce_write_tools(): void {
        $agent = new WAA_Agent(
            new WAA_Provider_Fake('runtime-v1'),
            new WAA_Tool_Registry(),
            new WAA_Audit_Log()
        );

        $settings_confirmation = $agent->classify_action('update_woocommerce_settings', [
            'updates' => ['woocommerce_currency' => 'USD'],
        ]);
        $product_confirmation = $agent->classify_action('create_woocommerce_product', [
            'name' => 'Beanie',
            'status' => 'publish',
        ]);
        $order_confirmation = $agent->classify_action('update_woocommerce_order_status', [
            'order_id' => 123,
            'status' => 'completed',
        ]);

        $this->assertTrue($settings_confirmation['requires_confirmation']);
        $this->assertSame('commerce_setup', $settings_confirmation['action_type']);
        $this->assertTrue($product_confirmation['requires_confirmation']);
        $this->assertSame('commerce_catalog_write', $product_confirmation['action_type']);
        $this->assertTrue($order_confirmation['requires_confirmation']);
        $this->assertSame('commerce_order_write', $order_confirmation['action_type']);
    }

    public function test_get_woocommerce_status_returns_installation_state_without_fatal_errors(): void {
        $tool = new WAA_Tool_Get_WooCommerce_Status();
        $result = $tool->execute([]);

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('installed', $result);
        $this->assertArrayHasKey('active', $result);
    }

    public function test_agent_runs_destructive_tool_after_confirmation_payload_is_approved(): void {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $tool = new TestConfirmationTool();
        $registry = new WAA_Tool_Registry();
        $registry->register($tool);
        $agent = new WAA_Agent(
            new TestConfirmationProvider([
                'stop_reason' => 'end_turn',
                'text' => '',
                'tool_calls' => [],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            ]),
            $registry,
            new TestAuditLog()
        );

        $events = iterator_to_array($agent->run('Yes, proceed.', [], [
            'approved' => true,
            'tool_name' => 'deactivate_plugin',
            'tool_use_id' => 'tc_confirm',
            'tool_input' => ['plugin_file' => 'hello-dolly/hello.php'],
        ]));
        $tool_end = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === 'tool_end'));
        $text = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === 'text_delta'));

        $this->assertSame(1, $tool->executions);
        $this->assertCount(1, $tool_end);
        $this->assertSame('hello-dolly/hello.php', $tool_end[0]['result']['plugin']);
        $this->assertCount(1, $text);
        $this->assertStringContainsString('has been deactivated', $text[0]['content']);
    }

    public function test_agent_reports_confirmed_action_failure_without_false_success_copy(): void {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $tool = new TestFailingConfirmationTool();
        $registry = new WAA_Tool_Registry();
        $registry->register($tool);
        $agent = new WAA_Agent(
            new TestConfirmationProvider([
                'stop_reason' => 'end_turn',
                'text' => '',
                'tool_calls' => [],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            ]),
            $registry,
            new TestAuditLog()
        );

        $events = iterator_to_array($agent->run('Yes, proceed.', [], [
            'approved' => true,
            'tool_name' => 'deactivate_plugin',
            'tool_use_id' => 'tc_failed_confirm',
            'tool_input' => ['plugin_file' => 'hello-dolly/hello.php'],
        ]));
        $tool_end = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === 'tool_end'));
        $text = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === 'text_delta'));

        $this->assertSame(1, $tool->executions);
        $this->assertCount(1, $tool_end);
        $this->assertFalse($tool_end[0]['result']['success']);
        $this->assertCount(1, $text);
        $this->assertSame('I tried to run the confirmed action, but it failed.', $text[0]['content']);
        $this->assertStringNotContainsString('deactivated', $text[0]['content']);
    }

    public function test_agent_requires_confirmation_for_sensitive_site_level_tools(): void {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $cases = [
            ['tool' => 'install_plugin', 'input' => ['slug' => 'woocommerce']],
            ['tool' => 'install_theme', 'input' => ['slug' => 'astra']],
            ['tool' => 'activate_plugin', 'input' => ['plugin_file' => 'akismet/akismet.php']],
            ['tool' => 'update_site_settings', 'input' => ['updates' => ['blogname' => 'New title']]],
            ['tool' => 'set_site_icon', 'input' => ['image_url' => 'https://example.com/icon.png']],
            ['tool' => 'security_harden', 'input' => ['disable_registration' => true, 'disable_xmlrpc' => true]],
            ['tool' => 'wordfence_update_settings', 'input' => ['settings' => ['firewall_enabled' => true]]],
            ['tool' => 'wordfence_disconnect_central', 'input' => []],
        ];

        foreach ($cases as $index => $case) {
            $agent = new WAA_Agent(
                new TestConfirmationProvider([
                    'stop_reason' => 'tool_use',
                    'text' => 'I am ready to apply this site-level change.',
                    'tool_calls' => [[
                        'id' => 'tc_sensitive_' . $index,
                        'name' => $case['tool'],
                        'input' => $case['input'],
                    ]],
                    'usage' => ['input_tokens' => 2, 'output_tokens' => 1],
                ]),
                new WAA_Tool_Registry(),
                new TestAuditLog()
            );

            $events = iterator_to_array($agent->run('Apply this change'));
            $confirmation = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === 'confirmation_required'));

            $this->assertCount(1, $confirmation, 'Expected confirmation event for ' . $case['tool']);
            $this->assertSame($case['tool'], $confirmation[0]['tool_name']);
            $this->assertNotEmpty($confirmation[0]['message']);
            $this->assertEmpty(array_filter($events, static fn(array $event): bool => $event['type'] === 'tool_end'));
        }
    }

    public function test_agent_runs_sensitive_site_setting_change_after_confirmation_payload_is_approved(): void {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $tool = new TestUpdateSettingsTool();
        $registry = new WAA_Tool_Registry();
        $registry->register($tool);
        $agent = new WAA_Agent(
            new TestConfirmationProvider([
                'stop_reason' => 'end_turn',
                'text' => '',
                'tool_calls' => [],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            ]),
            $registry,
            new TestAuditLog()
        );

        $events = iterator_to_array($agent->run('Yes, proceed.', [], [
            'approved' => true,
            'tool_name' => 'update_site_settings',
            'tool_use_id' => 'tc_settings',
            'tool_input' => ['updates' => ['blogname' => 'New title', 'timezone_string' => 'Asia/Ho_Chi_Minh']],
        ]));
        $tool_end = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === 'tool_end'));
        $text = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === 'text_delta'));

        $this->assertSame(1, $tool->executions);
        $this->assertCount(1, $tool_end);
        $this->assertArrayHasKey('blogname', $tool_end[0]['result']['results']);
        $this->assertArrayHasKey('timezone_string', $tool_end[0]['result']['results']);
        $this->assertCount(1, $text);
        $this->assertStringContainsString('blogname', $text[0]['content']);
        $this->assertStringContainsString('timezone_string', $text[0]['content']);
    }

    public function test_agent_runs_plugin_install_after_confirmation_payload_is_approved(): void {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $tool = new TestInstallPluginTool();
        $registry = new WAA_Tool_Registry();
        $registry->register($tool);
        $agent = new WAA_Agent(
            new TestConfirmationProvider([
                'stop_reason' => 'end_turn',
                'text' => '',
                'tool_calls' => [],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            ]),
            $registry,
            new TestAuditLog()
        );

        $events = iterator_to_array($agent->run('Yes, proceed.', [], [
            'approved' => true,
            'tool_name' => 'install_plugin',
            'tool_use_id' => 'tc_install_plugin',
            'tool_input' => ['slug' => 'woocommerce'],
        ]));
        $tool_end = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === 'tool_end'));
        $text = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === 'text_delta'));

        $this->assertSame(1, $tool->executions);
        $this->assertCount(1, $tool_end);
        $this->assertSame('woocommerce/woocommerce.php', $tool_end[0]['result']['plugin_file']);
        $this->assertCount(1, $text);
        $this->assertStringContainsString('ready to activate', $text[0]['content']);
    }

    public function test_agent_runs_theme_install_after_confirmation_payload_is_approved(): void {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $tool = new TestInstallThemeTool();
        $registry = new WAA_Tool_Registry();
        $registry->register($tool);
        $agent = new WAA_Agent(
            new TestConfirmationProvider([
                'stop_reason' => 'end_turn',
                'text' => '',
                'tool_calls' => [],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            ]),
            $registry,
            new TestAuditLog()
        );

        $events = iterator_to_array($agent->run('Yes, proceed.', [], [
            'approved' => true,
            'tool_name' => 'install_theme',
            'tool_use_id' => 'tc_install_theme',
            'tool_input' => ['slug' => 'astra'],
        ]));
        $tool_end = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === 'tool_end'));
        $text = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === 'text_delta'));

        $this->assertSame(1, $tool->executions);
        $this->assertCount(1, $tool_end);
        $this->assertSame('astra', $tool_end[0]['result']['theme_slug']);
        $this->assertCount(1, $text);
        $this->assertStringContainsString('ready to activate', $text[0]['content']);
    }

    public function test_action_classification_returns_standardized_metadata_for_plugin_install(): void {
        $agent = new WAA_Agent(
            new TestConfirmationProvider([
                'stop_reason' => 'end_turn',
                'text' => '',
                'tool_calls' => [],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            ]),
            new WAA_Tool_Registry(),
            new TestAuditLog()
        );

        $classification = $agent->classify_action('install_plugin', ['slug' => 'woocommerce']);

        $this->assertTrue($classification['requires_confirmation']);
        $this->assertSame('extension_install', $classification['action_type']);
        $this->assertSame('sensitive', $classification['risk_level']);
        $this->assertSame('Approve change', $classification['title']);
        $this->assertStringContainsString('install plugin `woocommerce`', $classification['summary']);
        $this->assertSame('Confirm change', $classification['confirm_label']);
    }

    public function test_action_classification_returns_standardized_metadata_for_live_content_edit(): void {
        $published_post_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Published post',
        ]);

        $agent = new WAA_Agent(
            new TestConfirmationProvider([
                'stop_reason' => 'end_turn',
                'text' => '',
                'tool_calls' => [],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            ]),
            new WAA_Tool_Registry(),
            new TestAuditLog()
        );

        $classification = $agent->classify_action('update_post', [
            'post_id' => $published_post_id,
            'content' => '<p>Updated live content.</p>',
        ]);

        $this->assertTrue($classification['requires_confirmation']);
        $this->assertSame('content_write', $classification['action_type']);
        $this->assertSame('sensitive', $classification['risk_level']);
        $this->assertStringContainsString('update live post', $classification['summary']);
        $this->assertStringContainsString('live post', (string) ($classification['impact'] ?? ''));
    }

    public function test_action_classification_marks_background_scan_as_async_without_confirmation(): void {
        $agent = new WAA_Agent(
            new TestConfirmationProvider([
                'stop_reason' => 'end_turn',
                'text' => '',
                'tool_calls' => [],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            ]),
            new WAA_Tool_Registry(),
            new TestAuditLog()
        );

        $classification = $agent->classify_action('wordfence_run_scan', []);

        $this->assertFalse($classification['requires_confirmation']);
        $this->assertTrue($classification['is_async']);
        $this->assertSame('background_job', $classification['action_type']);
        $this->assertSame('safe', $classification['risk_level']);
        $this->assertStringContainsString('background security scan', (string) ($classification['impact'] ?? ''));
    }

    public function test_content_creation_requires_confirmation_only_for_publish_and_private_statuses(): void {
        $agent = new WAA_Agent(
            new TestConfirmationProvider([
                'stop_reason' => 'end_turn',
                'text' => '',
                'tool_calls' => [],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            ]),
            new WAA_Tool_Registry(),
            new TestAuditLog()
        );

        $this->assertFalse($agent->requires_confirmation('create_post', ['status' => 'draft']));
        $this->assertTrue($agent->requires_confirmation('create_post', ['status' => 'publish']));
        $this->assertTrue($agent->requires_confirmation('create_post', ['status' => 'private']));

        $this->assertFalse($agent->requires_confirmation('create_simple_post', ['status' => 'draft']));
        $this->assertTrue($agent->requires_confirmation('create_simple_post', ['status' => 'publish']));
        $this->assertTrue($agent->requires_confirmation('create_simple_post', ['status' => 'private']));

        $this->assertFalse($agent->requires_confirmation('create_rich_post', ['status' => 'draft']));
        $this->assertTrue($agent->requires_confirmation('create_rich_post', ['status' => 'publish']));
        $this->assertTrue($agent->requires_confirmation('create_rich_post', ['status' => 'private']));
    }

    public function test_update_post_requires_confirmation_for_live_status_changes_and_live_content_edits(): void {
        $published_post_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Published post',
        ]);
        $private_post_id = self::factory()->post->create([
            'post_status' => 'private',
            'post_title' => 'Private post',
        ]);
        $draft_post_id = self::factory()->post->create([
            'post_status' => 'draft',
            'post_title' => 'Draft post',
        ]);

        $agent = new WAA_Agent(
            new TestConfirmationProvider([
                'stop_reason' => 'end_turn',
                'text' => '',
                'tool_calls' => [],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            ]),
            new WAA_Tool_Registry(),
            new TestAuditLog()
        );

        $this->assertFalse($agent->requires_confirmation('update_post', ['post_id' => $draft_post_id]));
        $this->assertFalse($agent->requires_confirmation('update_post', ['post_id' => $draft_post_id, 'status' => 'draft']));
        $this->assertFalse($agent->requires_confirmation('update_post', ['post_id' => $draft_post_id, 'content' => '<p>Draft update</p>']));

        $this->assertTrue($agent->requires_confirmation('update_post', ['post_id' => $draft_post_id, 'status' => 'publish']));
        $this->assertTrue($agent->requires_confirmation('update_post', ['post_id' => $draft_post_id, 'status' => 'private']));
        $this->assertTrue($agent->requires_confirmation('update_post', ['post_id' => $draft_post_id, 'status' => 'trash']));

        $this->assertTrue($agent->requires_confirmation('update_post', ['post_id' => $published_post_id, 'content' => '<p>Live update</p>']));
        $this->assertTrue($agent->requires_confirmation('update_post', ['post_id' => $private_post_id, 'title' => 'New private title']));
        $this->assertTrue($agent->requires_confirmation('update_post', ['post_id' => $published_post_id, 'status' => 'draft']));
    }

    public function test_set_post_image_requires_confirmation_only_for_live_posts(): void {
        $published_post_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Published post',
        ]);
        $private_post_id = self::factory()->post->create([
            'post_status' => 'private',
            'post_title' => 'Private post',
        ]);
        $draft_post_id = self::factory()->post->create([
            'post_status' => 'draft',
            'post_title' => 'Draft post',
        ]);

        $agent = new WAA_Agent(
            new TestConfirmationProvider([
                'stop_reason' => 'end_turn',
                'text' => '',
                'tool_calls' => [],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            ]),
            new WAA_Tool_Registry(),
            new TestAuditLog()
        );

        $this->assertTrue($agent->requires_confirmation('set_post_image', [
            'post_id' => $published_post_id,
            'attachment_id' => 55,
            'mode' => 'featured',
        ]));
        $this->assertTrue($agent->requires_confirmation('set_post_image', [
            'post_id' => $private_post_id,
            'attachment_id' => 55,
            'mode' => 'append',
        ]));
        $this->assertFalse($agent->requires_confirmation('set_post_image', [
            'post_id' => $draft_post_id,
            'attachment_id' => 55,
            'mode' => 'prepend',
        ]));
    }

    public function test_update_post_requires_confirmation_for_live_pages_but_not_draft_pages(): void {
        $live_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Live page',
        ]);
        $draft_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_title' => 'Draft page',
        ]);

        $agent = new WAA_Agent(
            new TestConfirmationProvider([
                'stop_reason' => 'end_turn',
                'text' => '',
                'tool_calls' => [],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            ]),
            new WAA_Tool_Registry(),
            new TestAuditLog()
        );

        $this->assertTrue($agent->requires_confirmation('update_post', [
            'post_id' => $live_page_id,
            'content' => '<p>Updated live page content.</p>',
        ]));
        $this->assertFalse($agent->requires_confirmation('update_post', [
            'post_id' => $draft_page_id,
            'content' => '<p>Updated draft page content.</p>',
        ]));
    }

    public function test_set_post_image_requires_confirmation_for_live_pages_but_not_draft_pages(): void {
        $live_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'private',
            'post_title' => 'Private page',
        ]);
        $draft_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_title' => 'Draft page',
        ]);

        $agent = new WAA_Agent(
            new TestConfirmationProvider([
                'stop_reason' => 'end_turn',
                'text' => '',
                'tool_calls' => [],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            ]),
            new WAA_Tool_Registry(),
            new TestAuditLog()
        );

        $this->assertTrue($agent->requires_confirmation('set_post_image', [
            'post_id' => $live_page_id,
            'attachment_id' => 55,
            'mode' => 'featured',
        ]));
        $this->assertFalse($agent->requires_confirmation('set_post_image', [
            'post_id' => $draft_page_id,
            'attachment_id' => 55,
            'mode' => 'append',
        ]));
    }

    public function test_agent_emits_confirmation_for_live_post_content_edit_without_status_change(): void {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);
        $published_post_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Published post',
        ]);

        $agent = new WAA_Agent(
            new TestConfirmationProvider([
                'stop_reason' => 'tool_use',
                'text' => 'I am ready to update the live post.',
                'tool_calls' => [[
                    'id' => 'tc_live_edit',
                    'name' => 'update_post',
                    'input' => [
                        'post_id' => $published_post_id,
                        'content' => '<p>Updated live content.</p>',
                    ],
                ]],
                'usage' => ['input_tokens' => 2, 'output_tokens' => 1],
            ]),
            new WAA_Tool_Registry(),
            new TestAuditLog()
        );

        $events = iterator_to_array($agent->run('Update the live post intro'));
        $confirmation = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === 'confirmation_required'));

        $this->assertCount(1, $confirmation);
        $this->assertSame('update_post', $confirmation[0]['tool_name']);
        $this->assertStringContainsString('update live post', $confirmation[0]['message']);
    }

    public function test_agent_emits_confirmation_for_setting_an_image_on_a_live_post(): void {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);
        $published_post_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Published post',
        ]);

        $agent = new WAA_Agent(
            new TestConfirmationProvider([
                'stop_reason' => 'tool_use',
                'text' => 'I am ready to update the featured image.',
                'tool_calls' => [[
                    'id' => 'tc_set_image',
                    'name' => 'set_post_image',
                    'input' => [
                        'post_id' => $published_post_id,
                        'attachment_id' => 55,
                        'mode' => 'featured',
                    ],
                ]],
                'usage' => ['input_tokens' => 2, 'output_tokens' => 1],
            ]),
            new WAA_Tool_Registry(),
            new TestAuditLog()
        );

        $events = iterator_to_array($agent->run('Set a new featured image on the live post'));
        $confirmation = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === 'confirmation_required'));

        $this->assertCount(1, $confirmation);
        $this->assertSame('set_post_image', $confirmation[0]['tool_name']);
        $this->assertStringContainsString('featured image', $confirmation[0]['message']);
        $this->assertStringContainsString('live post', $confirmation[0]['message']);
    }

    public function test_agent_emits_confirmation_for_publishing_a_post_but_not_for_saving_a_draft(): void {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $publish_events = iterator_to_array((new WAA_Agent(
            new TestConfirmationProvider([
                'stop_reason' => 'tool_use',
                'text' => 'Ready to publish the post.',
                'tool_calls' => [[
                    'id' => 'tc_publish',
                    'name' => 'create_post',
                    'input' => ['title' => 'Launch note', 'status' => 'publish'],
                ]],
                'usage' => ['input_tokens' => 3, 'output_tokens' => 2],
            ]),
            new WAA_Tool_Registry(),
            new TestAuditLog()
        ))->run('Publish this post'));

        $draft_tool = new TestCreatePostTool();
        $draft_registry = new WAA_Tool_Registry();
        $draft_registry->register($draft_tool);
        $draft_events = iterator_to_array((new WAA_Agent(
            new TestSequenceProvider([
                [
                    'stop_reason' => 'tool_use',
                    'text' => 'Saving a draft now.',
                    'tool_calls' => [[
                        'id' => 'tc_draft',
                        'name' => 'create_post',
                        'input' => ['title' => 'Draft note', 'status' => 'draft'],
                    ]],
                    'usage' => ['input_tokens' => 3, 'output_tokens' => 2],
                ],
                [
                    'stop_reason' => 'end_turn',
                    'text' => 'Draft saved.',
                    'tool_calls' => [],
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                ],
            ]),
            $draft_registry,
            new TestAuditLog()
        ))->run('Save this as draft'));

        $publish_confirmation = array_values(array_filter($publish_events, static fn(array $event): bool => $event['type'] === 'confirmation_required'));
        $draft_tool_end = array_values(array_filter($draft_events, static fn(array $event): bool => $event['type'] === 'tool_end'));

        $this->assertCount(1, $publish_confirmation);
        $this->assertSame('create_post', $publish_confirmation[0]['tool_name']);
        $this->assertStringContainsString('publish post', $publish_confirmation[0]['message']);
        $this->assertSame(1, $draft_tool->executions);
        $this->assertCount(1, $draft_tool_end);
        $this->assertSame('draft', $draft_tool_end[0]['result']['status']);
    }
}

class TestConfirmationProvider extends WAA_Provider_Base {
    public function __construct(
        private readonly array $response
    ) {}

    public function complete(string $system, array $messages, array $tools): array {
        return $this->response;
    }

    public function get_id(): string {
        return 'fake';
    }

    public function get_label(): string {
        return 'Test Confirmation Provider';
    }
}

class TestSequenceProvider extends WAA_Provider_Base {
    private int $cursor = 0;

    public function __construct(
        private readonly array $responses
    ) {}

    public function complete(string $system, array $messages, array $tools): array {
        $response = $this->responses[$this->cursor] ?? end($this->responses);
        $this->cursor++;

        return $response;
    }

    public function get_id(): string {
        return 'fake';
    }

    public function get_label(): string {
        return 'Test Sequence Provider';
    }
}

class TestConfirmationTool extends WAA_Tool_Base {
    public int $executions = 0;

    public function get_name(): string {
        return 'deactivate_plugin';
    }

    public function get_description(): string {
        return 'Test confirmation tool.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'plugin_file' => ['type' => 'string'],
            ],
            'required' => ['plugin_file'],
        ];
    }

    public function execute(array $input): array {
        $this->executions++;

        return [
            'plugin' => (string) ($input['plugin_file'] ?? ''),
            'success' => true,
        ];
    }
}

class TestFailingConfirmationTool extends WAA_Tool_Base {
    public int $executions = 0;

    public function get_name(): string {
        return 'deactivate_plugin';
    }

    public function get_description(): string {
        return 'Test failing confirmation tool.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'plugin_file' => ['type' => 'string'],
            ],
            'required' => ['plugin_file'],
        ];
    }

    public function execute(array $input): array {
        $this->executions++;

        return [
            'plugin' => (string) ($input['plugin_file'] ?? ''),
            'success' => false,
            'error' => 'Plugin could not be deactivated.',
        ];
    }
}

class TestUpdateSettingsTool extends WAA_Tool_Base {
    public int $executions = 0;

    public function get_name(): string {
        return 'update_site_settings';
    }

    public function get_description(): string {
        return 'Test site settings tool.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'updates' => ['type' => 'object'],
            ],
            'required' => ['updates'],
        ];
    }

    public function execute(array $input): array {
        $this->executions++;

        $results = [];
        foreach ((array) ($input['updates'] ?? []) as $key => $value) {
            $results[(string) $key] = [
                'status' => 'updated',
                'new_value' => $value,
            ];
        }

        return ['results' => $results];
    }
}

class TestInstallPluginTool extends WAA_Tool_Base {
    public int $executions = 0;

    public function get_name(): string {
        return 'install_plugin';
    }

    public function get_description(): string {
        return 'Test plugin install tool.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string'],
            ],
            'required' => ['slug'],
        ];
    }

    public function execute(array $input): array {
        $this->executions++;

        return [
            'success' => true,
            'plugin_file' => (string) ($input['slug'] ?? 'unknown') . '/' . (string) ($input['slug'] ?? 'unknown') . '.php',
            'name' => ucfirst((string) ($input['slug'] ?? 'plugin')),
        ];
    }
}

class TestInstallThemeTool extends WAA_Tool_Base {
    public int $executions = 0;

    public function get_name(): string {
        return 'install_theme';
    }

    public function get_description(): string {
        return 'Test theme install tool.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string'],
            ],
            'required' => ['slug'],
        ];
    }

    public function execute(array $input): array {
        $this->executions++;

        return [
            'success' => true,
            'theme_slug' => (string) ($input['slug'] ?? 'unknown'),
            'name' => ucfirst((string) ($input['slug'] ?? 'theme')),
        ];
    }
}

class TestCreatePostTool extends WAA_Tool_Base {
    public int $executions = 0;

    public function get_name(): string {
        return 'create_post';
    }

    public function get_description(): string {
        return 'Test create post tool.';
    }

    public function get_input_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'status' => ['type' => 'string'],
            ],
            'required' => ['title'],
        ];
    }

    public function execute(array $input): array {
        $this->executions++;

        return [
            'post_id' => 321,
            'status' => (string) ($input['status'] ?? 'draft'),
            'success' => true,
        ];
    }
}

class TestAuditLog extends WAA_Audit_Log {
    public array $entries = [];

    public function write(string $tool, array $params, array $result, array $meta = []): void {
        $this->entries[] = compact('tool', 'params', 'result', 'meta');
    }
}
