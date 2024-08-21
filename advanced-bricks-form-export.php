<?php
/*
Plugin Name: Automated Bricks Form Export
Description: Automates the export of Bricks Builder form submissions to CSV and emails them on a scheduled basis.
Version: 1.0.1
Author: LFMC
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Plugin Update Checker
require 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/websupport-lfmc/automated-bricks-form-export',
    __FILE__,
    'automated-bricks-form-export'
);

$myUpdateChecker->setBranch('main');

// Plugin logging
function log_bricks_export($message)
{
    if (WP_DEBUG === true) {
        if (is_array($message) || is_object($message)) {
            error_log(print_r($message, true));
        } else {
            error_log($message);
        }
    }
}

// Function to fetch Bricks form titles
function get_bricks_form_title($form_id)
{
    return \Bricks\Integrations\Form\Submission_Database::get_form_name_by_id($form_id);
}

// Function to fetch and link data from the Bricks database
function fetch_bricks_data($limit = false)
{
    $forms_data = [];
    $args = [];

    if ($limit) {
        $args['limit'] = $limit;
    }

    $entries = \Bricks\Integrations\Form\Submission_Database::get_entries($args);

    foreach ($entries as $entry) {
        $form_id = $entry['form_id'];
        $form_data = json_decode($entry['form_data'], true);

        if (!isset($forms_data[$form_id])) {
            $forms_data[$form_id] = [];
        }

        // Initialize the entry data array with the standard fields
        $entry_data = [
            'entry_id' => $entry['id'],
            'submission_date' => $entry['created_at'],
        ];

        // Iterate over each form field and add it to the entry data
        foreach ($form_data as $field_key => $field_info) {
            $field_value = is_array($field_info) && isset($field_info['value']) ? $field_info['value'] : '';
            $entry_data[$field_key] = $field_value;
        }

        $forms_data[$form_id][] = $entry_data;
    }

    return $forms_data;
}

// Function to export Bricks data to CSV
function export_bricks_data_to_csv($limit = false)
{
    $forms_data = fetch_bricks_data($limit);
    $csv_files = [];

    foreach ($forms_data as $form_id => $entries) {
        $form_title = get_bricks_form_title($form_id);
        $csv_file = plugin_dir_path(__FILE__) . "bricks_submissions_{$form_title}.csv";
        $file_handle = fopen($csv_file, 'w');

        // Collect all unique field names
        $unique_field_names = [];
        foreach ($entries as $entry) {
            foreach ($entry['form_data'] as $field_name => $field_data) {
                if (!in_array($field_name, $unique_field_names)) {
                    $unique_field_names[] = $field_name;
                }
            }
        }
        sort($unique_field_names);

        // Add CSV header
        $headers = array_merge(['Entry ID', 'Submission Date'], $unique_field_names);
        fputcsv($file_handle, $headers);

        // Add data rows
        foreach ($entries as $entry) {
            $row = array_fill_keys($headers, '');
            $row['Entry ID'] = $entry['entry_id'];
            $row['Submission Date'] = $entry['submission_date'];

            foreach ($entry['form_data'] as $field_name => $field_data) {
                $row[$field_name] = $field_data['value'];
            }

            fputcsv($file_handle, $row);
        }

        fclose($file_handle);
        $csv_files[] = $csv_file;
    }

    return $csv_files;
}

// Function to send email with Bricks CSV attachment
function send_bricks_email($limit = false)
{
    log_bricks_export("Bricks email event triggered.");

    $options = get_option('bricks_form_export_options'); // Reusing the same option name for simplicity
    $to = isset($options['export_emails']) ? $options['export_emails'] : '';
    if (empty($to)) {
        log_bricks_export("No email address provided for Bricks export.");
        return;
    }

    $frequency = isset($options['schedule_frequency']) ? $options['schedule_frequency'] : 'monthly';
    $site_name = get_bloginfo('name');
    $subject = ucfirst($frequency) . " Form Submissions for $site_name";
    $body = "Here's a $frequency update on the form submissions for $site_name:<br><br>";

    // Fetch form data and generate table
    $forms_data = fetch_bricks_data($limit);
    $body .= "<table border='1' cellpadding='5' cellspacing='0' style='text-align: left;'>";
    $body .= "<tr><th style='text-align: left;'>Form Name</th><th style='text-align: left;'>Number of " . ucfirst($frequency) . " Submissions</th></tr>";

    $total_submissions = 0;
    foreach ($forms_data as $form_id => $entries) {
        $form_title = get_bricks_form_title($form_id);
        $form_submissions = count($entries);
        $total_submissions += $form_submissions;
        $body .= "<tr><td style='text-align: left;'>$form_title</td><td style='text-align: left;'>$form_submissions</td></tr>";
    }

    $body .= "<tr><th style='text-align: left;'>Total $frequency Submissions (All Forms)</th><th style='text-align: left;'>$total_submissions</th></tr>";
    $body .= "</table><br><br>";

    $test_email = isset($options['test_email']) ? $options['test_email'] : '';
    $body .= "For further information about the export, please reach out to $test_email.";
    $headers = array('Content-Type: text/html; charset=UTF-8');
    $attachments = export_bricks_data_to_csv($limit);

    log_bricks_export("Sending email to: $to");
    wp_mail($to, $subject, $body, $headers, $attachments);
}

// Hook the scheduled event to the send_bricks_email function
add_action('send_bricks_email_event', 'send_bricks_email');

// Schedule the email function
function schedule_bricks_email_event()
{
    $options = get_option('bricks_form_export_options'); // Reusing the same option name for simplicity
    $frequency = isset($options['schedule_frequency']) ? $options['schedule_frequency'] : 'monthly';
    $emails = isset($options['export_emails']) ? $options['export_emails'] : '';

    if (empty($emails)) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>Error: No email address provided for Bricks export.</p></div>';
        });
        return;
    }

    if (!wp_next_scheduled('send_bricks_email_event')) {
        log_bricks_export("Scheduling Bricks email event for frequency: $frequency");
        if ($frequency === 'weekly') {
            wp_schedule_event(strtotime('next Monday'), 'weekly', 'send_bricks_email_event');
        } elseif ($frequency === 'daily') {
            wp_schedule_event(time(), 'daily', 'send_bricks_email_event');
        } else {
            wp_schedule_event(strtotime('first day of next month midnight'), 'monthly', 'send_bricks_email_event');
        }
    } else {
        log_bricks_export("Bricks email event is already scheduled.");
    }
}

// Clear scheduled event
function clear_bricks_email_schedule()
{
    $timestamp = wp_next_scheduled('send_bricks_email_event');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'send_bricks_email_event');
    }
}

add_action('wp', 'schedule_bricks_email_event');

// Register settings
function bricks_export_register_settings()
{
    register_setting('bricks_form_export_options_group', 'bricks_form_export_options', 'bricks_form_export_options_validate');
}
add_action('admin_init', 'bricks_export_register_settings');

// Validate and sanitize settings
function bricks_form_export_options_validate($input)
{
    $output = [];

    $output['export_emails'] = sanitize_text_field($input['export_emails']);
    if (empty($output['export_emails'])) {
        $output['export_emails'] = '';
    }

    $output['test_email'] = sanitize_text_field($input['test_email']);
    if (empty($output['test_email'])) {
        $output['test_email'] = '';
    }

    $output['test_limit'] = intval($input['test_limit']);
    if ($output['test_limit'] <= 0) {
        $output['test_limit'] = 1;
    }

    $output['schedule_frequency'] = sanitize_text_field($input['schedule_frequency']);
    if (!in_array($output['schedule_frequency'], ['daily', 'weekly', 'monthly'])) {
        $output['schedule_frequency'] = 'monthly';
    }

    return $output;
}

// Add options page
function bricks_export_options_page()
{
    add_menu_page(
        'Automated Bricks Form Export',
        'Bricks Export',
        'manage_options',
        'bricks_form_export', // Reusing the same slug for simplicity
        'bricks_export_options_page_html',
        'dashicons-email-alt',
        30
    );

    add_submenu_page(
        'bricks_form_export', // Reusing the same slug for simplicity
        'Bricks Export Settings',
        'Bricks Export Settings',
        'manage_options',
        'bricks_form_export', // Reusing the same slug for simplicity
        'bricks_export_options_page_html'
    );

    add_submenu_page(
        'bricks_form_export', // Reusing the same slug for simplicity
        'Bricks Export Test Email',
        'Bricks Export Test Email',
        'manage_options',
        'test-bricks-export-email',
        'test_email_button_callback'
    );
}
add_action('admin_menu', 'bricks_export_options_page');

function bricks_export_options_page_html()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $message = '';
    if (isset($_POST['bricks_form_toggle_schedule'])) {
        if (wp_next_scheduled('send_bricks_email_event')) {
            clear_bricks_email_schedule();
            $message = '<div class="updated"><p>Scheduled sending has been canceled.</p></div>';
        } else {
            schedule_bricks_email_event();
            $message = '<div class="updated"><p>Scheduled sending has been started.</p></div>';
        }
    }

    if (isset($_POST['bricks_form_clear_options'])) {
        delete_option('bricks_form_export_options');
        $message = '<div class="updated"><p>All options have been cleared.</p></div>';
    }

?>
    <div class="wrap">
        <h1>Automated Bricks Form Export Settings</h1>
        <?php if ($message) echo $message; ?>

        <!-- Form for updating settings -->
        <form method="post" action="options.php" id="bricks_form_export_settings_form">
            <?php
            settings_fields('bricks_form_export_options_group'); // Reusing the same group for simplicity
            $options = get_option('bricks_form_export_options'); // Reusing the same option for simplicity
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Receiving Email Addresses</th>
                    <td><input type="text" name="bricks_form_export_options[export_emails]" value="<?php echo isset($options['export_emails']) ? esc_attr($options['export_emails']) : ''; ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Admin &amp; Test Email Address</th>
                    <td><input type="text" name="bricks_form_export_options[test_email]" value="<?php echo isset($options['test_email']) ? esc_attr($options['test_email']) : ''; ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Test Export Limit</th>
                    <td><input type="number" name="bricks_form_export_options[test_limit]" value="<?php echo isset($options['test_limit']) ? esc_attr($options['test_limit']) : 1; ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Schedule Frequency</th>
                    <td>
                        <select name="bricks_form_export_options[schedule_frequency]">
                            <option value="daily" <?php selected(isset($options['schedule_frequency']) ? $options['schedule_frequency'] : 'monthly', 'daily'); ?>>Daily</option>
                            <option value="weekly" <?php selected(isset($options['schedule_frequency']) ? $options['schedule_frequency'] : 'monthly', 'weekly'); ?>>Weekly (Mondays)</option>
                            <option value="monthly" <?php selected(isset($options['schedule_frequency']) ? $options['schedule_frequency'] : 'monthly', 'monthly'); ?>>Monthly (First of the month)</option>
                        </select>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <?php submit_button('Save Changes', 'primary', 'submit', false); ?>
            </p>
        </form>

        <!-- Form to start/stop schedule sending -->
        <form method="post" action="">
            <h2>Schedule Sending</h2>
            <p>Use the button below to start or stop scheduled sending of Bricks submissions.</p>
            <p class="submit">
                <button type="submit" name="bricks_form_toggle_schedule" class="button button-secondary"><?php echo wp_next_scheduled('send_bricks_email_event') ? 'Cancel Scheduled Sending' : 'Start Scheduled Sending'; ?></button>
            </p>
        </form>

        <!-- Form to clear all options -->
        <form method="post" action="" onsubmit="return confirm('Are you sure you want to clear all options?');">
            <h2>Clear All Options</h2>
            <p>Use the button below to clear all options. This action cannot be undone.</p>
            <p class="submit">
                <button type="submit" name="bricks_form_clear_options" class="button button-secondary" style="background-color: #d63638; color: white; border-color: #d63638;">Clear All Options</button>
            </p>
        </form>
    </div>

    <script type="text/javascript">
        document.getElementById('bricks_form_export_settings_form').onsubmit = function() {
            var exportEmails = document.querySelector('[name="bricks_form_export_options[export_emails]"]').value;
            var testEmail = document.querySelector('[name="bricks_form_export_options[test_email]"]').value;
            var testLimit = document.querySelector('[name="bricks_form_export_options[test_limit]"]').value;

            if (!exportEmails || !testEmail || !testLimit) {
                alert('Please fill out all fields before saving.');
                return false;
            }

            return true;
        };
    </script>
<?php
}

// Add test email button in admin interface
function test_email_button_callback()
{
    $options = get_option('bricks_form_export_options'); // Reusing the same option for simplicity
    $test_email = isset($options['test_email']) ? $options['test_email'] : '';
    $test_limit = isset($options['test_limit']) ? $options['test_limit'] : 1;

    if (empty($test_email)) {
        echo '<div class="notice notice-error"><p>Error: No test email address provided.</p></div>';
        return;
    }

    if (isset($_POST['send_test_email'])) {
        send_bricks_email($test_limit);
        echo '<div class="updated"><p>Test email with the most recent submission(s) sent successfully to ' . esc_html($test_email) . '!</p></div>';
    }

    echo '<div class="wrap">';
    echo '<h2>Send Test Bricks Export Email</h2>';
    echo '<p>The button below will send a test email to ' . esc_html($test_email) . ' with the most recent submission(s).</p>';
    echo '<form method="post">';
    echo '<input type="hidden" name="send_test_email" value="true">';
    submit_button('Send Test Email');
    echo '</form>';
    echo '</div>';
}
?>
