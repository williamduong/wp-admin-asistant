<?php

defined('ABSPATH') || exit;

class WAA_Tool_Wordfence_Disconnect_Central extends WAA_Tool_Base {
    public function get_name(): string { return 'wordfence_disconnect_central'; }

    public function get_description(): string {
        return 'Disconnect this site from Wordfence Central. Local firewall and scan protections remain fully active.';
    }

    public function get_input_schema(): array {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $input): array {
        if (!class_exists('wfConfig')) {
            return ['success' => false, 'error' => 'Wordfence is not active or not fully loaded.'];
        }

        // Disable Central mode and clear connection tokens
        wfConfig::set('wordfenceCentralEnabled', 0);
        delete_option('wfCentralSiteToken');
        delete_option('wfCentralSitePingKey');
        delete_option('wfCentralSiteURL');

        return [
            'success' => true,
            'message' => 'Wordfence Central disconnected. Firewall and scans continue to run locally.',
        ];
    }
}
