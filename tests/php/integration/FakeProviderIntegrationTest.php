<?php

class FakeProviderIntegrationTest extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        do_action('rest_api_init');
    }

    public function tearDown(): void {
        delete_option('waa_provider');
        delete_option('waa_model');
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_provider_factory_builds_fake_provider_from_settings(): void {
        update_option('waa_provider', 'fake');
        update_option('waa_model', 'runtime-v1');

        $provider = WAA_Provider_Factory::make(new WAA_Settings());

        $this->assertInstanceOf(WAA_Provider_Fake::class, $provider);
        $this->assertSame('fake', $provider->get_id());
    }

    public function test_fake_provider_returns_deterministic_response_for_known_prompt(): void {
        $provider = new WAA_Provider_Fake('runtime-v1');

        $response = $provider->complete(
            'System prompt.',
            [['role' => 'user', 'content' => 'Reply with the single word OK.']],
            []
        );

        $this->assertSame('end_turn', $response['stop_reason']);
        $this->assertSame('OK', $response['text']);
        $this->assertSame([], $response['tool_calls']);
    }

    public function test_test_connection_route_accepts_fake_provider_without_credentials(): void {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $request = new WP_REST_Request('POST', '/wp-admin-agent/v1/test-connection');
        $request->set_body(wp_json_encode([
            'provider' => 'fake',
            'model' => 'runtime-v1',
        ]));
        $request->set_header('content-type', 'application/json');

        $response = rest_get_server()->dispatch($request);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['success']);
        $this->assertSame('OK', $data['reply']);
    }
}
