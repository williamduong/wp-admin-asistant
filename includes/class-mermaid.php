<?php

defined('ABSPATH') || exit;

/**
 * Renders [mermaid]...diagram...[/mermaid] shortcodes in post content.
 * Enqueues Mermaid.js only when the shortcode is actually used on the page.
 */
class WAA_Mermaid {
    private static bool $enqueued = false;

    public static function init(): void {
        add_shortcode('mermaid', [self::class, 'render']);
    }

    public static function render(?array $atts, ?string $content): string {
        if (empty($content)) return '';

        self::enqueue();

        // WordPress encodes HTML entities inside shortcode content — decode before output
        $diagram = html_entity_decode(trim($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Theme: auto | default | neutral | dark | forest | base
        $theme = sanitize_key($atts['theme'] ?? 'neutral');

        return sprintf(
            '<div class="waa-mermaid" data-theme="%s"><pre class="mermaid">%s</pre></div>',
            esc_attr($theme),
            esc_html($diagram)
        );
    }

    private static function enqueue(): void {
        if (self::$enqueued) return;
        self::$enqueued = true;

        // Mermaid v11 ESM build via CDN — loaded as a module
        add_action('wp_footer', function () {
            echo <<<'HTML'
<script type="module">
import mermaid from 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs';
mermaid.initialize({ startOnLoad: false, theme: 'neutral', securityLevel: 'loose' });
document.querySelectorAll('.waa-mermaid').forEach(wrap => {
    const pre = wrap.querySelector('pre.mermaid');
    if (!pre) return;
    const theme = wrap.dataset.theme || 'neutral';
    mermaid.initialize({ startOnLoad: false, theme, securityLevel: 'loose' });
    mermaid.run({ nodes: [pre] });
});
</script>
HTML;
        }, 99);
    }
}
