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
     * Usage: [priority_ticket_form]
     */
    public function priority_ticket_form_shortcode($atts) {
        $atts = shortcode_atts(array(
            'price' => Priority_Ticket_Payment::get_option('default_ticket_price', '50.00'),
            'title' => __('Submit Priority Ticket', 'priority-ticket-payment'),
            'description' => __('Submit your priority support ticket with payment.', 'priority-ticket-payment'),
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
        
        // Detect user priority
        $user_priority = Priority_Ticket_Payment_Elementor_Utils::get_user_ticket_priority(get_current_user_id());
        
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
        
        // Check if form is configured
        if (empty($form_id)) {
            $priority_labels = array(
                'A' => __('Premium (100€)', 'priority-ticket-payment'),
                'B' => __('Standard (50€)', 'priority-ticket-payment'),
                'C' => __('Basic (100€)', 'priority-ticket-payment'),
                'D' => __('Free', 'priority-ticket-payment'),
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
        .status-processing {
            background-color: #74b9ff;
            color: #ffffff;
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