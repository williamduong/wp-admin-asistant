<?php

defined('ABSPATH') || exit;

class WAA_Tool_Search_Images extends WAA_Tool_Base {
    private const MIN_CONFIDENCE_TO_IMPORT = 0.34;
    private const DEFAULT_EVALUATION_POOL = 5;
    private const QUERY_GLOSSARY = [
        'sau rieng' => ['durian', 'durian fruit'],
        'sầu riêng' => ['durian', 'durian fruit'],
        'bo' => ['avocado', 'avocado fruit'],
        'bơ' => ['avocado', 'avocado fruit'],
        'mang cut' => ['mangosteen', 'mangosteen fruit'],
        'măng cụt' => ['mangosteen', 'mangosteen fruit'],
        'thanh long' => ['dragon fruit', 'pitaya'],
        'thanh long ruot do' => ['red dragon fruit', 'pitaya'],
        'thanh long ruột đỏ' => ['red dragon fruit', 'pitaya'],
        'mit' => ['jackfruit', 'jackfruit fruit'],
        'mít' => ['jackfruit', 'jackfruit fruit'],
        'xoai' => ['mango', 'mango fruit'],
        'xoài' => ['mango', 'mango fruit'],
        'cafe' => ['coffee', 'coffee drink'],
        'cà phê' => ['coffee', 'coffee drink'],
    ];

    public function get_name(): string { return 'search_images'; }

