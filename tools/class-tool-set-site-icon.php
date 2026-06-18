<?php

defined('ABSPATH') || exit;

/**
 * Set the WordPress site icon (favicon) from a public image URL.
 *
 * Flow: image_url → WAA_Resource_Fetcher (validate) →
 *       WAA_Media_Importer (upload to Media Library) →
 *       update_option('site_icon', $attachment_id)
 *
 * The AI should provide a direct image URL (PNG, JPG, ICO, WebP, SVG).
 * Free icon sources: flaticon.com, icons8.com, svgrepo.com, favicon.io
 */
class WAA_Tool_Set_Site_Icon extends WAA_Tool_Base {
    public function get_name(): string { return 'set_site_icon'; }

    public function get_description(): string {
        return 'Set the WordPress site icon (favicon) from a public image URL. '
             . 'Download the image and upload it to the Media Library. '
             . 'Accepted formats: PNG, JPG, GIF, WebP, ICO, SVG. Max 5 MB. '
             . 'Use a square image (e.g. 512×512) for best results.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'image_url' => [
                    'type'        => 'string',
                    'description' => 'Direct URL to a publicly accessible image file (https recommended). Must resolve to an image, not an HTML page.',
                ],
                'title' => [
                    'type'        => 'string',
                    'description' => 'Optional label for the image in the Media Library. Defaults to "Site Icon".',
                ],
            ],
            'required' => ['image_url'],
        ];
    }

    public function execute(array $input): array {
        $url   = sanitize_url($input['image_url'] ?? '');
        $title = sanitize_text_field($input['title'] ?? 'Site Icon');

        if (empty($url)) {
            throw new RuntimeException('image_url is required.');
        }

        $importer      = new WAA_Media_Importer(new WAA_Resource_Fetcher());
        $attachment_id = $importer->import_from_url($url, $title);

        update_option('site_icon', $attachment_id);

        $icon_url = wp_get_attachment_image_url($attachment_id, 'full') ?: '';

        return [
            'success'       => true,
            'attachment_id' => $attachment_id,
            'site_icon_url' => $icon_url,
            'message'       => "Site icon updated. Attachment ID: $attachment_id.",
        ];
    }
}
