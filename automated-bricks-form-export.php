<?php
/*
Plugin Name: Automated Bricks Form Export
Description: Automates the export of Bricks Builder form submissions to CSV and emails them on a scheduled basis.
Version: 1.0.6
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
    log_bricks_export("Fetching form title for form ID: $form_id");
    $form_title = \Bricks\Integrations\Form\Submission_Database::get_form_name_by_id($form_id);
    log_bricks_export("Form title retrieved: $form_title");
    return $form_title;
}

// Function to fetch Bricks form field labels
function get_bricks_form_field_labels($post_id, $form_id)
{
    $form_settings = \Bricks\Integrations\Form\Submission_Database::get_form_settings($post_id, $form_id);
    $field_labels = [];

    if (!empty($form_settings['fields'])) {
        foreach ($form_settings['fields'] as $field) {
            $field_id = isset($field['id']) ? $field['id'] : '';
            $field_label = isset($field['label']) ? $field['label'] : $field_id;
            if ($field_id) {
                $field_labels[$field_id] = $field_label;
            }
        }
    }

    return $field_labels;
}

// Function to fetch Bricks data by form ID
function fetch_bricks_data($form_id, $limit = false)
{
    log_bricks_export("Fetching data for form ID: $form_id with limit: " . ($limit ? $limit : 'no limit'));

    $forms_data = [];
    $args = [
        'form_id'  => $form_id,
        'order_by' => 'id',
        'order'    => 'DESC',
    ];

    if ($limit) {
        $args['limit'] = $limit;
    }

    log_bricks_export("Querying the database with arguments: " . print_r($args, true));
    $entries = \Bricks\Integrations\Form\Submission_Database::get_entries($args);

    if (empty($entries)) {
        log_bricks_export("No entries found for form ID: $form_id");
        return $forms_data;
    }

    log_bricks_export("Number of entries found: " . count($entries));

    // Fetch the field labels using the first entry's post_id (assuming all entries belong to the same form setup)
    $first_entry = reset($entries);
    $post_id = $first_entry['post_id']; // Assuming 'post_id' is available in your entries
    $field_labels = get_bricks_form_field_labels($post_id, $form_id);

    foreach ($entries as $entry) {
        $form_data = json_decode($entry['form_data'], true);
        log_bricks_export("Processing entry ID: " . $entry['id'] . ", Form Data: " . print_r($form_data, true));

        $entry_data = [
            'Entry ID' => $entry['id'],
            'Submission Date' => $entry['created_at'],
            'Browser' => $entry['browser'],
            'IP Address' => $entry['ip'],
            'OS' => $entry['os'],
            'Referrer' => $entry['referrer'],
            'User ID' => $entry['user_id'],
        ];

        if (is_array($form_data)) {
            foreach ($form_data as $field_key => $field_info) {
                $field_label = isset($field_labels[$field_key]) ? $field_labels[$field_key] : $field_key;
                $field_value = isset($field_info['value']) ? $field_info['value'] : '';
                $entry_data[$field_label] = is_array($field_value) ? implode(', ', $field_value) : $field_value;
            }
        }

        $forms_data[] = $entry_data;
    }

    log_bricks_export("Finished processing entries for form ID: $form_id");
    return $forms_data;
}

// Function to export Bricks data to CSV with correct headers
function export_bricks_data_to_csv($form_id, $count, $limit = false)
{
    log_bricks_export("Exporting data to CSV for form ID: $form_id with limit: " . ($limit ? $limit : 'no limit'));
    $entries = fetch_bricks_data($form_id, $limit);
    if (empty($entries)) {
        log_bricks_export("No entries to export for form ID: $form_id");
        return false;
    }

    $form_title = get_bricks_form_title($form_id);
    $csv_file = plugin_dir_path(__FILE__) . "bricks_submissions_{$form_title}_{$count}.csv";  // Use the count in the filename
    $file_handle = fopen($csv_file, 'w');

    // Prepare headers from the first entry
    $headers = array_keys($entries[0]);

    // Write the CSV headers
    fputcsv($file_handle, $headers);

    // Write the data
    foreach ($entries as $entry) {
        $row = [];
        foreach ($headers as $header) {
            $row[] = $entry[$header] ?? '';  // Use the header to map the data correctly
        }
        fputcsv($file_handle, $row);
    }

    fclose($file_handle);

    log_bricks_export("CSV file created: $csv_file");
    return $csv_file;
}

// Function to send email with Bricks CSV attachment
function send_bricks_email($limit = false)
{
    log_bricks_export("Bricks email event triggered.");

    $options = get_option('bricks_form_export_options');
    $to = isset($options['export_emails']) ? $options['export_emails'] : '';
    if (empty($to)) {
        log_bricks_export("No email address provided for Bricks export.");
        return;
    }

    $frequency = isset($options['schedule_frequency']) ? $options['schedule_frequency'] : 'monthly';
    $site_name = get_bloginfo('name');
    $subject = ucfirst($frequency) . " Form Submissions for $site_name";
    $body = "Here's a $frequency update on the form submissions for $site_name:<br><br>";

    log_bricks_export("Fetching form data to include in the email.");
    $available_form_ids = get_available_form_ids();
    if (empty($available_form_ids)) {
        log_bricks_export("No forms found to include in the email.");
        return;
    }

    $total_submissions = 0;
    $body .= "<table border='1' cellpadding='5' cellspacing='0' style='text-align: left;'>";
    $body .= "<tr><th style='text-align: left;'>Form Name</th><th style='text-align: left;'>Number of " . ucfirst($frequency) . " Submissions</th></tr>";

    $attachments = [];
    $count = 1;  // Initialize the counter

    foreach ($available_form_ids as $form_id) {
        $entries = fetch_bricks_data($form_id, $limit);
        $form_title = get_bricks_form_title($form_id);
        $form_submissions = count($entries);
        $total_submissions += $form_submissions;
        $body .= "<tr><td style='text-align: left;'>$form_title</td><td style='text-align: left;'>$form_submissions</td></tr>";

        // Generate CSV for each form with the count appended
        $attachment = export_bricks_data_to_csv($form_id, $count, $limit);
        if ($attachment) {
            $attachments[] = $attachment;
        }
        $count++;  // Increment the counter
    }

    $body .= "<tr><th style='text-align: left;'>Total $frequency Submissions (All Forms)</th><th style='text-align: left;'>$total_submissions</th></tr>";
    $body .= "</table><br><br>";

    $test_email = isset($options['test_email']) ? $options['test_email'] : '';
    $body .= "For further information about the export, please reach out to $test_email.";
    $headers = array('Content-Type: text/html; charset=UTF-8');

    log_bricks_export("Sending email to: $to with attachments: " . implode(', ', $attachments));
    wp_mail($to, $subject, $body, $headers, $attachments);
}

// Fetches available form IDs from the database
function get_available_form_ids() {
    global $wpdb;
    $table_name = \Bricks\Integrations\Form\Submission_Database::get_table_name();
    $query = "SELECT DISTINCT form_id FROM {$table_name}";
    $results = $wpdb->get_col($query);
    return $results;
}

// Hook the scheduled event to the send_bricks_email function
add_action('send_bricks_email_event', 'send_bricks_email');

// Schedule the email function
function schedule_bricks_email_event()
{
    $options = get_option('bricks_form_export_options');
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
        'bricks_form_export',
        'bricks_export_options_page_html',
        'dashicons-email-alt',
        30
    );

    add_submenu_page(
        'bricks_form_export',
        'Bricks Export Settings',
        'Bricks Export Settings',
        'manage_options',
        'bricks_form_export',
        'bricks_export_options_page_html'
    );

    add_submenu_page(
        'bricks_form_export',
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

        <form method="post" action="options.php" id="bricks_form_export_settings_form">
            <?php
            settings_fields('bricks_form_export_options_group');
            $options = get_option('bricks_form_export_options');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Receiving Email Addresses</th>
                    <td><input type="text" name="bricks_form_export_options[export_emails]" value="<?php echo isset($options['export_emails']) ? esc_attr($options['export_emails']) : ''; ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Admin & Test Email Address</th>
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

        <form method="post" action="">
            <h2>Schedule Sending</h2>
            <p>Use the button below to start or stop scheduled sending of Bricks submissions.</p>
            <p class="submit">
                <button type="submit" name="bricks_form_toggle_schedule" class="button button-secondary"><?php echo wp_next_scheduled('send_bricks_email_event') ? 'Cancel Scheduled Sending' : 'Start Scheduled Sending'; ?></button>
            </p>
        </form>

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
    $options = get_option('bricks_form_export_options');
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
