<?php

namespace WPWatchdog\Agent\Admin;

class Settings
{
    protected $incidents_view;

    public function __construct()
    {
        $this->incidents_view = new IncidentsView();
    }
    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function validate_api_key_option($value, $old_value, $option): string
    {
        $api_key = is_string($value) ? trim(wp_unslash($value)) : '';
        $api_key = sanitize_text_field($api_key);

        if ('' === $api_key) {
            return '';
        }

        $version_path = '/' . WP_WATCHDOG_API_VERSION;
        $verify_url = rtrim(WP_WATCHDOG_API_URL, '/') . (false !== strpos(WP_WATCHDOG_API_URL, $version_path) ? '/sites/verify' : $version_path . '/sites/verify');
        error_log('Verifying API Key with Watchdog server: ' . $verify_url);
        $response = wp_remote_post($verify_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 10,
        ]);

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            error_log('Failed to verify API Key with Watchdog server: ' . (is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response)));
            add_settings_error('wp_watchdog_api_key', 'invalid_key', __('Could not verify API Key with Watchdog server.', 'wp-watchdog'));

            return $old_value;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $site_id = isset($body['data']['site_id']) ? sanitize_text_field((string) $body['data']['site_id']) : '';
        error_log('Watchdog server response: ' . wp_remote_retrieve_body($response) . ', site_id: ' . $site_id);
        if ('' === $site_id) {
            add_settings_error('wp_watchdog_api_key', 'invalid_key', __('Could not verify API Key with Watchdog server.', 'wp-watchdog'));

            return $old_value;
        }

        update_option('wp_watchdog_api_key', $api_key);
        update_option('wp_watchdog_site_id', $site_id);
        delete_transient('wp_watchdog_cached_incidents');

        return $api_key;
    }

    public function add_menu_page(): void
    {
        add_menu_page(
            __('WordPress Watchdog', 'wp-watchdog'),
            __('WordPress Watchdog', 'wp-watchdog'),
            'manage_options',
            'wp-watchdog-settings',
            [$this, 'render_page'],
            'dashicons-shield'
        );
    }

    public function register_settings(): void
    {
        add_filter('pre_update_option_wp_watchdog_api_key', [$this, 'validate_api_key_option'], 10, 3);

        add_settings_section(
            'wp_watchdog_main',
            __('Main Settings', 'wp-watchdog'),
            [$this, 'render_section_intro'],
            'wp_watchdog_settings_group'
        );

        register_setting(
            'wp_watchdog_settings_group',
            'wp_watchdog_api_key',
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_api_key'],
                'default' => '',
            ]
        );

        register_setting(
            'wp_watchdog_settings_group',
            'wp_watchdog_environment',
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_environment'],
                'default' => 'production',
            ]
        );

        add_settings_field(
            'wp_watchdog_api_key',
            __('API Key', 'wp-watchdog'),
            [$this, 'render_api_key_field'],
            'wp_watchdog_settings_group',
            'wp_watchdog_main'
        );

        add_settings_field(
            'wp_watchdog_environment',
            __('Environment', 'wp-watchdog'),
            [$this, 'render_environment_field'],
            'wp_watchdog_settings_group',
            'wp_watchdog_main'
        );
    }

    public function sanitize_api_key($value): string
    {
        $value = is_string($value) ? trim(wp_unslash($value)) : '';

        return sanitize_text_field($value);
    }

    public function sanitize_environment($value): string
    {
        $allowed = ['production', 'staging', 'development'];
        $value = is_string($value) ? strtolower(trim(wp_unslash($value))) : 'production';

        return in_array($value, $allowed, true) ? $value : 'production';
    }

    public function render_section_intro(): void
    {
        echo '<p>' . esc_html__('Configure the connection to WordPress Watchdog.', 'wp-watchdog') . '</p>';
    }

    public function render_api_key_field(): void
    {
        $value = get_option('wp_watchdog_api_key', '');

        echo '<input type="text" id="wp_watchdog_api_key" name="wp_watchdog_api_key" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Enter the API key provided by WordPress Watchdog.', 'wp-watchdog') . '</p>';
    }

    public function render_environment_field(): void
    {
        $environment = get_option('wp_watchdog_environment', 'production');

        echo '<select id="wp_watchdog_environment" name="wp_watchdog_environment">';
        foreach (['production', 'staging', 'development'] as $option) {
            echo '<option value="' . esc_attr($option) . '" ' . selected($environment, $option, false) . '>' . esc_html(ucfirst($option)) . '</option>';
        }
        echo '</select>';
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'wp-watchdog'));
        }

?>
        <div class="wrap">
            <h1><?php echo esc_html__('WordPress Watchdog Settings', 'wp-watchdog'); ?></h1>
            <?php settings_errors('wp_watchdog_api_key'); ?>
            <form method="post" action="options.php">
                <?php settings_fields('wp_watchdog_settings_group'); ?>
                <?php do_settings_sections('wp_watchdog_settings_group'); ?>
                <?php submit_button(__('Save Settings', 'wp-watchdog')); ?>
            </form>
            <?php $this->incidents_view->render(); ?>
        </div>
<?php
    }
}
