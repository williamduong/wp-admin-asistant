<?php

class ResolveImageToolTest extends WP_UnitTestCase {
    public function test_resolve_image_returns_stock_result_without_generation_when_confidence_is_good(): void {
        $tool = new ResolveImageToolHarness(
            new ResolveImageFakeSearchTool([
                'success' => true,
                'low_confidence' => false,
                'selected_query' => 'durian fruit',
                'confidence_score' => 0.81,
                'imported' => [[
                    'attachment_id' => 101,
                    'media_url' => 'https://example.com/durian-stock.png',
                ]],
            ]),
            new ResolveImageFakeGenerateTool([
                'success' => true,
                'attachment_id' => 202,
                'media_url' => 'https://example.com/durian-generated.png',
            ])
        );

        $result = $tool->execute([
            'query' => 'sầu riêng',
            'usage_context' => 'product',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('stock', $result['source_type']);
        $this->assertSame(101, $result['attachment_id']);
    }

    public function test_resolve_image_falls_back_to_generation_when_stock_is_low_confidence(): void {
        $tool = new ResolveImageToolHarness(
            new ResolveImageFakeSearchTool([
                'success' => false,
                'low_confidence' => true,
                'selected_query' => 'durian fruit',
                'confidence_score' => 0.12,
                'error' => 'Image search confidence was too low.',
                'matches' => [],
            ]),
            new ResolveImageFakeGenerateTool([
                'success' => true,
                'attachment_id' => 303,
                'media_url' => 'https://example.com/durian-generated.png',
            ])
        );

        $result = $tool->execute([
            'query' => 'sầu riêng',
            'usage_context' => 'product',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('generated', $result['source_type']);
        $this->assertSame(303, $result['attachment_id']);
        $this->assertSame('https://example.com/durian-generated.png', $result['media_url']);
    }
}

class ResolveImageToolHarness extends WAA_Tool_Resolve_Image {
    public function __construct(
        private readonly WAA_Tool_Search_Images $searchTool,
        private readonly WAA_Tool_Generate_Image $generateTool
    ) {}

    protected function create_search_tool(): WAA_Tool_Search_Images {
        return $this->searchTool;
    }

    protected function create_generate_tool(): WAA_Tool_Generate_Image {
        return $this->generateTool;
    }
}

class ResolveImageFakeSearchTool extends WAA_Tool_Search_Images {
    public function __construct(private readonly array $result) {}

    public function execute(array $input): array {
        return $this->result;
    }
}

class ResolveImageFakeGenerateTool extends WAA_Tool_Generate_Image {
    public function __construct(private readonly array $result) {}

    public function execute(array $input): array {
        return $this->result;
    }
}
