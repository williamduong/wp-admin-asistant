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

        $diagram = self::normalize_diagram_source($content);
        if ($diagram === '') {
            return '';
        }

        // Theme: auto | default | neutral | dark | forest | base
        $theme = sanitize_key($atts['theme'] ?? 'neutral');

        return sprintf(
            '<div class="waa-mermaid" data-theme="%s"><pre class="mermaid">%s</pre><div class="waa-mermaid-error" hidden><strong>Mermaid syntax error.</strong> Showing source instead.<pre class="waa-mermaid-source">%s</pre></div></div>',
            esc_attr($theme),
            esc_html($diagram),
            esc_html($diagram)
        );
    }

    private static function normalize_diagram_source(string $content): string {
        // WordPress encodes HTML entities inside shortcode content — decode before output.
        $diagram = html_entity_decode(trim($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($diagram === '') {
            return '';
        }

        $diagram = preg_replace('/<br\s*\/?>/i', "\n", $diagram);
        $diagram = wp_strip_all_tags($diagram);

        // Models sometimes wrap shortcode content in Markdown fences, which Mermaid cannot parse.
        $diagram = preg_replace('/^```mermaid\s*/i', '', $diagram);
        $diagram = preg_replace('/^```\s*/', '', $diagram);
        $diagram = preg_replace('/\s*```$/', '', $diagram);

        $diagram = str_replace(
            ["\xE2\x80\x93", "\xE2\x80\x94", "\xE2\x88\x92", "\r\n", "\r"],
            ['-', '-', '-', "\n", "\n"],
            trim($diagram)
        );
        $diagram = preg_replace('/\b([A-Za-z][A-Za-z0-9_]*)\(([^()\n]+)\)/', '$1[$2]', $diagram);
        $diagram = preg_replace("/[ \t]*\n[ \t]*/", "\n", $diagram);

        return $diagram;
    }

    private static function enqueue(): void {
        if (self::$enqueued) return;
        self::$enqueued = true;

        // Mermaid v11 ESM build via CDN — loaded as a module
        add_action('wp_footer', function () {
            echo <<<'HTML'
<script type="module">
import mermaid from 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs';

function normalizeMermaidSource(source) {
    return String(source || '')
        .replace(/<br\s*\/?>/gi, '\n')
        .replace(/<[^>]+>/g, '')
        .replace(/^```mermaid\s*/i, '')
        .replace(/^```\s*/i, '')
        .replace(/\s*```$/i, '')
        .replace(/[\u2013\u2014\u2212]/g, '-')
        .replace(/\r\n?/g, '\n')
        .replace(/\b([A-Za-z][A-Za-z0-9_]*)\(([^()\n]+)\)/g, '$1[$2]')
        .replace(/[ \t]*\n[ \t]*/g, '\n')
        .trim();
}

async function renderMermaid(wrap) {
    const pre = wrap.querySelector('pre.mermaid');
    const errorBox = wrap.querySelector('.waa-mermaid-error');
    const sourcePre = wrap.querySelector('.waa-mermaid-source');
    if (!pre) return;

    const source = normalizeMermaidSource(pre.textContent);
    if (!source) {
        wrap.hidden = true;
        return;
    }

    pre.textContent = source;
    if (sourcePre) {
        sourcePre.textContent = source;
    }

    const theme = wrap.dataset.theme || 'neutral';

    try {
        mermaid.initialize({ startOnLoad: false, theme, securityLevel: 'loose' });
        await mermaid.parse(source);
        await mermaid.run({ nodes: [pre] });
    } catch (error) {
        wrap.dataset.renderStatus = 'invalid';
        pre.hidden = true;
        if (errorBox) {
            errorBox.hidden = false;
        }
        console.warn('WAA Mermaid render failed:', error);
    }
}

document.querySelectorAll('.waa-mermaid').forEach(wrap => {
    void renderMermaid(wrap);
});
</script>
HTML;
        }, 99);
    }
}