    public function get_description(): string {
        return 'Search Pexels for royalty-free photos and import the best matches into the WordPress Media Library. Returns attachment IDs ready to use with set_post_image. The tool now tries multiple query candidates and rejects low-confidence mismatches instead of importing clearly unrelated images. Requires a Pexels API key in Settings.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'Primary search query. English works best, but the tool will derive extra English candidates automatically when possible.',
                ],
                'query_candidates' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'Optional extra search candidates ordered by preference. Useful when the caller already knows a stronger English variant such as "durian fruit".',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Number of images to import (1–5). Default: 1.',
                    'minimum'     => 1,
                    'maximum'     => 5,
                ],
                'orientation' => [
                    'type'        => 'string',
                    'enum'        => ['landscape', 'portrait', 'square'],
                    'description' => 'Image orientation. Default: landscape.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $input): array {
        $api_key = (new WAA_Settings())->get_pexels_api_key();
        if (!$api_key) {
            return ['success' => false, 'error' => 'Pexels API key not configured. Add it in Settings → Media & Images.'];
        }

        $query       = sanitize_text_field($input['query'] ?? '');
        $limit       = max(1, min(5, (int) ($input['limit'] ?? 1)));
        $orientation = in_array($input['orientation'] ?? '', ['landscape', 'portrait', 'square'], true)
            ? $input['orientation']
            : 'landscape';

        if ($query === '') {
            return ['success' => false, 'error' => 'Image search query cannot be empty.'];
        }

        $candidate_input = is_array($input['query_candidates'] ?? null) ? $input['query_candidates'] : [];
        $query_candidates = $this->derive_query_candidates($query, $candidate_input);
        $evaluation_limit = max($limit, self::DEFAULT_EVALUATION_POOL);
        $attempts = [];
        $best_attempt = null;

        foreach ($query_candidates as $candidate) {
            $attempt = $this->search_candidate($api_key, $query, $candidate, $orientation, $evaluation_limit);
            $attempts[] = $attempt;

            if (!empty($attempt['http_error'])) {
                continue;
            }

            if ($best_attempt === null || ($attempt['best_confidence'] ?? 0) > ($best_attempt['best_confidence'] ?? 0)) {
                $best_attempt = $attempt;
            }

            if (!$this->is_low_confidence($attempt['best_confidence'] ?? 0)) {
                break;
            }
        }

        if (empty($attempts)) {
            return ['success' => false, 'error' => 'No image search candidates could be evaluated.'];
        }

        $selected_query = $best_attempt['query'] ?? $query_candidates[0];
        $selected_confidence = (float) ($best_attempt['best_confidence'] ?? 0);
        $selected_photos = is_array($best_attempt['ranked_photos'] ?? null) ? $best_attempt['ranked_photos'] : [];
        $low_confidence = $this->is_low_confidence($selected_confidence);

        if ($low_confidence || empty($selected_photos)) {
            return [
                'success' => false,
                'error' => sprintf(
                    'Image search confidence was too low for "%s". Try a more specific English query or use an AI-generated image tool.',
                    $query
                ),
                'low_confidence' => true,
                'confidence_score' => $selected_confidence,
                'selected_query' => $selected_query,
                'query_candidates' => $query_candidates,
                'attempted_queries' => $this->summarize_attempts($attempts),
                'matches' => $this->summarize_ranked_photos($selected_photos, 3),
            ];
        }

        $importer = new WAA_Media_Importer(new WAA_Resource_Fetcher());
        $imported = [];

        foreach (array_slice($selected_photos, 0, $limit) as $photo) {
            $url = $photo['src']['large2x'] ?? $photo['src']['large'] ?? $photo['src']['original'] ?? '';
            $title = sanitize_text_field($photo['alt'] ?: $selected_query);

            if ($url === '') {
                $imported[] = ['error' => 'Photo did not contain a usable source URL.', 'photo_id' => $photo['id'] ?? null];
                continue;
            }

            try {
                $attachment_id = $importer->import_from_url($url, $title);

                update_post_meta($attachment_id, '_pexels_photographer', sanitize_text_field($photo['photographer'] ?? ''));
                update_post_meta($attachment_id, '_pexels_photo_url', esc_url_raw($photo['url'] ?? ''));
                update_post_meta($attachment_id, '_pexels_search_query', sanitize_text_field($selected_query));
                update_post_meta($attachment_id, '_pexels_confidence_score', $photo['_relevance_score'] ?? $selected_confidence);
                update_post_meta($attachment_id, '_wp_attachment_image_alt', $title);

                $imported[] = [
                    'attachment_id' => $attachment_id,
                    'title' => $title,
                    'photographer' => $photo['photographer'] ?? '',
                    'source_url' => $photo['url'] ?? '',
                    'media_url' => wp_get_attachment_url($attachment_id),
                    'confidence_score' => (float) ($photo['_relevance_score'] ?? $selected_confidence),
                    'matched_query' => $selected_query,
                ];
            } catch (Throwable $e) {
                $imported[] = ['error' => $e->getMessage(), 'photo_id' => $photo['id'] ?? null];
            }
        }

        $successful = array_values(array_filter($imported, static fn($item) => isset($item['attachment_id'])));

        return [
            'success' => !empty($successful),
            'imported' => $imported,
            'selected_query' => $selected_query,
            'query_candidates' => $query_candidates,
            'attempted_queries' => $this->summarize_attempts($attempts),
            'low_confidence' => false,
            'confidence_score' => $selected_confidence,
            'message' => sprintf(
                '%d image(s) imported to Media Library from query "%s". Use set_post_image with the attachment_id to attach to a post.',
                count($successful),
                $selected_query
            ),
        ];
    }

    protected function derive_query_candidates(string $query, array $extra_candidates = []): array {
        $candidates = [];
        $push = static function (array &$list, string $value): void {
            $value = trim($value);
            if ($value === '') {
                return;
            }
            if (!in_array($value, $list, true)) {
                $list[] = $value;
            }
        };

        $normalized_original = sanitize_text_field($query);
        $ascii_original = $this->normalize_for_matching($normalized_original);

        $push($candidates, $normalized_original);
        if ($ascii_original !== '' && $ascii_original !== strtolower($normalized_original)) {
            $push($candidates, $ascii_original);
        }

        foreach ($extra_candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $push($candidates, sanitize_text_field($candidate));
        }

        foreach (self::QUERY_GLOSSARY as $source => $mapped_values) {
            if ($source === '' || !str_contains($ascii_original, $this->normalize_for_matching($source))) {
                continue;
            }
            foreach ($mapped_values as $mapped) {
                $push($candidates, $mapped);
            }
        }

        $tokens = $this->tokenize($ascii_original);
        if (!empty($tokens)) {
            $push($candidates, implode(' ', $tokens));
            if (count($tokens) <= 3) {
                $push($candidates, implode(' ', $tokens) . ' fruit');
                $push($candidates, implode(' ', $tokens) . ' food');
            }
        }

        return array_slice($candidates, 0, 6);
    }

    protected function search_candidate(string $api_key, string $original_query, string $candidate_query, string $orientation, int $per_page): array {
        $response = wp_remote_get(add_query_arg([
            'query' => $candidate_query,
            'per_page' => $per_page,
            'orientation' => $orientation,
        ], 'https://api.pexels.com/v1/search'), [
            'timeout' => 20,
            'headers' => ['Authorization' => $api_key],
        ]);

        if (is_wp_error($response)) {
            return [
                'query' => $candidate_query,
                'http_error' => $response->get_error_message(),
                'best_confidence' => 0,
                'ranked_photos' => [],
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            return [
                'query' => $candidate_query,
                'http_error' => sprintf('Pexels API error (HTTP %d): %s', $code, $body['error'] ?? 'unknown'),
                'best_confidence' => 0,
                'ranked_photos' => [],
            ];
        }

        $photos = is_array($body['photos'] ?? null) ? $body['photos'] : [];
        $ranked_photos = [];

        foreach ($photos as $photo) {
            $score = $this->score_photo_relevance($original_query, $candidate_query, $photo);
            $photo['_relevance_score'] = $score;
            $ranked_photos[] = $photo;
        }

        usort($ranked_photos, static function (array $left, array $right): int {
            return ($right['_relevance_score'] ?? 0) <=> ($left['_relevance_score'] ?? 0);
        });

        return [
            'query' => $candidate_query,
            'http_error' => null,
            'best_confidence' => (float) (($ranked_photos[0]['_relevance_score'] ?? 0)),
            'ranked_photos' => $ranked_photos,
        ];
    }

    protected function score_photo_relevance(string $original_query, string $candidate_query, array $photo): float {
        $haystack = implode(' ', array_filter([
            (string) ($photo['alt'] ?? ''),
            (string) ($photo['url'] ?? ''),
            (string) ($photo['photographer'] ?? ''),
        ]));
        $normalized_haystack = $this->normalize_for_matching($haystack);

        if ($normalized_haystack === '') {
            return 0.05;
        }

        $query_tokens = array_values(array_unique(array_merge(
            $this->tokenize($original_query),
            $this->tokenize($candidate_query)
        )));

        if (empty($query_tokens)) {
            return 0.05;
        }

        $matched = 0;
        $exact_phrase_bonus = 0;
        foreach ($query_tokens as $token) {
            if (strlen($token) < 3) {
                continue;
            }
            if (str_contains($normalized_haystack, $token)) {
                $matched += 1;
            }
        }

        $original_phrase = $this->normalize_for_matching($original_query);
        $candidate_phrase = $this->normalize_for_matching($candidate_query);
        if ($original_phrase !== '' && str_contains($normalized_haystack, $original_phrase)) {
            $exact_phrase_bonus += 0.28;
        }
        if ($candidate_phrase !== '' && $candidate_phrase !== $original_phrase && str_contains($normalized_haystack, $candidate_phrase)) {
            $exact_phrase_bonus += 0.18;
        }

        $coverage = $matched / max(count($query_tokens), 1);
        $generic_penalty = preg_match('/\b(coffee|cafe|portrait|person|man|woman|office|laptop|city)\b/', $normalized_haystack) === 1 ? 0.10 : 0.0;

        return max(0.0, min(1.0, ($coverage * 0.82) + $exact_phrase_bonus - $generic_penalty));
    }

    protected function is_low_confidence(float $score): bool {
        return $score < self::MIN_CONFIDENCE_TO_IMPORT;
    }

    protected function normalize_for_matching(string $value): string {
        $value = remove_accents($value);
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9\s]+/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        return trim((string) $value);
    }

    protected function tokenize(string $value): array {
        $normalized = $this->normalize_for_matching($value);
        if ($normalized === '') {
            return [];
        }

        $tokens = array_filter(explode(' ', $normalized), static fn(string $token): bool => $token !== '');
        return array_values(array_unique($tokens));
    }

    private function summarize_attempts(array $attempts): array {
        return array_map(function (array $attempt): array {
            return [
                'query' => (string) ($attempt['query'] ?? ''),
                'best_confidence' => (float) ($attempt['best_confidence'] ?? 0),
                'http_error' => $attempt['http_error'] ?? null,
                'top_matches' => $this->summarize_ranked_photos($attempt['ranked_photos'] ?? [], 2),
            ];
        }, $attempts);
    }

    private function summarize_ranked_photos(array $photos, int $limit): array {
        $summary = [];
        foreach (array_slice($photos, 0, $limit) as $photo) {
            $summary[] = [
                'photo_id' => $photo['id'] ?? null,
                'title' => sanitize_text_field((string) ($photo['alt'] ?? '')),
                'source_url' => esc_url_raw((string) ($photo['url'] ?? '')),
                'confidence_score' => (float) ($photo['_relevance_score'] ?? 0),
            ];
        }

        return $summary;
    }
}
