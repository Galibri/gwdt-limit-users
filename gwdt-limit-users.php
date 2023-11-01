<?php
/**
 * Plugin Name: Limit WP Users
 * Description: This plugin ensures that only a specified number of users remain in the WordPress users table, deleting any additional users on schedule like 15 mins, hourly, daily etc.
 * Version: 1.0
 * Author: Asadullah Al Galib
 */

// ABSPATH for wordpress plugin
defined('ABSPATH') or die('Hey, you can\t access this file, you silly human!');

// Add custom intervals to wp cron schedules
function gwdt_lwu_add_cron_intervals($schedules)
{
    $schedules['gwdt_lwu_every_fifteen_minutes'] = array(
        'interval' => 15 * 60,
        'display' => esc_html__('Every Fifteen Minutes'),
    );
    $schedules['gwdt_lwu_hourly'] = array(
        'interval' => HOUR_IN_SECONDS,
        'display' => esc_html__('Hourly'),
    );
    $schedules['gwdt_lwu_daily'] = array(
        'interval' => DAY_IN_SECONDS,
        'display' => esc_html__('Daily'),
    );
    $schedules['gwdt_lwu_weekly'] = array(
        'interval' => WEEK_IN_SECONDS,
        'display' => esc_html__('Weekly'),
    );
    return $schedules;
}
add_filter('cron_schedules', 'gwdt_lwu_add_cron_intervals');

// Register settings
function gwdt_lwu_register_settings()
{
    register_setting('gwdt_lwu_settings_group', 'gwdt_lwu_number_of_users', 'intval');
    register_setting('gwdt_lwu_settings_group', 'gwdt_lwu_cron_schedule');
}
add_action('admin_init', 'gwdt_lwu_register_settings');

// Add options page
function gwdt_lwu_options_page()
{
    add_options_page('GWDT Limit Users Settings', 'Users Limit Settings', 'manage_options', 'gwdt_lwu', 'gwdt_lwu_options_page_html');
}
add_action('admin_menu', 'gwdt_lwu_options_page');

// Options page HTML
function gwdt_lwu_options_page_html()
{
    if (!current_user_can('manage_options'))
        return;
    ?>
    <div class="wrap">
        <h1>
            <?= esc_html(get_admin_page_title()); ?>
        </h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('gwdt_lwu_settings_group');
            do_settings_sections('gwdt_lwu');
            submit_button('Save Settings');
            ?>
        </form>
    </div>
    <?php
}

// Add settings section and fields
function gwdt_lwu_settings_init()
{
    add_settings_section('gwdt_lwu_settings_section', 'User Limit Settings', 'gwdt_lwu_settings_section_cb', 'gwdt_lwu');

    add_settings_field('gwdt_lwu_field_number_of_users', 'Number of users to keep', 'gwdt_lwu_field_number_of_users_cb', 'gwdt_lwu', 'gwdt_lwu_settings_section');
    add_settings_field('gwdt_lwu_field_cron_schedule', 'Cron Schedule', 'gwdt_lwu_field_cron_schedule_cb', 'gwdt_lwu', 'gwdt_lwu_settings_section');
}
add_action('admin_init', 'gwdt_lwu_settings_init');

function gwdt_lwu_settings_section_cb()
{
    echo '<p>Set the number of users you want to keep and the schedule for checking.</p>';
}

function gwdt_lwu_field_number_of_users_cb()
{
    $value = get_option('gwdt_lwu_number_of_users', '2');
    echo '<input type="number" name="gwdt_lwu_number_of_users" value="' . esc_attr($value) . '" min="1" />';
}

function gwdt_lwu_field_cron_schedule_cb()
{
    $value = get_option('gwdt_lwu_cron_schedule', 'gwdt_lwu_hourly');
    $schedules = array(
        'gwdt_lwu_every_fifteen_minutes' => 'Every 15 Minutes',
        'gwdt_lwu_hourly' => 'Hourly',
        'gwdt_lwu_daily' => 'Daily',
        'gwdt_lwu_weekly' => 'Weekly',
    );
    echo '<select name="gwdt_lwu_cron_schedule">';
    foreach ($schedules as $key => $display) {
        echo '<option value="' . esc_attr($key) . '"' . selected($value, $key, false) . '>' . esc_html($display) . '</option>';
    }
    echo '</select>';
}

// Hook for plugin activation
register_activation_hook(__FILE__, 'gwdt_lwu_activation');
function gwdt_lwu_activation()
{
    add_option('gwdt_lwu_number_of_users', '100');
    add_option('gwdt_lwu_cron_schedule', 'gwdt_lwu_hourly');
    gwdt_lwu_reschedule_event();
}

// Hook for plugin deactivation
register_deactivation_hook(__FILE__, 'gwdt_lwu_deactivation');
function gwdt_lwu_deactivation()
{
    $timestamp = wp_next_scheduled('gwdt_lwu_user_check_event');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'gwdt_lwu_user_check_event');
    }

    // Delete the options
    delete_option('gwdt_lwu_number_of_users');
    delete_option('gwdt_lwu_cron_schedule');
}

// Reschedule the event when the plugin is activated or settings are changed
function gwdt_lwu_reschedule_event()
{
    $schedule = get_option('gwdt_lwu_cron_schedule', 'gwdt_lwu_hourly');
    $timestamp = wp_next_scheduled('gwdt_lwu_user_check_event');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'gwdt_lwu_user_check_event');
    }
    wp_schedule_event(time(), $schedule, 'gwdt_lwu_user_check_event');
}

// Handle the rescheduling of the event whenever the option is updated
add_action('update_option_gwdt_lwu_cron_schedule', 'gwdt_lwu_reschedule_event');


// Hook the function to the action
add_action('gwdt_lwu_user_check_event', 'gwdt_lwu_limit_users');

function gwdt_lwu_limit_users()
{
    global $wpdb;
    $number_of_users_to_keep = get_option('gwdt_lwu_number_of_users', '100');

    // We'll keep the specified number of users based on user_registered date
    $users_to_keep = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->users} ORDER BY user_registered ASC LIMIT %d", $number_of_users_to_keep));

    // Prepare the query to delete any other users
    if (!empty($users_to_keep)) {
        $placeholders = implode(',', array_fill(0, count($users_to_keep), '%d'));
        $users_to_keep_sql = $wpdb->prepare("IN ($placeholders)", $users_to_keep);

        // Delete users that are not in the list of users to keep
        $wpdb->query("DELETE FROM {$wpdb->users} WHERE ID NOT {$users_to_keep_sql}");
    }
}

// Ensure our action hook is available and scheduled correctly
add_action('init', function () {
    if (!wp_next_scheduled('gwdt_lwu_user_check_event')) {
        gwdt_lwu_reschedule_event();
    }
});