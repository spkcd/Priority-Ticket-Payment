<?php
/**
 * Elementor Pro Forms integration for Priority Ticket Payment
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Priority_Ticket_Payment_Elementor_Integration {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Debug: Check if Elementor integration is enabled
        $elementor_enabled = Priority_Ticket_Payment::get_option('enable_elementor_integration', 'no');
        error_log('Priority Ticket Payment: Elementor integration enabled: ' . $elementor_enabled);
        
        // Only proceed if Elementor integration is enabled
        if ($elementor_enabled !== 'yes') {
            error_log('Priority Ticket Payment: Elementor integration disabled - exiting constructor');
            return;
        }
        
        // Debug: Check if Elementor Pro is available
        $elementor_pro_available = defined('ELEMENTOR_PRO_VERSION');
        error_log('Priority Ticket Payment: Elementor Pro available: ' . ($elementor_pro_available ? 'yes' : 'no'));
        
        // Main form submission handler (full featured)
        add_action('elementor_pro/forms/new_record', array($this, 'handle_form_submission'), 10, 2);
        
        // Alternative: Static form handler (simplified version) - REMOVED SINCE MAIN HANDLER NOW WORKS
        // add_action('elementor_pro/forms/new_record', array(__CLASS__, 'handle_ticket_form'), 10, 2);
        
        add_action('init', array($this, 'maybe_create_woocommerce_products'));
        add_action('woocommerce_checkout_order_processed', array($this, 'handle_checkout_completion'), 10, 3);
        add_action('woocommerce_checkout_create_order', array($this, 'add_ticket_metadata_to_order'), 10, 2);
        
        // Store ticket ID in session for checkout
        add_action('woocommerce_before_checkout_form', array($this, 'store_ticket_data_in_session'));
        
        // Add Elementor Pro form action for redirects
        add_action('elementor_pro/forms/form_submitted', array($this, 'handle_elementor_redirect'), 10, 2);
        
        // Debug: Confirm hooks are registered
        error_log('Priority Ticket Payment: Elementor hooks registered successfully');
    }
    
    /**
     * Get target form ID from settings
     */
    private function get_target_form_id() {
        return Priority_Ticket_Payment::get_option('elementor_form_id', '');
    }
    
    /**
     * Handle Elementor Pro form submission
     */
    public function handle_form_submission($record, $handler) {
        // Debug: Log that the handler was called
        error_log('Priority Ticket Payment: handle_form_submission called');
        
        // Get form settings
        $form_name = $record->get_form_settings('form_name');
        $form_id = $record->get_form_settings('id');
        
        // Debug: Log form details
        error_log('Priority Ticket Payment: Form submitted - ID: ' . $form_id . ', Name: ' . $form_name);
        
        // Check if this is one of our configured forms
        $configured_forms = array(
            Priority_Ticket_Payment::get_option('ticket_form_id_a', ''),
            Priority_Ticket_Payment::get_option('ticket_form_id_b', ''),
            Priority_Ticket_Payment::get_option('ticket_form_id_c', ''),
        );
        
        // Debug: Log configured forms
        error_log('Priority Ticket Payment: Configured forms: ' . print_r($configured_forms, true));
        
        if (!in_array($form_id, array_filter($configured_forms))) {
            error_log('Priority Ticket Payment: Form ID not in configured forms - exiting handler');
            return; // Not one of our forms
        }
        
        // Debug: Confirm we're processing this form
        error_log('Priority Ticket Payment: Processing form submission for configured form: ' . $form_id);
        
        // Get submitted fields
        $raw_fields = $record->get('fields');
        $fields = $this->normalize_fields($raw_fields);
        
        // Validate required fields
        if (!$this->validate_submission($fields)) {
            error_log('Priority Ticket Payment: Invalid Elementor form submission - missing required fields');
            return;
        }
        
        // Determine user priority and corresponding pricing
        $user_id = get_current_user_id() ?: 0;
        $user_priority = Priority_Ticket_Payment_Elementor_Utils::get_user_ticket_priority($user_id);
        
        // Debug: Log user details and priority
        error_log('Priority Ticket Payment: User ID: ' . $user_id . ', Priority: ' . $user_priority);
        
        // Map priority to price and product
        $priority_config = $this->get_priority_config($user_priority, $form_id);
        
        if (!$priority_config) {
            error_log('Priority Ticket Payment: Unable to determine pricing configuration for priority: ' . $user_priority);
            return;
        }
        
        // Debug: Log priority configuration
        error_log('Priority Ticket Payment: Priority config: ' . print_r($priority_config, true));
        
        // Generate unique token
        $token = $this->generate_uuid();
        
        // Prepare form data for database
        $form_data = array(
            'ticket_subject' => $this->get_field_value($fields, 'name') . ' - Priority Support Request',
            'ticket_priority' => $this->normalize_urgency($this->get_field_value($fields, 'urgency')),
            'ticket_category' => 'general',
            'ticket_description' => $this->build_description($fields),
            'contact_email' => $this->get_field_value($fields, 'email'),
            'contact_phone' => $this->get_field_value($fields, 'phone'),
            'elementor_form_id' => $form_id,
            'user_priority' => $user_priority,
            'date_note' => $this->get_field_value($fields, 'date_note'),
            'coach' => $this->get_field_value($fields, 'coach'),
            'website' => $this->get_field_value($fields, 'website'),
            'original_fields' => $fields, // Store original field data
        );
        
        // Handle attachments if any (only for paid tiers)
        $attachments = array();
        if ($user_priority !== 'C') {
            $attachments = $this->process_attachments($fields);
        }
        
        // Insert submission into database
        $submission_data = array(
            'user_id' => $user_id,
            'form_data' => $form_data,
            'attachments' => $attachments,
            'price' => $priority_config['price'],
            'payment_status' => $priority_config['price'] > 0 ? 'pending_payment' : 'completed',
            'token' => $token,
        );
        
        $submission_id = Priority_Ticket_Payment_Database::insert_submission($submission_data);
        
        if (!$submission_id) {
            error_log('Priority Ticket Payment: Failed to save Elementor form submission to database');
            return;
        }
        
        // Log successful submission
        error_log(sprintf('Priority Ticket Payment: Elementor form submission saved with ID %d, token %s, priority %s', $submission_id, $token, $user_priority));
        
        // Handle based on whether payment is required
        if ($priority_config['price'] > 0) {
            // Redirect to WooCommerce checkout for paid tiers
            $this->redirect_to_checkout($priority_config, $token, $submission_id, $handler);
        } else {
            // For free tier, create support ticket immediately
            $this->handle_free_tier_submission($submission_id, $token, $handler);
        }
    }
    
    /**
     * Get priority configuration based on user priority and form ID
     */
    private function get_priority_config($user_priority, $form_id) {
        $config = array(
            'A' => array(
                'price' => 100.00,
                'product_id_setting' => 'product_id_a',
                'form_id_setting' => 'ticket_form_id_a',
                'tier' => 'premium',
                'label' => 'Premium (100€)',
            ),
            'B' => array(
                'price' => 50.00,
                'product_id_setting' => 'product_id_b',
                'form_id_setting' => 'ticket_form_id_b',
                'tier' => 'standard',
                'label' => 'Standard (50€)',
            ),
            'C' => array(
                'price' => 0.00,
                'product_id_setting' => null,
                'form_id_setting' => 'ticket_form_id_c',
                'tier' => 'free',
                'label' => 'Free',
            ),
        );
        
        if (!isset($config[$user_priority])) {
            return null;
        }
        
        $priority_config = $config[$user_priority];
        
        // Get product ID from settings if applicable
        if ($priority_config['product_id_setting']) {
            $priority_config['product_id'] = Priority_Ticket_Payment::get_option($priority_config['product_id_setting'], 0);
        }
        
        return $priority_config;
    }
    
    /**
     * Handle free tier submission (no payment required)
     */
    private function handle_free_tier_submission($submission_id, $token, $handler) {
        // Update submission status to completed
        Priority_Ticket_Payment_Database::update_submission($submission_id, array(
            'payment_status' => 'completed',
        ));
        
        // Create Awesome Support ticket immediately if enabled
        if (Priority_Ticket_Payment::get_option('enable_awesome_support_integration', 'yes') === 'yes') {
            $submission = Priority_Ticket_Payment_Database::get_submission($submission_id);
            if ($submission) {
                $this->create_free_tier_support_ticket($submission);
            }
        }
        
        // Redirect to thank you page or show success message
        $redirect_url = home_url('?ticket_submitted=1&ticket_id=' . $token);
        
        // Handle AJAX vs non-AJAX submissions
        if (wp_doing_ajax() || (defined('DOING_AJAX') && DOING_AJAX)) {
            // For AJAX submissions (Elementor Pro), use proper response data
            $handler->add_response_data('redirect_url', $redirect_url);
            $handler->add_response_data('success_message', 'Your free support ticket has been submitted successfully! Redirecting...');
            error_log('Priority Ticket Payment: Free tier - AJAX response data set for redirect');
            return;
        } else {
            // For non-AJAX submissions, use traditional redirect
            wp_redirect($redirect_url);
            exit;
        }
    }
    
    /**
     * Create support ticket for free tier
     */
    private function create_free_tier_support_ticket($submission) {
        if (!function_exists('wpas_insert_ticket')) {
            return false;
        }
        
        $form_data = $submission['form_data'];
        
        // Prepare ticket data
        $ticket_data = array(
            'post_title' => isset($form_data['ticket_subject']) ? $form_data['ticket_subject'] : 'Free Tier Support Request',
            'post_content' => $this->build_ticket_content($form_data, null), // No order for free tier
            'post_status' => 'queued',
            'post_author' => $submission['user_id'] ?: 0,
        );
        
        // Create the ticket
        $ticket_id = wpas_insert_ticket($ticket_data);
        
        if (is_wp_error($ticket_id)) {
            error_log('Priority Ticket Payment: Failed to create free tier support ticket - ' . $ticket_id->get_error_message());
            return false;
        }
        
        // Set ticket metadata for free tier
        update_post_meta($ticket_id, '_wpas_priority', 136); // c-ticket priority for free tier (term ID 136)
        update_post_meta($ticket_id, '_priority_ticket_submission_id', $submission['id']);
        update_post_meta($ticket_id, '_priority_ticket_token', $submission['token']);
        update_post_meta($ticket_id, '_priority_ticket_tier', 'free');
        
        // Store ticket ID in submission
        Priority_Ticket_Payment_Database::update_ticket_id($submission['id'], $ticket_id);
        
        error_log(sprintf('Priority Ticket Payment: Created free tier support ticket %d for submission %d with c-ticket priority (ID: 136)', $ticket_id, $submission['id']));
        
        return $ticket_id;
    }
    
    /**
     * Normalize form fields into a consistent format
     */
    private function normalize_fields($raw_fields) {
        $normalized = array();
        
        foreach ($raw_fields as $field_id => $field) {
            $field_data = array(
                'id' => $field_id,
                'title' => isset($field['title']) ? $field['title'] : $field_id,
                'value' => isset($field['value']) ? $field['value'] : '',
                'type' => isset($field['type']) ? $field['type'] : 'text',
                'raw_value' => isset($field['raw_value']) ? $field['raw_value'] : '',
            );
            
            $normalized[$field_id] = $field_data;
        }
        
        return $normalized;
    }
    
    /**
     * Get field value by field name or ID
     */
    private function get_field_value($fields, $field_name) {
        // Try exact match first
        if (isset($fields[$field_name])) {
            return sanitize_text_field($fields[$field_name]['value']);
        }
        
        // Try to find by title (case-insensitive)
        foreach ($fields as $field) {
            if (strtolower($field['title']) === strtolower($field_name)) {
                return sanitize_text_field($field['value']);
            }
        }
        
        // Try partial matches for common variations
        $variations = array(
            'name' => array('name', 'full_name', 'full name', 'client_name', 'your_name'),
            'email' => array('email', 'email_address', 'e-mail', 'your_email'),
            'phone' => array('phone', 'telephone', 'phone_number', 'contact_phone'),
            'urgency' => array('urgency', 'priority', 'urgent', 'priority_level'),
            'date_note' => array('date_note', 'date note', 'preferred_date', 'date'),
            'coach' => array('coach', 'trainer', 'preferred_coach', 'coach_preference'),
            'message' => array('message', 'description', 'details', 'comments', 'note'),
            'website' => array('website', 'url', 'site', 'website_url'),
        );
        
        if (isset($variations[$field_name])) {
            foreach ($variations[$field_name] as $variation) {
                foreach ($fields as $field) {
                    if (stripos($field['title'], $variation) !== false || stripos($field['id'], $variation) !== false) {
                        return sanitize_text_field($field['value']);
                    }
                }
            }
        }
        
        return '';
    }
    
    /**
     * Validate required fields
     */
    private function validate_submission($fields) {
        $required_fields = array('name', 'email');
        
        foreach ($required_fields as $field_name) {
            $value = $this->get_field_value($fields, $field_name);
            if (empty($value)) {
                return false;
            }
        }
        
        // Validate email format
        $email = $this->get_field_value($fields, 'email');
        if (!is_email($email)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Normalize urgency field to standard priority levels
     */
    private function normalize_urgency($urgency) {
        $urgency = strtolower(trim($urgency));
        
        $urgency_map = array(
            'urgent' => 'urgent',
            'emergency' => 'urgent',
            'asap' => 'urgent',
            'high' => 'high',
            'important' => 'high',
            'medium' => 'medium',
            'normal' => 'medium',
            'low' => 'low',
            'whenever' => 'low',
        );
        
        return isset($urgency_map[$urgency]) ? $urgency_map[$urgency] : 'medium';
    }
    
    /**
     * Build description from form fields
     */
    private function build_description($fields) {
        $description_parts = array();
        
        // Primary message
        $message = $this->get_field_value($fields, 'message');
        if (!empty($message)) {
            $description_parts[] = "Message: " . $message;
        }
        
        // Additional details
        $date_note = $this->get_field_value($fields, 'date_note');
        if (!empty($date_note)) {
            $description_parts[] = "Preferred Date/Note: " . $date_note;
        }
        
        $coach = $this->get_field_value($fields, 'coach');
        if (!empty($coach)) {
            $description_parts[] = "Preferred Coach: " . $coach;
        }
        
        $website = $this->get_field_value($fields, 'website');
        if (!empty($website)) {
            $description_parts[] = "Website: " . $website;
        }
        
        $description = implode("\n\n", $description_parts);
        
        return !empty($description) ? $description : 'Priority support request submitted via Elementor form.';
    }
    
    /**
     * Process attachments from form
     */
    private function process_attachments($fields) {
        $attachments = array();
        $max_files = 3;
        $uploaded_count = 0;
        
        // Create upload directory if it doesn't exist
        $upload_dir = $this->get_priority_tickets_upload_dir();
        if (!$upload_dir['success']) {
            error_log('Priority Ticket Payment: Failed to create upload directory - ' . $upload_dir['error']);
            return $attachments;
        }
        
        foreach ($fields as $field) {
            if ($field['type'] === 'upload' && !empty($field['value']) && $uploaded_count < $max_files) {
                // Elementor stores file URLs in the value
                $file_urls = is_array($field['value']) ? $field['value'] : array($field['value']);
                
                foreach ($file_urls as $file_url) {
                    if (!empty($file_url) && $uploaded_count < $max_files) {
                        $attachment = $this->download_and_save_file($file_url, $field['title'], $upload_dir['path']);
                        
                        if ($attachment) {
                            $attachments[] = $attachment;
                            $uploaded_count++;
                        }
                    }
                    
                    if ($uploaded_count >= $max_files) {
                        break;
                    }
                }
            }
            
            if ($uploaded_count >= $max_files) {
                break;
            }
        }
        
        return $attachments;
    }
    
    /**
     * Get or create priority tickets upload directory
     */
    private function get_priority_tickets_upload_dir() {
        $wp_upload_dir = wp_upload_dir();
        $priority_tickets_dir = $wp_upload_dir['basedir'] . '/priority-tickets';
        $priority_tickets_url = $wp_upload_dir['baseurl'] . '/priority-tickets';
        
        // Create directory if it doesn't exist
        if (!file_exists($priority_tickets_dir)) {
            if (!wp_mkdir_p($priority_tickets_dir)) {
                return array(
                    'success' => false,
                    'error' => 'Failed to create directory'
                );
            }
            
            // Create .htaccess file for security
            $htaccess_content = "Options -Indexes\n";
            $htaccess_content .= "deny from all\n";
            file_put_contents($priority_tickets_dir . '/.htaccess', $htaccess_content);
            
            // Create index.php file for security
            file_put_contents($priority_tickets_dir . '/index.php', '<?php // Silence is golden');
        }
        
        return array(
            'success' => true,
            'path' => $priority_tickets_dir,
            'url' => $priority_tickets_url
        );
    }
    
    /**
     * Download and save file to priority tickets directory
     */
    private function download_and_save_file($file_url, $field_name, $upload_path) {
        // Download file to temporary location
        $tmp_file = download_url($file_url);
        
        if (is_wp_error($tmp_file)) {
            error_log('Priority Ticket Payment: Failed to download file - ' . $tmp_file->get_error_message());
            return false;
        }
        
        // Get original filename and sanitize it
        $original_filename = basename(parse_url($file_url, PHP_URL_PATH));
        $sanitized_filename = $this->sanitize_filename($original_filename);
        
        // Get file extension and validate
        $file_extension = strtolower(pathinfo($sanitized_filename, PATHINFO_EXTENSION));
        if (!$this->is_allowed_file_type($file_extension)) {
            unlink($tmp_file);
            error_log('Priority Ticket Payment: File type not allowed - ' . $file_extension);
            return false;
        }
        
        // Check file size
        $file_size = filesize($tmp_file);
        $max_file_size = Priority_Ticket_Payment::get_option('max_file_size', '10') * 1024 * 1024; // Convert MB to bytes
        
        if ($file_size > $max_file_size) {
            unlink($tmp_file);
            error_log('Priority Ticket Payment: File too large - ' . $file_size . ' bytes');
            return false;
        }
        
        // Generate unique filename to prevent conflicts
        $unique_filename = $this->generate_unique_filename($sanitized_filename, $upload_path);
        $destination_path = $upload_path . '/' . $unique_filename;
        
        // Move file to final destination
        if (!copy($tmp_file, $destination_path)) {
            unlink($tmp_file);
            error_log('Priority Ticket Payment: Failed to move file to destination');
            return false;
        }
        
        // Clean up temporary file
        unlink($tmp_file);
        
        // Return attachment data
        return array(
            'original_name' => $original_filename,
            'filename' => $unique_filename,
            'path' => $destination_path,
            'size' => $file_size,
            'type' => $file_extension,
            'field_name' => $field_name,
            'mime_type' => $this->get_mime_type($destination_path),
            'upload_date' => current_time('mysql'),
        );
    }
    
    /**
     * Sanitize filename for safe storage
     */
    private function sanitize_filename($filename) {
        // Remove path information
        $filename = basename($filename);
        
        // Get file extension
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        
        // Sanitize the name part
        $name = sanitize_file_name($name);
        
        // Remove any remaining special characters
        $name = preg_replace('/[^a-zA-Z0-9\-_]/', '', $name);
        
        // Ensure name is not empty
        if (empty($name)) {
            $name = 'attachment';
        }
        
        // Limit length
        $name = substr($name, 0, 50);
        
        return $name . '.' . $extension;
    }
    
    /**
     * Generate unique filename to prevent conflicts
     */
    private function generate_unique_filename($filename, $upload_path) {
        $file_info = pathinfo($filename);
        $name = $file_info['filename'];
        $extension = isset($file_info['extension']) ? '.' . $file_info['extension'] : '';
        
        $counter = 1;
        $unique_filename = $filename;
        
        while (file_exists($upload_path . '/' . $unique_filename)) {
            $unique_filename = $name . '_' . $counter . $extension;
            $counter++;
        }
        
        return $unique_filename;
    }
    
    /**
     * Check if file type is allowed
     */
    private function is_allowed_file_type($extension) {
        $allowed_types = Priority_Ticket_Payment::get_option('allowed_file_types', array('pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif'));
        
        if (is_string($allowed_types)) {
            $allowed_types = explode(',', $allowed_types);
        }
        
        return in_array(strtolower($extension), array_map('strtolower', $allowed_types));
    }
    
    /**
     * Get MIME type of file
     */
    private function get_mime_type($file_path) {
        if (function_exists('finfo_file')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file_path);
            finfo_close($finfo);
            return $mime_type;
        } elseif (function_exists('mime_content_type')) {
            return mime_content_type($file_path);
        } else {
            // Fallback to extension-based detection
            $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
            $mime_types = array(
                'pdf' => 'application/pdf',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
            );
            
            return isset($mime_types[$extension]) ? $mime_types[$extension] : 'application/octet-stream';
        }
    }
    
    /**
     * Generate UUID token
     */
    private function generate_uuid() {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * Redirect to WooCommerce checkout
     */
    private function redirect_to_checkout($priority_config, $token, $submission_id, $handler) {
        if (!class_exists('WooCommerce')) {
            error_log('Priority Ticket Payment: WooCommerce not available for checkout redirect');
            return;
        }
        
        // Clear cart first
        WC()->cart->empty_cart();
        
        // Get product ID from configuration or create product
        $product_id = 0;
        if (isset($priority_config['product_id']) && $priority_config['product_id'] > 0) {
            $product_id = $priority_config['product_id'];
        }
        
        // If no product ID configured or product doesn't exist, create one
        if (!$product_id || !get_post($product_id)) {
            $product_id = $this->create_priority_ticket_product($priority_config['price']);
        }
        
        if (!$product_id) {
            error_log('Priority Ticket Payment: Failed to create or find WooCommerce product for price: ' . $priority_config['price']);
            return;
        }
        
        // Log the chosen product ID
        error_log('Priority Ticket Payment: Chosen product ID: ' . $product_id);
        
        // Add product to cart with ticket metadata
        $cart_item_key = WC()->cart->add_to_cart($product_id, 1, 0, array(), array(
            'priority_ticket_token' => $token,
            'priority_ticket_submission_id' => $submission_id,
            'priority_ticket_tier' => isset($priority_config['tier']) ? $priority_config['tier'] : 'unknown',
        ));
        
        if (!$cart_item_key) {
            error_log('Priority Ticket Payment: Failed to add product to cart');
            return;
        }
        
        // Store ticket data in session for checkout process
        WC()->session->set('priority_ticket_token', $token);
        WC()->session->set('priority_ticket_submission_id', $submission_id);
        
        // Get checkout URL
        $checkout_url = wc_get_checkout_url();
        
        // Log the checkout URL
        error_log('Priority Ticket Payment: Checkout URL: ' . $checkout_url);
        
        // Build checkout URL with token parameters
        $final_redirect_url = add_query_arg(array(
            'ticket_id' => $token,
            'submission_id' => $submission_id,
        ), $checkout_url);
        
        // Log the final redirect URL
        error_log('Priority Ticket Payment: Final redirect URL: ' . $final_redirect_url);
        
        // For Elementor Pro AJAX forms, we need to use actions instead of wp_redirect
        // Check if this is an AJAX request (Elementor Pro form submission)
        if (wp_doing_ajax() || (defined('DOING_AJAX') && DOING_AJAX)) {
            // Log that we're using AJAX redirect
            error_log('Priority Ticket Payment: Using AJAX redirect for Elementor Pro form');
            
            // Use Elementor's proper AJAX response system - redirect to checkout directly
            $handler->add_response_data('redirect_url', $final_redirect_url);
            
            // Also set a success message with JavaScript redirect as fallback
            $redirect_script = '<script type="text/javascript">setTimeout(function(){ window.location.href = "' . esc_url($final_redirect_url) . '"; }, 1000);</script>';
            $handler->add_response_data('success_message', 'Processing your request... ' . $redirect_script);
            
            // Log successful AJAX handling
            error_log('Priority Ticket Payment: AJAX response data set for redirect to: ' . $final_redirect_url);
            
            // Don't use header() or exit in AJAX context - let Elementor handle the response
            return;
        } else {
            // For non-AJAX submissions, use traditional redirect
            error_log('Priority Ticket Payment: Using traditional wp_redirect');
            wp_redirect($final_redirect_url);
            exit;
        }
    }
    
    /**
     * Create WooCommerce product for priority tickets
     */
    private function create_priority_ticket_product($price) {
        if (!class_exists('WC_Product_Simple')) {
            return false;
        }
        
        $product_name = sprintf(__('Priority Support Ticket - $%s', 'priority-ticket-payment'), number_format($price, 2));
        
        // Check if product already exists
        $existing_products = get_posts(array(
            'post_type' => 'product',
            'meta_query' => array(
                array(
                    'key' => '_priority_ticket_price_tier',
                    'value' => $price,
                    'compare' => '=',
                ),
            ),
            'posts_per_page' => 1,
        ));
        
        if (!empty($existing_products)) {
            return $existing_products[0]->ID;
        }
        
        // Create new product
        $product = new WC_Product_Simple();
        $product->set_name($product_name);
        $product->set_status('publish');
        $product->set_catalog_visibility('hidden'); // Hide from catalog
        $product->set_regular_price($price);
        $product->set_virtual(true);
        $product->set_downloadable(false);
        $product->set_sold_individually(true);
        $product->set_manage_stock(false);
        $product->set_stock_status('instock');
        $product->set_description(__('Priority support ticket with expedited handling and dedicated attention.', 'priority-ticket-payment'));
        $product->set_short_description(__('Get priority support with faster response times.', 'priority-ticket-payment'));
        
        $product_id = $product->save();
        
        if ($product_id) {
            // Add custom meta to identify this as a priority ticket product
            update_post_meta($product_id, '_priority_ticket_product', 'yes');
            update_post_meta($product_id, '_priority_ticket_price_tier', $price);
            
            // Trigger action to mark product as priority ticket
            do_action('priority_ticket_payment_product_created', $product_id);
            
            // Update class constants (for next time)
            if ($price >= 100 && !Priority_Ticket_Payment::get_option('woocommerce_product_id_100', 0)) {
                // You might want to store these in options instead of constants
                update_option('priority_ticket_payment_product_id_100', $product_id);
            } elseif ($price < 100 && !Priority_Ticket_Payment::get_option('woocommerce_product_id_50', 0)) {
                update_option('priority_ticket_payment_product_id_50', $product_id);
            }
        }
        
        return $product_id;
    }
    
    /**
     * Maybe create WooCommerce products on init
     */
    public function maybe_create_woocommerce_products() {
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        // Check if products need to be created
        $product_50_id = Priority_Ticket_Payment::get_option('woocommerce_product_id_50', 0);
        $product_100_id = Priority_Ticket_Payment::get_option('woocommerce_product_id_100', 0);
        
        if (!$product_50_id || !get_post($product_50_id)) {
            $product_id = $this->create_priority_ticket_product(50.00);
            if ($product_id) {
                update_option('priority_ticket_payment_product_id_50', $product_id);
            }
        }
        
        if (!$product_100_id || !get_post($product_100_id)) {
            $product_id = $this->create_priority_ticket_product(100.00);
            if ($product_id) {
                update_option('priority_ticket_payment_product_id_100', $product_id);
            }
        }
    }
    
    /**
     * Handle checkout completion
     */
    public function handle_checkout_completion($order_id, $posted_data, $order) {
        // Check if this order contains priority ticket items
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $is_priority_ticket = get_post_meta($product_id, '_priority_ticket_product', true);
            
            if ($is_priority_ticket === 'yes') {
                // Get cart item data
                $token = '';
                $submission_id = 0;
                
                // Try to get from order meta or cart session
                $cart_item_data = $item->get_meta_data();
                foreach ($cart_item_data as $meta) {
                    if ($meta->get_data()['key'] === 'priority_ticket_token') {
                        $token = $meta->get_data()['value'];
                    }
                    if ($meta->get_data()['key'] === 'priority_ticket_submission_id') {
                        $submission_id = $meta->get_data()['value'];
                    }
                }
                
                // Also check URL parameters if available
                if (empty($token) && isset($_GET['ticket_id'])) {
                    $token = sanitize_text_field($_GET['ticket_id']);
                }
                if (empty($submission_id) && isset($_GET['submission_id'])) {
                    $submission_id = intval($_GET['submission_id']);
                }
                
                // Update submission with order ID and payment status
                if ($submission_id) {
                    Priority_Ticket_Payment_Database::update_submission($submission_id, array(
                        'order_id' => $order_id,
                        'payment_status' => 'processing',
                    ));
                    
                    // Store submission ID in order meta for future reference
                    $order->add_meta_data('_priority_ticket_submission_id', $submission_id);
                    $order->save();
                    
                    // Trigger action for other integrations
                    do_action('priority_ticket_payment_elementor_order_completed', $order_id, $submission_id, $token);
                }
                
                break;
            }
        }
    }
    
    /**
     * Get product ID for price tier
     */
    private function get_product_id_for_price($price) {
        if ($price >= 100) {
            $product_id = Priority_Ticket_Payment::get_option('woocommerce_product_id_100', 0);
            return $product_id ?: get_option('priority_ticket_payment_product_id_100', 0);
        } else {
            $product_id = Priority_Ticket_Payment::get_option('woocommerce_product_id_50', 0);
            return $product_id ?: get_option('priority_ticket_payment_product_id_50', 0);
        }
    }
    
    /**
     * Add ticket metadata to order
     */
    public function add_ticket_metadata_to_order($order, $data) {
        // Try to get ticket ID and submission ID from multiple sources
        $ticket_id = '';
        $submission_id = 0;
        
        // First, try URL parameters
        if (isset($_GET['ticket_id']) && isset($_GET['submission_id'])) {
            $ticket_id = sanitize_text_field($_GET['ticket_id']);
            $submission_id = intval($_GET['submission_id']);
        }
        
        // Fallback to session data
        if (empty($ticket_id) || empty($submission_id)) {
            $session_ticket_id = WC()->session->get('priority_ticket_token');
            $session_submission_id = WC()->session->get('priority_ticket_submission_id');
            
            if ($session_ticket_id && $session_submission_id) {
                $ticket_id = $session_ticket_id;
                $submission_id = $session_submission_id;
            }
        }
        
        // Also check cart item data for ticket metadata
        if ((empty($ticket_id) || empty($submission_id)) && WC()->cart) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                if (isset($cart_item['priority_ticket_token']) && isset($cart_item['priority_ticket_submission_id'])) {
                    $ticket_id = $cart_item['priority_ticket_token'];
                    $submission_id = $cart_item['priority_ticket_submission_id'];
                    break;
                }
            }
        }
        
        if ($ticket_id && $submission_id) {
            // Get submission data to validate the token
            $submission = Priority_Ticket_Payment_Database::get_submission($submission_id);
            
            if ($submission && $submission['token'] === $ticket_id) {
                // Add ticket metadata to order
                $order->add_meta_data('_priority_ticket_token', $ticket_id);
                $order->add_meta_data('_priority_ticket_submission_id', $submission_id);
                $order->add_meta_data('_priority_ticket_tier', isset($submission['form_data']['user_priority']) ? $submission['form_data']['user_priority'] : 'unknown');
                
                // Store form data for later use
                if (is_array($submission['form_data'])) {
                    foreach ($submission['form_data'] as $key => $value) {
                        if (is_string($value) || is_numeric($value)) {
                            $order->add_meta_data('_priority_ticket_' . $key, $value);
                        }
                    }
                }
                
                // Store attachments info
                if (!empty($submission['attachments'])) {
                    $order->add_meta_data('_priority_ticket_attachments', $submission['attachments']);
                }
                
                // Clear session data after successful order creation
                WC()->session->__unset('priority_ticket_token');
                WC()->session->__unset('priority_ticket_submission_id');
                
                error_log(sprintf('Priority Ticket Payment: Added ticket metadata to order %d (ticket: %s, submission: %d)', $order->get_id(), $ticket_id, $submission_id));
            } else {
                error_log('Priority Ticket Payment: Invalid ticket token or submission ID for order ' . $order->get_id());
            }
        } else {
            error_log('Priority Ticket Payment: No ticket metadata found for order ' . $order->get_id());
        }
    }
    
    /**
     * Store ticket data in session for checkout
     */
    public function store_ticket_data_in_session() {
        // Check if ticket data is available in the session
        if (isset($_GET['ticket_id']) && isset($_GET['submission_id'])) {
            $ticket_id = sanitize_text_field($_GET['ticket_id']);
            $submission_id = intval($_GET['submission_id']);
            
            // Store ticket data in session
            WC()->session->set('priority_ticket_token', $ticket_id);
            WC()->session->set('priority_ticket_submission_id', $submission_id);
        }
    }
    
    /**
     * Handle ticket form submission (alternative simplified method)
     * 
     * This is a simplified static method for handling form submissions
     * For full functionality, use the main handle_form_submission method
     */
    public static function handle_ticket_form($record, $handler) {
        // Debug: Log that the static handler was called
        error_log('Priority Ticket Payment: static handle_ticket_form called');
        
        $form_name = $record->get_form_settings('form_name');
        $user_id = get_current_user_id();
        
        // Debug: Log form and user details
        error_log('Priority Ticket Payment: Static handler - Form: ' . $form_name . ', User ID: ' . $user_id);
        
        // Get and normalize form data
        $raw_fields = $record->get('fields');
        $form_data = array();
        
        // Convert Elementor field format to our format
        foreach ($raw_fields as $field_id => $field) {
            $form_data[$field_id] = isset($field['value']) ? $field['value'] : '';
        }
        
        // Determine priority
        $priority = Priority_Ticket_Payment_Elementor_Utils::get_user_ticket_priority($user_id);
        
        // Determine price based on priority
        $price = 0;
        switch ($priority) {
            case 'A':
                $price = 100;
                break;
            case 'B':
                $price = 50;
                break;
            case 'C':
            default:
                $price = 0;
                break;
        }
        
        // Generate submission and token
        $token = self::generate_uuid_static();
        
        // Prepare submission data
        $submission_data = array(
            'user_id' => $user_id,
            'form_data' => $form_data,
            'attachments' => array(), // File attachments to be processed separately
            'price' => $price,
            'payment_status' => $price > 0 ? 'pending_payment' : 'completed',
            'token' => $token,
        );
        
        // Store submission in database
        $submission_id = Priority_Ticket_Payment_Database::insert_submission($submission_data);
        
        if (!$submission_id) {
            error_log('Priority Ticket Payment: Failed to store submission in handle_ticket_form');
            wp_die(__('Failed to process form submission. Please try again.', 'priority-ticket-payment'));
        }
        
        // Handle free tier (Priority C)
        if ($priority === 'C' || $price == 0) {
            // For free tier, create ticket immediately if Awesome Support is enabled
            if (Priority_Ticket_Payment::get_option('enable_awesome_support_integration', 'yes') === 'yes') {
                $submission = Priority_Ticket_Payment_Database::get_submission($submission_id);
                if ($submission) {
                    // Create free tier ticket (this will be handled by the existing method in elementor integration)
                    $instance = new self();
                    $instance->create_free_tier_support_ticket($submission);
                }
            }
            
            // Redirect to thank you page
            $redirect_url = home_url('?ticket_submitted=1&ticket_id=' . $token . '&tier=free');
            wp_safe_redirect($redirect_url);
            exit;
        }
        
        // Handle paid tiers (A and B)
        // Get product ID from plugin settings
        $product_id = ($priority === 'B' ? Priority_Ticket_Payment::get_option('product_id_b', 0) : Priority_Ticket_Payment::get_option('product_id_a', 0));
        
        // Log the chosen product ID
        error_log('Priority Ticket Payment: Static handler - Chosen product ID: ' . $product_id . ' (Priority: ' . $priority . ')');
        
        // If no product configured, create a default one
        if (!$product_id) {
            $instance = new self();
            $product_id = $instance->create_priority_ticket_product($price);
            error_log('Priority Ticket Payment: Static handler - Created new product ID: ' . $product_id);
        }
        
        if (!$product_id) {
            error_log('Priority Ticket Payment: Failed to get or create product for priority ' . $priority);
            wp_die(__('Payment processing unavailable. Please contact support.', 'priority-ticket-payment'));
        }
        
        // Add product to cart if WooCommerce is available
        if (class_exists('WooCommerce') && function_exists('wc_get_checkout_url')) {
            // Clear cart first
            WC()->cart->empty_cart();
            
            // Add product to cart with metadata
            $cart_item_key = WC()->cart->add_to_cart($product_id, 1, 0, array(), array(
                'priority_ticket_token' => $token,
                'priority_ticket_submission_id' => $submission_id,
                'priority_ticket_tier' => $priority,
            ));
            
            if (!$cart_item_key) {
                error_log('Priority Ticket Payment: Failed to add product to cart in handle_ticket_form');
                wp_die(__('Failed to add item to cart. Please try again.', 'priority-ticket-payment'));
            }
            
            // Store data in session
            WC()->session->set('priority_ticket_token', $token);
            WC()->session->set('priority_ticket_submission_id', $submission_id);
            
            // Build checkout URL with metadata
            $checkout_url = wc_get_checkout_url();
            
            // Log the checkout URL
            error_log('Priority Ticket Payment: Static handler - Checkout URL: ' . $checkout_url);
            
            $redirect_url = add_query_arg(array(
                'ticket_id' => $token,
                'submission_id' => $submission_id,
                'tier' => $priority
            ), $checkout_url);
            
            // Log the final redirect URL
            error_log('Priority Ticket Payment: Static handler - Final redirect URL: ' . $redirect_url);
            
            // SAFER FALLBACK: Redirect to homepage with add-to-cart and ticket metadata
            $safer_fallback_url = add_query_arg(array(
                'add-to-cart' => $product_id,
                'ticket_id' => $token,
                'submission_id' => $submission_id,
                'tier' => $priority
            ), home_url('/'));
            
            error_log('Priority Ticket Payment: Static handler - SAFER FALLBACK - Homepage with add-to-cart URL: ' . $safer_fallback_url);
            
            // Redirect to homepage with add-to-cart (using safer fallback approach)
            wp_safe_redirect($safer_fallback_url);
            exit;
        } else {
            error_log('Priority Ticket Payment: WooCommerce not available in handle_ticket_form');
            wp_die(__('Payment processing unavailable. Please contact support.', 'priority-ticket-payment'));
        }
    }
    
    /**
     * Static method to generate UUID (for use in static context)
     */
    private static function generate_uuid_static() {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * Handle Elementor Pro form redirect after submission
     */
    public function handle_elementor_redirect($record, $ajax_handler) {
        // Check if we have a stored redirect URL
        $redirect_url = get_transient('priority_ticket_redirect_' . get_current_user_id());
        
        if ($redirect_url) {
            // Clear the transient
            delete_transient('priority_ticket_redirect_' . get_current_user_id());
            
            // Set the redirect URL for Elementor to handle
            $ajax_handler->add_response_data('redirect_url', $redirect_url);
            
            error_log('Priority Ticket Payment: Elementor redirect handler - URL: ' . $redirect_url);
        }
    }
} 