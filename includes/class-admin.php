<?php
/**
 * Admin functionality for Priority Ticket Payment
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Priority_Ticket_Payment_Admin {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_pages'));
        add_action('wp_ajax_priority_ticket_payment_update_status', array($this, 'ajax_update_status'));
        add_action('wp_ajax_priority_ticket_payment_delete_submission', array($this, 'ajax_delete_submission'));
        add_action('wp_ajax_priority_ticket_payment_manual_cleanup', array($this, 'ajax_manual_cleanup'));
        add_action('wp_ajax_priority_ticket_payment_get_cleanup_stats', array($this, 'ajax_get_cleanup_stats'));
        add_action('wp_ajax_priority_ticket_payment_create_ticket', array($this, 'ajax_create_ticket'));
        add_action('wp_ajax_priority_ticket_payment_view_submission', array($this, 'ajax_view_submission'));
        
        // Add settings validation
        add_filter('pre_update_option_priority_ticket_payment_settings', array($this, 'validate_settings'), 10, 2);
    }
    
    /**
     * Initialize admin functionality
     */
    public function init() {
        // Register settings
        register_setting('priority_ticket_payment_settings', 'priority_ticket_payment_settings');
        
        // Add settings sections and fields
        $this->add_settings_sections();
    }
    
    /**
     * Add settings sections and fields
     */
    private function add_settings_sections() {
        // Essential Integration Settings
        add_settings_section(
            'priority_ticket_payment_integrations',
            __('Plugin Integrations', 'priority-ticket-payment'),
            array($this, 'integration_settings_callback'),
            'priority_ticket_payment_settings'
        );
        
        // Form Configuration
        add_settings_section(
            'priority_ticket_payment_form_mapping',
            __('Form Configuration', 'priority-ticket-payment'),
            array($this, 'form_mapping_settings_callback'),
            'priority_ticket_payment_settings'
        );
        
        // Payment Settings
        add_settings_section(
            'priority_ticket_payment_payment',
            __('Payment Settings', 'priority-ticket-payment'),
            array($this, 'payment_settings_callback'),
            'priority_ticket_payment_settings'
        );
        
        // File & Cleanup Settings
        add_settings_section(
            'priority_ticket_payment_system',
            __('System Settings', 'priority-ticket-payment'),
            array($this, 'system_settings_callback'),
            'priority_ticket_payment_settings'
        );
        
        // Add fields
        $this->add_settings_fields();
    }
    
    /**
     * Add settings fields
     */
    private function add_settings_fields() {
        $fields = array(
            // Integration Settings - Essential for plugin functionality
            array(
                'id' => 'enable_elementor_integration',
                'title' => __('Enable Elementor Pro Integration', 'priority-ticket-payment'),
                'callback' => 'render_checkbox_field',
                'section' => 'priority_ticket_payment_integrations',
                'args' => array()
            ),
            array(
                'id' => 'enable_woocommerce_integration',
                'title' => __('Enable WooCommerce Integration', 'priority-ticket-payment'),
                'callback' => 'render_checkbox_field',
                'section' => 'priority_ticket_payment_integrations',
                'args' => array()
            ),
            array(
                'id' => 'enable_awesome_support_integration',
                'title' => __('Enable Awesome Support Integration', 'priority-ticket-payment'),
                'callback' => 'render_checkbox_field',
                'section' => 'priority_ticket_payment_integrations',
                'args' => array()
            ),
            
            // Form Configuration - Core settings for the priority system
            array(
                'id' => 'ticket_form_id_a',
                'title' => __('Legacy Form ID (50€)', 'priority-ticket-payment'),
                'callback' => 'render_text_field',
                'section' => 'priority_ticket_payment_form_mapping',
                'args' => array('type' => 'text', 'placeholder' => 'Enter Elementor Form ID')
            ),
            array(
                'id' => 'ticket_form_id_b',
                'title' => __('Standard Form ID (50€)', 'priority-ticket-payment'),
                'callback' => 'render_text_field',
                'section' => 'priority_ticket_payment_form_mapping',
                'args' => array('type' => 'text', 'placeholder' => 'Enter Elementor Form ID')
            ),
            array(
                'id' => 'ticket_form_id_c',
                'title' => __('Basic Form ID (100€)', 'priority-ticket-payment'),
                'callback' => 'render_text_field',
                'section' => 'priority_ticket_payment_form_mapping',
                'args' => array('type' => 'text', 'placeholder' => 'Enter Elementor Form ID')
            ),

            array(
                'id' => 'ticket_form_id_d',
                'title' => __('Free Ticket Form ID (0€)', 'priority-ticket-payment'),
                'callback' => 'render_text_field',
                'section' => 'priority_ticket_payment_form_mapping',
                'args' => array(
                    'type' => 'text', 
                    'placeholder' => 'Enter Elementor Form ID',
                    'description' => __('Form for creating free tickets. Only works for logged-in users.', 'priority-ticket-payment')
                )
            ),

            array(
                'id' => 'additional_form_ids',
                'title' => __('Additional Form IDs', 'priority-ticket-payment'),
                'callback' => 'render_text_field',
                'section' => 'priority_ticket_payment_form_mapping',
                'args' => array(
                    'type' => 'text', 
                    'placeholder' => 'f755476, form123, another-form',
                    'description' => __('Comma-separated list of additional Elementor Form IDs that should work with priority tickets. User priority will be determined automatically based on their account.', 'priority-ticket-payment')
                )
            ),

            array(
                'id' => 'product_id_b',
                'title' => __('Standard Product ID (50€)', 'priority-ticket-payment'),
                'callback' => 'render_text_field',
                'section' => 'priority_ticket_payment_form_mapping',
                'args' => array('type' => 'number', 'min' => '1', 'placeholder' => 'WooCommerce Product ID')
            ),
            array(
                'id' => 'product_id_c',
                'title' => __('Basic Product ID (100€)', 'priority-ticket-payment'),
                'callback' => 'render_text_field',
                'section' => 'priority_ticket_payment_form_mapping',
                'args' => array('type' => 'number', 'min' => '1', 'placeholder' => 'WooCommerce Product ID')
            ),
            array(
                'id' => 'coaching_product_ids',
                'title' => __('Coaching Product IDs', 'priority-ticket-payment'),
                'callback' => 'render_text_field',
                'section' => 'priority_ticket_payment_form_mapping',
                'args' => array(
                    'type' => 'text', 
                    'placeholder' => '123,456,789',
                    'description' => __('Comma-separated product IDs that qualify users for 50€ tickets', 'priority-ticket-payment')
                )
            ),
            
            // Payment Settings - Payment and checkout behavior
            array(
                'id' => 'custom_thank_you_page_url',
                'title' => __('Custom Thank You Page URL', 'priority-ticket-payment'),
                'callback' => 'render_text_field',
                'section' => 'priority_ticket_payment_payment',
                'args' => array(
                    'type' => 'url', 
                    'placeholder' => 'https://example.com/thank-you',
                    'description' => __('Users will be redirected to this page after a paid ticket is completed. Leave blank to use the default WooCommerce thank-you page.', 'priority-ticket-payment'),
                    'tooltip' => __('Use a full URL like https://example.com/thank-you to send users to a custom page after purchase.', 'priority-ticket-payment')
                )
            ),
            array(
                'id' => 'priority_ticket_admin_email',
                'title' => __('Admin Notification Email', 'priority-ticket-payment'),
                'callback' => 'render_text_field',
                'section' => 'priority_ticket_payment_payment',
                'args' => array(
                    'type' => 'email', 
                    'placeholder' => 'admin@example.com',
                    'description' => __('Address where new ticket alerts are sent. Leave blank to use WordPress admin email.', 'priority-ticket-payment')
                )
            ),
            
            // Client Reply Notification Settings
            array(
                'id' => 'send_reply_notifications',
                'title' => __('Send Reply Notifications to Clients', 'priority-ticket-payment'),
                'callback' => 'render_checkbox_field',
                'section' => 'priority_ticket_payment_payment',
                'args' => array(
                    'description' => __('When enabled, clients receive an email notification when coaches reply to their tickets.', 'priority-ticket-payment')
                )
            ),
            
            array(
                'id' => 'reply_notification_subject',
                'title' => __('Reply Notification Subject', 'priority-ticket-payment'),
                'callback' => 'render_text_field',
                'section' => 'priority_ticket_payment_payment',
                'args' => array(
                    'type' => 'text',
                    'placeholder' => 'Antwort auf Ihr Coaching Ticket',
                    'description' => __('Email subject line when clients receive reply notifications. Leave blank for default.', 'priority-ticket-payment')
                )
            ),
            
            array(
                'id' => 'reply_notification_template',
                'title' => __('Reply Notification Template', 'priority-ticket-payment'),
                'callback' => 'render_textarea_field',
                'section' => 'priority_ticket_payment_payment',
                'args' => array(
                    'rows' => 8,
                    'placeholder' => 'Leave blank for default template...',
                    'description' => __('Custom HTML email template for reply notifications. Use [customer_name] and [ticket_link] as placeholders. Leave blank for default.', 'priority-ticket-payment')
                )
            ),
            
            // System Settings - Essential maintenance settings
            array(
                'id' => 'max_file_size',
                'title' => __('Max File Size (MB)', 'priority-ticket-payment'),
                'callback' => 'render_text_field',
                'section' => 'priority_ticket_payment_system',
                'args' => array('type' => 'number', 'min' => '1', 'max' => '50', 'placeholder' => '10')
            ),
            array(
                'id' => 'max_attachments',
                'title' => __('Maximum Number of Attachments', 'priority-ticket-payment'),
                'callback' => 'render_text_field',
                'section' => 'priority_ticket_payment_system',
                'args' => array(
                    'type' => 'number', 
                    'min' => '1', 
                    'max' => '5', 
                    'placeholder' => '3',
                    'tooltip' => __('Only applies to paid ticket forms (A and B tiers). Users can upload up to this number of files.', 'priority-ticket-payment')
                )
            ),
            array(
                'id' => 'cleanup_enabled',
                'title' => __('Auto Cleanup', 'priority-ticket-payment'),
                'callback' => 'render_checkbox_field',
                'section' => 'priority_ticket_payment_system',
                'args' => array('description' => 'Delete old pending submissions (48 hours)')
            ),
        );
        
        foreach ($fields as $field) {
            add_settings_field(
                $field['id'],
                $field['title'],
                array($this, $field['callback']),
                'priority_ticket_payment_settings',
                $field['section'],
                array_merge(array('id' => $field['id']), $field['args'])
            );
        }
    }
    
    /**
     * Settings section callbacks
     */
    public function integration_settings_callback() {
        echo '<p>' . __('Configure integrations with Elementor Pro, WooCommerce, and Awesome Support.', 'priority-ticket-payment') . '</p>';
    }
    
    public function form_mapping_settings_callback() {
        echo '<div style="background: #f0f8ff; padding: 15px; border-left: 4px solid #0073aa; margin-bottom: 20px;">';
        echo '<h4 style="margin-top: 0;">🎯 How Priority Detection Works:</h4>';
        echo '<ol style="margin: 0;">';
        echo '<li><strong>Standard (50€):</strong> Users who purchased <em>coaching products</em> (specified below)</li>';
        echo '<li><strong>Basic (100€):</strong> Guest users or users with no qualifying purchases</li>';
        echo '<li><strong>Free (0€):</strong> Logged-in users using the designated free form only</li>';
        echo '</ol>';
        echo '<p style="margin-bottom: 0;"><strong>Note:</strong> Coaching clients get discounted 50€ tickets. All other users pay 100€ for basic support. Free forms require user login and create tickets immediately without payment.</p>';
        echo '</div>';
    }
    
    public function payment_settings_callback() {
        echo '<p>' . __('Configure payment and checkout behavior settings.', 'priority-ticket-payment') . '</p>';
    }
    
    public function system_settings_callback() {
        echo '<p>' . __('System maintenance and file upload settings.', 'priority-ticket-payment') . '</p>';
    }
    
    /**
     * Field rendering methods
     */
    public function render_text_field($args) {
        $id = $args['id'];
        $type = isset($args['type']) ? $args['type'] : 'text';
        $settings = get_option('priority_ticket_payment_settings', array());
        $value = isset($settings[$id]) ? $settings[$id] : '';
        $attributes = '';
        
        foreach ($args as $key => $val) {
            if (in_array($key, array('min', 'max', 'step', 'maxlength', 'placeholder'))) {
                $attributes .= sprintf(' %s="%s"', $key, esc_attr($val));
            }
        }
        
        printf(
            '<input type="%s" id="%s" name="priority_ticket_payment_settings[%s]" value="%s" class="regular-text"%s />',
            esc_attr($type),
            esc_attr($id),
            esc_attr($id),
            esc_attr($value),
            $attributes
        );
        
        // Add helper text for form mapping fields
        if (in_array($id, array('ticket_form_id_a', 'ticket_form_id_b', 'ticket_form_id_c', 'ticket_form_id_d'))) {
            echo '<p class="description">' . __('Find this in Elementor Editor → Form Settings → Advanced → Form ID', 'priority-ticket-payment') . '</p>';
        }
        
        // Add specific description for free form field
        if ($id === 'ticket_form_id_d') {
            $description = isset($args['description']) ? $args['description'] : '';
            if ($description) {
                echo '<p class="description" style="color: #d63638; font-weight: 600;">' . esc_html($description) . '</p>';
            }
        }
        
        if (in_array($id, array('product_id_a', 'product_id_b', 'product_id_c'))) {
            echo '<p class="description">' . __('WooCommerce product for this tier. Leave empty to auto-create.', 'priority-ticket-payment') . '</p>';
        }
        
        if ($id === 'coaching_product_ids') {
            $description = isset($args['description']) ? $args['description'] : '';
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        
        if ($id === 'max_file_size') {
            echo '<p class="description">' . __('Maximum file size for user uploads (1-50 MB recommended)', 'priority-ticket-payment') . '</p>';
        }
        
        if ($id === 'max_attachments') {
            $description = __('Default: 3 files per submission', 'priority-ticket-payment');
            $tooltip = isset($args['tooltip']) ? $args['tooltip'] : '';
            
            echo '<p class="description">' . esc_html($description);
            
            if ($tooltip) {
                echo ' <span class="dashicons dashicons-info" style="font-size: 16px; vertical-align: middle; color: #72777c; cursor: help;" title="' . esc_attr($tooltip) . '"></span>';
            }
            
            echo '</p>';
        }
        
        if ($id === 'custom_thank_you_page_url') {
            $description = isset($args['description']) ? $args['description'] : '';
            $tooltip = isset($args['tooltip']) ? $args['tooltip'] : '';
            
            echo '<p class="description">' . esc_html($description);
            
            if ($tooltip) {
                echo ' <span class="dashicons dashicons-info" style="font-size: 16px; vertical-align: middle; color: #72777c; cursor: help;" title="' . esc_attr($tooltip) . '"></span>';
            }
            
            echo '</p>';
        }
        
        if ($id === 'priority_ticket_admin_email') {
            $description = isset($args['description']) ? $args['description'] : '';
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        
        if ($id === 'reply_notification_subject') {
            $description = isset($args['description']) ? $args['description'] : '';
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
    }
    
    public function render_checkbox_field($args) {
        $id = $args['id'];
        $settings = get_option('priority_ticket_payment_settings', array());
        $value = isset($settings[$id]) ? $settings[$id] : 'no';
        
        printf(
            '<input type="checkbox" id="%s" name="priority_ticket_payment_settings[%s]" value="yes" %s />',
            esc_attr($id),
            esc_attr($id),
            checked('yes', $value, false)
        );
        
        // Add description for notification settings
        if ($id === 'send_reply_notifications') {
            $description = isset($args['description']) ? $args['description'] : '';
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
    }
    
    public function render_textarea_field($args) {
        $id = $args['id'];
        $settings = get_option('priority_ticket_payment_settings', array());
        $value = isset($settings[$id]) ? $settings[$id] : '';
        $rows = isset($args['rows']) ? intval($args['rows']) : 5;
        $placeholder = isset($args['placeholder']) ? $args['placeholder'] : '';
        
        printf(
            '<textarea id="%s" name="priority_ticket_payment_settings[%s]" rows="%d" class="large-text" placeholder="%s">%s</textarea>',
            esc_attr($id),
            esc_attr($id),
            $rows,
            esc_attr($placeholder),
            esc_textarea($value)
        );
        
        if ($id === 'reply_notification_template') {
            $description = isset($args['description']) ? $args['description'] : '';
            echo '<p class="description">' . esc_html($description) . '</p>';
            echo '<p class="description"><strong>Available placeholders:</strong> [customer_name], [ticket_link]</p>';
        }
    }
    
    /**
     * Render text input field for settings
     */
    public static function render_text_input($args) {
        $option_name = $args['label_for'];
        $value = Priority_Ticket_Payment::get_option($option_name, '');
        printf(
            '<input type="text" name="priority_ticket_payment_settings[%s]" id="%s" value="%s" class="regular-text" />',
            esc_attr($option_name),
            esc_attr($option_name),
            esc_attr($value)
        );

        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }
    
    /**
     * Render checkbox input field for settings
     */
    public static function render_checkbox_input($args) {
        $option_name = $args['label_for'];
        $value = Priority_Ticket_Payment::get_option($option_name, false);
        printf(
            '<input type="checkbox" name="priority_ticket_payment_settings[%s]" id="%s" value="1" %s />',
            esc_attr($option_name),
            esc_attr($option_name),
            checked(1, $value, false)
        );

        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }
    
    /**
     * Add admin pages
     */
    public function add_admin_pages() {
        // Main menu already added in main class
        // Add submenu pages here if needed
    }
    
    /**
     * Render submissions list page
     */
    public static function render_submissions_page() {
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($current_page - 1) * $per_page;
        
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        
        $args = array(
            'limit' => $per_page,
            'offset' => $offset,
        );
        
        if (!empty($status_filter)) {
            $args['payment_status'] = $status_filter;
        }
        
        $submissions = Priority_Ticket_Payment_Database::get_submissions($args);
        $total_items = Priority_Ticket_Payment_Database::get_submissions_count($args);
        $total_pages = ceil($total_items / $per_page);
        
        ?>
        <div class="wrap">
            <h1><?php _e('Priority Ticket Submissions', 'priority-ticket-payment'); ?></h1>
            
            <!-- Status Filter -->
            <div class="tablenav top">
                <div class="alignleft actions">
                    <select name="status" id="status-filter">
                        <option value=""><?php _e('All Statuses', 'priority-ticket-payment'); ?></option>
                        <option value="pending" <?php selected($status_filter, 'pending'); ?>><?php _e('Pending', 'priority-ticket-payment'); ?></option>
                        <option value="processing" <?php selected($status_filter, 'processing'); ?>><?php _e('Processing', 'priority-ticket-payment'); ?></option>
                        <option value="completed" <?php selected($status_filter, 'completed'); ?>><?php _e('Completed', 'priority-ticket-payment'); ?></option>
                        <option value="failed" <?php selected($status_filter, 'failed'); ?>><?php _e('Failed', 'priority-ticket-payment'); ?></option>
                        <option value="refunded" <?php selected($status_filter, 'refunded'); ?>><?php _e('Refunded', 'priority-ticket-payment'); ?></option>
                    </select>
                    <button type="button" id="filter-submit" class="button"><?php _e('Filter', 'priority-ticket-payment'); ?></button>
                </div>
            </div>
            
            <!-- Submissions Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col" class="manage-column column-id"><?php _e('ID', 'priority-ticket-payment'); ?></th>
                        <th scope="col" class="manage-column column-user"><?php _e('User', 'priority-ticket-payment'); ?></th>
                        <th scope="col" class="manage-column column-subject"><?php _e('Subject', 'priority-ticket-payment'); ?></th>
                        <th scope="col" class="manage-column column-price"><?php _e('Price', 'priority-ticket-payment'); ?></th>
                        <th scope="col" class="manage-column column-status"><?php _e('Status', 'priority-ticket-payment'); ?></th>
                        <th scope="col" class="manage-column column-date"><?php _e('Date', 'priority-ticket-payment'); ?></th>
                        <th scope="col" class="manage-column column-actions"><?php _e('Actions', 'priority-ticket-payment'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($submissions)) : ?>
                        <tr>
                            <td colspan="7"><?php _e('No submissions found.', 'priority-ticket-payment'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($submissions as $submission) : ?>
                            <tr>
                                <td><?php echo esc_html($submission['id']); ?></td>
                                <td>
                                    <?php
                                    $user = get_user_by('id', $submission['user_id']);
                                    echo $user ? esc_html($user->display_name) : __('Unknown User', 'priority-ticket-payment');
                                    ?>
                                </td>
                                                <td>
                    <?php
                    // Extract subject from form_data
                    $form_data = $submission['form_data'];
                    if (is_string($form_data)) {
                        $form_data = unserialize($form_data);
                    }
                    
                    $subject = '';
                    if (is_array($form_data) && isset($form_data['subject']) && !empty(trim($form_data['subject']))) {
                        $subject = sanitize_text_field(trim($form_data['subject']));
                    }
                    
                    if (empty($subject)) {
                        echo '<span title="' . esc_attr(__('This field is submitted by the user via the Elementor form and used as the ticket title.', 'priority-ticket-payment')) . '" style="font-style: italic; color: #666;">' . esc_html(__('(No subject)', 'priority-ticket-payment')) . '</span>';
                    } else {
                        $display_subject = strlen($subject) > 40 ? substr($subject, 0, 40) . '...' : $subject;
                        echo '<span title="' . esc_attr($subject . ' - ' . __('This field is submitted by the user via the Elementor form and used as the ticket title.', 'priority-ticket-payment')) . '">' . esc_html($display_subject) . '</span>';
                    }
                    ?>
                </td>
                                <td><?php echo esc_html(Priority_Ticket_Payment::get_option('currency_symbol', '$') . number_format($submission['price'], 2)); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo esc_attr($submission['payment_status']); ?>">
                                        <?php echo esc_html(ucfirst($submission['payment_status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($submission['created_at']))); ?></td>
                                <td>
                                    <button type="button" class="button button-small view-submission" data-id="<?php echo esc_attr($submission['id']); ?>">
                                        <?php _e('View', 'priority-ticket-payment'); ?>
                                    </button>
                                    <?php if ($submission['payment_status'] === 'completed' && empty($submission['awesome_support_ticket_id'])): ?>
                                        <button type="button" class="button button-small button-primary create-ticket" data-id="<?php echo esc_attr($submission['id']); ?>">
                                            <?php _e('Create Ticket', 'priority-ticket-payment'); ?>
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="button button-small delete-submission" data-id="<?php echo esc_attr($submission['id']); ?>">
                                        <?php _e('Delete', 'priority-ticket-payment'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => __('&laquo; Previous'),
                            'next_text' => __('Next &raquo;'),
                            'total' => $total_pages,
                            'current' => $current_page,
                        ));
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <style>
        .column-subject {
            width: 25%;
        }
        .column-id {
            width: 8%;
        }
        .column-user {
            width: 15%;
        }
        .column-price {
            width: 10%;
        }
        .column-status {
            width: 12%;
        }
        .column-date {
            width: 15%;
        }
        .column-actions {
            width: 15%;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('#filter-submit').on('click', function() {
                var status = $('#status-filter').val();
                var url = new URL(window.location);
                if (status) {
                    url.searchParams.set('status', status);
                } else {
                    url.searchParams.delete('status');
                }
                url.searchParams.delete('paged');
                window.location.href = url.toString();
            });
            
            // Handle Create Ticket button
            $('.create-ticket').on('click', function() {
                var submissionId = $(this).data('id');
                var $btn = $(this);
                
                if (!confirm('<?php _e('Are you sure you want to create a support ticket for this completed order?', 'priority-ticket-payment'); ?>')) {
                    return;
                }
                
                $btn.prop('disabled', true).text('<?php _e('Creating...', 'priority-ticket-payment'); ?>');
                
                $.post(priority_ticket_payment_ajax.ajax_url, {
                    action: 'priority_ticket_payment_create_ticket',
                    submission_id: submissionId,
                    nonce: priority_ticket_payment_ajax.nonce
                }, function(response) {
                    if (response.success) {
                        alert('<?php _e('Ticket created successfully!', 'priority-ticket-payment'); ?>');
                        location.reload(); // Refresh to update the buttons
                    } else {
                        alert('<?php _e('Error: ', 'priority-ticket-payment'); ?>' + response.data);
                        $btn.prop('disabled', false).text('<?php _e('Create Ticket', 'priority-ticket-payment'); ?>');
                    }
                }).fail(function() {
                    alert('<?php _e('AJAX request failed', 'priority-ticket-payment'); ?>');
                    $btn.prop('disabled', false).text('<?php _e('Create Ticket', 'priority-ticket-payment'); ?>');
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render settings page
     */
    public static function render_settings_page() {
        if (isset($_GET['settings-updated'])) {
            add_settings_error('priority_ticket_payment_messages', 'priority_ticket_payment_message', __('Settings saved.', 'priority-ticket-payment'), 'updated');
        }
        
        settings_errors('priority_ticket_payment_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('priority_ticket_payment_settings');
                do_settings_sections('priority_ticket_payment_settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Render cleanup page
     */
    public static function render_cleanup_page() {
        // Get cleanup stats
        $stats = Priority_Ticket_Payment_Database::get_cleanup_stats();
        $file_stats = Priority_Ticket_Payment_Elementor_Utils::get_file_size_stats();
        $next_cleanup = wp_next_scheduled('priority_ticket_payment_daily_cleanup');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="notice notice-info">
                <p><?php _e('This page allows you to manage the cleanup of old pending submissions and their associated files.', 'priority-ticket-payment'); ?></p>
            </div>
            
            <div class="postbox-container" style="width: 70%;">
                <div class="meta-box-sortables">
                    
                    <!-- Statistics -->
                    <div class="postbox">
                        <h2 class="hndle"><span><?php _e('Cleanup Statistics', 'priority-ticket-payment'); ?></span></h2>
                        <div class="inside">
                            <table class="widefat striped">
                                <tbody>
                                    <tr>
                                        <td><strong><?php _e('Old Pending Submissions (48+ hours)', 'priority-ticket-payment'); ?></strong></td>
                                        <td><?php echo esc_html($stats['old_pending_submissions']); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong><?php _e('Total Pending Submissions', 'priority-ticket-payment'); ?></strong></td>
                                        <td><?php echo esc_html($stats['total_pending_submissions']); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong><?php _e('Completed Submissions', 'priority-ticket-payment'); ?></strong></td>
                                        <td><?php echo esc_html($stats['completed_submissions']); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong><?php _e('Upload Directory Files', 'priority-ticket-payment'); ?></strong></td>
                                        <td><?php echo esc_html($file_stats['file_count']); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong><?php _e('Total File Storage', 'priority-ticket-payment'); ?></strong></td>
                                        <td><?php echo esc_html($file_stats['total_size_formatted']); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong><?php _e('Next Scheduled Cleanup', 'priority-ticket-payment'); ?></strong></td>
                                        <td><?php echo $next_cleanup ? esc_html(date('Y-m-d H:i:s', $next_cleanup)) : __('Not scheduled', 'priority-ticket-payment'); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Manual Cleanup -->
                    <div class="postbox">
                        <h2 class="hndle"><span><?php _e('Manual Cleanup', 'priority-ticket-payment'); ?></span></h2>
                        <div class="inside">
                            <p><?php _e('You can manually trigger the cleanup process to remove old pending submissions and their files.', 'priority-ticket-payment'); ?></p>
                            
                            <?php if ($stats['old_pending_submissions'] > 0): ?>
                                <div class="notice notice-warning inline">
                                    <p><?php printf(__('There are %d pending submissions older than 48 hours that will be deleted.', 'priority-ticket-payment'), $stats['old_pending_submissions']); ?></p>
                                </div>
                            <?php else: ?>
                                <div class="notice notice-success inline">
                                    <p><?php _e('No old pending submissions found that need cleanup.', 'priority-ticket-payment'); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <p>
                                <button type="button" id="manual-cleanup-btn" class="button button-secondary">
                                    <?php _e('Run Manual Cleanup', 'priority-ticket-payment'); ?>
                                </button>
                                <button type="button" id="refresh-stats-btn" class="button">
                                    <?php _e('Refresh Statistics', 'priority-ticket-payment'); ?>
                                </button>
                            </p>
                            
                            <div id="cleanup-results" style="display: none;"></div>
                        </div>
                    </div>
                    
                </div>
            </div>
            
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#manual-cleanup-btn').on('click', function() {
                    var $btn = $(this);
                    var $results = $('#cleanup-results');
                    
                    $btn.prop('disabled', true).text('<?php _e('Running cleanup...', 'priority-ticket-payment'); ?>');
                    $results.hide();
                    
                    $.post(ajaxurl, {
                        action: 'priority_ticket_payment_manual_cleanup',
                        nonce: '<?php echo wp_create_nonce('priority_ticket_payment_admin_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            $results.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>').show();
                            // Refresh statistics after cleanup
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            $results.html('<div class="notice notice-error inline"><p>' + response.data + '</p></div>').show();
                        }
                    }).always(function() {
                        $btn.prop('disabled', false).text('<?php _e('Run Manual Cleanup', 'priority-ticket-payment'); ?>');
                    });
                });
                
                $('#refresh-stats-btn').on('click', function() {
                    location.reload();
                });
            });
            </script>
        </div>
        <?php
    }
    
    /**
     * AJAX handler for updating submission status
     */
    public function ajax_update_status() {
        // Verify nonce and permissions
        if (!check_ajax_referer('priority_ticket_payment_admin_nonce', 'nonce', false) || 
            !current_user_can('manage_options')) {
            wp_die(__('Security check failed', 'priority-ticket-payment'));
        }
        
        $submission_id = intval($_POST['submission_id']);
        $new_status = sanitize_text_field($_POST['status']);
        
        $allowed_statuses = array('pending', 'processing', 'completed', 'failed', 'refunded');
        if (!in_array($new_status, $allowed_statuses)) {
            wp_send_json_error(__('Invalid status.', 'priority-ticket-payment'));
        }
        
        $result = Priority_Ticket_Payment_Database::update_payment_status($submission_id, $new_status);
        
        if ($result !== false) {
            wp_send_json_success(__('Status updated successfully.', 'priority-ticket-payment'));
        } else {
            wp_send_json_error(__('Failed to update status.', 'priority-ticket-payment'));
        }
    }
    
    /**
     * Handle AJAX submission deletion
     */
    public function ajax_delete_submission() {
        // Verify nonce and permissions
        if (!check_ajax_referer('priority_ticket_payment_admin_nonce', 'nonce', false) || 
            !current_user_can('manage_options')) {
            wp_die(__('Security check failed', 'priority-ticket-payment'));
        }
        
        $submission_id = intval($_POST['submission_id']);
        
        if ($submission_id <= 0) {
            wp_send_json_error(__('Invalid submission ID', 'priority-ticket-payment'));
        }
        
        $result = Priority_Ticket_Payment_Database::delete_submission($submission_id);
        
        if ($result) {
            wp_send_json_success(__('Submission deleted successfully', 'priority-ticket-payment'));
        } else {
            wp_send_json_error(__('Failed to delete submission', 'priority-ticket-payment'));
        }
    }
    
    /**
     * Handle AJAX manual cleanup trigger
     */
    public function ajax_manual_cleanup() {
        // Verify nonce and permissions
        if (!check_ajax_referer('priority_ticket_payment_admin_nonce', 'nonce', false) || 
            !current_user_can('manage_options')) {
            wp_die(__('Security check failed', 'priority-ticket-payment'));
        }
        
        $result = Priority_Ticket_Payment::trigger_manual_cleanup();
        
        if ($result) {
            wp_send_json_success(array(
                'message' => sprintf(
                    __('Cleanup completed: %d submissions and %d files deleted', 'priority-ticket-payment'),
                    $result['deleted_submissions'],
                    $result['deleted_files']
                ),
                'deleted_submissions' => $result['deleted_submissions'],
                'deleted_files' => $result['deleted_files']
            ));
        } else {
            wp_send_json_error(__('Cleanup failed', 'priority-ticket-payment'));
        }
    }
    
    /**
     * Handle AJAX request for cleanup statistics
     */
    public function ajax_get_cleanup_stats() {
        // Verify nonce and permissions
        if (!check_ajax_referer('priority_ticket_payment_admin_nonce', 'nonce', false) || 
            !current_user_can('manage_options')) {
            wp_die(__('Security check failed', 'priority-ticket-payment'));
        }
        
        $stats = Priority_Ticket_Payment_Database::get_cleanup_stats();
        $file_stats = Priority_Ticket_Payment_Elementor_Utils::get_file_size_stats();
        
        // Check when the next cleanup is scheduled
        $next_cleanup = wp_next_scheduled('priority_ticket_payment_daily_cleanup');
        
        wp_send_json_success(array(
            'database_stats' => $stats,
            'file_stats' => $file_stats,
            'next_cleanup' => $next_cleanup ? date('Y-m-d H:i:s', $next_cleanup) : 'Not scheduled',
            'next_cleanup_timestamp' => $next_cleanup
        ));
    }
    
    /**
     * Handle AJAX ticket creation
     */
    public function ajax_create_ticket() {
        // Verify nonce and permissions
        if (!check_ajax_referer('priority_ticket_payment_admin_nonce', 'nonce', false) || 
            !current_user_can('manage_options')) {
            wp_die(__('Security check failed', 'priority-ticket-payment'));
        }
        
        $submission_id = intval($_POST['submission_id']);
        
        if ($submission_id <= 0) {
            wp_send_json_error(__('Invalid submission ID', 'priority-ticket-payment'));
        }
        
        $result = Priority_Ticket_Payment::create_ticket_for_completed_submission($submission_id);
        
        if ($result) {
            wp_send_json_success(__('Ticket created successfully', 'priority-ticket-payment'));
        } else {
            wp_send_json_error(__('Failed to create ticket', 'priority-ticket-payment'));
        }
    }
    
    /**
     * Handle AJAX view submission
     */
    public function ajax_view_submission() {
        // Verify nonce and permissions
        if (!check_ajax_referer('priority_ticket_payment_admin_nonce', 'nonce', false) || 
            !current_user_can('manage_options')) {
            wp_die(__('Security check failed', 'priority-ticket-payment'));
        }
        
        $submission_id = intval($_POST['submission_id']);
        
        if ($submission_id <= 0) {
            wp_send_json_error(__('Invalid submission ID', 'priority-ticket-payment'));
        }
        
        $submission = Priority_Ticket_Payment_Database::get_submission($submission_id);
        
        if (!$submission) {
            wp_send_json_error(__('Failed to retrieve submission', 'priority-ticket-payment'));
        }
        
        // Get user information
        $user = get_user_by('id', $submission['user_id']);
        $user_data = array(
            'display_name' => $user ? $user->display_name : __('Unknown User', 'priority-ticket-payment'),
            'email' => $user ? $user->user_email : __('No email', 'priority-ticket-payment'),
        );
        
        // Format the data for the modal
        $formatted_data = array(
            'submission' => $submission,
            'user' => $user_data,
            'formatted_price' => Priority_Ticket_Payment::get_option('currency_symbol', '$') . number_format($submission['price'], 2),
            'formatted_date' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($submission['created_at'])),
        );
        
        wp_send_json_success($formatted_data);
    }
    
    /**
     * Validate settings before saving
     */
    public function validate_settings($new_value, $old_value) {
        // Validate and sanitize custom thank you page URL
        if (!empty($new_value['custom_thank_you_page_url'])) {
            // Sanitize URL using esc_url_raw()
            $sanitized_url = esc_url_raw($new_value['custom_thank_you_page_url']);
            
            if (!Priority_Ticket_Payment_Payment_Handler::validate_thank_you_url($sanitized_url)) {
                add_settings_error(
                    'priority_ticket_payment_settings',
                    'invalid_thank_you_url',
                    __('The custom thank you page URL is invalid. Please enter a valid URL or leave it empty to use the default WooCommerce thank-you page.', 'priority-ticket-payment'),
                    'error'
                );
                
                // Keep the old value for this field
                $new_value['custom_thank_you_page_url'] = isset($old_value['custom_thank_you_page_url']) ? $old_value['custom_thank_you_page_url'] : '';
            } else {
                // Use the sanitized URL
                $new_value['custom_thank_you_page_url'] = $sanitized_url;
            }
        }
        
        // Validate max file size
        if (!empty($new_value['max_file_size'])) {
            $max_file_size = intval($new_value['max_file_size']);
            if ($max_file_size < 1 || $max_file_size > 100) {
                add_settings_error(
                    'priority_ticket_payment_settings',
                    'invalid_max_file_size',
                    __('Maximum file size must be between 1 and 100 MB.', 'priority-ticket-payment'),
                    'error'
                );
                
                // Keep the old value for this field
                $new_value['max_file_size'] = isset($old_value['max_file_size']) ? $old_value['max_file_size'] : '10';
            }
        }
        
        // Validate max attachments
        if (!empty($new_value['max_attachments'])) {
            $max_attachments = intval($new_value['max_attachments']);
            if ($max_attachments < 1 || $max_attachments > 5) {
                add_settings_error(
                    'priority_ticket_payment_settings',
                    'invalid_max_attachments',
                    __('Maximum number of attachments must be between 1 and 5 files.', 'priority-ticket-payment'),
                    'error'
                );
                
                // Keep the old value for this field
                $new_value['max_attachments'] = isset($old_value['max_attachments']) ? $old_value['max_attachments'] : '3';
            }
        }
        
        // Validate admin notification email
        if (!empty($new_value['priority_ticket_admin_email'])) {
            $admin_email = sanitize_email($new_value['priority_ticket_admin_email']);
            
            if (!is_email($admin_email)) {
                add_settings_error(
                    'priority_ticket_payment_settings',
                    'invalid_admin_email',
                    __('The admin notification email address is invalid. Please enter a valid email address or leave it empty to use the WordPress admin email.', 'priority-ticket-payment'),
                    'error'
                );
                
                // Keep the old value for this field
                $new_value['priority_ticket_admin_email'] = isset($old_value['priority_ticket_admin_email']) ? $old_value['priority_ticket_admin_email'] : '';
            } else {
                // Use the sanitized email
                $new_value['priority_ticket_admin_email'] = $admin_email;
            }
        }
        
        // Validate product IDs
        $product_fields = array('product_id_a', 'product_id_b', 'product_id_c');
        foreach ($product_fields as $field) {
            if (!empty($new_value[$field])) {
                $product_id = intval($new_value[$field]);
                if ($product_id > 0) {
                    $product = get_post($product_id);
                    if (!$product || $product->post_type !== 'product') {
                        add_settings_error(
                            'priority_ticket_payment_settings',
                            'invalid_product_id',
                            sprintf(__('Product ID %d does not exist or is not a valid WooCommerce product.', 'priority-ticket-payment'), $product_id),
                            'error'
                        );
                        
                        // Keep the old value for this field
                        $new_value[$field] = isset($old_value[$field]) ? $old_value[$field] : '';
                    }
                }
            }
        }
        
        return $new_value;
    }
} 