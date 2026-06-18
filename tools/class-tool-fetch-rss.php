<?php

defined('ABSPATH') || exit;

class WAA_Tool_Fetch_Rss extends WAA_Tool_Base {

    private const PRESETS = [
        // Tech / Dev
        'ars_technica'        => ['label' => 'Ars Technica',          'url' => 'https://feeds.arstechnica.com/arstechnica/index',                              'tags' => ['tech','dev','security']],
        'ars_technica_ai'     => ['label' => 'Ars Technica – Science','url' => 'https://feeds.arstechnica.com/arstechnica/science',                             'tags' => ['science','ai']],
        'techcrunch'          => ['label' => 'TechCrunch',            'url' => 'https://techcrunch.com/feed/',                                                  'tags' => ['tech','startup']],
        'techcrunch_ai'       => ['label' => 'TechCrunch – AI',       'url' => 'https://techcrunch.com/category/artificial-intelligence/feed/',                 'tags' => ['ai']],
        'the_verge'           => ['label' => 'The Verge',             'url' => 'https://www.theverge.com/rss/index.xml',                                        'tags' => ['tech']],
        'the_verge_ai'        => ['label' => 'The Verge – AI',        'url' => 'https://www.theverge.com/ai-artificial-intelligence/rss/index.xml',             'tags' => ['ai']],
        'wired'               => ['label' => 'Wired',                 'url' => 'https://www.wired.com/feed/rss',                                                'tags' => ['tech']],
        'wired_science'       => ['label' => 'Wired – Science',       'url' => 'https://www.wired.com/feed/category/science/latest/rss',                        'tags' => ['science']],
        'mit_review'          => ['label' => 'MIT Technology Review', 'url' => 'https://www.technologyreview.com/feed/',                                        'tags' => ['tech','ai']],
        'mit_review_ai'       => ['label' => 'MIT Tech Review – AI',  'url' => 'https://www.technologyreview.com/topic/artificial-intelligence/feed',           'tags' => ['ai']],
        // Science
        'sciencedaily'        => ['label' => 'ScienceDaily – Top',    'url' => 'https://www.sciencedaily.com/rss/top/science.xml',                              'tags' => ['science']],
        'sciencedaily_tech'   => ['label' => 'ScienceDaily – Tech',   'url' => 'https://www.sciencedaily.com/rss/top/technology.xml',                          'tags' => ['tech','science']],
        'physorg'             => ['label' => 'Phys.org',              'url' => 'https://phys.org/rss-feed/',                                                    'tags' => ['science','tech']],
        'physorg_ai'          => ['label' => 'Phys.org – AI',         'url' => 'https://phys.org/technology-news/machine-learning-ai/rss-feed/',                'tags' => ['ai','science']],
        'nature'              => ['label' => 'Nature',                'url' => 'https://www.nature.com/nature.rss',                                             'tags' => ['science']],
        'nature_ml'           => ['label' => 'Nature – ML/AI',        'url' => 'https://www.nature.com/subjects/machine-learning.rss',                         'tags' => ['ai','science']],
        'nasa'                => ['label' => 'NASA Breaking News',    'url' => 'https://www.nasa.gov/rss/dyn/breaking_news.rss',                                'tags' => ['science','space']],
        'esa'                 => ['label' => 'ESA Space Science',     'url' => 'https://www.esa.int/rssfeed/Our_Activities/Space_Science',                      'tags' => ['science','space']],
        // Google News keyword feeds
        'gnews_ai'            => ['label' => 'Google News – AI',      'url' => 'https://news.google.com/rss/search?q=artificial+intelligence&hl=en&gl=US&ceid=US:en', 'tags' => ['ai']],
        'gnews_openai'        => ['label' => 'Google News – OpenAI',  'url' => 'https://news.google.com/rss/search?q=openai&hl=en&gl=US&ceid=US:en',           'tags' => ['ai']],
        'gnews_nvidia'        => ['label' => 'Google News – NVIDIA',  'url' => 'https://news.google.com/rss/search?q=nvidia+ai&hl=en&gl=US&ceid=US:en',        'tags' => ['ai','tech']],
    ];

    public function get_name(): string { return 'fetch_rss'; }

    public function get_description(): string {
        return 'Fetch the latest articles from curated technology and science RSS feeds. Use this instead of web search to get real, up-to-date news. Supports presets by name or tag (ai, tech, science, space, dev, security, startup), or a custom URL. Returns article title, summary, link, and published date.';
    }

    public function get_input_schema(): array {
        $preset_keys = implode(', ', array_keys(self::PRESETS));
        return [
            'type'       => 'object',
            'properties' => [
                'presets' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => "Named preset feeds to fetch. Available: $preset_keys",
                ],
                'tags' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'Fetch all presets matching any of these tags: ai, tech, science, space, dev, security, startup.',
                ],
                'custom_urls' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'Any additional RSS/Atom feed URLs to fetch alongside presets.',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Max articles per feed (1–10). Default 3.',
                ],
            ],
        ];
    }

    public function execute(array $input): array {
        include_once ABSPATH . WPINC . '/feed.php';

        $limit       = max(1, min(10, (int) ($input['limit'] ?? 3)));
        $feeds_to_fetch = []; // [label => url]

        // Collect by preset name
        foreach ((array) ($input['presets'] ?? []) as $key) {
            $key = sanitize_key($key);
            if (isset(self::PRESETS[$key])) {
                $feeds_to_fetch[self::PRESETS[$key]['label']] = self::PRESETS[$key]['url'];
            }
        }

        // Collect by tag
        foreach ((array) ($input['tags'] ?? []) as $tag) {
            $tag = sanitize_key($tag);
            foreach (self::PRESETS as $preset) {
                if (in_array($tag, $preset['tags'], true)) {
                    $feeds_to_fetch[$preset['label']] = $preset['url'];
                }
            }
        }

        // Custom URLs
        foreach ((array) ($input['custom_urls'] ?? []) as $url) {
            $url = esc_url_raw($url);
            if ($url) $feeds_to_fetch[$url] = $url;
        }

        // Default: single best general tech feed if nothing specified
        if (empty($feeds_to_fetch)) {
            $feeds_to_fetch[self::PRESETS['ars_technica']['label']] = self::PRESETS['ars_technica']['url'];
        }

        $results = [];
        $errors  = [];

        foreach ($feeds_to_fetch as $label => $url) {
            // Use a short cache to avoid hammering external servers
            $feed = fetch_feed($url);

            if (is_wp_error($feed)) {
                $errors[] = "$label: " . $feed->get_error_message();
                continue;
            }

            $items   = $feed->get_items(0, $limit);
            $articles = [];

            foreach ($items as $item) {
                $desc = wp_strip_all_tags($item->get_description() ?? '');
                $articles[] = [
                    'title'     => wp_strip_all_tags($item->get_title() ?? ''),
                    'summary'   => wp_trim_words($desc, 40),
                    'link'      => esc_url_raw($item->get_permalink() ?? ''),
                    'published' => $item->get_date('Y-m-d H:i') ?: '',
                    'author'    => wp_strip_all_tags($item->get_author()?->get_name() ?? ''),
                ];
            }

            if (!empty($articles)) {
                $results[] = [
                    'source'   => $label,
                    'feed_url' => $url,
                    'articles' => $articles,
                ];
            }
        }

        return [
            'success'      => true,
            'fetched'      => count($results),
            'total_articles' => array_sum(array_map(fn($r) => count($r['articles']), $results)),
            'results'      => $results,
            'errors'       => $errors,
        ];
    }
}
