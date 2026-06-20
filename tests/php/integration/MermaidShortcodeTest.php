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

    public function test_mermaid_shortcode_returns_empty_string_for_empty_content(): void {
        $this->assertSame('', WAA_Mermaid::render([], ''));
    }
}
