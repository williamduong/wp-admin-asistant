<?php

defined('ABSPATH') || exit;

/**
 * Safely downloads a remote resource (image, file) via URL.
 *
 * Returns metadata + a local temp path. Caller is responsible for
 * moving the file and cleaning up via unlink($result['path']).
 */
class WAA_Resource_Fetcher {
    private const MAX_BYTES = 5 * 1024 * 1024; // 5 MB

    private const ALLOWED_MIME = [
        'image/png', 'image/jpeg', 'image/gif',
        'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon',
        'image/svg+xml',
    ];

    /**
     * @throws RuntimeException on any validation or download failure
     * @return array{ path: string, mime: string, filename: string, size: int }
     */
    public function fetch_image(string $url): array {
        $url = esc_url_raw($url);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException("Invalid URL: $url");
        }

        $scheme = wp_parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException("Only http/https URLs are allowed.");
        }

        // HEAD first — check content-type and size without downloading
        $head = wp_remote_head($url, [
            'timeout'    => 10,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; WAA-Bot',
            'redirection' => 3,
        ]);

        if (is_wp_error($head)) {
            throw new RuntimeException("HEAD request failed: " . $head->get_error_message());
        }

        $head_code = wp_remote_retrieve_response_code($head);
        if ($head_code !== 200) {
            // Some servers don't support HEAD — fall through to GET
        } else {
            $this->validate_headers(wp_remote_retrieve_headers($head));
        }

        // Full download
        $response = wp_remote_get($url, [
            'timeout'    => 30,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; WAA-Bot',
            'redirection' => 3,
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException("Download failed: " . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            throw new RuntimeException("Remote server returned HTTP $code");
        }

        $this->validate_headers(wp_remote_retrieve_headers($response));

        $body = wp_remote_retrieve_body($response);
        if (strlen($body) > self::MAX_BYTES) {
            throw new RuntimeException("File exceeds size limit (" . (self::MAX_BYTES / 1024 / 1024) . " MB).");
        }

        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $tmp = wp_tempnam($url);
        file_put_contents($tmp, $body);

        $content_type_header = strtok(wp_remote_retrieve_header($response, 'content-type') ?: '', ';');
        $mime = $this->detect_mime($tmp, $url, $content_type_header);

        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            unlink($tmp);
            throw new RuntimeException("File type '$mime' is not allowed. Accepted: " . implode(', ', self::ALLOWED_MIME));
        }

        $filename = $this->extract_filename($url, $mime);

        return [
            'path'     => $tmp,
            'mime'     => $mime,
            'filename' => $filename,
            'size'     => strlen($body),
        ];
    }

    private function detect_mime(string $path, string $url, string $header_mime): string {
        $ext = strtolower(pathinfo(wp_parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        // SVG: trust header or extension + peek at content
        if ($header_mime === 'image/svg+xml' || $ext === 'svg') {
            $peek = file_get_contents($path, false, null, 0, 512) ?: '';
            if (str_contains($peek, '<svg') || str_contains($peek, '<?xml')) {
                return 'image/svg+xml';
            }
        }
        // mime_content_type is the primary detector
        $detected = function_exists('mime_content_type') ? (mime_content_type($path) ?: '') : '';
        // Remap XML variants → SVG when the extension says so
        if (in_array($detected, ['text/xml', 'application/xml', 'text/plain', 'text/html'], true) && $ext === 'svg') {
            return 'image/svg+xml';
        }
        // Fall back to response Content-Type header
        return $detected ?: $header_mime;
    }

    private function validate_headers(object $headers): void {
        $content_length = (int) ($headers['content-length'] ?? 0);
        if ($content_length > self::MAX_BYTES) {
            throw new RuntimeException("File too large ({$content_length} bytes). Limit: " . self::MAX_BYTES . " bytes.");
        }

        $content_type = strtok($headers['content-type'] ?? '', ';');
        if ($content_type && !in_array($content_type, self::ALLOWED_MIME, true)) {
            throw new RuntimeException("Content-Type '$content_type' not allowed.");
        }
    }

    private function extract_filename(string $url, string $mime): string {
        $path = wp_parse_url($url, PHP_URL_PATH) ?? '';
        $name = sanitize_file_name(basename($path)) ?: 'image';

        // Strip query strings that may have crept in
        $name = preg_replace('/[?#].*/', '', $name);

        $ext_map = [
            'image/png'                    => 'png',
            'image/jpeg'                   => 'jpg',
            'image/gif'                    => 'gif',
            'image/webp'                   => 'webp',
            'image/x-icon'                 => 'ico',
            'image/vnd.microsoft.icon'     => 'ico',
            'image/svg+xml'                => 'svg',
        ];

        $ext      = $ext_map[$mime] ?? 'png';
        $has_ext  = pathinfo($name, PATHINFO_EXTENSION) !== '';

        return $has_ext ? $name : "$name.$ext";
    }
}
