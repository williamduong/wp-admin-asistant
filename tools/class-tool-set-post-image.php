<?php

defined('ABSPATH') || exit;

class WAA_Tool_Set_Post_Image extends WAA_Tool_Base {
    public function get_name(): string { return 'set_post_image'; }

    public function get_description(): string {
        return 'Attach a Media Library image to a post. Use mode "featured" to set the featured image (thumbnail), "prepend" to insert at the top of content, or "append" to insert at the bottom.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'post_id' => [
                    'type'        => 'integer',
                    'description' => 'ID of the post or page to update.',
                ],
                'attachment_id' => [
                    'type'        => 'integer',
                    'description' => 'Media Library attachment ID (from search_images or list_media).',
                ],
                'mode' => [
                    'type'        => 'string',
                    'enum'        => ['featured', 'prepend', 'append'],
                    'description' => 'How to attach the image. Default: featured.',
                ],
                'caption' => [
                    'type'        => 'string',
                    'description' => 'Optional caption text shown below the image (for prepend/append modes).',
                ],
            ],
            'required' => ['post_id', 'attachment_id'],
        ];
    }

    public function execute(array $input): array {
        $post_id       = (int) ($input['post_id'] ?? 0);
        $attachment_id = (int) ($input['attachment_id'] ?? 0);
        $mode          = in_array($input['mode'] ?? '', ['featured', 'prepend', 'append'], true)
                             ? $input['mode']
                             : 'featured';

        if (!get_post($post_id)) {
            return ['success' => false, 'error' => "Post $post_id not found."];
        }

        if (!wp_attachment_is_image($attachment_id)) {
            return ['success' => false, 'error' => "Attachment $attachment_id is not an image or does not exist."];
        }

        if ($mode === 'featured') {
            $result = set_post_thumbnail($post_id, $attachment_id);
            if (!$result) {
                return ['success' => false, 'error' => 'Failed to set featured image.'];
            }
            return [
                'success'       => true,
                'mode'          => 'featured',
                'post_id'       => $post_id,
                'attachment_id' => $attachment_id,
                'image_url'     => wp_get_attachment_url($attachment_id),
                'message'       => "Featured image set on post $post_id.",
            ];
        }

        // prepend or append — insert <figure> block into post content
        $post    = get_post($post_id);
        $img_url = wp_get_attachment_image_url($attachment_id, 'large');
        $alt     = esc_attr(get_post_meta($attachment_id, '_wp_attachment_image_alt', true) ?: get_the_title($attachment_id));
        $caption = !empty($input['caption']) ? '<figcaption>' . esc_html($input['caption']) . '</figcaption>' : '';

        $block = "\n<!-- wp:image {\"id\":$attachment_id,\"sizeSlug\":\"large\"} -->\n"
               . "<figure class=\"wp-block-image size-large\"><img src=\"$img_url\" alt=\"$alt\" class=\"wp-image-$attachment_id\"/>$caption</figure>\n"
               . "<!-- /wp:image -->\n";

        $content = $mode === 'prepend'
            ? $block . $post->post_content
            : $post->post_content . $block;

        $result = wp_update_post(['ID' => $post_id, 'post_content' => $content], true);

        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }

        return [
            'success'       => true,
            'mode'          => $mode,
            'post_id'       => $post_id,
            'attachment_id' => $attachment_id,
            'image_url'     => $img_url,
            'message'       => "Image inserted ($mode) into post $post_id content.",
        ];
    }
}
