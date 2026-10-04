<?php

namespace WPWatchdog\Agent\Admin;

use WPWatchdog\Agent\Http\Client;

class Settings
{
    protected $incidents_view;

    public function __construct()
    {
        $this->incidents_view = new IncidentsView();
    }

    public static function is_api_key_verified(): bool
    {
        return (bool) get_option('site_watchdog_api_key_verified', false);
    }

    public static function mark_api_key_verified(bool $verified): void
    {
        update_option('site_watchdog_api_key_verified', $verified, false);
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'render_verification_notice']);
    }

    public function validate_api_key_option($value, $old_value, $option): string
    {
        $api_key = is_string($value) ? trim(wp_unslash($value)) : '';
        $api_key = sanitize_text_field($api_key);

        if ('' === $api_key) {
            self::mark_api_key_verified(false);

            return '';
        }

        $result = (new Client())->check_key($api_key);

        if (true !== $result['valid']) {
            self::mark_api_key_verified(false);
            error_log('Failed to verify API Key with Watchdog server: ' . $result['error']);
            add_settings_error('site_watchdog_api_key', 'invalid_key', __('Could not verify API Key with Watchdog server.', 'site-watchdog'));

            return $old_value;
        }

        $site_id = $result['site_id'];
        remove_filter('pre_update_option_site_watchdog_api_key', [$this, 'validate_api_key_option']);
        update_option('site_watchdog_api_key', $api_key);
        add_filter('pre_update_option_site_watchdog_api_key', [$this, 'validate_api_key_option'], 10, 3);

        update_option('site_watchdog_site_id', $site_id);
        self::mark_api_key_verified(true);
        delete_transient('site_watchdog_cached_incidents');

        return $api_key;
    }

    public function add_menu_page(): void
    {
        add_menu_page(
            __('Site Watchdog', 'site-watchdog'),
            __('Site Watchdog', 'site-watchdog'),
            'manage_options',
            'site-watchdog-settings',
            [$this, 'render_page'],
            'dashicons-shield'
        );
    }

    public function register_settings(): void
    {
        add_filter('pre_update_option_site_watchdog_api_key', [$this, 'validate_api_key_option'], 10, 3);

        add_settings_section(
            'site_watchdog_main',
            __('Main Settings', 'site-watchdog'),
            [$this, 'render_section_intro'],
            'site_watchdog_settings_group'
        );

        register_setting(
            'site_watchdog_settings_group',
            'site_watchdog_api_key',
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_api_key'],
                'default' => '',
            ]
        );

        register_setting(
            'site_watchdog_settings_group',
            'site_watchdog_environment',
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_environment'],
                'default' => 'production',
            ]
        );

        add_settings_field(
            'site_watchdog_api_key',
            __('API Key', 'site-watchdog'),
            [$this, 'render_api_key_field'],
            'site_watchdog_settings_group',
            'site_watchdog_main'
        );

        add_settings_field(
            'site_watchdog_environment',
            __('Environment', 'site-watchdog'),
            [$this, 'render_environment_field'],
            'site_watchdog_settings_group',
            'site_watchdog_main'
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
        echo '<p>' . esc_html__('Configure the connection to Site Watchdog.', 'site-watchdog') . '</p>';
    }

    public function render_api_key_field(): void
    {
        $value = get_option('site_watchdog_api_key', '');

        echo '<input type="text" id="site_watchdog_api_key" name="site_watchdog_api_key" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Enter the API key provided by Site Watchdog.', 'site-watchdog') . '</p>';
    }

    public function render_environment_field(): void
    {
        $environment = get_option('site_watchdog_environment', 'production');

        echo '<select id="site_watchdog_environment" name="site_watchdog_environment">';
        foreach (['production', 'staging', 'development'] as $option) {
            echo '<option value="' . esc_attr($option) . '" ' . selected($environment, $option, false) . '>' . esc_html(ucfirst($option)) . '</option>';
        }
        echo '</select>';
    }

    public function render_verification_notice(): void
    {
        if (!current_user_can('manage_options') || self::is_api_key_verified()) {
            return;
        }

        $api_key = get_option('site_watchdog_api_key', '');
        if ('' === $api_key) {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__('Site Watchdog:', 'site-watchdog') . '</strong> ' . esc_html__('Your API key is not verified, so event delivery is paused until the key is updated and re-validated.', 'site-watchdog') . '</p></div>';
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'site-watchdog'));
        }

?>
        <div class="wrap">
            <h1><?php echo esc_html__('Site Watchdog Settings', 'site-watchdog'); ?></h1>
            <?php if (!self::is_api_key_verified() && '' !== get_option('site_watchdog_api_key', '')) : ?>
                <div class="notice notice-warning inline">
                    <p><?php echo esc_html__('Warning: this API key is not verified. Events are paused until the key is corrected and re-verified.', 'site-watchdog'); ?></p>
                </div>
            <?php endif; ?>
            <?php settings_errors('site_watchdog_api_key'); ?>
            <form method="post" action="options.php">
                <?php settings_fields('site_watchdog_settings_group'); ?>
                <?php do_settings_sections('site_watchdog_settings_group'); ?>
                <?php submit_button(__('Save Settings', 'site-watchdog')); ?>
            </form>
            <?php $this->incidents_view->render(); ?>
        </div>
<?php
    }
}
