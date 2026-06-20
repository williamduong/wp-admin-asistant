<?php

class CreateRichPostToolTest extends WP_UnitTestCase {
    public function tearDown(): void {
        delete_option('waa_pexels_key_enc');
        parent::tearDown();
    }

    public function test_create_rich_post_can_defer_featured_image_for_long_form_drafts(): void {
        $tool = new WAA_Tool_Create_Rich_Post();

        $result = $tool->execute([
            'title' => 'Deferred image draft',
            'content' => '<h2>Overview</h2><p>Long-form draft body.</p>',
            'status' => 'draft',
            'skip_featured_image' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('draft', $result['status']);
        $this->assertTrue($result['image_deferred']);
        $this->assertNull($result['thumbnail_id']);
        $this->assertArrayNotHasKey('image_warning', $result);
        $this->assertStringContainsString('Attach one later', $result['message']);
        $this->assertNotEmpty(get_post($result['post_id']));
    }

    public function test_create_rich_post_reports_image_warning_when_auto_fetch_is_enabled_but_unavailable(): void {
        $tool = new WAA_Tool_Create_Rich_Post();

        $result = $tool->execute([
            'title' => 'Image warning draft',
            'content' => '<h2>Overview</h2><p>Long-form draft body.</p>',
            'status' => 'draft',
        ]);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['image_deferred']);
        $this->assertNull($result['thumbnail_id']);
        $this->assertSame(
            'Featured image could not be fetched (Pexels key missing or no results). Add it manually.',
            $result['image_warning']
        );
    }
}
