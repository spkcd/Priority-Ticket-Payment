<?php
/**
 * Frontend functionality for Priority Ticket Payment
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Priority_Ticket_Payment_Frontend {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_shortcode('priority_ticket_form', array($this, 'priority_ticket_form_shortcode'));
        add_shortcode('priority_ticket_status', array($this, 'priority_ticket_status_shortcode'));
    }
    
    /**
     * Initialize frontend functionality
     */
    public function init() {
        // Add any initialization code here
    }
    
    /**
     * Priority ticket form shortcode
     * Usage: [priority_ticket_form] or [priority_ticket_form form_id="f755476"]
     */
    public function priority_ticket_form_shortcode($atts) {
        $atts = shortcode_atts(array(
            'price' => Priority_Ticket_Payment::get_option('default_ticket_price', '50.00'),
            'title' => __('Submit Priority Ticket', 'priority-ticket-payment'),
            'description' => __('Submit your priority support ticket with payment.', 'priority-ticket-payment'),
            'form_id' => '', // Allow specifying a specific form ID
        ), $atts);
        
        ob_start();
        $this->render_priority_ticket_form($atts);
        return ob_get_clean();
    }
    
    /**
     * Priority ticket status shortcode
     * Usage: [priority_ticket_status]
     */
    public function priority_ticket_status_shortcode($atts) {
        if (!is_user_logged_in()) {
            return '<p>' . __('You must be logged in to view your ticket submissions.', 'priority-ticket-payment') . '</p>';
        }
        
        $atts = shortcode_atts(array(
            'title' => __('Your Priority Ticket Submissions', 'priority-ticket-payment'),
            'per_page' => 10,
        ), $atts);
        
        ob_start();
        $this->render_user_submissions($atts);
        return ob_get_clean();
    }
    
    /**
     * Render priority ticket form
     */
    private function render_priority_ticket_form($atts) {
        if (!is_user_logged_in()) {
            echo '<p>' . __('You must be logged in to submit a priority ticket.', 'priority-ticket-payment') . '</p>';
            return;
        }
        
        // Always detect user priority (needed for form display logic)
        $user_priority = Priority_Ticket_Payment_Elementor_Utils::get_user_ticket_priority(get_current_user_id());
        
        // Check if a specific form ID was provided via shortcode
        if (!empty($atts['form_id'])) {
            $form_id = sanitize_text_field($atts['form_id']);
        } else {
            // Get form ID based on priority
            $form_id_setting_map = array(
                'A' => 'ticket_form_id_a',
                'B' => 'ticket_form_id_b',
                'C' => 'ticket_form_id_c',
                'D' => 'ticket_form_id_d',
            );
            
            $form_id = '';
            if (isset($form_id_setting_map[$user_priority])) {
                $form_id = Priority_Ticket_Payment::get_option($form_id_setting_map[$user_priority], '');
            }
        }
        
        // Check if form is configured
        if (empty($form_id)) {
            $priority_labels = array(
                'A' => __('Coaching Client (Free)', 'priority-ticket-payment'),
                'B' => __('Standard (50€)', 'priority-ticket-payment'),
                'C' => __('Basic (100€)', 'priority-ticket-payment'),
            );
            
            $priority_label = isset($priority_labels[$user_priority]) ? $priority_labels[$user_priority] : $user_priority;
            
            ?>
            <div class="priority-ticket-form-container">
                <div class="form-not-configured">
                    <h3><?php _e('Priority Ticket Form', 'priority-ticket-payment'); ?></h3>
                    <p class="notice notice-warning">
                        <?php printf(__('Form not configured for your ticket tier: %s. Please contact the administrator.', 'priority-ticket-payment'), esc_html($priority_label)); ?>
                    </p>
                    <p><strong><?php printf(__('Your detected priority tier: %s', 'priority-ticket-payment'), esc_html($priority_label)); ?></strong></p>
                </div>
            </div>
            
            <style>
            .priority-ticket-form-container {
                max-width: 600px;
                margin: 0 auto;
            }
            .form-not-configured {
                text-align: center;
                padding: 20px;
            }
            .notice {
                padding: 12px;
                border-radius: 4px;
                margin-bottom: 15px;
            }
            .notice-warning {
                background-color: #fff3cd;
                color: #856404;
                border: 1px solid #ffeaa7;
            }
            </style>
            <?php
            return;
        }
        
        // Check if Elementor Pro is available
        if (!class_exists('\ElementorPro\Modules\Forms\Module')) {
            ?>
            <div class="priority-ticket-form-container">
                <div class="elementor-not-available">
                    <h3><?php _e('Priority Ticket Form', 'priority-ticket-payment'); ?></h3>
                    <p class="notice notice-error">
                        <?php _e('Elementor Pro is required to display the form. Please contact the administrator.', 'priority-ticket-payment'); ?>
                    </p>
                </div>
            </div>
            
            <style>
            .priority-ticket-form-container {
                max-width: 600px;
                margin: 0 auto;
            }
            .elementor-not-available {
                text-align: center;
                padding: 20px;
            }
            .notice {
                padding: 12px;
                border-radius: 4px;
                margin-bottom: 15px;
            }
            .notice-error {
                background-color: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }
            </style>
            <?php
            return;
        }
        
        // Render the Elementor form
        ?>
        <div class="priority-ticket-form-container">
            <?php
            try {
                echo \ElementorPro\Modules\Forms\Module::instance()->render_form($form_id);
            } catch (Exception $e) {
                ?>
                <div class="form-render-error">
                    <h3><?php _e('Priority Ticket Form', 'priority-ticket-payment'); ?></h3>
                    <p class="notice notice-error">
                        <?php printf(__('Error loading form (ID: %s). Please contact the administrator.', 'priority-ticket-payment'), esc_html($form_id)); ?>
                    </p>
                </div>
                <?php
            }
            ?>
        </div>
        
        <?php
        // Add JavaScript to auto-populate name and email fields for logged-in users
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $user_display_name = !empty($current_user->display_name) ? $current_user->display_name : (!empty($current_user->first_name) && !empty($current_user->last_name) ? $current_user->first_name . ' ' . $current_user->last_name : $current_user->user_login);
            $user_email = $current_user->user_email;
            ?>
            <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function() {
                // Auto-populate name and email fields for logged-in users
                function populateUserFields() {
                    // Define user data
                    var userName = <?php echo wp_json_encode($user_display_name); ?>;
                    var userEmail = <?php echo wp_json_encode($user_email); ?>;
                    
                    // Common selectors for name fields
                    var nameSelectors = [
                        'input[name="form_fields[name]"]',
                        'input[name="form_fields[full_name]"]', 
                        'input[name="form_fields[full name]"]',
                        'input[name="form_fields[client_name]"]',
                        'input[name="form_fields[your_name]"]',
                        'input[id*="name"]',
                        'input[placeholder*="name" i]',
                        'input[placeholder*="Name" i]',
                        '.elementor-field-group-name input',
                        '.elementor-field-type-text input[placeholder*="name" i]'
                    ];
                    
                    // Common selectors for email fields  
                    var emailSelectors = [
                        'input[name="form_fields[email]"]',
                        'input[name="form_fields[email_address]"]',
                        'input[name="form_fields[e-mail]"]',
                        'input[name="form_fields[your_email]"]',
                        'input[type="email"]',
                        'input[id*="email"]',
                        'input[placeholder*="email" i]',
                        '.elementor-field-group-email input',
                        '.elementor-field-type-email input'
                    ];
                    
                    // Auto-populate name field
                    nameSelectors.forEach(function(selector) {
                        var nameField = document.querySelector(selector);
                        if (nameField && nameField.value === '') {
                            nameField.value = userName;
                            // Trigger change event for any listeners
                            var event = new Event('change', { bubbles: true });
                            nameField.dispatchEvent(event);
                        }
                    });
                    
                    // Auto-populate email field
                    emailSelectors.forEach(function(selector) {
                        var emailField = document.querySelector(selector);
                        if (emailField && emailField.value === '') {
                            emailField.value = userEmail;
                            // Trigger change event for any listeners
                            var event = new Event('change', { bubbles: true });
                            emailField.dispatchEvent(event);
                        }
                    });
                }
                
                // Run immediately
                populateUserFields();
                
                // Also run after a short delay to handle dynamic form loading
                setTimeout(populateUserFields, 500);
                
                // Run when Elementor form is fully loaded (if using AJAX loading)
                if (typeof jQuery !== 'undefined') {
                    jQuery(document).on('elementor/popup/show', function() {
                        setTimeout(populateUserFields, 100);
                    });
                }
            });
            </script>
            <?php
        }
        
        // Add file upload information for paid tiers
        if (in_array($user_priority, array('A', 'B'))) {
            $max_attachments = Priority_Ticket_Payment::get_option('max_attachments', '3');
            ?>
            <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function() {
                // Add file upload clarification for paid tiers
                const formContainer = document.querySelector('.priority-ticket-form-container');
                if (formContainer) {
                    const notice = document.createElement('div');
                    notice.className = 'file-upload-info';
                    notice.style.cssText = 'background: #f0f8ff; border: 1px solid #4CAF50; padding: 12px; border-radius: 4px; margin-bottom: 20px; color: #2e7d32;';
                    notice.innerHTML = '<strong><?php _e('File Uploads:', 'priority-ticket-payment'); ?></strong> <?php printf(__('You can upload up to %s attachments (PDF, JPG, PNG, etc.).', 'priority-ticket-payment'), esc_js($max_attachments)); ?>';
                    
                    const form = formContainer.querySelector('form') || formContainer.querySelector('.elementor-form');
                    if (form) {
                        form.parentNode.insertBefore(notice, form);
                    } else {
                        formContainer.insertBefore(notice, formContainer.firstChild);
                    }
                }
            });
            </script>
            <?php
        }
        
        // Add special handling for Priority D (Free tier)
        if ($user_priority === 'D') {
            ?>
            <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function() {
                // Limit description field to 500 characters
                const descriptionField = document.querySelector('#form-field-ticket_description, [name*="ticket_description"], [name*="description"], [name*="message"]');
                if (descriptionField) {
                    descriptionField.setAttribute('maxlength', '500');
                    
                    // Add character counter
                    const counterDiv = document.createElement('div');
                    counterDiv.className = 'character-counter';
                    counterDiv.style.cssText = 'font-size: 12px; color: #666; margin-top: 5px; text-align: right;';
                    
                    const updateCounter = function() {
                        const remaining = 500 - descriptionField.value.length;
                        counterDiv.textContent = remaining + '/500 characters remaining';
                        if (remaining < 50) {
                            counterDiv.style.color = '#d63031';
                        } else {
                            counterDiv.style.color = '#666';
                        }
                    };
                    
                    descriptionField.addEventListener('input', updateCounter);
                    descriptionField.parentNode.appendChild(counterDiv);
                    updateCounter();
                }
                
                // Hide upload/attachment fields
                const uploadSelectors = [
                    '#form-field-ticket_attachments',
                    '[name*="attachment"]',
                    '[name*="upload"]',
                    '[name*="file"]',
                    'input[type="file"]'
                ];
                
                uploadSelectors.forEach(function(selector) {
                    const elements = document.querySelectorAll(selector);
                    elements.forEach(function(element) {
                        // Hide the field and its parent container
                        let container = element.closest('.elementor-field-group') || element.closest('.form-group') || element.parentNode;
                        if (container) {
                            container.style.display = 'none';
                        }
                    });
                });
                
                // Add free tier notice
                const formContainer = document.querySelector('.priority-ticket-form-container');
                if (formContainer) {
                    const notice = document.createElement('div');
                    notice.className = 'free-tier-notice';
                    notice.style.cssText = 'background: #e3f2fd; border: 1px solid #2196f3; padding: 12px; border-radius: 4px; margin-bottom: 20px; color: #1565c0;';
                    notice.innerHTML = '<strong><?php _e('Free Tier:', 'priority-ticket-payment'); ?></strong> <?php _e('Description limited to 500 characters. File uploads not available.', 'priority-ticket-payment'); ?>';
                    
                    const form = formContainer.querySelector('form') || formContainer.querySelector('.elementor-form');
                    if (form) {
                        form.parentNode.insertBefore(notice, form);
                    } else {
                        formContainer.insertBefore(notice, formContainer.firstChild);
                    }
                }
            });
            </script>
            <?php
        }
        
        // Add general styling
        ?>
        <style>
        .priority-ticket-form-container {
            max-width: 600px;
            margin: 0 auto;
        }
        .form-render-error {
            text-align: center;
            padding: 20px;
        }
        .notice {
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .notice-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .character-counter {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
            text-align: right;
        }
        .free-tier-notice {
            background: #e3f2fd;
            border: 1px solid #2196f3;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            color: #1565c0;
        }
        .file-upload-info {
            background: #f0f8ff;
            border: 1px solid #4CAF50;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            color: #2e7d32;
        }
        </style>
        <?php
    }
    
    /**
     * Render user submissions list
     */
    private function render_user_submissions($atts) {
        $current_user_id = get_current_user_id();
        $per_page = intval($atts['per_page']);
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        $args = array(
            'user_id' => $current_user_id,
            'limit' => $per_page,
            'offset' => $offset,
            'orderby' => 'created_at',
            'order' => 'DESC',
        );
        
        $submissions = Priority_Ticket_Payment_Database::get_submissions($args);
        $total_items = Priority_Ticket_Payment_Database::get_submissions_count(array('user_id' => $current_user_id));
        $total_pages = ceil($total_items / $per_page);
        $currency_symbol = Priority_Ticket_Payment::get_option('currency_symbol', '$');
        
        ?>
        <div class="priority-ticket-status-container">
            <h3><?php echo esc_html($atts['title']); ?></h3>
            
            <?php if (empty($submissions)) : ?>
                <p><?php _e('You have not submitted any priority tickets yet.', 'priority-ticket-payment'); ?></p>
            <?php else : ?>
                <table class="priority-tickets-table">
                    <thead>
                        <tr>
                            <th><?php _e('ID', 'priority-ticket-payment'); ?></th>
                            <th><?php _e('Subject', 'priority-ticket-payment'); ?></th>
                            <th><?php _e('Price', 'priority-ticket-payment'); ?></th>
                            <th><?php _e('Status', 'priority-ticket-payment'); ?></th>
                            <th><?php _e('Date', 'priority-ticket-payment'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($submissions as $submission) : ?>
                            <tr>
                                <td>#<?php echo esc_html($submission['id']); ?></td>
                                <td>
                                    <?php
                                    $form_data = $submission['form_data'];
                                    $subject = is_array($form_data) && isset($form_data['ticket_subject']) ? $form_data['ticket_subject'] : __('No Subject', 'priority-ticket-payment');
                                    echo esc_html($subject);
                                    ?>
                                </td>
                                <td><?php echo esc_html($currency_symbol . number_format($submission['price'], 2)); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo esc_attr($submission['payment_status']); ?>">
                                        <?php echo esc_html(ucfirst($submission['payment_status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($submission['created_at']))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if ($total_pages > 1) : ?>
                    <div class="pagination">
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
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <style>
        .priority-ticket-status-container {
            max-width: 800px;
            margin: 0 auto;
        }
        .priority-tickets-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .priority-tickets-table th,
        .priority-tickets-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .priority-tickets-table th {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        .priority-tickets-table tr:hover {
            background-color: #f5f5f5;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .status-pending {
            background-color: #ffeaa7;
            color: #d63031;
        }

        .status-completed {
            background-color: #00b894;
            color: #ffffff;
        }
        .status-failed {
            background-color: #d63031;
            color: #ffffff;
        }
        .status-refunded {
            background-color: #636e72;
            color: #ffffff;
        }
        .pagination {
            text-align: center;
            margin-top: 20px;
        }
        .pagination a,
        .pagination span {
            display: inline-block;
            padding: 8px 12px;
            margin: 0 2px;
            text-decoration: none;
            border: 1px solid #ddd;
            color: #0073aa;
        }
        .pagination a:hover {
            background-color: #f5f5f5;
        }
        .pagination .current {
            background-color: #0073aa;
            color: white;
            border-color: #0073aa;
        }
        </style>
        <?php
    }
    
    /**
     * Get form data from submission
     */
    public static function get_form_data_value($form_data, $key, $default = '') {
        if (is_array($form_data) && isset($form_data[$key])) {
            return $form_data[$key];
        }
        return $default;
    }
} 