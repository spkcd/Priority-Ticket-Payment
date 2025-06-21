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
        // Check if Elementor integration is enabled
        $elementor_enabled = Priority_Ticket_Payment::get_option('enable_elementor_integration', 'no');
        
        // Only proceed if Elementor integration is enabled
        if ($elementor_enabled !== 'yes') {
            return;
        }
        
        // Check if Elementor Pro is available
        $elementor_pro_available = defined('ELEMENTOR_PRO_VERSION');
        if (!$elementor_pro_available) {
            return;
        }
        
        // Main form submission handler (full featured)
        add_action('elementor_pro/forms/new_record', array($this, 'handle_form_submission'), 10, 2);
        
        // Capture all Elementor form submissions for session storage
        add_action('elementor_pro/forms/new_record', array($this, 'capture_form_data_to_session'), 5, 2);
        
        add_action('init', array($this, 'maybe_create_woocommerce_products'));
        add_action('woocommerce_checkout_order_processed', array($this, 'handle_checkout_completion'), 10, 3);
        add_action('woocommerce_checkout_create_order', array($this, 'add_ticket_metadata_to_order'), 10, 2);
        
        // Store ticket ID in session for checkout
        add_action('woocommerce_before_checkout_form', array($this, 'store_ticket_data_in_session'));
        
        // Add Elementor Pro form action for redirects
        add_action('elementor_pro/forms/form_submitted', array($this, 'handle_elementor_redirect'), 10, 2);
        
        // Add auto-population JavaScript for all configured forms
        add_action('wp_enqueue_scripts', array($this, 'enqueue_auto_population_script'));
        
        // Add WooCommerce checkout auto-population from session data
        add_action('wp_enqueue_scripts', array($this, 'enqueue_woocommerce_checkout_population'));
    }
    
    /**
     * Capture Elementor form submission data and store in PHP session
     * This runs for ALL Elementor forms, not just configured ones
     */
    public function capture_form_data_to_session($record, $handler) {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Get submitted fields
        $raw_fields = $record->get('fields');
        
        // Log raw fields for debugging file uploads
        error_log('Priority Ticket Payment: Raw Elementor fields - ' . print_r($raw_fields, true));
        
        $fields = $this->normalize_fields($raw_fields);
        
        // Extract form data with flexible field detection
        $checkout_data = array();
        
        // Try to extract name fields (first name, last name, or combined name)
        $first_name = $this->extract_field_by_labels($fields, array('first_name', 'firstname', 'first-name', 'fname', 'vorname'));
        $last_name = $this->extract_field_by_labels($fields, array('last_name', 'lastname', 'last-name', 'lname', 'nachname', 'surname'));
        $full_name = $this->extract_field_by_labels($fields, array('name', 'full_name', 'fullname', 'full-name', 'customer_name', 'user_name', 'username'));
        
        // Handle name fields
        if (!empty($first_name) && !empty($last_name)) {
            $checkout_data['first_name'] = sanitize_text_field($first_name);
            $checkout_data['last_name'] = sanitize_text_field($last_name);
            $checkout_data['name'] = sanitize_text_field($first_name . ' ' . $last_name);
        } elseif (!empty($full_name)) {
            $checkout_data['name'] = sanitize_text_field($full_name);
            // Try to split full name into first and last
            $name_parts = explode(' ', trim($full_name), 2);
            $checkout_data['first_name'] = sanitize_text_field($name_parts[0]);
            $checkout_data['last_name'] = isset($name_parts[1]) ? sanitize_text_field($name_parts[1]) : '';
        }
        
        // Extract email
        $email = $this->extract_field_by_labels($fields, array('email', 'e-mail', 'e_mail', 'email_address', 'user_email', 'customer_email'));
        if (!empty($email)) {
            $checkout_data['email'] = sanitize_email($email);
        }
        
        // Extract phone
        $phone = $this->extract_field_by_labels($fields, array('phone', 'telephone', 'tel', 'phone_number', 'phonenumber', 'mobile', 'contact_number', 'telefon'));
        if (!empty($phone)) {
            $checkout_data['phone'] = sanitize_text_field($phone);
        }
        
        // Only store if we have at least email or name
        if (!empty($checkout_data['email']) || !empty($checkout_data['name'])) {
            // Add form metadata
            $checkout_data['form_id'] = $record->get_form_settings('id');
            $checkout_data['form_name'] = $record->get_form_settings('form_name');
            $checkout_data['timestamp'] = current_time('mysql');
            
            // Store in session
            $_SESSION['ptp_checkout_data'] = $checkout_data;
            
            // Log for debugging
            error_log('Priority Ticket Payment: Captured form data to session - Form ID: ' . $checkout_data['form_id'] . ', Email: ' . ($checkout_data['email'] ?? 'N/A') . ', Name: ' . ($checkout_data['name'] ?? 'N/A'));
        }
    }
    
    /**
     * Extract field value by trying multiple possible labels/IDs
     */
    private function extract_field_by_labels($fields, $possible_labels) {
        foreach ($possible_labels as $label) {
            $value = $this->get_field_value($fields, $label);
            if (!empty($value)) {
                return $value;
            }
        }
        return '';
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
        // Get form settings
        $form_name = $record->get_form_settings('form_name');
        $form_id = $record->get_form_settings('id');
        
        // Check if this is one of our configured forms
        $configured_forms = array();
        
        // Get all configured form IDs
        $form_id_a = Priority_Ticket_Payment::get_option('ticket_form_id_a', '');
        $form_id_b = Priority_Ticket_Payment::get_option('ticket_form_id_b', '');
        $form_id_c = Priority_Ticket_Payment::get_option('ticket_form_id_c', '');
        
        // Only add non-empty form IDs
        if (!empty($form_id_a)) $configured_forms[] = $form_id_a;
        if (!empty($form_id_b)) $configured_forms[] = $form_id_b;
        if (!empty($form_id_c)) $configured_forms[] = $form_id_c;
        
        // Add additional form IDs from settings
        $additional_form_ids = Priority_Ticket_Payment::get_option('additional_form_ids', '');
        if (!empty($additional_form_ids)) {
            // Split by comma and clean up whitespace
            $additional_ids = array_map('trim', explode(',', $additional_form_ids));
            $additional_ids = array_filter($additional_ids); // Remove empty values
            $configured_forms = array_merge($configured_forms, $additional_ids);
        }
        
        // Remove duplicates and empty values
        $configured_forms = array_unique(array_filter($configured_forms));
        
        // Debug logging
        error_log('Priority Ticket Payment: Form submission detected - Form ID: ' . $form_id . ', Configured forms: ' . implode(', ', $configured_forms));
        
        // Check if current form ID matches any configured form
        if (!in_array($form_id, $configured_forms, true)) {
            error_log('Priority Ticket Payment: Form ID ' . $form_id . ' not in configured forms, skipping processing');
            return; // Not one of our forms
        }
        
        error_log('Priority Ticket Payment: Processing form ID ' . $form_id . ' as it matches configured forms');
        
        // Get submitted fields
        $raw_fields = $record->get('fields');
        $fields = $this->normalize_fields($raw_fields);
        
        // Validate required fields
        if (!$this->validate_submission($fields)) {
            error_log('Priority Ticket Payment: Invalid form submission - missing required fields');
            return;
        }
        
        // Get message field for character limit validation
        $message_field = $this->get_field_value($fields, 'message');
        
        // Validate character limits based on form tier
        $free_form_id = Priority_Ticket_Payment::get_option('ticket_form_id_d', '');
        $is_free_tier = ($form_id === $free_form_id);
        $character_limit = $is_free_tier ? 800 : 2500;
        
        if (!empty($message_field) && strlen($message_field) > $character_limit) {
            $tier_name = $is_free_tier ? 'Free' : 'Paid';
            error_log(sprintf('Priority Ticket Payment: Message exceeds character limit for %s tier - %d characters (limit: %d)', 
                $tier_name, strlen($message_field), $character_limit));
            // Truncate the message to the allowed limit
            $message_field = substr($message_field, 0, $character_limit);
        }
        
        // Determine user priority and corresponding pricing
        $user_id = get_current_user_id() ?: 0;
        
        // Check if this is the free form (Priority D)
        $free_form_id = Priority_Ticket_Payment::get_option('ticket_form_id_d', '');
        if ($form_id === $free_form_id) {
            $user_priority = 'D'; // Free tier for everyone
        } else {
            $user_priority = Priority_Ticket_Payment_Elementor_Utils::get_user_ticket_priority($user_id);
        }
        
        // Map priority to price and product
        $priority_config = $this->get_priority_config($user_priority, $form_id);
        
        if (!$priority_config) {
            error_log('Priority Ticket Payment: Unable to determine pricing configuration for priority: ' . $user_priority);
            return;
        }
        
        // Generate unique token
        $token = $this->generate_uuid();
        
        // Prepare form data for database
        // Extract subject field, with fallback logic
        $subject_field = $this->get_field_value($fields, 'subject');
        if (empty($subject_field)) {
            $subject_field = $this->get_field_value($fields, 'message');
            if (empty($subject_field)) {
                $subject_field = $this->get_field_value($fields, 'name') . ' - Priority Support Request';
            }
        }
        
        // Extract field values with debugging
        $urgency_value = $this->get_field_value($fields, 'urgency');
        $website_value = $this->get_field_value($fields, 'website');
        
        error_log('Priority Ticket Payment: Field extraction - Urgency: "' . $urgency_value . '", Website: "' . $website_value . '"');
        
        $form_data = array(
            'name' => sanitize_text_field($this->get_field_value($fields, 'name')),
            'email' => sanitize_email($this->get_field_value($fields, 'email')),
            'subject' => sanitize_text_field($this->get_field_value($fields, 'subject')),
            'message' => sanitize_textarea_field($message_field), // Use validated message with character limit
            'urgency' => sanitize_text_field($urgency_value),
            'coach' => sanitize_text_field($this->get_field_value($fields, 'coach')),
            'website' => sanitize_url($website_value),
            'elementor_form_id' => $form_id,
            'user_priority' => $user_priority,
        );
        
        // Log the final form data structure
        error_log('Priority Ticket Payment: Final form data - ' . print_r($form_data, true));
        
        // Handle attachments if any (support up to 3 files for all tiers)
        $attachments = $this->process_attachments($fields);
        
        // Insert submission into database
        $submission_data = array(
            'user_id' => $user_id,
            'form_data' => $form_data,
            'attachments' => $attachments,
            'price' => $priority_config['price'],
            'payment_status' => 'pending_payment',
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
            // For truly free tier submissions (this should rarely happen now)
            $this->handle_free_tier_submission($submission_id, $token, $handler);
        }
    }
    
    /**
     * Get priority configuration based on user priority and form ID
     */
    private function get_priority_config($user_priority, $form_id) {
        $config = array(
            'A' => array(
                'price' => 0.00,
                'product_id_setting' => null,
                'form_id_setting' => 'ticket_form_id_a',
                'tier' => 'coaching_free',
                'label' => 'Coaching Client (Free)',
            ),
            'B' => array(
                'price' => 50.00,
                'product_id_setting' => 'product_id_b',
                'form_id_setting' => 'ticket_form_id_b',
                'tier' => 'standard',
                'label' => 'Standard (50€)',
            ),
            'C' => array(
                'price' => 100.00,
                'product_id_setting' => 'product_id_c',
                'form_id_setting' => 'ticket_form_id_c',
                'tier' => 'basic',
                'label' => 'Basic (100€)',
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
     * Handle submissions that don't require payment (legacy free tier method)
     * Note: This method is maintained for backwards compatibility but should rarely be used
     * since all current tiers now require payment.
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
            $handler->add_response_data('success_message', 'Your support ticket has been submitted successfully! Redirecting...');
            error_log('Priority Ticket Payment: Legacy free tier - AJAX response data set for redirect');
            return;
        } else {
            // For non-AJAX submissions, use traditional redirect
            wp_redirect($redirect_url);
            exit;
        }
    }
    
    /**
     * Create support ticket directly (legacy method for free tier)
     * Note: This method is maintained for backwards compatibility
     */
    private function create_free_tier_support_ticket($submission) {
        if (!function_exists('wpas_insert_ticket')) {
            return false;
        }
        
        $form_data = $submission['form_data'];
        
        // Build ticket title using subject field as primary source
        $ticket_title = '';
        
        // First, try to use the subject field
        if (isset($form_data['subject']) && !empty(trim($form_data['subject']))) {
            $ticket_title = sanitize_text_field(trim($form_data['subject']));
        }
        
        // If no subject, fall back to ticket_subject
        if (empty($ticket_title) && isset($form_data['ticket_subject'])) {
            $ticket_title = sanitize_text_field($form_data['ticket_subject']);
        }
        
        // If still empty, fall back to message field
        if (empty($ticket_title) && isset($form_data['message']) && !empty(trim($form_data['message']))) {
            // Use first 50 characters of message as subject
            $message_subject = substr(trim($form_data['message']), 0, 50);
            if (strlen(trim($form_data['message'])) > 50) {
                $message_subject .= '...';
            }
            $ticket_title = sanitize_text_field($message_subject);
        }
        
        // Final fallback to generic title
        if (empty($ticket_title)) {
            $ticket_title = 'Free Support Request';
        }
        
        // Prepare ticket data
        $ticket_data = array(
            'post_title' => $ticket_title,
            'post_content' => $this->build_ticket_content($form_data, null, isset($submission['attachments']) ? $submission['attachments'] : array()), // No order for free submissions
            'post_status' => 'queued',
            'post_author' => $submission['user_id'] ?: 0,
        );
        
        // Create the ticket
        $ticket_id = wpas_insert_ticket($ticket_data);
        
        if (is_wp_error($ticket_id)) {
            error_log('Priority Ticket Payment: Failed to create support ticket - ' . $ticket_id->get_error_message());
            return false;
        }
        
        // Determine priority term ID based on user priority
        $user_priority = isset($form_data['user_priority']) ? $form_data['user_priority'] : 'C';
        $priority_term_id = 134; // Default to a-ticket for coaching clients
        
        if ($user_priority === 'A') {
            $priority_term_id = 134; // a-ticket priority for coaching clients (free)
            $tier_name = 'coaching_free';
        } else {
            $priority_term_id = 136; // c-ticket priority for basic tier
            $tier_name = 'basic';
        }
        
        // Set ticket metadata
        update_post_meta($ticket_id, '_wpas_priority', $priority_term_id);
        update_post_meta($ticket_id, '_priority_ticket_submission_id', $submission['id']);
        update_post_meta($ticket_id, '_priority_ticket_token', $submission['token']);
        update_post_meta($ticket_id, '_priority_ticket_tier', $tier_name);
        
        // Store ticket ID in submission
        Priority_Ticket_Payment_Database::update_ticket_id($submission['id'], $ticket_id);
        
        error_log(sprintf('Priority Ticket Payment: Created support ticket %d for submission %d with priority %s (ID: %d)', $ticket_id, $submission['id'], $user_priority, $priority_term_id));
        
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
            'subject' => array('subject', 'title', 'topic', 'issue_title', 'ticket_subject', 'betreff'),
            'urgency' => array('urgency', 'priority', 'urgent', 'priority_level', 'dringend', 'dringlichkeit', 'termin', 'deadline'),
            'date_note' => array('date_note', 'date note', 'preferred_date', 'date'),
            'coach' => array('coach', 'trainer', 'preferred_coach', 'coach_preference', 'wer ist ihr coach'),
            'message' => array('message', 'description', 'details', 'comments', 'note', 'nachricht'),
            'website' => array('website', 'url', 'site', 'website_url', 'webseite', 'hinweis', 'hinweis auf eine webseite'),
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
        
        // Special handling for specific German field titles from the form
        $german_field_mapping = array(
            'urgency' => array(
                'Wie dringend ist die Beantwortung? Gibt es einen Termin zu beachten?',
                'Wie dringend ist die Beantwortung',
                'Gibt es einen Termin zu beachten'
            ),
            'website' => array(
                'Hinweis auf eine Webseite',
                'Hinweis auf eine webseite',
                'Webseite'
            ),
            'coach' => array(
                'Wer ist Ihr Coach?',
                'Wer ist ihr Coach',
                'Coach'
            ),
            'subject' => array(
                'Betreff',
                'Subject'
            ),
            'message' => array(
                'Nachricht',
                'Message'
            ),
            'phone' => array(
                'Phone',
                'Telefon'
            )
        );
        
        if (isset($german_field_mapping[$field_name])) {
            foreach ($german_field_mapping[$field_name] as $german_title) {
                foreach ($fields as $field) {
                    if (stripos($field['title'], $german_title) !== false) {
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
     * Build ticket content from form data, order, and attachments
     */
    private function build_ticket_content($form_data, $order = null, $attachments = array()) {
        // Ensure form_data is an array
        if (!is_array($form_data)) {
            error_log('Priority Ticket Payment: form_data is not an array in build_ticket_content, using empty array');
            $form_data = array();
        }
        
        $content_parts = array();
        
        // Priority/urgency indicator - use the actual user priority tier if available
        $priority_display = 'Basic (c-ticket)'; // Default for basic tier
        if (!empty($form_data['user_priority'])) {
            $priority_map = array(
                'A' => 'Coaching Client (a-ticket)',
                'B' => 'Standard (b-ticket)', 
                'C' => 'Basic (c-ticket)'
            );
            $priority_display = isset($priority_map[$form_data['user_priority']]) ? $priority_map[$form_data['user_priority']] : $form_data['user_priority'];
        } elseif (!empty($form_data['ticket_priority'])) {
            $priority_display = ucfirst($form_data['ticket_priority']);
        }
        $content_parts[] = '**Priority Level:** ' . $priority_display;
        
        // Main message/description
        if (!empty($form_data['ticket_description'])) {
            $content_parts[] = '**Description:**' . "\n" . $form_data['ticket_description'];
        } elseif (!empty($form_data['message'])) {
            $content_parts[] = '**Description:**' . "\n" . $form_data['message'];
        }
        
        // Additional details with spacing
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
        
        // Contact information - try multiple field variations with better formatting
        $contact_info = array();
        
        // Email - try multiple variations
        $email_fields = array('contact_email', 'email', 'e_mail', 'your_email', 'client_email');
        foreach ($email_fields as $email_field) {
            if (!empty($form_data[$email_field])) {
                $contact_info[] = 'Email: ' . $form_data[$email_field];
                break;
            }
        }
        
        // Phone - try multiple variations
        $phone_fields = array('contact_phone', 'phone', 'telephone', 'phone_number', 'contact_number');
        foreach ($phone_fields as $phone_field) {
            if (!empty($form_data[$phone_field])) {
                $contact_info[] = 'Phone: ' . $form_data[$phone_field];
                break;
            }
        }
        
        // Name - try multiple variations
        $name_fields = array('name', 'full_name', 'client_name', 'contact_name', 'your_name');
        foreach ($name_fields as $name_field) {
            if (!empty($form_data[$name_field])) {
                $contact_info[] = 'Name: ' . $form_data[$name_field];
                break;
            }
        }
        
        if (!empty($contact_info)) {
            $content_parts[] = '**Contact Information:**' . "\n" . implode("\n", $contact_info);
        }
        
        // Order information (only if order exists - for paid tiers) with better formatting
        if ($order) {
            $order_info = array();
            $order_info[] = 'Order ID: #' . $order->get_id();
            $order_info[] = 'Order Date: ' . $order->get_date_created()->format('Y-m-d H:i:s');
            $order_info[] = 'Order Total: ' . $order->get_formatted_order_total();
            $order_info[] = 'Payment Method: ' . $order->get_payment_method_title();
            
            $content_parts[] = '**Order Information:**' . "\n" . implode("\n", $order_info);
        } else {
            // For submissions without order (this should rarely happen now that all tiers are paid)
            $content_parts[] = '**Submission Type:** Support Request';
            $content_parts[] = '**Submitted:** ' . current_time('Y-m-d H:i:s');
        }
        
        // Attachments section (if any files were uploaded) with improved formatting
        if (!empty($attachments) && is_array($attachments)) {
            $attachment_links = array();
            $file_count = 0;
            
            foreach ($attachments as $attachment) {
                // Ensure attachment is an array and has required fields
                if (is_array($attachment) && isset($attachment['url']) && isset($attachment['original_name'])) {
                    $file_count++;
                    $file_size = isset($attachment['size']) && is_numeric($attachment['size']) ? ' (' . $this->format_file_size($attachment['size']) . ')' : '';
                    
                    // Sanitize the filename and generate secure download URL
                    $safe_filename = sanitize_text_field($attachment['original_name']);
                    $download_url = Priority_Ticket_Payment_File_Handler::generate_download_url($attachment);
                    
                    if (!empty($safe_filename) && !empty($download_url)) {
                        $attachment_links[] = sprintf(
                            '- %s%s [<a href="%s" target="_blank" rel="noopener">Download File</a>]',
                            esc_html($safe_filename),
                            $file_size,
                            esc_url($download_url)
                        );
                    }
                }
                
                // Enforce 3-file limit in display
                if ($file_count >= 3) {
                    break;
                }
            }
            
            if (!empty($attachment_links)) {
                $content_parts[] = '**Attachments:**' . "\n" . implode("\n", $attachment_links);
                error_log('Priority Ticket Payment: Added ' . count($attachment_links) . ' attachment links to ticket content');
            }
        }
        
        // Return with consistent double line spacing between all sections
        return implode("\n\n", $content_parts);
    }
    
    /**
     * Format file size for display
     */
    private function format_file_size($bytes) {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        } else {
            return $bytes . ' B';
        }
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
                // Log field data for debugging
                error_log('Priority Ticket Payment: Processing upload field - ' . print_r($field, true));
                
                // Also log $_FILES for debugging
                if (!empty($_FILES)) {
                    error_log('Priority Ticket Payment: $_FILES data - ' . print_r($_FILES, true));
                }
                
                // Elementor stores file URLs in the value, but we also have raw_value with file paths
                $file_urls = is_array($field['value']) ? $field['value'] : array($field['value']);
                $file_paths = isset($field['raw_value']) ? (is_array($field['raw_value']) ? $field['raw_value'] : array($field['raw_value'])) : array();
                
                // Check if Elementor provides original filenames in a separate field
                $original_names = array();
                
                // Try multiple ways to get original filename from Elementor
                if (isset($field['original_name'])) {
                    $original_names = is_array($field['original_name']) ? $field['original_name'] : array($field['original_name']);
                } elseif (isset($field['files'])) {
                    // Sometimes Elementor stores file info in a 'files' array
                    foreach ($field['files'] as $file_info) {
                        if (isset($file_info['name'])) {
                            $original_names[] = $file_info['name'];
                        }
                    }
                } elseif (isset($field['file_names'])) {
                    $original_names = is_array($field['file_names']) ? $field['file_names'] : array($field['file_names']);
                } elseif (isset($field['uploaded_files'])) {
                    foreach ($field['uploaded_files'] as $file_info) {
                        if (isset($file_info['original_name'])) {
                            $original_names[] = $file_info['original_name'];
                        } elseif (isset($file_info['name'])) {
                            $original_names[] = $file_info['name'];
                        }
                    }
                }
                
                // Try to extract from $_FILES if available (for current upload)
                if (empty($original_names) && !empty($_FILES)) {
                    foreach ($_FILES as $file_input) {
                        if (isset($file_input['name'])) {
                            if (is_array($file_input['name'])) {
                                $original_names = array_merge($original_names, $file_input['name']);
                            } else {
                                $original_names[] = $file_input['name'];
                            }
                        }
                    }
                }
                
                // Try to extract from hidden fields created by our JavaScript
                if (empty($original_names) && !empty($_POST)) {
                    foreach ($_POST as $key => $value) {
                        if (strpos($key, '_original_names') !== false && !empty($value)) {
                            $names = explode(',', $value);
                            $original_names = array_merge($original_names, array_map('trim', $names));
                        }
                    }
                }
                
                // Try to extract from normalized fields (check for original_names field)
                if (empty($original_names)) {
                    $normalized_fields = $this->normalize_fields(array($field));
                    foreach ($normalized_fields as $normalized_field) {
                        if (isset($normalized_field['original_names']) && !empty($normalized_field['original_names'])) {
                            $names = is_array($normalized_field['original_names']) ? $normalized_field['original_names'] : explode(',', $normalized_field['original_names']);
                            $original_names = array_merge($original_names, array_map('trim', $names));
                        }
                    }
                }
                
                // Handle comma-separated values (Elementor format)
                if (count($file_urls) === 1 && strpos($file_urls[0], ',') !== false) {
                    $file_urls = array_map('trim', explode(',', $file_urls[0]));
                }
                if (count($file_paths) === 1 && strpos($file_paths[0], ',') !== false) {
                    $file_paths = array_map('trim', explode(',', $file_paths[0]));
                }
                if (count($original_names) === 1 && strpos($original_names[0], ',') !== false) {
                    $original_names = array_map('trim', explode(',', $original_names[0]));
                }
                
                // Log what original names we found
                error_log('Priority Ticket Payment: Extracted original filenames - ' . print_r($original_names, true));
                
                // Process each file
                for ($i = 0; $i < max(count($file_urls), count($file_paths)) && $uploaded_count < $max_files; $i++) {
                    $file_url = isset($file_urls[$i]) ? trim($file_urls[$i]) : '';
                    $file_path = isset($file_paths[$i]) ? trim($file_paths[$i]) : '';
                    $original_name = isset($original_names[$i]) ? trim($original_names[$i]) : '';
                    
                    if (!empty($file_url) || !empty($file_path)) {
                        // Try processing with file path first (more reliable), then fall back to URL
                        $attachment = null;
                        if (!empty($file_path) && file_exists($file_path)) {
                            $attachment = $this->copy_local_file_to_priority_directory($file_path, $field['title'], $upload_dir['path'], $upload_dir['url'], $original_name);
                        } elseif (!empty($file_url)) {
                            $attachment = $this->download_and_save_file($file_url, $field['title'], $upload_dir['path'], $upload_dir['url'], $original_name);
                        }
                        
                        if ($attachment) {
                            $attachments[] = $attachment;
                            $uploaded_count++;
                        }
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
            
            // Create .htaccess file for security - allow file access but deny directory listing
            $htaccess_content = "Options -Indexes\n";
            $htaccess_content .= "# Allow direct file access for authorized downloads\n";
            $htaccess_content .= "<Files ~ \"\\.(pdf|doc|docx|jpg|jpeg|png|gif|txt|zip|bmp|tiff|webp|rtf)$\">\n";
            $htaccess_content .= "    Order allow,deny\n";
            $htaccess_content .= "    Allow from all\n";
            $htaccess_content .= "</Files>\n";
            $htaccess_content .= "# Deny access to PHP files and other potentially dangerous files\n";
            $htaccess_content .= "<Files ~ \"\\.(php|phtml|php3|php4|php5|pl|py|jsp|asp|sh|cgi)$\">\n";
            $htaccess_content .= "    Order allow,deny\n";
            $htaccess_content .= "    Deny from all\n";
            $htaccess_content .= "</Files>\n";
            file_put_contents($priority_tickets_dir . '/.htaccess', $htaccess_content);
            
            // Create index.php file for security
            file_put_contents($priority_tickets_dir . '/index.php', '<?php // Silence is golden');
        }
        
        // Test if directory is web-accessible
        $test_file = $priority_tickets_dir . '/test-access.txt';
        file_put_contents($test_file, 'test');
        $test_url = $priority_tickets_url . '/test-access.txt';
        
        $response = wp_remote_get($test_url, array('timeout' => 5));
        $is_accessible = !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
        
        // Clean up test file
        if (file_exists($test_file)) {
            unlink($test_file);
        }
        
        if (!$is_accessible) {
            error_log('Priority Ticket Payment: Upload directory may not be web-accessible. URL tested: ' . $test_url);
        }
        
        return array(
            'success' => true,
            'path' => $priority_tickets_dir,
            'url' => $priority_tickets_url,
            'accessible' => $is_accessible
        );
    }
    
    /**
     * Copy local file to priority tickets directory (for Elementor temporary files)
     */
    private function copy_local_file_to_priority_directory($source_path, $field_name, $upload_path, $upload_url, $provided_original_name = '') {
        // Check if source file exists
        if (!file_exists($source_path)) {
            error_log('Priority Ticket Payment: Source file does not exist: ' . $source_path);
            return false;
        }
        
        // Get original filename - use provided name if available, otherwise extract from path
        $original_filename = !empty($provided_original_name) ? $provided_original_name : basename($source_path);
        
        // Log for debugging
        error_log('Priority Ticket Payment: Processing file - Source: ' . basename($source_path) . ', Original: ' . $original_filename);
        
        $sanitized_filename = $this->sanitize_filename($original_filename);
        
        // Get file extension and validate
        $file_extension = strtolower(pathinfo($sanitized_filename, PATHINFO_EXTENSION));
        if (!$this->is_allowed_file_type($file_extension)) {
            error_log('Priority Ticket Payment: File type not allowed - ' . $file_extension);
            return false;
        }
        
        // Check file size
        $file_size = filesize($source_path);
        $max_file_size = Priority_Ticket_Payment::get_option('max_file_size', '10') * 1024 * 1024; // Convert MB to bytes
        
        if ($file_size > $max_file_size) {
            error_log('Priority Ticket Payment: File too large - ' . $file_size . ' bytes');
            return false;
        }
        
        // Generate unique filename to prevent conflicts
        $unique_filename = $this->generate_unique_filename($sanitized_filename, $upload_path);
        $destination_path = $upload_path . '/' . $unique_filename;
        
        // Copy file to final destination
        if (!copy($source_path, $destination_path)) {
            error_log('Priority Ticket Payment: Failed to copy file from ' . $source_path . ' to ' . $destination_path);
            return false;
        }
        
        // Return attachment data
        return array(
            'original_name' => $original_filename,
            'filename' => $unique_filename,
            'path' => $destination_path,
            'url' => $upload_url . '/' . $unique_filename,
            'size' => $file_size,
            'type' => $file_extension,
            'field_name' => $field_name,
            'mime_type' => $this->get_mime_type($destination_path),
            'upload_date' => current_time('mysql'),
        );
    }
    
    /**
     * Download and save file to priority tickets directory
     */
    private function download_and_save_file($file_url, $field_name, $upload_path, $upload_url, $provided_original_name = '') {
        // Download file to temporary location
        $tmp_file = download_url($file_url);
        
        if (is_wp_error($tmp_file)) {
            error_log('Priority Ticket Payment: Failed to download file - ' . $tmp_file->get_error_message());
            return false;
        }
        
        // Get original filename - use provided name if available, otherwise extract from URL
        if (!empty($provided_original_name)) {
            $original_filename = $provided_original_name;
        } else {
            $original_filename = basename(parse_url($file_url, PHP_URL_PATH));
            // Try to decode URL-encoded filename
            $original_filename = urldecode($original_filename);
            
            // If filename is still generic or empty, try to extract from URL parameters
            if (empty($original_filename) || $original_filename === 'index.php' || strlen($original_filename) < 3) {
                // Check for filename in URL parameters
                $url_parts = parse_url($file_url);
                if (isset($url_parts['query'])) {
                    parse_str($url_parts['query'], $params);
                    if (isset($params['filename'])) {
                        $original_filename = $params['filename'];
                    } elseif (isset($params['file'])) {
                        $original_filename = $params['file'];
                    } elseif (isset($params['name'])) {
                        $original_filename = $params['name'];
                    }
                }
            }
            
            // Final fallback
            if (empty($original_filename) || strlen($original_filename) < 3) {
                $original_filename = 'attachment_' . time() . '.file';
            }
        }
        
        // Log for debugging
        error_log('Priority Ticket Payment: Downloading file - URL: ' . $file_url . ', Original: ' . $original_filename);
        
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
            'url' => $upload_url . '/' . $unique_filename,
            'size' => $file_size,
            'type' => $file_extension,
            'field_name' => $field_name,
            'mime_type' => $this->get_mime_type($destination_path),
            'upload_date' => current_time('mysql'),
        );
    }
    
    /**
     * Sanitize filename for safe storage while preserving original name
     */
    private function sanitize_filename($filename) {
        // Remove path information
        $filename = basename($filename);
        
        // Get file extension
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        
        // Use WordPress built-in sanitization which preserves numbers and basic characters
        $name = sanitize_file_name($name);
        
        // Only remove truly dangerous characters, keep numbers and basic punctuation
        $name = preg_replace('/[<>:"\/\\|?*]/', '', $name);
        
        // Ensure name is not empty
        if (empty($name)) {
            $name = 'attachment';
        }
        
        // Limit length but keep it reasonable
        $name = substr($name, 0, 100);
        
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
        $default_allowed = array('pdf', 'doc', 'docx', 'txt', 'rtf', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'webp');
        $allowed_types = Priority_Ticket_Payment::get_option('allowed_file_types', $default_allowed);
        
        if (is_string($allowed_types)) {
            $allowed_types = explode(',', $allowed_types);
        }
        
        $extension_lower = strtolower($extension);
        $allowed_lower = array_map('strtolower', $allowed_types);
        return in_array($extension_lower, $allowed_lower);
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
                'txt' => 'text/plain',
                'rtf' => 'application/rtf',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'bmp' => 'image/bmp',
                'tiff' => 'image/tiff',
                'webp' => 'image/webp',
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
        
        // Get cart URL instead of checkout URL to allow coupon application
        $cart_url = wc_get_cart_url();
        
        // Log the cart URL
        error_log('Priority Ticket Payment: Cart URL: ' . $cart_url);
        
        // Build cart URL with token parameters
        $final_redirect_url = add_query_arg(array(
            'ticket_id' => $token,
            'submission_id' => $submission_id,
        ), $cart_url);
        
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
        error_log('Priority Ticket Payment: === STATIC handle_ticket_form called ===');
        
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
        
        // Extract subject field, with fallback logic
        $subject_field = isset($form_data['subject']) ? $form_data['subject'] : '';
        if (empty($subject_field)) {
            $subject_field = isset($form_data['message']) ? $form_data['message'] : '';
            if (empty($subject_field)) {
                $name = isset($form_data['name']) ? $form_data['name'] : 'Guest';
                $subject_field = $name . ' - Priority Support Request';
            }
        }
        
        // Store sanitized subject in form_data
        $form_data['ticket_subject'] = sanitize_text_field($subject_field);
        $form_data['subject'] = sanitize_text_field(isset($raw_fields['subject']['value']) ? $raw_fields['subject']['value'] : '');
        
        // Determine priority
        $priority = Priority_Ticket_Payment_Elementor_Utils::get_user_ticket_priority($user_id);
        
        // Determine price based on priority
        $price = 0;
        switch ($priority) {
            case 'A':
                $price = 0; // Free for coaching clients
                break;
            case 'B':
                $price = 50;
                break;
            case 'C':
            default:
                $price = 100; // Basic tier
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
            'payment_status' => 'pending_payment', // All tiers now require payment
            'token' => $token,
        );
        
        // Store submission in database
        $submission_id = Priority_Ticket_Payment_Database::insert_submission($submission_data);
        
        if (!$submission_id) {
            error_log('Priority Ticket Payment: Failed to store submission in handle_ticket_form');
            wp_die(__('Failed to process form submission. Please try again.', 'priority-ticket-payment'));
        }
        
        // Handle free tier (Priority A - coaching clients)
        if ($priority === 'A' || $price == 0) {
            // For free tier, create ticket immediately if Awesome Support is enabled
            if (Priority_Ticket_Payment::get_option('enable_awesome_support_integration', 'yes') === 'yes') {
                $submission = Priority_Ticket_Payment_Database::get_submission($submission_id);
                if ($submission) {
                    // Create free tier ticket
                    $instance = new self();
                    $instance->create_free_tier_support_ticket($submission);
                }
            }
            
            // Redirect to thank you page
            $redirect_url = home_url('?ticket_submitted=1&ticket_id=' . $token . '&tier=coaching_free');
            wp_safe_redirect($redirect_url);
            exit;
        }
        
        // All other tiers require payment, so proceed to checkout
        // Get product ID from plugin settings
        if ($priority === 'B') {
            $product_id = Priority_Ticket_Payment::get_option('product_id_b', 0);
        } else { // Priority C
            $product_id = Priority_Ticket_Payment::get_option('product_id_c', 0);
        }
        
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
            
            // Build cart URL with metadata to allow coupon application
            $cart_url = wc_get_cart_url();
            
            // Log the cart URL
            error_log('Priority Ticket Payment: Static handler - Cart URL: ' . $cart_url);
            
            $redirect_url = add_query_arg(array(
                'ticket_id' => $token,
                'submission_id' => $submission_id,
                'tier' => $priority
            ), $cart_url);
            
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
    
    /**
     * Enqueue auto-population script for all configured Elementor forms
     */
    public function enqueue_auto_population_script() {
        // Get all configured form IDs and their tiers
        $form_tiers = array(
            Priority_Ticket_Payment::get_option('ticket_form_id_a', '') => array('tier' => 'A', 'limit' => 2500, 'price' => 'Free'),
            Priority_Ticket_Payment::get_option('ticket_form_id_b', '') => array('tier' => 'B', 'limit' => 2500, 'price' => '50€'),
            Priority_Ticket_Payment::get_option('ticket_form_id_c', '') => array('tier' => 'C', 'limit' => 2500, 'price' => '100€'),
        );
        
        // Add additional form IDs (default to paid tier limits)
        $additional_form_ids = Priority_Ticket_Payment::get_option('additional_form_ids', '');
        if (!empty($additional_form_ids)) {
            $additional_ids = array_map('trim', explode(',', $additional_form_ids));
            $additional_ids = array_filter($additional_ids); // Remove empty values
            foreach ($additional_ids as $form_id) {
                if (!isset($form_tiers[$form_id])) {
                    $form_tiers[$form_id] = array('tier' => 'PAID', 'limit' => 2500, 'price' => 'Paid');
                }
            }
        }
        
        // Remove empty form IDs
        $form_tiers = array_filter($form_tiers, function($key) { return !empty($key); }, ARRAY_FILTER_USE_KEY);
        
        // Only enqueue if we have configured forms
        if (empty($form_tiers)) {
            return;
        }
        
        // Get current user data (only for logged-in users)
        $is_logged_in = is_user_logged_in();
        $user_data = array(
            'name' => '',
            'email' => '',
            'phone' => ''
        );
        
        if ($is_logged_in) {
            $current_user = wp_get_current_user();
            $user_data['name'] = !empty($current_user->display_name) ? $current_user->display_name : 
                                (!empty($current_user->first_name) && !empty($current_user->last_name) ? 
                                 $current_user->first_name . ' ' . $current_user->last_name : 
                                 $current_user->user_login);
            $user_data['email'] = $current_user->user_email;
            
            // Get user phone number from various possible meta fields
            $phone_meta_keys = array('phone', 'user_phone', 'contact_phone', 'billing_phone', 'phone_number');
            foreach ($phone_meta_keys as $meta_key) {
                $phone_value = get_user_meta($current_user->ID, $meta_key, true);
                if (!empty($phone_value)) {
                    $user_data['phone'] = $phone_value;
                    break;
                }
            }
        }
        
        // Add inline script for auto-population and character limits
        $script = "
        document.addEventListener('DOMContentLoaded', function() {
            // Configure user data and form tiers
            var priorityTicketUserData = {
                name: " . wp_json_encode($user_data['name']) . ",
                email: " . wp_json_encode($user_data['email']) . ",
                phone: " . wp_json_encode($user_data['phone']) . ",
                formTiers: " . wp_json_encode($form_tiers) . ",
                isLoggedIn: " . wp_json_encode($is_logged_in) . "
            };
            
            // Auto-population and character limit function
            function initializeFormEnhancements() {
                // Common selectors for name fields
                var nameSelectors = [
                    'input[name=\"form_fields[name]\"]',
                    'input[name=\"form_fields[full_name]\"]', 
                    'input[name=\"form_fields[full name]\"]',
                    'input[name=\"form_fields[client_name]\"]',
                    'input[name=\"form_fields[your_name]\"]',
                    'input[id*=\"name\"]',
                    'input[placeholder*=\"name\" i]',
                    'input[placeholder*=\"Name\" i]',
                    '.elementor-field-group-name input',
                    '.elementor-field-type-text input[placeholder*=\"name\" i]'
                ];
                
                // Common selectors for email fields  
                var emailSelectors = [
                    'input[name=\"form_fields[email]\"]',
                    'input[name=\"form_fields[email_address]\"]',
                    'input[name=\"form_fields[e-mail]\"]',
                    'input[name=\"form_fields[your_email]\"]',
                    'input[type=\"email\"]',
                    'input[id*=\"email\"]',
                    'input[placeholder*=\"email\" i]',
                    '.elementor-field-group-email input',
                    '.elementor-field-type-email input'
                ];
                
                // Common selectors for phone fields
                var phoneSelectors = [
                    'input[name=\"form_fields[phone]\"]',
                    'input[name=\"form_fields[phone_number]\"]',
                    'input[name=\"form_fields[contact_phone]\"]',
                    'input[name=\"form_fields[telephone]\"]',
                    'input[type=\"tel\"]',
                    'input[id*=\"phone\"]',
                    'input[placeholder*=\"phone\" i]',
                    'input[placeholder*=\"telephone\" i]',
                    '.elementor-field-group-phone input',
                    '.elementor-field-type-tel input'
                ];
                
                // Common selectors for textarea/message fields
                var textareaSelectors = [
                    'textarea[name=\"form_fields[message]\"]',
                    'textarea[name=\"form_fields[description]\"]',
                    'textarea[name=\"form_fields[details]\"]',
                    'textarea[name=\"form_fields[comments]\"]',
                    'textarea[name=\"form_fields[note]\"]',
                    'textarea[id*=\"message\"]',
                    'textarea[id*=\"description\"]',
                    'textarea[placeholder*=\"message\" i]',
                    'textarea[placeholder*=\"description\" i]',
                    '.elementor-field-type-textarea textarea'
                ];
                
                // Determine current form tier and character limit
                var currentFormLimit = 2500; // Default to paid tier
                var currentFormTier = 'PAID';
                var currentFormPrice = 'Paid';
                var hasConfiguredForm = false;
                
                // Check which form we're on - be more specific with form detection
                Object.keys(priorityTicketUserData.formTiers).forEach(function(formId) {
                    // Try multiple ways to detect the form ID
                    var formFound = document.querySelector('.elementor-form[data-form-id=\"' + formId + '\"]') || 
                                   document.querySelector('form[data-form-id=\"' + formId + '\"]') ||
                                   document.querySelector('form[id=\"' + formId + '\"]') ||
                                   document.querySelector('form[class*=\"' + formId + '\"]') ||
                                   document.querySelector('[data-settings*=\"' + formId + '\"]');
                    
                    if (formFound) {
                        currentFormLimit = priorityTicketUserData.formTiers[formId].limit;
                        currentFormTier = priorityTicketUserData.formTiers[formId].tier;
                        currentFormPrice = priorityTicketUserData.formTiers[formId].price;
                        hasConfiguredForm = true;
                        
                        // Log which form was detected for debugging
                        console.log('Priority Ticket Form detected:', formId, 'Tier:', currentFormTier, 'Limit:', currentFormLimit);
                    }
                });
                
                // Only apply enhancements to configured forms
                var shouldApplyEnhancements = hasConfiguredForm;
                
                // AUTO-POPULATION (only for logged-in users on configured forms)
                if (priorityTicketUserData.isLoggedIn && hasConfiguredForm) {
                    // Auto-populate name field
                    nameSelectors.forEach(function(selector) {
                        var nameField = document.querySelector(selector);
                        if (nameField && nameField.value === '') {
                            nameField.value = priorityTicketUserData.name;
                            // Trigger change event for any listeners
                            var event = new Event('change', { bubbles: true });
                            nameField.dispatchEvent(event);
                        }
                    });
                    
                    // Auto-populate email field
                    emailSelectors.forEach(function(selector) {
                        var emailField = document.querySelector(selector);
                        if (emailField && emailField.value === '') {
                            emailField.value = priorityTicketUserData.email;
                            // Trigger change event for any listeners
                            var event = new Event('change', { bubbles: true });
                            emailField.dispatchEvent(event);
                        }
                    });
                    
                    // Auto-populate phone field (only if user has phone data)
                    if (priorityTicketUserData.phone) {
                        phoneSelectors.forEach(function(selector) {
                            var phoneField = document.querySelector(selector);
                            if (phoneField && phoneField.value === '') {
                                phoneField.value = priorityTicketUserData.phone;
                                // Trigger change event for any listeners
                                var event = new Event('change', { bubbles: true });
                                phoneField.dispatchEvent(event);
                            }
                        });
                    }
                }
                
                // CHARACTER LIMITS (only for configured priority ticket forms)
                if (shouldApplyEnhancements) {
                    // Apply character limits and counters to textarea fields
                    textareaSelectors.forEach(function(selector) {
                        var textarea = document.querySelector(selector);
                        if (textarea) {
                            setupCharacterLimit(textarea, currentFormLimit, currentFormTier, currentFormPrice);
                        }
                    });
                }
                
                // NAME FIELD VALIDATION (only for configured priority ticket forms)
                if (shouldApplyEnhancements) {
                    // Apply name field validation
                    nameSelectors.forEach(function(selector) {
                        var nameField = document.querySelector(selector);
                        if (nameField) {
                            setupNameValidation(nameField);
                        }
                    });
                }
            }
            
            // Character limit setup function
            function setupCharacterLimit(textarea, limit, tier, price) {
                // Check if this textarea already has a character limit setup
                if (textarea.hasAttribute('data-priority-ticket-char-limit') || 
                    textarea.parentNode.querySelector('.priority-ticket-char-limit-container')) {
                    return; // Already setup, skip
                }
                
                // Mark this textarea as having character limit setup
                textarea.setAttribute('data-priority-ticket-char-limit', 'true');
                
                // Set maxlength attribute
                textarea.setAttribute('maxlength', limit);
                
                // Create character counter container
                var counterContainer = document.createElement('div');
                counterContainer.className = 'priority-ticket-char-limit-container';
                counterContainer.style.cssText = 'margin-top: 8px; display: flex; justify-content: space-between; align-items: center; font-size: 12px;';
                
                // Create tier info
                var tierInfo = document.createElement('div');
                tierInfo.className = 'priority-ticket-tier-info';
                tierInfo.style.cssText = 'color: #666; font-weight: 500;';
                
                if (tier === 'D') {
                    tierInfo.innerHTML = '<span style=\"color: #2196f3;\">📝 Free Tier</span> - ' + limit + ' characters max';
                    tierInfo.style.color = '#2196f3';
                } else {
                    tierInfo.innerHTML = '<span style=\"color: #4caf50;\">💎 ' + price + ' Tier</span> - ' + limit + ' characters max';
                    tierInfo.style.color = '#4caf50';
                }
                
                // Create character counter
                var counter = document.createElement('div');
                counter.className = 'priority-ticket-char-counter';
                counter.style.cssText = 'font-weight: 600;';
                
                // Update counter function
                function updateCounter() {
                    var currentLength = textarea.value.length;
                    var remaining = limit - currentLength;
                    
                    counter.textContent = currentLength + ' / ' + limit;
                    
                    // Color coding based on usage
                    if (remaining < 50) {
                        counter.style.color = '#f44336'; // Red
                        counter.innerHTML = currentLength + ' / ' + limit + ' <span style=\"color: #f44336;\">⚠️</span>';
                    } else if (remaining < 100) {
                        counter.style.color = '#ff9800'; // Orange
                        counter.innerHTML = currentLength + ' / ' + limit + ' <span style=\"color: #ff9800;\">⚡</span>';
                    } else {
                        counter.style.color = '#4caf50'; // Green
                        counter.innerHTML = currentLength + ' / ' + limit + ' <span style=\"color: #4caf50;\">✅</span>';
                    }
                    
                    // Prevent further input if limit reached
                    if (currentLength >= limit) {
                        // Truncate text if it exceeds limit (safety measure)
                        if (currentLength > limit) {
                            textarea.value = textarea.value.substring(0, limit);
                        }
                    }
                }
                
                // Add event listeners
                textarea.addEventListener('input', updateCounter);
                textarea.addEventListener('keyup', updateCounter);
                textarea.addEventListener('paste', function() {
                    setTimeout(updateCounter, 10);
                });
                
                // Assemble counter container
                counterContainer.appendChild(tierInfo);
                counterContainer.appendChild(counter);
                
                // Insert counter after textarea
                var parentContainer = textarea.parentNode;
                if (parentContainer) {
                    parentContainer.appendChild(counterContainer);
                }
                
                // Initial counter update
                updateCounter();
                
                // Add warning notice for free tier
                if (tier === 'D') {
                    var freeNotice = document.createElement('div');
                    freeNotice.className = 'priority-ticket-free-notice';
                    freeNotice.style.cssText = 'background: #e3f2fd; border: 1px solid #2196f3; border-radius: 4px; padding: 12px; margin-top: 12px; color: #1565c0; font-size: 13px;';
                    freeNotice.innerHTML = '<strong>📋 Free Tier:</strong> Description limited to ' + limit + ' characters. File uploads may not be available.';
                    
                    counterContainer.appendChild(freeNotice);
                }
            }
            
            // Name Field Validation Setup
            function setupNameValidation(nameField) {
                // Check if this field already has validation setup
                if (nameField.hasAttribute('data-priority-ticket-name-validation') ||
                    nameField.parentNode.querySelector('.priority-ticket-name-validation-container')) {
                    return; // Already setup, skip
                }
                
                // Mark this field as having validation setup
                nameField.setAttribute('data-priority-ticket-name-validation', 'true');
                
                // Create validation container
                var validationContainer = document.createElement('div');
                validationContainer.className = 'priority-ticket-name-validation-container';
                validationContainer.style.cssText = 'margin-top: 5px; display: none;';
                
                // Create error message
                var errorMessage = document.createElement('div');
                errorMessage.className = 'priority-ticket-name-error';
                errorMessage.style.cssText = 'color: #f44336; font-size: 12px; font-weight: 500; padding: 5px 8px; background: #ffebee; border: 1px solid #ffcdd2; border-radius: 3px; display: flex; align-items: center;';
                errorMessage.innerHTML = '<span style=\"margin-right: 6px;\">⚠️</span> Bitte geben Sie Ihren vollständigen Namen ein (mindestens 3 Zeichen)';
                
                validationContainer.appendChild(errorMessage);
                
                // Insert validation container after name field
                var parentContainer = nameField.parentNode;
                if (parentContainer) {
                    parentContainer.appendChild(validationContainer);
                }
                
                // Validation function
                function validateName() {
                    var value = nameField.value.trim();
                    var isValid = value.length >= 3;
                    
                    if (isValid) {
                        // Hide error message
                        validationContainer.style.display = 'none';
                        nameField.style.borderColor = '';
                        nameField.setAttribute('aria-invalid', 'false');
                        nameField.removeAttribute('data-validation-error');
                    } else if (value.length > 0) {
                        // Show error message only if user has started typing
                        validationContainer.style.display = 'block';
                        nameField.style.borderColor = '#f44336';
                        nameField.setAttribute('aria-invalid', 'true');
                        nameField.setAttribute('data-validation-error', 'name-too-short');
                    } else {
                        // Field is empty, hide error but don't mark as valid
                        validationContainer.style.display = 'none';
                        nameField.style.borderColor = '';
                        nameField.removeAttribute('aria-invalid');
                        nameField.removeAttribute('data-validation-error');
                    }
                    
                    return isValid || value.length === 0; // Allow empty (for optional fields) or valid
                }
                
                // Add event listeners for real-time validation
                nameField.addEventListener('input', function() {
                    // Small delay to avoid flickering while typing
                    clearTimeout(this.validationTimeout);
                    this.validationTimeout = setTimeout(validateName, 300);
                });
                
                nameField.addEventListener('blur', function() {
                    // Immediate validation on blur
                    clearTimeout(this.validationTimeout);
                    validateName();
                });
                
                nameField.addEventListener('keyup', function() {
                    // Real-time validation while typing
                    clearTimeout(this.validationTimeout);
                    this.validationTimeout = setTimeout(validateName, 300);
                });
                
                // Form submission validation
                var form = nameField.closest('form');
                if (form) {
                    form.addEventListener('submit', function(e) {
                        if (!validateName() && nameField.value.trim().length > 0) {
                            e.preventDefault();
                            e.stopPropagation();
                            
                            // Focus on the invalid field
                            nameField.focus();
                            
                            // Show error immediately
                            validationContainer.style.display = 'block';
                            nameField.style.borderColor = '#f44336';
                            
                            // Scroll to field if needed
                            nameField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            
                            return false;
                        }
                    });
                }
                
                // Initial validation (in case field has pre-filled value)
                if (nameField.value.trim().length > 0) {
                    setTimeout(validateName, 100);
                }
            }
            
            // German Translation for Elementor Form Messages
            function translateElementorMessages() {
                // Translation mapping for common Elementor form messages
                var translations = {
                    // Success Messages
                    'Thank you': 'Vielen Dank',
                    'Thank you!': 'Vielen Dank!',
                    'Thank you for your message. It has been sent.': 'Vielen Dank für Ihre Nachricht. Sie wurde erfolgreich gesendet.',
                    'Thank you for your submission!': 'Vielen Dank für Ihre Nachricht!',
                    'Your message has been sent successfully!': 'Ihre Nachricht wurde erfolgreich gesendet!',
                    'Your submission was successful': 'Der nächste Schritt führt Sie zur Bezahlung.',
                    'Your submission was successful!': 'Der nächste Schritt führt Sie zur Bezahlung.',
                    'Submission successful': 'Der nächste Schritt führt Sie zur Bezahlung.',
                    'Submission successful!': 'Der nächste Schritt führt Sie zur Bezahlung.',
                    'Successfully submitted': 'Der nächste Schritt führt Sie zur Bezahlung.',
                    'Successfully submitted!': 'Der nächste Schritt führt Sie zur Bezahlung.',
                    'Form submitted successfully': 'Der nächste Schritt führt Sie zur Bezahlung.',
                    'Message sent successfully': 'Der nächste Schritt führt Sie zur Bezahlung.',
                    'Your form has been submitted': 'Der nächste Schritt führt Sie zur Bezahlung.',
                    
                    // Error Messages
                    'There was an error trying to send your message. Please try again later.': 'Beim Senden Ihrer Nachricht ist ein Fehler aufgetreten. Bitte versuchen Sie es später erneut.',
                    'Your submission failed': 'Ihre Übermittlung ist fehlgeschlagen',
                    'Your submission failed.': 'Ihre Übermittlung ist fehlgeschlagen.',
                    'Something went wrong': 'Etwas ist schief gelaufen',
                    'Something went wrong.': 'Etwas ist schief gelaufen.',
                    'Form submission failed': 'Formular-Übermittlung fehlgeschlagen',
                    'Please try again': 'Bitte versuchen Sie es erneut',
                    'Please try again.': 'Bitte versuchen Sie es erneut.',
                    'An error occurred': 'Ein Fehler ist aufgetreten',
                    'An error occurred.': 'Ein Fehler ist aufgetreten.',
                    'Server error': 'Server-Fehler',
                    'Network error': 'Netzwerk-Fehler',
                    
                    // Validation Messages
                    'This field is required': 'Dieses Feld ist erforderlich',
                    'This field is required.': 'Dieses Feld ist erforderlich.',
                    'Please fill out this field': 'Bitte füllen Sie dieses Feld aus',
                    'Please fill out this field.': 'Bitte füllen Sie dieses Feld aus.',
                    'Invalid email address': 'Ungültige E-Mail-Adresse',
                    'Invalid email address.': 'Ungültige E-Mail-Adresse.',
                    'Please enter a valid email': 'Bitte geben Sie eine gültige E-Mail ein',
                    'Please enter a valid email.': 'Bitte geben Sie eine gültige E-Mail ein.',
                    'Email is not valid': 'E-Mail ist nicht gültig',
                    'The email address is not valid': 'Die E-Mail-Adresse ist nicht gültig',
                    
                    // Button texts
                    'Send': 'Kostenpflichtig bestellen',
                    'Submit': 'Kostenpflichtig bestellen',
                    'Send Message': 'Kostenpflichtig bestellen',
                    'Submit Form': 'Kostenpflichtig bestellen',
                    'Get in Touch': 'Kontakt aufnehmen',
                    'Contact Us': 'Kontaktieren Sie uns',
                    
                    // Loading states
                    'Sending...': 'Wird gesendet...',
                    'Submitting...': 'Wird übermittelt...',
                    'Please wait...': 'Bitte warten...',
                    'Processing...': 'Wird verarbeitet...',
                    
                    // File upload messages
                    'File uploaded successfully': 'Datei erfolgreich hochgeladen',
                    'File upload failed': 'Datei-Upload fehlgeschlagen',
                    'Invalid file type': 'Ungültiger Dateityp',
                    'File too large': 'Datei zu groß',
                    'Maximum file size exceeded': 'Maximale Dateigröße überschritten',
                    'No file selected': 'Keine Datei ausgewählt',
                    'Choose file': 'Datei auswählen',
                    'Choose files': 'Dateien auswählen',
                    'Drop files here': 'Dateien hier ablegen',
                    'or click to select': 'oder klicken zum Auswählen'
                };
                
                // Function to translate text content
                function translateText(element) {
                    if (!element || !element.textContent) return;
                    
                    var originalText = element.textContent.trim();
                    if (translations[originalText]) {
                        element.textContent = translations[originalText];
                        element.setAttribute('data-translated', 'true');
                    }
                }
                
                // Function to translate placeholder text
                function translatePlaceholder(element) {
                    if (!element || !element.placeholder) return;
                    
                    var originalPlaceholder = element.placeholder.trim();
                    if (translations[originalPlaceholder]) {
                        element.placeholder = translations[originalPlaceholder];
                        element.setAttribute('data-placeholder-translated', 'true');
                    }
                }
                
                // Function to translate all messages in Elementor forms
                function translateAllMessages() {
                    // Find all Elementor forms
                    var elementorForms = document.querySelectorAll('.elementor-form, form[class*=\"elementor\"]');
                    
                    elementorForms.forEach(function(form) {
                        // Translate form messages (success/error)
                        var messages = form.querySelectorAll('.elementor-message, .elementor-form-message, .elementor-message-success, .elementor-message-error, .elementor-message-danger');
                        messages.forEach(translateText);
                        
                        // Translate submit buttons
                        var submitButtons = form.querySelectorAll('button[type=\"submit\"], input[type=\"submit\"], .elementor-button');
                        submitButtons.forEach(translateText);
                        
                        // Translate placeholders
                        var inputs = form.querySelectorAll('input[placeholder], textarea[placeholder]');
                        inputs.forEach(translatePlaceholder);
                        
                        // Translate validation messages
                        var validationMessages = form.querySelectorAll('.elementor-field-validation, .help-block, .invalid-feedback');
                        validationMessages.forEach(translateText);
                    });
                    
                    // Also check for messages outside forms (global messages)
                    var globalMessages = document.querySelectorAll('.elementor-message, .elementor-form-message, .elementor-message-success, .elementor-message-error, .elementor-message-danger');
                    globalMessages.forEach(function(message) {
                        if (!message.getAttribute('data-translated')) {
                            translateText(message);
                        }
                    });
                }
                
                // Run translation immediately
                translateAllMessages();
                
                // Watch for new messages using MutationObserver
                if (window.MutationObserver) {
                    var messageObserver = new MutationObserver(function(mutations) {
                        var shouldTranslate = false;
                        mutations.forEach(function(mutation) {
                            if (mutation.type === 'childList') {
                                for (var i = 0; i < mutation.addedNodes.length; i++) {
                                    var node = mutation.addedNodes[i];
                                    if (node.nodeType === 1 && (
                                        node.classList.contains('elementor-message') ||
                                        node.classList.contains('elementor-form-message') ||
                                        node.classList.contains('elementor-message-success') ||
                                        node.classList.contains('elementor-message-error') ||
                                        node.classList.contains('elementor-message-danger') ||
                                        (node.querySelector && node.querySelector('.elementor-message, .elementor-form-message'))
                                    )) {
                                        shouldTranslate = true;
                                        break;
                                    }
                                }
                            } else if (mutation.type === 'characterData' || mutation.type === 'attributes') {
                                // Text content changed
                                var target = mutation.target;
                                if (target && target.parentNode && (
                                    target.parentNode.classList.contains('elementor-message') ||
                                    target.parentNode.classList.contains('elementor-form-message')
                                )) {
                                    shouldTranslate = true;
                                }
                            }
                        });
                        if (shouldTranslate) {
                            setTimeout(translateAllMessages, 50);
                        }
                    });
                    
                    messageObserver.observe(document.body, { 
                        childList: true, 
                        subtree: true, 
                        characterData: true, 
                        attributes: true,
                        attributeFilter: ['class']
                    });
                }
                
                // Also listen for form submission events to catch loading states
                document.addEventListener('submit', function(e) {
                    if (e.target.classList.contains('elementor-form') || e.target.closest('.elementor-form')) {
                        setTimeout(translateAllMessages, 100);
                        setTimeout(translateAllMessages, 500);
                        setTimeout(translateAllMessages, 1000);
                    }
                });
            }
            
            // Enhanced File Upload with Incremental Selection
            function setupIncrementalFileUpload() {
                // Find all file input fields
                var fileInputs = document.querySelectorAll('input[type=\"file\"]');
                
                fileInputs.forEach(function(fileInput) {
                    // Check if this file input already has been processed
                    if (fileInput.hasAttribute('data-priority-ticket-file-enhanced') ||
                        fileInput.parentNode.classList.contains('incremental-file-upload-wrapper')) {
                        return; // Already processed, skip
                    }
                    
                    // Mark this input as processed
                    fileInput.setAttribute('data-priority-ticket-file-enhanced', 'true');
                    
                    // Create hidden field to store original filenames
                    var originalNamesField = document.createElement('input');
                    originalNamesField.type = 'hidden';
                    originalNamesField.name = fileInput.name + '_original_names';
                    originalNamesField.className = 'priority-ticket-original-names';
                    fileInput.parentNode.appendChild(originalNamesField);
                    
                    // Store accumulated files
                    var accumulatedFiles = [];
                    var maxFiles = 3; // Limit to 3 files
                    
                    // Create main wrapper
                    var wrapper = document.createElement('div');
                    wrapper.className = 'incremental-file-upload-wrapper';
                    wrapper.style.cssText = 'position: relative; width: 100%;';
                    
                    // Insert wrapper before the file input
                    fileInput.parentNode.insertBefore(wrapper, fileInput);
                    wrapper.appendChild(fileInput);
                    
                    // Create upload button
                    var uploadButton = document.createElement('button');
                    uploadButton.type = 'button';
                    uploadButton.className = 'incremental-upload-button';
                    
                    // Try to get website primary color from CSS variables or computed styles, fallback to #778D26
                    var primaryColor = '#778D26'; // Fallback color
                    var hoverColor = '#6b7a22'; // Darker shade for hover
                    
                    // Try to detect website colors from existing elements
                    var existingButton = document.querySelector('.btn-primary, .elementor-button, .wp-block-button__link, .button-primary');
                    if (existingButton) {
                        var computedStyle = window.getComputedStyle(existingButton);
                        var bgColor = computedStyle.backgroundColor;
                        if (bgColor && bgColor !== 'rgba(0, 0, 0, 0)' && bgColor !== 'transparent') {
                            primaryColor = bgColor;
                        }
                    }
                    
                    // Also check for CSS custom properties (CSS variables)
                    var docStyle = window.getComputedStyle(document.documentElement);
                    var cssVarColor = docStyle.getPropertyValue('--primary-color') || 
                                     docStyle.getPropertyValue('--theme-primary') || 
                                     docStyle.getPropertyValue('--accent-color') ||
                                     docStyle.getPropertyValue('--main-color') ||
                                     docStyle.getPropertyValue('--button-color');
                    if (cssVarColor && cssVarColor.trim()) {
                        primaryColor = cssVarColor.trim();
                    }
                    
                    uploadButton.style.cssText = 'display: inline-block; padding: 10px 20px; background: ' + primaryColor + '; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.3s ease; margin-bottom: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);';
                    uploadButton.textContent = 'Datei hinzufügen';
                    
                    // Create file list container
                    var fileListContainer = document.createElement('div');
                    fileListContainer.className = 'file-list-container';
                    fileListContainer.style.cssText = 'margin-top: 10px;';
                    
                    // Create status text
                    var statusText = document.createElement('div');
                    statusText.className = 'upload-status';
                    statusText.style.cssText = 'font-size: 12px; color: #666; margin-top: 5px;';
                    statusText.textContent = 'Keine Dateien ausgewählt (max. ' + maxFiles + ' Dateien)';
                    
                    // Hide original file input
                    fileInput.style.cssText = 'position: absolute; left: -9999px; opacity: 0; pointer-events: none;';
                    
                    // Add elements to wrapper
                    wrapper.appendChild(uploadButton);
                    wrapper.appendChild(fileListContainer);
                    wrapper.appendChild(statusText);
                    
                    // Button hover effects
                    uploadButton.addEventListener('mouseenter', function() {
                        if (!this.disabled) {
                            // Darken the primary color for hover effect
                            if (primaryColor.startsWith('#')) {
                                // Convert hex to RGB and darken
                                var r = parseInt(primaryColor.slice(1, 3), 16);
                                var g = parseInt(primaryColor.slice(3, 5), 16);
                                var b = parseInt(primaryColor.slice(5, 7), 16);
                                var darkerColor = 'rgb(' + Math.max(0, r - 30) + ',' + Math.max(0, g - 30) + ',' + Math.max(0, b - 30) + ')';
                                this.style.background = darkerColor;
                            } else {
                                this.style.background = hoverColor;
                            }
                            this.style.transform = 'translateY(-1px)';
                            this.style.boxShadow = '0 4px 8px rgba(0,0,0,0.2)';
                        }
                    });
                    uploadButton.addEventListener('mouseleave', function() {
                        if (!this.disabled) {
                            this.style.background = primaryColor;
                            this.style.transform = 'translateY(0)';
                            this.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
                        }
                    });
                    
                    // Function to update file list display
                    function updateFileList() {
                        fileListContainer.innerHTML = '';
                        
                        if (accumulatedFiles.length === 0) {
                            statusText.textContent = 'Keine Dateien ausgewählt (max. ' + maxFiles + ' Dateien)';
                            statusText.style.color = '#666';
                        } else {
                            statusText.textContent = accumulatedFiles.length + ' von ' + maxFiles + ' Dateien ausgewählt';
                            statusText.style.color = '#4caf50';
                            
                            accumulatedFiles.forEach(function(file, index) {
                                var fileItem = document.createElement('div');
                                fileItem.className = 'file-item';
                                fileItem.style.cssText = 'display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 5px; font-size: 14px;';
                                
                                var fileInfo = document.createElement('span');
                                fileInfo.style.cssText = 'flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;';
                                fileInfo.textContent = file.name + ' (' + formatFileSize(file.size) + ')';
                                
                                var removeButton = document.createElement('button');
                                removeButton.type = 'button';
                                removeButton.className = 'remove-file-button';
                                removeButton.style.cssText = 'background: #f44336; color: white; border: none; border-radius: 3px; padding: 4px 8px; font-size: 12px; cursor: pointer; margin-left: 10px;';
                                removeButton.textContent = 'Entfernen';
                                
                                removeButton.addEventListener('click', function() {
                                    accumulatedFiles.splice(index, 1);
                                    updateFileList();
                                    updateOriginalInput();
                                    updateButtonState();
                                });
                                
                                fileItem.appendChild(fileInfo);
                                fileItem.appendChild(removeButton);
                                fileListContainer.appendChild(fileItem);
                            });
                        }
                    }
                    
                    // Function to update button state
                    function updateButtonState() {
                        if (accumulatedFiles.length >= maxFiles) {
                            uploadButton.disabled = true;
                            uploadButton.style.background = '#ccc';
                            uploadButton.style.cursor = 'not-allowed';
                            uploadButton.style.transform = 'translateY(0)';
                            uploadButton.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
                            uploadButton.textContent = 'Maximum erreicht (' + maxFiles + ' Dateien)';
                        } else {
                            uploadButton.disabled = false;
                            uploadButton.style.background = primaryColor;
                            uploadButton.style.cursor = 'pointer';
                            uploadButton.style.transform = 'translateY(0)';
                            uploadButton.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
                            uploadButton.textContent = 'Datei hinzufügen';
                        }
                    }
                    
                    // Function to update original input with accumulated files
                    function updateOriginalInput() {
                        var dataTransfer = new DataTransfer();
                        var originalNames = [];
                        
                        accumulatedFiles.forEach(function(file) {
                            dataTransfer.items.add(file);
                            originalNames.push(file.name);
                        });
                        
                        fileInput.files = dataTransfer.files;
                        
                        // Store original filenames in hidden field
                        var originalNamesField = fileInput.parentNode.querySelector('.priority-ticket-original-names');
                        if (originalNamesField) {
                            originalNamesField.value = originalNames.join(',');
                        }
                        
                        // Trigger change event for form validation
                        var event = new Event('change', { bubbles: true });
                        fileInput.dispatchEvent(event);
                    }
                    
                    // Function to format file size
                    function formatFileSize(bytes) {
                        if (bytes >= 1048576) {
                            return (bytes / 1048576).toFixed(1) + ' MB';
                        } else if (bytes >= 1024) {
                            return (bytes / 1024).toFixed(1) + ' KB';
                        } else {
                            return bytes + ' B';
                        }
                    }
                    
                    // Handle upload button click
                    uploadButton.addEventListener('click', function() {
                        if (accumulatedFiles.length >= maxFiles) return;
                        
                        // Create temporary file input for selection
                        var tempInput = document.createElement('input');
                        tempInput.type = 'file';
                        tempInput.multiple = true;
                        tempInput.accept = fileInput.accept || '';
                        tempInput.style.display = 'none';
                        
                        tempInput.addEventListener('change', function() {
                            var newFiles = Array.from(this.files);
                            
                            // Add new files up to the limit
                            newFiles.forEach(function(file) {
                                if (accumulatedFiles.length < maxFiles) {
                                    // Check for duplicates
                                    var isDuplicate = accumulatedFiles.some(function(existingFile) {
                                        return existingFile.name === file.name && existingFile.size === file.size;
                                    });
                                    
                                    if (!isDuplicate) {
                                        accumulatedFiles.push(file);
                                    }
                                }
                            });
                            
                            updateFileList();
                            updateOriginalInput();
                            updateButtonState();
                            
                            // Clean up temp input
                            document.body.removeChild(tempInput);
                        });
                        
                        // Trigger file selection
                        document.body.appendChild(tempInput);
                        tempInput.click();
                    });
                    
                    // Initialize display
                    updateFileList();
                    updateButtonState();
                });
            }
            
            // Main enhancement function that runs all features
            function runEnhancements() {
                initializeFormEnhancements();
                setupIncrementalFileUpload();
                translateElementorMessages();
            }
            
            // Run immediately
            runEnhancements();
            
            // Also run after delays to handle dynamic form loading (reduced frequency)
            setTimeout(runEnhancements, 500);
            setTimeout(runEnhancements, 1500);
            
            // Run when Elementor widgets are loaded
            if (typeof jQuery !== 'undefined') {
                jQuery(document).on('elementor/popup/show', function() {
                    setTimeout(runEnhancements, 100);
                });
                
                // Run when Elementor frontend is loaded
                jQuery(window).on('elementor/frontend/init', function() {
                    setTimeout(runEnhancements, 300);
                });
            }
            
            // Run on any dynamic content changes using MutationObserver
            if (window.MutationObserver) {
                var observer = new MutationObserver(function(mutations) {
                    var shouldRun = false;
                    mutations.forEach(function(mutation) {
                        if (mutation.type === 'childList') {
                            for (var i = 0; i < mutation.addedNodes.length; i++) {
                                var node = mutation.addedNodes[i];
                                if (node.nodeType === 1 && (
                                    node.classList.contains('elementor-form') ||
                                    (node.querySelector && node.querySelector('.elementor-form')) ||
                                    node.tagName === 'TEXTAREA' ||
                                    node.tagName === 'INPUT'
                                )) {
                                    shouldRun = true;
                                    break;
                                }
                            }
                        }
                    });
                    if (shouldRun) {
                        setTimeout(runEnhancements, 100);
                    }
                });
                
                observer.observe(document.body, { childList: true, subtree: true });
            }
        });";
        
        wp_add_inline_script('jquery', $script);
    }
    
    /**
     * Enqueue WooCommerce checkout auto-population script
     */
    public function enqueue_woocommerce_checkout_population() {
        // Only load on WooCommerce checkout page
        if (!class_exists('WooCommerce') || !is_checkout()) {
            return;
        }
        
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Get session data
        $checkout_data = isset($_SESSION['ptp_checkout_data']) ? $_SESSION['ptp_checkout_data'] : array();
        
        // Only proceed if we have data
        if (empty($checkout_data)) {
            return;
        }
        
        // Prepare checkout data for JavaScript
        $js_data = array(
            'first_name' => !empty($checkout_data['first_name']) ? $checkout_data['first_name'] : '',
            'last_name' => !empty($checkout_data['last_name']) ? $checkout_data['last_name'] : '',
            'name' => !empty($checkout_data['name']) ? $checkout_data['name'] : '',
            'email' => !empty($checkout_data['email']) ? $checkout_data['email'] : '',
            'phone' => !empty($checkout_data['phone']) ? $checkout_data['phone'] : '',
        );
        
        // Add WooCommerce checkout auto-population script
        $checkout_script = "
        document.addEventListener('DOMContentLoaded', function() {
            // Session checkout data from form submission
            var checkoutData = " . wp_json_encode($js_data) . ";
            
            // Function to populate WooCommerce checkout fields
            function populateCheckoutFields() {
                // Only proceed if we have checkout data
                if (!checkoutData || (!checkoutData.email && !checkoutData.name && !checkoutData.first_name)) {
                    return;
                }
                
                // Handle name splitting if we only have a full name
                var firstName = checkoutData.first_name;
                var lastName = checkoutData.last_name;
                
                if (!firstName && !lastName && checkoutData.name) {
                    // Split full name into first and last name
                    var nameParts = checkoutData.name.trim().split(' ');
                    firstName = nameParts[0] || '';
                    lastName = nameParts.slice(1).join(' ') || '';
                }
                
                // WooCommerce checkout field selectors
                var fieldMappings = {
                    // Billing fields
                    'billing_first_name': firstName,
                    'billing_last_name': lastName,
                    'billing_email': checkoutData.email,
                    'billing_phone': checkoutData.phone,
                    
                    // Shipping fields (in case they're separate)
                    'shipping_first_name': firstName,
                    'shipping_last_name': lastName,
                    
                    // Account fields
                    'account_username': checkoutData.email,
                    'account_email': checkoutData.email,
                };
                
                // German field mappings (Vorname, Nachname, Telefon)
                var germanFieldMappings = {
                    'vorname': firstName,
                    'nachname': lastName,
                    'telefon': checkoutData.phone,
                    'e-mail': checkoutData.email,
                    'email': checkoutData.email
                };
                
                // Function to safely set field value
                function setFieldValue(selector, value) {
                    if (!value) return false;
                    
                    var field = document.querySelector(selector);
                    if (field && field.value === '') {
                        field.value = value;
                        
                        // Trigger change events for validation
                        var changeEvent = new Event('change', { bubbles: true });
                        field.dispatchEvent(changeEvent);
                        
                        var inputEvent = new Event('input', { bubbles: true });
                        field.dispatchEvent(inputEvent);
                        
                        // Trigger blur for some validation systems
                        var blurEvent = new Event('blur', { bubbles: true });
                        field.dispatchEvent(blurEvent);
                        
                        return true;
                    }
                    return false;
                }
                
                // Populate standard WooCommerce fields
                Object.keys(fieldMappings).forEach(function(fieldName) {
                    var value = fieldMappings[fieldName];
                    if (value) {
                        // Try multiple selector patterns
                        var selectors = [
                            '#' + fieldName,
                            'input[name=\"' + fieldName + '\"]',
                            'input[id=\"' + fieldName + '\"]',
                            '.form-row-' + fieldName.replace('_', '-') + ' input',
                            '.woocommerce-' + fieldName.replace('_', '-') + ' input'
                        ];
                        
                        selectors.forEach(function(selector) {
                            setFieldValue(selector, value);
                        });
                    }
                });
                
                // Populate German fields (by name, placeholder, or label)
                Object.keys(germanFieldMappings).forEach(function(germanField) {
                    var value = germanFieldMappings[germanField];
                    if (value) {
                        // Try multiple German field detection methods
                        var germanSelectors = [
                            'input[name*=\"' + germanField + '\"]',
                            'input[id*=\"' + germanField + '\"]',
                            'input[placeholder*=\"' + germanField + '\" i]',
                            'input[class*=\"' + germanField + '\"]'
                        ];
                        
                        germanSelectors.forEach(function(selector) {
                            setFieldValue(selector, value);
                        });
                    }
                });
                
                // Special handling for common German checkout field patterns
                if (firstName) {
                    setFieldValue('input[placeholder*=\"Vorname\" i]', firstName);
                    setFieldValue('input[label*=\"Vorname\" i]', firstName);
                    setFieldValue('.checkout-firstname input', firstName);
                    setFieldValue('.wc-firstname input', firstName);
                }
                
                if (lastName) {
                    setFieldValue('input[placeholder*=\"Nachname\" i]', lastName);
                    setFieldValue('input[label*=\"Nachname\" i]', lastName);
                    setFieldValue('.checkout-lastname input', lastName);
                    setFieldValue('.wc-lastname input', lastName);
                }
                
                if (checkoutData.phone) {
                    setFieldValue('input[placeholder*=\"Telefon\" i]', checkoutData.phone);
                    setFieldValue('input[placeholder*=\"Phone\" i]', checkoutData.phone);
                    setFieldValue('input[type=\"tel\"]', checkoutData.phone);
                    setFieldValue('.checkout-phone input', checkoutData.phone);
                    setFieldValue('.wc-phone input', checkoutData.phone);
                }
                
                if (checkoutData.email) {
                    setFieldValue('input[placeholder*=\"E-Mail\" i]', checkoutData.email);
                    setFieldValue('input[type=\"email\"]', checkoutData.email);
                    setFieldValue('.checkout-email input', checkoutData.email);
                    setFieldValue('.wc-email input', checkoutData.email);
                }
                
                console.log('Priority Ticket Payment: Auto-populated checkout fields from session data');
            }
            
            // Run population immediately
            populateCheckoutFields();
            
            // Also run after checkout form updates (for AJAX checkout pages)
            setTimeout(populateCheckoutFields, 500);
            setTimeout(populateCheckoutFields, 1000);
            setTimeout(populateCheckoutFields, 2000);
            
            // Watch for checkout form updates
            if (window.MutationObserver) {
                var checkoutObserver = new MutationObserver(function(mutations) {
                    var shouldRepopulate = false;
                    mutations.forEach(function(mutation) {
                        if (mutation.type === 'childList') {
                            for (var i = 0; i < mutation.addedNodes.length; i++) {
                                var node = mutation.addedNodes[i];
                                if (node.nodeType === 1 && (
                                    node.classList.contains('woocommerce-checkout') ||
                                    node.classList.contains('checkout') ||
                                    (node.querySelector && (
                                        node.querySelector('.woocommerce-checkout') ||
                                        node.querySelector('input[name*=\"billing_\"]') ||
                                        node.querySelector('input[placeholder*=\"Vorname\" i]')
                                    ))
                                )) {
                                    shouldRepopulate = true;
                                    break;
                                }
                            }
                        }
                    });
                    if (shouldRepopulate) {
                        setTimeout(populateCheckoutFields, 100);
                    }
                });
                
                checkoutObserver.observe(document.body, { childList: true, subtree: true });
            }
            
            // Listen for WooCommerce checkout updates
            jQuery(document.body).on('updated_checkout', function() {
                setTimeout(populateCheckoutFields, 100);
            });
            
            // Listen for payment method changes
            jQuery(document.body).on('payment_method_selected', function() {
                setTimeout(populateCheckoutFields, 100);
            });
        });";
        
        wp_add_inline_script('wc-checkout', $checkout_script);
        
        // Log for debugging
        error_log('Priority Ticket Payment: Loaded WooCommerce checkout auto-population with data: ' . print_r($checkout_data, true));
    }
} 