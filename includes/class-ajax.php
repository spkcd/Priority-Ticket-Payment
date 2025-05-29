<?php
/**
 * AJAX functionality for Priority Ticket Payment
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Priority_Ticket_Payment_Ajax {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_ajax_submit_priority_ticket', array($this, 'submit_priority_ticket'));
        add_action('wp_ajax_nopriv_submit_priority_ticket', array($this, 'submit_priority_ticket'));
        
        add_action('wp_ajax_get_submission_details', array($this, 'get_submission_details'));
        
        add_action('wp_ajax_upload_ticket_attachment', array($this, 'upload_ticket_attachment'));
        add_action('wp_ajax_nopriv_upload_ticket_attachment', array($this, 'upload_ticket_attachment'));
    }
    
    /**
     * Handle priority ticket form submission
     */
    public function submit_priority_ticket() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['priority_ticket_payment_nonce'], 'priority_ticket_payment_form')) {
            wp_send_json_error(__('Security check failed. Please refresh the page and try again.', 'priority-ticket-payment'));
        }
        
        // Check if user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(__('You must be logged in to submit a priority ticket.', 'priority-ticket-payment'));
        }
        
        // Validate required fields
        $required_fields = array('ticket_subject', 'ticket_priority', 'ticket_description');
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                wp_send_json_error(sprintf(__('The field "%s" is required.', 'priority-ticket-payment'), $field));
            }
        }
        
        // Sanitize form data
        $form_data = array(
            'ticket_subject' => sanitize_text_field($_POST['ticket_subject']),
            'ticket_priority' => sanitize_text_field($_POST['ticket_priority']),
            'ticket_category' => sanitize_text_field($_POST['ticket_category']),
            'ticket_description' => sanitize_textarea_field($_POST['ticket_description']),
            'contact_email' => sanitize_email($_POST['contact_email']),
            'contact_phone' => sanitize_text_field($_POST['contact_phone']),
        );
        
        // Validate priority level
        $allowed_priorities = array('urgent', 'high', 'medium', 'low');
        if (!in_array($form_data['ticket_priority'], $allowed_priorities)) {
            wp_send_json_error(__('Invalid priority level selected.', 'priority-ticket-payment'));
        }
        
        // Handle file uploads
        $attachments = array();
        if (!empty($_FILES['ticket_attachments']['name'][0])) {
            $upload_result = $this->handle_file_uploads();
            if (is_wp_error($upload_result)) {
                wp_send_json_error($upload_result->get_error_message());
            }
            $attachments = $upload_result;
        }
        
        // Get price
        $price = floatval($_POST['ticket_price']);
        if ($price <= 0) {
            $price = floatval(Priority_Ticket_Payment::get_option('default_ticket_price', '50.00'));
        }
        
        // Prepare submission data
        $submission_data = array(
            'user_id' => get_current_user_id(),
            'form_data' => $form_data,
            'attachments' => $attachments,
            'price' => $price,
            'payment_status' => 'pending',
        );
        
        // Insert submission into database
        $submission_id = Priority_Ticket_Payment_Database::insert_submission($submission_data);
        
        if (!$submission_id) {
            wp_send_json_error(__('Failed to save your submission. Please try again.', 'priority-ticket-payment'));
        }
        
        // Check if payment is required before submission
        $require_payment = Priority_Ticket_Payment::get_option('require_payment_before_submission', 'yes');
        
        if ($require_payment === 'yes') {
            // Create payment session/order and return payment URL
            $payment_url = $this->create_payment_session($submission_id, $price);
            
            if (is_wp_error($payment_url)) {
                wp_send_json_error($payment_url->get_error_message());
            }
            
            wp_send_json_success(array(
                'message' => __('Your priority ticket has been submitted. Please complete the payment to process your request.', 'priority-ticket-payment'),
                'submission_id' => $submission_id,
                'payment_url' => $payment_url,
                'redirect' => true,
            ));
        } else {
            // Send notification emails
            $this->send_notification_emails($submission_id, $form_data);
            
            wp_send_json_success(array(
                'message' => __('Your priority ticket has been submitted successfully!', 'priority-ticket-payment'),
                'submission_id' => $submission_id,
                'redirect' => false,
            ));
        }
    }
    
    /**
     * Handle file uploads
     */
    private function handle_file_uploads() {
        if (empty($_FILES['ticket_attachments']['name'][0])) {
            return array();
        }
        
        $max_file_size = Priority_Ticket_Payment::get_option('max_file_size', '10') * 1024 * 1024; // Convert MB to bytes
        $allowed_file_types = Priority_Ticket_Payment::get_option('allowed_file_types', array('pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif'));
        
        if (is_string($allowed_file_types)) {
            $allowed_file_types = explode(',', $allowed_file_types);
        }
        
        $uploaded_files = array();
        $upload_dir = wp_upload_dir();
        $plugin_upload_dir = $upload_dir['basedir'] . '/priority-ticket-attachments';
        
        // Create directory if it doesn't exist
        if (!file_exists($plugin_upload_dir)) {
            wp_mkdir_p($plugin_upload_dir);
        }
        
        for ($i = 0; $i < count($_FILES['ticket_attachments']['name']); $i++) {
            if ($_FILES['ticket_attachments']['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }
            
            $file_name = $_FILES['ticket_attachments']['name'][$i];
            $file_size = $_FILES['ticket_attachments']['size'][$i];
            $file_tmp = $_FILES['ticket_attachments']['tmp_name'][$i];
            
            // Check file size
            if ($file_size > $max_file_size) {
                return new WP_Error('file_too_large', sprintf(__('File "%s" is too large. Maximum allowed size is %d MB.', 'priority-ticket-payment'), $file_name, Priority_Ticket_Payment::get_option('max_file_size', '10')));
            }
            
            // Check file type
            $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            if (!in_array($file_extension, $allowed_file_types)) {
                return new WP_Error('invalid_file_type', sprintf(__('File type "%s" is not allowed.', 'priority-ticket-payment'), $file_extension));
            }
            
            // Generate unique filename
            $unique_filename = wp_unique_filename($plugin_upload_dir, $file_name);
            $file_path = $plugin_upload_dir . '/' . $unique_filename;
            
            // Move uploaded file
            if (move_uploaded_file($file_tmp, $file_path)) {
                $uploaded_files[] = array(
                    'original_name' => $file_name,
                    'filename' => $unique_filename,
                    'path' => $file_path,
                    'size' => $file_size,
                    'type' => $file_extension,
                );
            }
        }
        
        return $uploaded_files;
    }
    
    /**
     * Create payment session
     */
    private function create_payment_session($submission_id, $amount) {
        // Check if WooCommerce is available
        if (class_exists('WooCommerce') && Priority_Ticket_Payment::get_option('enable_woocommerce_integration', 'yes') === 'yes') {
            return $this->create_woocommerce_order($submission_id, $amount);
        }
        
        // For now, return a placeholder URL
        // In a real implementation, you would integrate with Stripe, PayPal, or other payment processors
        $payment_url = add_query_arg(array(
            'action' => 'priority_ticket_payment',
            'submission_id' => $submission_id,
            'amount' => $amount,
        ), home_url());
        
        return $payment_url;
    }
    
    /**
     * Create WooCommerce order for payment
     */
    private function create_woocommerce_order($submission_id, $amount) {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_not_available', __('WooCommerce is not available.', 'priority-ticket-payment'));
        }
        
        try {
            // Create order
            $order = wc_create_order();
            
            // Add product/service item
            $item = new WC_Order_Item_Product();
            $item->set_name(__('Priority Support Ticket', 'priority-ticket-payment'));
            $item->set_quantity(1);
            $item->set_subtotal($amount);
            $item->set_total($amount);
            $order->add_item($item);
            
            // Set order details
            $order->set_customer_id(get_current_user_id());
            $order->set_status('pending');
            $order->calculate_totals();
            
            // Add meta data to link order with submission
            $order->add_meta_data('_priority_ticket_submission_id', $submission_id);
            $order->save();
            
            // Update submission with order ID
            Priority_Ticket_Payment_Database::update_submission($submission_id, array(
                'order_id' => $order->get_id(),
            ));
            
            // Return checkout URL
            return $order->get_checkout_payment_url();
            
        } catch (Exception $e) {
            return new WP_Error('order_creation_failed', $e->getMessage());
        }
    }
    
    /**
     * Send notification emails
     */
    private function send_notification_emails($submission_id, $form_data) {
        $submission = Priority_Ticket_Payment_Database::get_submission($submission_id);
        if (!$submission) {
            return;
        }
        
        $user = get_user_by('id', $submission['user_id']);
        $admin_email = get_option('admin_email');
        
        // Email to user
        $user_subject = sprintf(__('Priority Ticket Submitted - #%d', 'priority-ticket-payment'), $submission_id);
        $user_message = sprintf(
            __("Hello %s,\n\nYour priority ticket has been submitted successfully.\n\nTicket ID: #%d\nSubject: %s\nPriority: %s\nPrice: %s%s\n\nWe will review your request and get back to you as soon as possible.\n\nThank you!", 'priority-ticket-payment'),
            $user->display_name,
            $submission_id,
            $form_data['ticket_subject'],
            $form_data['ticket_priority'],
            Priority_Ticket_Payment::get_option('currency_symbol', '$'),
            number_format($submission['price'], 2)
        );
        
        wp_mail($user->user_email, $user_subject, $user_message);
        
        // Email to admin
        $admin_subject = sprintf(__('New Priority Ticket Submission - #%d', 'priority-ticket-payment'), $submission_id);
        $admin_message = sprintf(
            __("A new priority ticket has been submitted.\n\nTicket ID: #%d\nUser: %s (%s)\nSubject: %s\nPriority: %s\nCategory: %s\nPrice: %s%s\n\nDescription:\n%s\n\nLogin to the admin panel to view the full details.", 'priority-ticket-payment'),
            $submission_id,
            $user->display_name,
            $user->user_email,
            $form_data['ticket_subject'],
            $form_data['ticket_priority'],
            $form_data['ticket_category'],
            Priority_Ticket_Payment::get_option('currency_symbol', '$'),
            number_format($submission['price'], 2),
            $form_data['ticket_description']
        );
        
        wp_mail($admin_email, $admin_subject, $admin_message);
    }
    
    /**
     * Get submission details via AJAX
     */
    public function get_submission_details() {
        check_ajax_referer('priority_ticket_payment_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to view submission details.', 'priority-ticket-payment'));
        }
        
        $submission_id = intval($_POST['submission_id']);
        $submission = Priority_Ticket_Payment_Database::get_submission($submission_id);
        
        if (!$submission) {
            wp_send_json_error(__('Submission not found.', 'priority-ticket-payment'));
        }
        
        // Get user info
        $user = get_user_by('id', $submission['user_id']);
        
        // Prepare response data
        $response_data = array(
            'submission' => $submission,
            'user' => array(
                'display_name' => $user ? $user->display_name : __('Unknown User', 'priority-ticket-payment'),
                'email' => $user ? $user->user_email : '',
            ),
            'formatted_date' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($submission['created_at'])),
            'formatted_price' => Priority_Ticket_Payment::get_option('currency_symbol', '$') . number_format($submission['price'], 2),
        );
        
        wp_send_json_success($response_data);
    }
    
    /**
     * Handle file upload via AJAX
     */
    public function upload_ticket_attachment() {
        check_ajax_referer('priority_ticket_payment_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(__('You must be logged in to upload files.', 'priority-ticket-payment'));
        }
        
        if (empty($_FILES['file'])) {
            wp_send_json_error(__('No file provided.', 'priority-ticket-payment'));
        }
        
        // Temporarily set up $_FILES in the expected format for handle_file_uploads
        $_FILES['ticket_attachments'] = array(
            'name' => array($_FILES['file']['name']),
            'type' => array($_FILES['file']['type']),
            'tmp_name' => array($_FILES['file']['tmp_name']),
            'error' => array($_FILES['file']['error']),
            'size' => array($_FILES['file']['size']),
        );
        
        $upload_result = $this->handle_file_uploads();
        
        if (is_wp_error($upload_result)) {
            wp_send_json_error($upload_result->get_error_message());
        }
        
        if (empty($upload_result)) {
            wp_send_json_error(__('File upload failed.', 'priority-ticket-payment'));
        }
        
        wp_send_json_success(array(
            'file' => $upload_result[0],
            'message' => __('File uploaded successfully.', 'priority-ticket-payment'),
        ));
    }
} 