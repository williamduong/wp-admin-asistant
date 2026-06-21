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

    /**
     * Import raw image bytes into the Media Library.
     *
     * @throws RuntimeException on invalid input or WP upload failure
     */
    public function import_from_binary(string $bytes, string $filename, string $mime, string $title = ''): int {
        if ($bytes === '') {
            throw new RuntimeException('Generated image payload was empty.');
        }

        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $tmp = wp_tempnam($filename ?: 'generated-image');
        file_put_contents($tmp, $bytes);

        try {
            return $this->upload_to_library([
                'path' => $tmp,
                'mime' => $mime,
                'filename' => sanitize_file_name($filename ?: 'generated-image.png'),
                'size' => strlen($bytes),
            ], $title ?: $filename);
        } finally {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
        }
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
