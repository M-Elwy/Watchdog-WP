<?php

namespace WPWatchdog\Agent\Admin;

class Settings
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_menu_page(): void
    {
        add_options_page(
            __('WordPress Watchdog', 'wp-watchdog'),
            __('WordPress Watchdog', 'wp-watchdog'),
            'manage_options',
            'wp-watchdog-settings',
            [$this, 'render_page']
        );
    }

    public function register_settings(): void
    {
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
            <form method="post" action="options.php">
                <?php settings_fields('wp_watchdog_settings_group'); ?>
                <?php do_settings_sections('wp_watchdog_settings_group'); ?>
                <?php submit_button(__('Save Settings', 'wp-watchdog')); ?>
            </form>
        </div>
<?php
    }
}
