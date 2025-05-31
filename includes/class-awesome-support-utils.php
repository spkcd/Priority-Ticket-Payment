<?php
/**
 * Awesome Support integration utilities for Priority Ticket Payment
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Priority_Ticket_Payment_Awesome_Support_Utils {
    
    /**
     * Initialize hooks
     */
    public static function init() {
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'handle_order_completion'), 10, 1);
    }
    
    /**
     * Handle WooCommerce order completion
     */
    public static function handle_order_completion($order_id) {
        if (!self::is_awesome_support_active()) {
            error_log('Priority Ticket Payment: Awesome Support not available for order completion handling');
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('Priority Ticket Payment: Invalid order ID: ' . $order_id);
            return;
        }
        
        // Check if this order contains priority ticket products
        $priority_ticket_found = false;
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $is_priority_ticket = get_post_meta($product_id, '_priority_ticket_product', true);
            
            if ($is_priority_ticket === 'yes') {
                $priority_ticket_found = true;
                break;
            }
        }
        
        if (!$priority_ticket_found) {
            return; // Not a priority ticket order
        }
        
        // Get ticket metadata from order
        $ticket_token = $order->get_meta('_priority_ticket_token');
        $submission_id = $order->get_meta('_priority_ticket_submission_id');
        $user_priority = $order->get_meta('_priority_ticket_tier');
        
        if (!$ticket_token || !$submission_id) {
            error_log('Priority Ticket Payment: Missing ticket metadata in completed order ' . $order_id);
            return;
        }
        
        // Load submission from database
        $submission = Priority_Ticket_Payment_Database::get_submission($submission_id);
        if (!$submission) {
            error_log('Priority Ticket Payment: Submission not found for order ' . $order_id . ', submission ID: ' . $submission_id);
            return;
        }
        
        // Check if already processed
        if (!empty($submission['awesome_support_ticket_id'])) {
            error_log('Priority Ticket Payment: Ticket already created for submission ' . $submission_id);
            return;
        }
        
        // Create the Awesome Support ticket
        $ticket_id = self::create_ticket_from_submission($submission, $order, $user_priority);
        
        if ($ticket_id && !is_wp_error($ticket_id)) {
            // Update payment status to paid
            Priority_Ticket_Payment_Database::update_submission($submission_id, array(
                'payment_status' => 'paid',
                'order_id' => $order_id,
            ));
            
            // Link ticket ID to submission
            Priority_Ticket_Payment_Database::update_ticket_id($submission_id, $ticket_id);
            
            // Send email confirmation
            self::send_completion_email($order, $submission, $ticket_id);
            
            error_log(sprintf('Priority Ticket Payment: Successfully created ticket %d for order %d (submission %d)', $ticket_id, $order_id, $submission_id));
        } else {
            $error_message = is_wp_error($ticket_id) ? $ticket_id->get_error_message() : 'Unknown error';
            error_log('Priority Ticket Payment: Failed to create ticket for order ' . $order_id . ': ' . $error_message);
        }
    }
    
    /**
     * Create Awesome Support ticket from submission
     */
    public static function create_ticket_from_submission($submission, $order, $user_priority) {
        if (!self::is_awesome_support_active()) {
            return new WP_Error('no_awesome_support', 'Awesome Support not available');
        }
        
        $form_data = $submission['form_data'];
        
        // Ensure form_data is properly formatted as an array
        if (!is_array($form_data)) {
            error_log('Priority Ticket Payment: form_data is not an array in create_ticket_from_submission, attempting to unserialize: ' . gettype($form_data));
            if (is_string($form_data)) {
                $form_data = unserialize($form_data);
            }
            if (!is_array($form_data)) {
                error_log('Priority Ticket Payment: Unable to convert form_data to array, using empty array');
                $form_data = array();
            }
        }
        
        // Build ticket title
        $ticket_title = isset($form_data['ticket_subject']) ? $form_data['ticket_subject'] : 'Priority Support Request';
        if (empty($ticket_title) || $ticket_title === ' - Priority Support Request') {
            $customer_name = $order->get_formatted_billing_full_name();
            $ticket_title = $customer_name . ' - Priority Support Request';
        }
        
        // Build ticket content
        $ticket_content = self::build_ticket_content($form_data, $order);
        
        // Prepare ticket data
        $ticket_data = array(
            'post_title' => $ticket_title,
            'post_content' => $ticket_content,
            'post_status' => 'queued',
            'post_author' => $submission['user_id'] ?: 0,
        );
        
        // Validate ticket data
        $validation = self::validate_ticket_data($ticket_data);
        if (!$validation['valid']) {
            return new WP_Error('invalid_ticket_data', 'Invalid ticket data: ' . implode(', ', $validation['errors']));
        }
        
        // Create the ticket
        $ticket_id = wpas_insert_ticket($ticket_data);
        
        if (is_wp_error($ticket_id)) {
            return $ticket_id;
        }
        
        // Set ticket metadata based on priority tier
        self::set_ticket_priority_metadata($ticket_id, $user_priority, $form_data, $order, $submission);
        
        // Attach files (skip for Priority C and D / free tiers)
        if ($user_priority !== 'C' && $user_priority !== 'D' && !empty($submission['attachments'])) {
            self::attach_files_to_ticket($ticket_id, $submission['attachments']);
        }
        
        // Add order information to ticket
        self::add_order_note_to_ticket($ticket_id, $order);
        
        return $ticket_id;
    }
    
    /**
     * Set ticket priority metadata based on user tier
     */
    private static function set_ticket_priority_metadata($ticket_id, $user_priority, $form_data, $order, $submission) {
        // Map priority tiers to actual Awesome Support priority term IDs
        $priority_map = array(
            'A' => 134, // Premium → a-ticket (ID 134)
            'B' => 135, // Standard → b-ticket (ID 135)  
            'C' => 136, // Basic → c-ticket (ID 136)
            'D' => 137, // Free → d-ticket (ID 137) - we'll need to create this term
        );
        
        $priority_term_id = isset($priority_map[$user_priority]) ? $priority_map[$user_priority] : 137; // Default to d-ticket for free
        
        // Ensure form_data is an array
        if (!is_array($form_data)) {
            error_log('Priority Ticket Payment: form_data is not an array, attempting to unserialize: ' . gettype($form_data));
            if (is_string($form_data)) {
                $form_data = unserialize($form_data);
            }
            if (!is_array($form_data)) {
                error_log('Priority Ticket Payment: Unable to convert form_data to array, using empty array');
                $form_data = array();
            }
        }
        
        // Set basic ticket metadata
        update_post_meta($ticket_id, '_wpas_priority', $priority_term_id);
        update_post_meta($ticket_id, '_priority_ticket_order_id', $order->get_id());
        update_post_meta($ticket_id, '_priority_ticket_submission_id', $submission['id']);
        update_post_meta($ticket_id, '_priority_ticket_token', $submission['token']);
        update_post_meta($ticket_id, '_priority_ticket_tier', $user_priority);
        update_post_meta($ticket_id, '_priority_ticket_price', $submission['price']);
        
        // Set priority as taxonomy term (Awesome Support uses ticket_priority taxonomy)
        $priority_set = wp_set_post_terms($ticket_id, array($priority_term_id), 'ticket_priority');
        if (is_wp_error($priority_set)) {
            error_log('Priority Ticket Payment: Error setting priority taxonomy: ' . $priority_set->get_error_message());
        } else {
            error_log("Priority Ticket Payment: Successfully set priority taxonomy term $priority_term_id for ticket $ticket_id");
        }
        
        // Log the priority assignment
        $priority_names = array('A' => 'a-ticket', 'B' => 'b-ticket', 'C' => 'c-ticket', 'D' => 'd-ticket');
        $priority_name = isset($priority_names[$user_priority]) ? $priority_names[$user_priority] : 'd-ticket';
        error_log("Priority Ticket Payment: Set ticket $ticket_id priority to $priority_name (term ID: $priority_term_id) for user priority: $user_priority");
        
        // Set assignee if coach is specified and this is a paid tier
        if ($user_priority !== 'C' && $user_priority !== 'D' && !empty($form_data['coach'])) {
            $coach_value = $form_data['coach'];
            
            // Filter out placeholder values
            $placeholders = array(
                '– Wer ist Ihr Coach? –',
                'Select a coach',
                'Choose coach',
                'Please select',
                '---',
                '--',
                'Select...'
            );
            
            if (!in_array($coach_value, $placeholders) && trim($coach_value) !== '') {
                $agent = get_user_by('display_name', $coach_value);
                if (!$agent) {
                    // Try searching by first/last name
                    $coach_name = sanitize_text_field($coach_value);
                    $users = get_users(array(
                        'search' => $coach_name,
                        'search_columns' => array('display_name', 'user_nicename'),
                    ));
                    
                    foreach ($users as $user) {
                        if (user_can($user->ID, 'edit_ticket')) {
                            $agent = $user;
                            break;
                        }
                    }
                }
                
                if ($agent && user_can($agent->ID, 'edit_ticket')) {
                    update_post_meta($ticket_id, '_wpas_assignee', $agent->ID);
                    error_log('Priority Ticket Payment: Assigned ticket ' . $ticket_id . ' to agent ' . $agent->display_name);
                } else {
                    error_log('Priority Ticket Payment: Could not find agent for coach: ' . $coach_value);
                }
            } else {
                error_log('Priority Ticket Payment: Coach field contains placeholder value, skipping assignment: ' . $coach_value);
            }
        }
        
        // Set product/topic if available
        if (!empty($form_data['ticket_category'])) {
            $products = get_terms(array(
                'taxonomy' => 'product',
                'hide_empty' => false,
                'name' => $form_data['ticket_category'],
            ));
            
            // Check if get_terms returned an error
            if (!is_wp_error($products) && !empty($products)) {
                wp_set_post_terms($ticket_id, array($products[0]->term_id), 'product');
            } elseif (is_wp_error($products)) {
                error_log('Priority Ticket Payment: Error getting terms: ' . $products->get_error_message());
            }
        }
        
        // Store original form data for reference
        update_post_meta($ticket_id, '_priority_ticket_form_data', $form_data);
        update_post_meta($ticket_id, '_priority_ticket_elementor_form_id', isset($form_data['elementor_form_id']) ? $form_data['elementor_form_id'] : '');
    }
    
    /**
     * Build ticket content from form data and order
     */
    private static function build_ticket_content($form_data, $order) {
        // Ensure form_data is an array
        if (!is_array($form_data)) {
            error_log('Priority Ticket Payment: form_data is not an array in build_ticket_content, using empty array');
            $form_data = array();
        }
        
        $content_parts = array();
        
        // Priority/urgency indicator - use the actual user priority tier if available
        $priority_display = 'Medium'; // Default fallback
        if (!empty($form_data['user_priority'])) {
            $priority_map = array(
                'A' => 'Premium (a-ticket)',
                'B' => 'Standard (b-ticket)',
                'C' => 'Basic (c-ticket)',
                'D' => 'Free (d-ticket)'
            );
            $priority_display = isset($priority_map[$form_data['user_priority']]) ? $priority_map[$form_data['user_priority']] : $form_data['user_priority'];
        } elseif (!empty($form_data['ticket_priority'])) {
            $priority_display = ucfirst($form_data['ticket_priority']);
        }
        $content_parts[] = '**Priority Level:** ' . $priority_display;
        
        // Main message/description
        if (!empty($form_data['ticket_description'])) {
            $content_parts[] = '**Description:**' . "\n" . $form_data['ticket_description'];
        }
        
        // Additional details
        if (!empty($form_data['date_note'])) {
            $content_parts[] = '**Preferred Date/Note:** ' . $form_data['date_note'];
        }
        
        // Handle coach field - filter out placeholder values
        if (!empty($form_data['coach'])) {
            $coach_value = $form_data['coach'];
            // Filter out common placeholder values
            $placeholders = array(
                '– Wer ist Ihr Coach? –',
                'Select a coach',
                'Choose coach',
                'Please select',
                '---',
                '--',
                'Select...'
            );
            
            if (!in_array($coach_value, $placeholders) && trim($coach_value) !== '') {
                $content_parts[] = '**Preferred Coach:** ' . $coach_value;
            }
        }
        
        if (!empty($form_data['website'])) {
            $content_parts[] = '**Website:** ' . $form_data['website'];
        }
        
        // Contact information
        $contact_info = array();
        if (!empty($form_data['contact_email'])) {
            $contact_info[] = 'Email: ' . $form_data['contact_email'];
        }
        if (!empty($form_data['contact_phone'])) {
            $contact_info[] = 'Phone: ' . $form_data['contact_phone'];
        }
        
        if (!empty($contact_info)) {
            $content_parts[] = '**Contact Information:**' . "\n" . implode("\n", $contact_info);
        }
        
        // Order information
        $order_info = array();
        $order_info[] = 'Order ID: #' . $order->get_id();
        $order_info[] = 'Order Date: ' . $order->get_date_created()->format('Y-m-d H:i:s');
        $order_info[] = 'Order Total: ' . $order->get_formatted_order_total();
        $order_info[] = 'Payment Method: ' . $order->get_payment_method_title();
        
        $content_parts[] = '**Order Information:**' . "\n" . implode("\n", $order_info);
        
        return implode("\n\n", $content_parts);
    }
    
    /**
     * Attach files to ticket
     */
    private static function attach_files_to_ticket($ticket_id, $attachments) {
        if (empty($attachments) || !is_array($attachments)) {
            return;
        }
        
        $attached_count = 0;
        
        foreach ($attachments as $attachment) {
            if (!isset($attachment['path']) || !file_exists($attachment['path'])) {
                error_log('Priority Ticket Payment: Attachment file not found - ' . (isset($attachment['path']) ? $attachment['path'] : 'no path'));
                continue;
            }
            
            $attachment_id = self::attach_file_to_ticket($ticket_id, $attachment);
            
            if ($attachment_id) {
                $attached_count++;
                error_log('Priority Ticket Payment: Successfully attached file to ticket ' . $ticket_id . ' (attachment ID: ' . $attachment_id . ')');
            }
        }
        
        if ($attached_count > 0) {
            update_post_meta($ticket_id, '_priority_ticket_attachments_count', $attached_count);
        }
    }
    
    /**
     * Attach individual file to ticket
     */
    private static function attach_file_to_ticket($ticket_id, $attachment_data) {
        $file_path = $attachment_data['path'];
        $filename = isset($attachment_data['original_name']) ? $attachment_data['original_name'] : $attachment_data['filename'];
        $mime_type = isset($attachment_data['mime_type']) ? $attachment_data['mime_type'] : '';
        
        // Copy file to WordPress uploads directory
        $wp_upload_dir = wp_upload_dir();
        $new_file_path = $wp_upload_dir['path'] . '/' . $attachment_data['filename'];
        
        if (!copy($file_path, $new_file_path)) {
            error_log('Priority Ticket Payment: Failed to copy attachment file to uploads directory');
            return false;
        }
        
        // Create attachment post
        $attachment_id = wp_insert_attachment(array(
            'post_title' => sanitize_text_field($filename),
            'post_content' => '',
            'post_status' => 'inherit',
            'post_mime_type' => $mime_type,
            'post_parent' => $ticket_id,
        ), $new_file_path);
        
        if ($attachment_id) {
            // Generate attachment metadata
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attachment_metadata = wp_generate_attachment_metadata($attachment_id, $new_file_path);
            wp_update_attachment_metadata($attachment_id, $attachment_metadata);
            
            // Link attachment to ticket
            update_post_meta($attachment_id, '_wpas_attachment_ticket_id', $ticket_id);
            update_post_meta($attachment_id, '_priority_ticket_original_path', $file_path);
            update_post_meta($attachment_id, '_priority_ticket_field_name', $attachment_data['field_name']);
            
            return $attachment_id;
        }
        
        return false;
    }
    
    /**
     * Send completion email to customer
     */
    private static function send_completion_email($order, $submission, $ticket_id) {
        $form_data = $submission['form_data'];
        $customer_email = $order->get_billing_email();
        
        if (empty($customer_email) && !empty($form_data['contact_email'])) {
            $customer_email = $form_data['contact_email'];
        }
        
        if (empty($customer_email)) {
            error_log('Priority Ticket Payment: No customer email found for completion notification');
            return;
        }
        
        $customer_name = $order->get_formatted_billing_full_name();
        $ticket_title = get_the_title($ticket_id);
        
        $subject = sprintf(
            __('Your Priority Support Ticket has been Created - Order #%d', 'priority-ticket-payment'),
            $order->get_id()
        );
        
        $message = sprintf(
            __('Dear %s,

Thank you for your payment! Your priority support ticket has been successfully created and is now being processed by our team.

**Ticket Details:**
- Ticket ID: #%d
- Subject: %s
- Priority Level: %s
- Order ID: #%d
- Submission ID: #%d

**What happens next:**
1. Our support team will review your request
2. You will receive a response within our priority timeframe
3. All communication will be handled through our support system

You can track the status of your ticket by logging into your account on our website.

If you have any questions about your ticket or payment, please don\'t hesitate to contact us.

Thank you for choosing our priority support service!

Best regards,
The Support Team', 'priority-ticket-payment'),
            $customer_name,
            $ticket_id,
            $ticket_title,
            isset($form_data['ticket_priority']) ? ucfirst($form_data['ticket_priority']) : 'Medium',
            $order->get_id(),
            $submission['id']
        );
        
        // Send email to customer
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $sent = wp_mail($customer_email, $subject, nl2br($message), $headers);
        
        if ($sent) {
            error_log('Priority Ticket Payment: Completion email sent to customer: ' . $customer_email);
        } else {
            error_log('Priority Ticket Payment: Failed to send completion email to customer: ' . $customer_email);
        }
        
        // Also send notification to admin
        $admin_email = get_option('admin_email');
        $admin_subject = sprintf(
            __('Priority Ticket Created - Order #%d (Ticket #%d)', 'priority-ticket-payment'),
            $order->get_id(),
            $ticket_id
        );
        
        $admin_message = sprintf(
            __('A priority support ticket has been created from a completed order.

**Order Details:**
- Order ID: #%d
- Customer: %s (%s)
- Order Total: %s

**Ticket Details:**
- Ticket ID: #%d
- Subject: %s
- Priority: %s

**Quick Actions:**
- [View Ticket](%s)
- [View Order](%s)

This ticket was created automatically from the Priority Ticket Payment system.', 'priority-ticket-payment'),
            $order->get_id(),
            $customer_name,
            $customer_email,
            $order->get_formatted_order_total(),
            $ticket_id,
            $ticket_title,
            isset($form_data['ticket_priority']) ? ucfirst($form_data['ticket_priority']) : 'Medium',
            admin_url('post.php?post=' . $ticket_id . '&action=edit'),
            admin_url('post.php?post=' . $order->get_id() . '&action=edit')
        );
        
        wp_mail($admin_email, $admin_subject, nl2br($admin_message), $headers);
    }
    
    /**
     * Add order note to ticket
     */
    private static function add_order_note_to_ticket($ticket_id, $order) {
        $note_content = sprintf(
            'This ticket was created from a paid priority support request.

**Order Details:**
- Order ID: #%d
- Order Date: %s
- Order Total: %s
- Payment Method: %s
- Customer: %s

[View Order in WooCommerce](%s)',
            $order->get_id(),
            $order->get_date_created()->format('Y-m-d H:i:s'),
            $order->get_formatted_order_total(),
            $order->get_payment_method_title(),
            $order->get_formatted_billing_full_name(),
            admin_url('post.php?post=' . $order->get_id() . '&action=edit')
        );
        
        // Add reply/note to ticket
        $reply_data = array(
            'post_title' => 'Order Information',
            'post_content' => $note_content,
            'post_status' => 'read',
            'post_type' => 'ticket_reply',
            'post_parent' => $ticket_id,
            'post_author' => get_current_user_id() ?: 1,
        );
        
        wp_insert_post($reply_data);
    }
    
    /**
     * Test if Awesome Support is available
     */
    public static function is_awesome_support_active() {
        // Check for the correct function name that Awesome Support actually uses
        $function_exists = function_exists('wpas_insert_ticket');
        $class_exists = class_exists('WPAS');
        
        // Log the detection status for debugging
        error_log('Priority Ticket Payment: Awesome Support detection - wpas_insert_ticket: ' . ($function_exists ? 'YES' : 'NO') . ', WPAS class: ' . ($class_exists ? 'YES' : 'NO'));
        
        // Check for alternative class names that Awesome Support might use
        $alternative_classes = class_exists('Awesome_Support') || class_exists('AwesomeSupport') || class_exists('WPAS_Settings');
        
        if ($alternative_classes) {
            error_log('Priority Ticket Payment: Found alternative Awesome Support class');
        }
        
        // Primary detection - check for the main ticket creation function
        if ($function_exists) {
            error_log('Priority Ticket Payment: wpas_insert_ticket found - considering Awesome Support active');
            return true;
        }
        
        // If function doesn't exist, try to trigger plugin loading
        if (!$function_exists) {
            // Sometimes plugins aren't fully loaded during admin AJAX calls
            if (is_admin() && (defined('DOING_AJAX') && DOING_AJAX)) {
                error_log('Priority Ticket Payment: In AJAX context, checking if Awesome Support plugin is installed');
                
                // Check if Awesome Support plugin file exists
                $plugin_paths = array(
                    WP_PLUGIN_DIR . '/awesome-support/awesome-support.php',
                    WP_PLUGIN_DIR . '/awesome-support-main/awesome-support.php',
                    WP_PLUGIN_DIR . '/awesome-support-master/awesome-support.php',
                );
                
                foreach ($plugin_paths as $path) {
                    if (file_exists($path)) {
                        error_log('Priority Ticket Payment: Found Awesome Support plugin file at: ' . $path);
                        // Try to include it
                        include_once($path);
                        
                        // Check again after inclusion
                        if (function_exists('wpas_insert_ticket')) {
                            error_log('Priority Ticket Payment: Successfully loaded wpas_insert_ticket after manual inclusion');
                            return true;
                        }
                    }
                }
            }
        }
        
        error_log('Priority Ticket Payment: Awesome Support not detected as active');
        return false;
    }
    
    /**
     * Get available Awesome Support agents
     */
    public static function get_available_agents() {
        if (!self::is_awesome_support_active()) {
            return array();
        }
        
        $agents = array();
        
        // Get users with agent capabilities
        $users = get_users(array(
            'meta_key' => 'wpas_can_be_assigned',
            'meta_value' => 'yes',
        ));
        
        foreach ($users as $user) {
            if (user_can($user->ID, 'edit_ticket')) {
                $agents[] = array(
                    'ID' => $user->ID,
                    'display_name' => $user->display_name,
                    'user_email' => $user->user_email,
                );
            }
        }
        
        return $agents;
    }
    
    /**
     * Get available products/topics
     */
    public static function get_available_products() {
        if (!self::is_awesome_support_active()) {
            return array();
        }
        
        $products = get_terms(array(
            'taxonomy' => 'product',
            'hide_empty' => false,
        ));
        
        return $products ?: array();
    }
    
    /**
     * Get priority levels
     */
    public static function get_priority_levels() {
        return array(
            0 => __('Low', 'priority-ticket-payment'),
            1 => __('Medium', 'priority-ticket-payment'),
            2 => __('High', 'priority-ticket-payment'),
            3 => __('Urgent', 'priority-ticket-payment'),
        );
    }
    
    /**
     * Create test ticket for debugging
     */
    public static function create_test_ticket() {
        if (!self::is_awesome_support_active()) {
            return new WP_Error('no_awesome_support', 'Awesome Support not available');
        }
        
        $ticket_data = array(
            'post_title' => 'Test Priority Ticket - ' . date('Y-m-d H:i:s'),
            'post_content' => 'This is a test ticket created by Priority Ticket Payment plugin for testing purposes.',
            'post_status' => 'queued',
            'post_author' => get_current_user_id() ?: 1,
        );
        
        $ticket_id = wpas_insert_ticket($ticket_data);
        
        if (!is_wp_error($ticket_id)) {
            // Set test metadata - use b-ticket priority (ID 135)
            update_post_meta($ticket_id, '_wpas_priority', 135);
            update_post_meta($ticket_id, '_priority_ticket_test', 'yes');
            
            error_log('Priority Ticket Payment: Created test ticket ' . $ticket_id . ' with b-ticket priority (ID: 135)');
            
            return $ticket_id;
        }
        
        return $ticket_id;
    }
    
    /**
     * Validate ticket data before creation
     */
    public static function validate_ticket_data($ticket_data) {
        $errors = array();
        
        // Check required fields
        if (empty($ticket_data['post_title'])) {
            $errors[] = 'Ticket title is required';
        }
        
        if (empty($ticket_data['post_content'])) {
            $errors[] = 'Ticket content is required';
        }
        
        // Check author exists
        if (!empty($ticket_data['post_author']) && !get_user_by('id', $ticket_data['post_author'])) {
            $errors[] = 'Invalid user ID for ticket author';
        }
        
        return array(
            'valid' => empty($errors),
            'errors' => $errors,
        );
    }
    
    /**
     * Get ticket status options
     */
    public static function get_ticket_statuses() {
        if (!self::is_awesome_support_active()) {
            return array();
        }
        
        // Default Awesome Support statuses
        return array(
            'open' => __('Open', 'priority-ticket-payment'),
            'queued' => __('Queued', 'priority-ticket-payment'),
            'processing' => __('Processing', 'priority-ticket-payment'),
            'hold' => __('On Hold', 'priority-ticket-payment'),
            'closed' => __('Closed', 'priority-ticket-payment'),
        );
    }
    
    /**
     * Link order to existing ticket
     */
    public static function link_order_to_ticket($ticket_id, $order_id) {
        if (!self::is_awesome_support_active()) {
            return false;
        }
        
        // Add order reference to ticket
        update_post_meta($ticket_id, '_priority_ticket_order_id', $order_id);
        
        // Add note about the order
        $order = wc_get_order($order_id);
        if ($order) {
            $note_content = sprintf(
                'This ticket has been linked to WooCommerce Order #%d (%s)',
                $order_id,
                $order->get_formatted_order_total()
            );
            
            $reply_data = array(
                'post_title' => 'Order Linked',
                'post_content' => $note_content,
                'post_status' => 'read',
                'post_type' => 'ticket_reply',
                'post_parent' => $ticket_id,
                'post_author' => get_current_user_id() ?: 1,
            );
            
            wp_insert_post($reply_data);
        }
        
        return true;
    }
    
    /**
     * Get ticket metrics for priority tickets
     */
    public static function get_priority_ticket_metrics() {
        global $wpdb;
        
        if (!self::is_awesome_support_active()) {
            return array();
        }
        
        $table_name = Priority_Ticket_Payment_Database::get_table_name();
        
        // Get tickets created in last 30 days
        $query = "
            SELECT 
                COUNT(*) as total_tickets,
                SUM(CASE WHEN payment_status = 'completed' THEN 1 ELSE 0 END) as paid_tickets,
                AVG(price) as avg_price,
                SUM(price) as total_revenue
            FROM {$table_name} 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND awesome_support_ticket_id IS NOT NULL
        ";
        
        $results = $wpdb->get_row($query, ARRAY_A);
        
        return $results ?: array(
            'total_tickets' => 0,
            'paid_tickets' => 0,
            'avg_price' => 0,
            'total_revenue' => 0,
        );
    }
    
    /**
     * Sync ticket status with payment status
     */
    public static function sync_ticket_payment_status($ticket_id, $payment_status) {
        if (!self::is_awesome_support_active()) {
            return false;
        }
        
        // Map payment status to ticket actions
        $status_map = array(
            'pending_payment' => 'hold',
            'processing' => 'queued',
            'completed' => 'open',
            'failed' => 'hold',
            'refunded' => 'closed',
        );
        
        if (isset($status_map[$payment_status])) {
            $ticket_status = $status_map[$payment_status];
            
            // Update ticket status
            wp_update_post(array(
                'ID' => $ticket_id,
                'post_status' => $ticket_status,
            ));
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Add payment note to ticket
     */
    public static function add_payment_note_to_ticket($ticket_id, $payment_status, $order_id = null) {
        if (!self::is_awesome_support_active()) {
            return false;
        }
        
        $status_messages = array(
            'pending_payment' => 'Payment is pending',
            'processing' => 'Payment is being processed',
            'completed' => 'Payment completed successfully',
            'failed' => 'Payment failed',
            'refunded' => 'Payment was refunded',
        );
        
        $message = isset($status_messages[$payment_status]) ? $status_messages[$payment_status] : 'Payment status updated';
        
        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $message .= sprintf(' (Order #%d - %s)', $order_id, $order->get_formatted_order_total());
            }
        }
        
        $reply_data = array(
            'post_title' => 'Payment Update',
            'post_content' => $message,
            'post_status' => 'read',
            'post_type' => 'ticket_reply',
            'post_parent' => $ticket_id,
            'post_author' => get_current_user_id() ?: 1,
        );
        
        return wp_insert_post($reply_data);
    }
} 