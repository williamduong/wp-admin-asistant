<?php

class GenerateImageToolTest extends WP_UnitTestCase {
    public function tearDown(): void {
        delete_option('waa_gemini_key_enc');
        parent::tearDown();
    }

    public function test_generate_image_returns_error_when_gemini_key_is_missing(): void {
        $tool = new WAA_Tool_Generate_Image();

        $result = $tool->execute([
            'prompt' => 'A durian fruit product image on a white background',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Gemini API key not configured', $result['error']);
    }

    public function test_media_importer_can_import_generated_binary_payload(): void {
        $png_bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9WnRk3sAAAAASUVORK5CYII=',
            true
        );

        $importer = new WAA_Media_Importer(new WAA_Resource_Fetcher());
        $attachment_id = $importer->import_from_binary(
            $png_bytes,
            'generated-test.png',
            'image/png',
            'Generated test image'
        );

        $this->assertGreaterThan(0, $attachment_id);
        $this->assertSame('Generated test image', get_the_title($attachment_id));
        $this->assertTrue(wp_attachment_is_image($attachment_id));
    }
}
