<?php

defined('ABSPATH') || exit;

/**
 * Imports a remote image into the WordPress Media Library.
 *
 * Usage:
 *   $id = (new WAA_Media_Importer(new WAA_Resource_Fetcher()))->import_from_url($url, 'Site Icon');
 */
class WAA_Media_Importer {
    public function __construct(
        private readonly WAA_Resource_Fetcher $fetcher
    ) {}

    /**
     * Download URL → upload to Media Library → return attachment ID.
     *
     * @throws RuntimeException on download or WP upload failure
     */
    public function import_from_url(string $url, string $title = ''): int {
        $resource = $this->fetcher->fetch_image($url);

        try {
            $attachment_id = $this->upload_to_library($resource, $title ?: $resource['filename']);
        } finally {
            // Always clean up the temp file
            if (file_exists($resource['path'])) {
                unlink($resource['path']);
            }
        }

        return $attachment_id;
    }

    private function upload_to_library(array $resource, string $title): int {
        // wp_upload_bits handles wp-content/uploads/ directory and year/month structure
        $upload = wp_upload_bits(
            $resource['filename'],
            null,
            file_get_contents($resource['path'])
        );

        if (!empty($upload['error'])) {
            throw new RuntimeException("Upload failed: " . $upload['error']);
        }

        $attachment = [
            'post_mime_type' => $resource['mime'],
            'post_title'     => sanitize_text_field($title),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $attachment_id = wp_insert_attachment($attachment, $upload['file']);

        if (is_wp_error($attachment_id)) {
            throw new RuntimeException("Failed to create attachment: " . $attachment_id->get_error_message());
        }

        // Generate thumbnails and metadata
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $metadata);

        return $attachment_id;
    }
}
