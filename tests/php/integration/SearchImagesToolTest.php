<?php

class SearchImagesToolTest extends WP_UnitTestCase {
    public function test_search_images_derives_english_query_candidates_for_durian(): void {
        $tool = new SearchImagesToolHarness();

        $candidates = $tool->deriveCandidates('sầu riêng', []);

        $this->assertContains('sầu riêng', $candidates);
        $this->assertContains('sau rieng', $candidates);
        $this->assertContains('durian', $candidates);
        $this->assertContains('durian fruit', $candidates);
    }

    public function test_search_images_scores_relevant_durian_result_higher_than_unrelated_cafe_result(): void {
        $tool = new SearchImagesToolHarness();

        $durianScore = $tool->scoreRelevance('sầu riêng', 'durian', [
            'alt' => 'Fresh durian fruit on market table',
            'url' => 'https://www.pexels.com/photo/durian-fruit-123/',
            'photographer' => 'Example',
        ]);
        $cafeScore = $tool->scoreRelevance('sầu riêng', 'durian', [
            'alt' => 'Casual scene of a young man enjoying an iced coffee at a cafe in Vietnam.',
            'url' => 'https://www.pexels.com/photo/coffee-at-cafe-999/',
            'photographer' => 'Example',
        ]);

        $this->assertGreaterThan($cafeScore, $durianScore);
        $this->assertFalse($tool->lowConfidence($durianScore));
        $this->assertTrue($tool->lowConfidence($cafeScore));
    }
}

class SearchImagesToolHarness extends WAA_Tool_Search_Images {
    public function deriveCandidates(string $query, array $extra = []): array {
        return $this->derive_query_candidates($query, $extra);
    }

    public function scoreRelevance(string $originalQuery, string $candidateQuery, array $photo): float {
        return $this->score_photo_relevance($originalQuery, $candidateQuery, $photo);
    }

    public function lowConfidence(float $score): bool {
        return $this->is_low_confidence($score);
    }
}
