<?php

class MermaidShortcodeTest extends WP_UnitTestCase {
    public function test_mermaid_shortcode_strips_markdown_fences_and_renders_fallback_shell(): void {
        $html = WAA_Mermaid::render([], "```mermaid\nflowchart TD\n  A-->B\n```");

        $this->assertStringContainsString('class="waa-mermaid"', $html);
        $this->assertStringContainsString('flowchart TD', $html);
        $this->assertStringContainsString('A--&gt;B', $html);
        $this->assertStringNotContainsString('```mermaid', $html);
        $this->assertStringContainsString('class="waa-mermaid-error"', $html);
        $this->assertStringContainsString('class="waa-mermaid-source"', $html);
    }

    public function test_mermaid_shortcode_normalizes_br_tags_and_unicode_dashes(): void {
        $html = WAA_Mermaid::render([], "flowchart TD<br />A[User] –>|Request| B(App)<br />B –>|Reply| A");

        $this->assertStringContainsString('flowchart TD', $html);
        $this->assertStringContainsString("A[User] -&gt;|Request| B[App]", $html);
        $this->assertStringContainsString("B -&gt;|Reply| A", $html);
        $this->assertStringNotContainsString('&lt;br /&gt;', $html);
        $this->assertStringNotContainsString('–&gt;', $html);
        $this->assertStringNotContainsString('B(App)', $html);
    }

    public function test_mermaid_shortcode_converts_round_nodes_to_square_nodes(): void {
        $html = WAA_Mermaid::render([], "flowchart TD\nA[Người dùng] -->|Yêu cầu bằng ngôn ngữ tự nhiên| B(Trợ lý AI WordPress)\nB -->|Phân tích & Lập kế hoạch| C{API WordPress}\nC -->|Thực thi lệnh| D[WordPress Core/Plugins/Themes]\nB -->|Cập nhật & Phản hồi| A");

        $this->assertStringContainsString('B[Trợ lý AI WordPress]', $html);
        $this->assertStringNotContainsString('B(Trợ lý AI WordPress)', $html);
    }

    public function test_mermaid_shortcode_returns_empty_string_for_empty_content(): void {
        $this->assertSame('', WAA_Mermaid::render([], ''));
    }
}
