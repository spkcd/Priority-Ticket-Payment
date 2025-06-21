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
        
        // Hook into Awesome Support ticket status changes and reply submission
        add_action('wpas_after_add_reply', array(__CLASS__, 'handle_ticket_reply'), 10, 2);
        add_action('wpas_ticket_status_updated', array(__CLASS__, 'handle_ticket_status_change'), 10, 3);
        
        // Hook into reply submission with higher priority to catch close actions
        add_action('wp_insert_post', array(__CLASS__, 'handle_reply_submission'), 5, 2);
        
        // Hook into Awesome Support's native reply processing
        add_action('wpas_reply_added', array(__CLASS__, 'handle_native_reply_added'), 10, 2);
        
        // Hook into post status transitions to catch manual status changes
        add_action('transition_post_status', array(__CLASS__, 'handle_status_transition'), 10, 3);
        
        // Hook into form submissions to clear caches and ensure immediate visibility
        add_action('template_redirect', array(__CLASS__, 'handle_form_submission'));
        
        // Add AJAX handler for Reply & Close functionality
        add_action('wp_ajax_priority_ticket_reply_and_close', array(__CLASS__, 'ajax_reply_and_close'));
        add_action('wp_ajax_nopriv_priority_ticket_reply_and_close', array(__CLASS__, 'ajax_reply_and_close'));
        
        // Hook into Awesome Support's reply processing specifically for Reply & Close
        add_action('init', array(__CLASS__, 'init_reply_close_handling'), 20);
        
        // Hook into Awesome Support form processing
        add_action('wpas_before_submit_new_ticket_reply', array(__CLASS__, 'handle_before_reply_submit'), 10, 1);
        add_action('wpas_after_submit_new_ticket_reply', array(__CLASS__, 'handle_after_reply_submit'), 10, 2);
        

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
        
        // Build ticket title using only client's name
        $ticket_title = '';
        
        // Primary: Try to get client name from form data
        if (isset($form_data['name']) && !empty(trim($form_data['name']))) {
            $ticket_title = sanitize_text_field(trim($form_data['name']));
        }
        
        // Secondary fallback: Get name from order billing information
        if (empty($ticket_title)) {
            $customer_name = $order->get_formatted_billing_full_name();
            if (!empty(trim($customer_name)) && $customer_name !== 'Unknown Customer') {
                $ticket_title = sanitize_text_field($customer_name);
            }
        }
        
        // Tertiary fallback: Try to get user information
        if (empty($ticket_title) && !empty($submission['user_id'])) {
            $user = get_user_by('id', $submission['user_id']);
            if ($user) {
                // Try display name first
                if (!empty($user->display_name)) {
                    $ticket_title = sanitize_text_field($user->display_name);
                }
                // Fallback to user login
                elseif (!empty($user->user_login)) {
                    $ticket_title = sanitize_text_field($user->user_login);
                }
                // Fallback to email (remove @ and domain for cleaner title)
                elseif (!empty($user->user_email)) {
                    $email_parts = explode('@', $user->user_email);
                    $ticket_title = sanitize_text_field($email_parts[0]);
                }
            }
        }
        
        // Final fallback: Generic title if nothing else available
        if (empty($ticket_title)) {
            $ticket_title = 'Support Request #' . $submission['id'];
        }
        
        // Build ticket content
        $ticket_content = self::build_ticket_content($form_data, $order, $submission['attachments']);
        
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
        
        // NOTE: Attachments are now displayed in the ticket content itself with better formatting
        // The native Awesome Support attachment system is disabled to prevent duplicate display
        // Files are safely stored in wp-content/uploads/priority-tickets/ with direct download links
        
        // Send admin notification email
        self::send_admin_notification_email($ticket_id, $submission, $form_data);
        
        // Order information is stored as post meta for internal reference
        // (Removed automatic order reply to keep ticket interface clean)
        
        return $ticket_id;
    }
    
    /**
     * Set ticket priority metadata based on user tier
     */
    private static function set_ticket_priority_metadata($ticket_id, $user_priority, $form_data, $order, $submission) {
        // Map priority tiers to actual Awesome Support priority term IDs
        $priority_map = array(
            'A' => 134, // Coaching Client (Free) → a-ticket (ID 134)
            'B' => 135, // Standard → b-ticket (ID 135)  
            'C' => 136, // Basic → c-ticket (ID 136)
        );
        
        $priority_term_id = isset($priority_map[$user_priority]) ? $priority_map[$user_priority] : 136; // Default to c-ticket for basic
        
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
        $priority_names = array('A' => 'a-ticket', 'B' => 'b-ticket', 'C' => 'c-ticket');
        $priority_name = isset($priority_names[$user_priority]) ? $priority_names[$user_priority] : 'c-ticket';
        error_log("Priority Ticket Payment: Set ticket $ticket_id priority to $priority_name (term ID: $priority_term_id) for user priority: $user_priority");
        
        // Set assignee based on coach field
        if (!empty($form_data['coach'])) {
            $coach_value = $form_data['coach'];
            
            // Check for "Bisher kein Coach" (no coach) option
            if (stripos($coach_value, 'Bisher kein Coach') !== false || 
                stripos($coach_value, 'kein Coach') !== false ||
                stripos($coach_value, 'no coach') !== false) {
                // Assign to user ID 332 (client's default user)
                update_post_meta($ticket_id, '_wpas_assignee', 332);
                error_log('Priority Ticket Payment: Assigned ticket ' . $ticket_id . ' to default user ID 332 (no coach selected)');
            } else {
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
                        // If no valid coach found, assign to default user ID 332
                        update_post_meta($ticket_id, '_wpas_assignee', 332);
                        error_log('Priority Ticket Payment: Could not find agent for coach: ' . $coach_value . ', assigned to default user ID 332');
                    }
                } else {
                    // If placeholder value, assign to default user ID 332
                    update_post_meta($ticket_id, '_wpas_assignee', 332);
                    error_log('Priority Ticket Payment: Coach field contains placeholder value, assigned to default user ID 332: ' . $coach_value);
                }
            }
        } else {
            // If no coach field specified, assign to default user ID 332
            update_post_meta($ticket_id, '_wpas_assignee', 332);
            error_log('Priority Ticket Payment: No coach field specified, assigned ticket ' . $ticket_id . ' to default user ID 332');
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
     * Build ticket content from form data, order, and attachments
     */
    private static function build_ticket_content($form_data, $order, $attachments = array()) {
        // Ensure form_data is an array
        if (!is_array($form_data)) {
            error_log('Priority Ticket Payment: form_data is not an array in build_ticket_content, using empty array');
            $form_data = array();
        }
        
        $content_parts = array();
        
        // Define the 8 required form fields with German labels and field mapping
        $required_fields = array(
            'name' => array(
                'label' => 'Name',
                'keys' => array('name', 'full_name', 'customer_name', 'vorname', 'nachname')
            ),
            'email' => array(
                'label' => 'Email',
                'keys' => array('email', 'contact_email', 'user_email', 'e_mail', 'e-mail')
            ),
            'subject' => array(
                'label' => 'Subject',
                'keys' => array('subject', 'ticket_subject', 'betreff', 'titel', 'thema')
            ),
            'urgency' => array(
                'label' => 'Wie dringend ist die Beantwortung? Gibt es einen Termin zu beachten?',
                'keys' => array('urgency', 'dringlichkeit', 'priority', 'termin', 'deadline', 'urgent')
            ),
            'coach' => array(
                'label' => 'Wer ist Ihr Coach?',
                'keys' => array('coach', 'preferred_coach', 'wunsch_coach', 'betreuer', 'berater')
            ),
            'message' => array(
                'label' => 'Nachricht',
                'keys' => array('message', 'nachricht', 'ticket_description', 'description', 'text', 'inhalt')
            ),
            'website' => array(
                'label' => 'Hinweis auf eine Webseite',
                'keys' => array('website_hint', 'webseite', 'website', 'domain', 'url', 'link')
            )
        );
        
        // Helper function to get field value from multiple possible keys
        $get_field_value = function($field_keys) use ($form_data) {
            foreach ($field_keys as $key) {
                if (isset($form_data[$key])) {
                    $value = $form_data[$key];
                    
                    // Handle array values (checkboxes, multi-select)
                    if (is_array($value)) {
                        $value = implode(', ', array_filter($value));
                    }
                    
                    // Clean and validate the value
                    $value = trim($value);
                    if (!empty($value)) {
                        return $value;
                    }
                }
            }
            return '';
        };
        
        // Helper function to filter placeholder values
        $filter_placeholders = function($value) {
            $placeholders = array(
                '– Wer ist Ihr Coach? –',
                'Select a coach',
                'Choose coach',
                'Please select',
                'Bitte wählen',
                'Auswählen',
                'Select...',
                'Choose...',
                'Wählen Sie...',
                '---',
                '--',
                'N/A',
                'None',
                'Keine Angabe'
            );
            
            return !in_array(trim($value), $placeholders) && trim($value) !== '';
        };
        
        // Process all 8 required fields in the specified order
        foreach ($required_fields as $field_id => $field_config) {
            $field_value = $get_field_value($field_config['keys']);
            
            // Special handling for specific fields
            if ($field_id === 'email' && !empty($field_value)) {
                // Validate email format
                if (!is_email($field_value)) {
                    continue; // Skip invalid emails
                }
            }
            
            if ($field_id === 'coach' && !empty($field_value)) {
                // Filter out placeholder values for coach field
                if (!$filter_placeholders($field_value)) {
                    continue; // Skip placeholder values
                }
            }
            
            // Add the field to content if it has a value
            if (!empty($field_value)) {
                if ($field_id === 'message') {
                    // Format message with line break
                    $content_parts[] = esc_html($field_config['label']) . ':';
                    $content_parts[] = esc_html($field_value);
                    $content_parts[] = ''; // Add empty line for spacing
                } else {
                    // Standard field format
                    $content_parts[] = esc_html($field_config['label']) . ': ' . esc_html($field_value);
                    $content_parts[] = ''; // Add empty line for spacing
                }
            }
        }
        
        // Skip additional fields processing to avoid duplicates and system fields
        // Only show the 8 required fields that were properly mapped above
        
        // Process attachments (up to 3 files)        
        // Handle case where attachments might be serialized string
        if (is_string($attachments)) {
            $unserialized_attachments = unserialize($attachments);
            if (is_array($unserialized_attachments)) {
                $attachments = $unserialized_attachments;
            }
        }
        
        if (!empty($attachments) && is_array($attachments)) {
            $attachment_links = array();
            $file_count = 0;
            
            foreach ($attachments as $attachment) {
                
                // Ensure attachment is an array and has required fields
                // Try different URL variations - some attachments might use 'path' instead of 'url'
                $attachment_url = '';
                if (isset($attachment['url'])) {
                    $attachment_url = $attachment['url'];
                } elseif (isset($attachment['path'])) {
                    // Convert file path to URL
                    $upload_dir = wp_upload_dir();
                    $attachment_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $attachment['path']);
                }
                
                if (is_array($attachment) && !empty($attachment_url) && isset($attachment['original_name'])) {
                    $file_count++;
                    
                    // Sanitize the filename and URL
                    $safe_filename = sanitize_text_field($attachment['original_name']);
                    $safe_url = esc_url_raw($attachment_url);
                    
                    if (!empty($safe_filename) && !empty($safe_url)) {
                        // Format as bullet point with download link
                        $attachment_link = sprintf(
                            '• <a href="%s" target="_blank">Download file %d</a> (%s)',
                            esc_url($safe_url),
                            $file_count,
                            esc_html($safe_filename)
                        );
                        $attachment_links[] = $attachment_link;
                    }
                }
                
                // Enforce 3-file limit
                if ($file_count >= 3) {
                    break;
                }
            }
            
            // Add attachments section if files are present
            if (!empty($attachment_links)) {
                $content_parts[] = ''; // Empty line for spacing
                $content_parts[] = 'Anhänge:';
                foreach ($attachment_links as $link) {
                    $content_parts[] = $link;
                }
            }
        }
        
        $final_content = implode("\n", $content_parts);
        
        return $final_content;
    }
    
    /**
     * Format file size for display
     */
    private static function format_file_size($bytes) {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        } else {
            return $bytes . ' B';
        }
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
    
    /**
     * Handle ticket reply submission to ensure proper status updates
     */
    public static function handle_reply_submission($post_id, $post) {
        // Only handle ticket replies
        if ($post->post_type !== 'ticket_reply') {
            return;
        }
        
        $ticket_id = $post->post_parent;
        if (!$ticket_id) {
            return;
        }
        
        // Check if this is a priority ticket
        $is_priority_ticket = get_post_meta($ticket_id, '_priority_ticket_submission_id', true);
        if (!$is_priority_ticket) {
            return;
        }
        
        // Clear caches to ensure reply is immediately visible
        self::clear_ticket_caches($ticket_id, $post_id);
        
        // Check multiple methods for close action detection
        $should_close = false;
        
        // Method 1: Check reply meta for close action
        $close_ticket = get_post_meta($post_id, '_wpas_close_ticket', true);
        if ($close_ticket === 'yes' || $close_ticket === '1') {
            $should_close = true;
            error_log('Priority Ticket Payment: Close action detected via reply meta for ticket ' . $ticket_id);
        }
        
        // Method 2: Check if ticket was marked for closing
        $pending_close = get_post_meta($ticket_id, '_priority_ticket_pending_close', true);
        if ($pending_close === '1') {
            $should_close = true;
            delete_post_meta($ticket_id, '_priority_ticket_pending_close');
            error_log('Priority Ticket Payment: Close action detected via pending meta for ticket ' . $ticket_id);
        }
        
        // Method 3: Check POST data for close action (most reliable)
        if (isset($_POST['wpas_do']) && $_POST['wpas_do'] === 'reply_close') {
            $should_close = true;
            error_log('Priority Ticket Payment: Close action detected via POST data for ticket ' . $ticket_id);
            
            // Immediately set the close flag to ensure Awesome Support processes it
            update_post_meta($post_id, '_wpas_close_ticket', 'yes');
        }
        
        // Method 4: Check if ticket status was set to closed in the current request
        $ticket_status = get_post_meta($ticket_id, '_wpas_status', true);
        if ($ticket_status === '3' || $ticket_status === 'closed') {
            $should_close = true;
            error_log('Priority Ticket Payment: Close action detected via ticket status for ticket ' . $ticket_id);
        }
        
        if ($should_close) {
            // Use immediate close action with proper hooks
            add_action('wpas_after_reply_added', function($reply_id, $reply_ticket_id) use ($ticket_id, $post_id) {
                if ($reply_ticket_id == $ticket_id && $reply_id == $post_id) {
                    // Force close the ticket immediately after reply is added
                    self::force_close_priority_ticket($ticket_id);
                    error_log('Priority Ticket Payment: Force closed ticket ' . $ticket_id . ' after reply ' . $reply_id);
                }
            }, 5, 2); // High priority to run early
            
            error_log('Priority Ticket Payment: Registered force close action for ticket ' . $ticket_id . ' after reply ' . $post_id);
        }
    }
    
    /**
     * Handle delayed ticket closing to ensure proper processing
     */
    public static function delayed_close_ticket($ticket_id, $reply_id) {
        self::close_priority_ticket($ticket_id);
        self::clear_ticket_caches($ticket_id, $reply_id);
        self::refresh_ticket_display($ticket_id);
        
        error_log('Priority Ticket Payment: Executed delayed close for ticket ' . $ticket_id);
    }
    
    /**
     * Handle ticket reply addition
     */
    public static function handle_ticket_reply($reply_id, $ticket_id) {
        if (!self::is_awesome_support_active()) {
            return;
        }
        
        // Check if this is a priority ticket
        $is_priority_ticket = get_post_meta($ticket_id, '_priority_ticket_submission_id', true);
        if (!$is_priority_ticket) {
            return;
        }
        
        // Clear caches to ensure reply is immediately visible
        self::clear_ticket_caches($ticket_id, $reply_id);
        
        // Send email notification to client about the reply (with duplicate prevention)
        if (Priority_Ticket_Payment::get_option('send_reply_notifications', 'yes') === 'yes') {
            self::send_reply_notification_email($reply_id, $ticket_id);
        }
        
        // Check if the reply should close the ticket
        $close_ticket = get_post_meta($reply_id, '_wpas_close_ticket', true);
        if ($close_ticket === 'yes' || $close_ticket === '1') {
            self::close_priority_ticket($ticket_id);
            error_log('Priority Ticket Payment: Ticket ' . $ticket_id . ' closed via reply action');
        }
    }
    
    /**
     * Handle ticket status changes
     */
    public static function handle_ticket_status_change($ticket_id, $old_status, $new_status) {
        if (!self::is_awesome_support_active()) {
            return;
        }
        
        // Check if this is a priority ticket
        $is_priority_ticket = get_post_meta($ticket_id, '_priority_ticket_submission_id', true);
        if (!$is_priority_ticket) {
            return;
        }
        
        error_log('Priority Ticket Payment: Ticket ' . $ticket_id . ' status changed from ' . $old_status . ' to ' . $new_status);
        
        // Additional logging for debugging
        if ($new_status === 'closed') {
            error_log('Priority Ticket Payment: Priority ticket ' . $ticket_id . ' successfully closed');
        }
    }
    
    /**
     * Close priority ticket with proper status updates
     */
    public static function close_priority_ticket($ticket_id) {
        if (!self::is_awesome_support_active()) {
            error_log('Priority Ticket Payment: Cannot close ticket - Awesome Support not active');
            return false;
        }
        
        // Update post status to closed
        $result = wp_update_post(array(
            'ID' => $ticket_id,
            'post_status' => 'closed'
        ));
        
        if (is_wp_error($result)) {
            error_log('Priority Ticket Payment: Error updating ticket post status: ' . $result->get_error_message());
            return false;
        }
        
        // Set the correct closed status ID (3) and meta
        update_post_meta($ticket_id, '_wpas_status', '3'); // Status ID 3 = Closed
        
        // Update ticket status taxonomy - try multiple approaches
        $closed_set = false;
        
        // Method 1: Try to find closed status by ID = 3
        $closed_term = get_term(3, 'ticket_status');
        if ($closed_term && !is_wp_error($closed_term)) {
            $taxonomy_result = wp_set_object_terms($ticket_id, array(3), 'ticket_status');
            if (!is_wp_error($taxonomy_result)) {
                $closed_set = true;
                error_log('Priority Ticket Payment: Set ticket ' . $ticket_id . ' status to closed using ID 3');
            }
        }
        
        // Method 2: Try to find by slug if ID method failed
        if (!$closed_set) {
            $closed_term = get_term_by('slug', 'closed', 'ticket_status');
            if ($closed_term) {
                $taxonomy_result = wp_set_object_terms($ticket_id, array($closed_term->term_id), 'ticket_status');
                if (!is_wp_error($taxonomy_result)) {
                    $closed_set = true;
                    error_log('Priority Ticket Payment: Set ticket ' . $ticket_id . ' status to closed using slug (term ID: ' . $closed_term->term_id . ')');
                }
            }
        }
        
        // Method 3: Search all terms if other methods failed
        if (!$closed_set) {
            $status_terms = get_terms(array(
                'taxonomy' => 'ticket_status',
                'hide_empty' => false,
            ));
            
            if (!is_wp_error($status_terms)) {
                foreach ($status_terms as $term) {
                    if (in_array($term->slug, array('closed', 'close')) || stripos($term->name, 'closed') !== false) {
                        $taxonomy_result = wp_set_object_terms($ticket_id, array($term->term_id), 'ticket_status');
                        if (!is_wp_error($taxonomy_result)) {
                            $closed_set = true;
                            error_log('Priority Ticket Payment: Set ticket ' . $ticket_id . ' status to ' . $term->name . ' (ID: ' . $term->term_id . ')');
                            break;
                        }
                    }
                }
            }
        }
        
        if (!$closed_set) {
            error_log('Priority Ticket Payment: Could not find or set closed status taxonomy for ticket ' . $ticket_id);
        }
        
        // Trigger Awesome Support close action if available
        if (function_exists('wpas_close_ticket')) {
            wpas_close_ticket($ticket_id);
            error_log('Priority Ticket Payment: Called wpas_close_ticket for ticket ' . $ticket_id);
        } else {
            // Manual close action trigger
            do_action('wpas_close_ticket', $ticket_id);
            error_log('Priority Ticket Payment: Triggered wpas_close_ticket action for ticket ' . $ticket_id);
        }
        
        // Additional meta updates for Awesome Support compatibility
        update_post_meta($ticket_id, '_wpas_ticket_status', '3');
        update_post_meta($ticket_id, '_priority_ticket_closed_by_reply', current_time('mysql'));
        
        error_log('Priority Ticket Payment: Successfully closed ticket ' . $ticket_id . ' with status ID 3');
        
        return true;
    }
    
    /**
     * Force close priority ticket - more aggressive method for "Reply & Close"
     */
    public static function force_close_priority_ticket($ticket_id) {
        if (!self::is_awesome_support_active()) {
            error_log('Priority Ticket Payment: Cannot force close ticket - Awesome Support not active');
            return false;
        }
        
        // First use Awesome Support's native close function if available
        if (function_exists('wpas_close_ticket')) {
            $result = wpas_close_ticket($ticket_id);
            error_log('Priority Ticket Payment: Called wpas_close_ticket for force close: ' . ($result ? 'SUCCESS' : 'FAILED'));
        }
        
        // Then manually ensure all status indicators are set
        wp_update_post(array(
            'ID' => $ticket_id,
            'post_status' => 'closed'
        ));
        
        // Set all possible status meta fields
        update_post_meta($ticket_id, '_wpas_status', 'closed'); // Use string 'closed' instead of ID
        update_post_meta($ticket_id, '_wpas_ticket_status', 'closed');
        update_post_meta($ticket_id, '_priority_ticket_force_closed', current_time('mysql'));
        
        // Try to find and set the closed taxonomy term
        $closed_terms = get_terms(array(
            'taxonomy' => 'ticket_status',
            'hide_empty' => false,
            'slug' => 'closed'
        ));
        
        if (!empty($closed_terms) && !is_wp_error($closed_terms)) {
            $closed_term = $closed_terms[0];
            wp_set_object_terms($ticket_id, array($closed_term->term_id), 'ticket_status');
            error_log('Priority Ticket Payment: Force set closed taxonomy term ID: ' . $closed_term->term_id);
        } else {
            // Fallback: try to find any term with "closed" in the name
            $all_status_terms = get_terms(array(
                'taxonomy' => 'ticket_status',
                'hide_empty' => false,
            ));
            
            if (!is_wp_error($all_status_terms)) {
                foreach ($all_status_terms as $term) {
                    if (stripos($term->name, 'closed') !== false || stripos($term->slug, 'closed') !== false) {
                        wp_set_object_terms($ticket_id, array($term->term_id), 'ticket_status');
                        error_log('Priority Ticket Payment: Force set fallback closed term: ' . $term->name . ' (ID: ' . $term->term_id . ')');
                        break;
                    }
                }
            }
        }
        
        // Clear caches to ensure status change is visible
        self::clear_ticket_caches($ticket_id);
        
        // Trigger WordPress and Awesome Support hooks
        do_action('wpas_close_ticket', $ticket_id);
        do_action('wpas_ticket_status_updated', $ticket_id, 'open', 'closed');
        do_action('priority_ticket_payment_ticket_force_closed', $ticket_id);
        
        error_log('Priority Ticket Payment: Force closed ticket ' . $ticket_id . ' with all status indicators');
        
        return true;
    }
    
    /**
     * Clear all relevant caches to ensure ticket replies are immediately visible
     */
    public static function clear_ticket_caches($ticket_id, $reply_id = null) {
        // Clear post cache for the ticket
        clean_post_cache($ticket_id);
        
        // Clear comments cache for the ticket
        if (function_exists('clean_comment_cache')) {
            // Get all comments for this ticket
            $comments = get_comments(array(
                'post_id' => $ticket_id,
                'meta_query' => array(
                    array(
                        'key' => '_wpas_reply_id',
                        'compare' => 'EXISTS'
                    )
                )
            ));
            
            foreach ($comments as $comment) {
                clean_comment_cache($comment);
            }
        }
        
        // Clear WordPress object cache groups
        $cache_groups = array('posts', 'post_meta', 'comments', 'comment_meta');
        
        foreach ($cache_groups as $group) {
            wp_cache_delete($ticket_id, $group);
            if ($reply_id) {
                wp_cache_delete($reply_id, $group);
            }
        }
        
        // Clear Awesome Support specific caches
        wp_cache_delete('wpas_tickets_' . get_current_user_id(), 'tickets');
        wp_cache_delete('wpas_ticket_replies_' . $ticket_id, 'ticket_replies');
        wp_cache_delete('wpas_ticket_' . $ticket_id, 'tickets');
        
        // Clear any persistent object cache if present
        if (self::has_persistent_object_cache()) {
            // Clear ticket-specific cache keys
            $cache_keys = array(
                'ticket_' . $ticket_id,
                'ticket_replies_' . $ticket_id,
                'ticket_meta_' . $ticket_id,
                'user_tickets_' . get_current_user_id(),
            );
            
            foreach ($cache_keys as $key) {
                wp_cache_delete($key);
                wp_cache_delete($key, 'default');
                wp_cache_delete($key, 'tickets');
                wp_cache_delete($key, 'ticket_replies');
            }
            
            // If using Redis/Memcached, consider selective flush
            if (function_exists('wp_cache_flush_group')) {
                wp_cache_flush_group('tickets');
                wp_cache_flush_group('ticket_replies');
            }
        }
        
        // Clear any query caches that might be storing ticket data
        wp_cache_delete('last_changed', 'posts');
        wp_cache_delete('last_changed', 'comments');
        
        // Force refresh of ticket children (replies) cache
        delete_transient('wpas_ticket_replies_' . $ticket_id);
        delete_transient('wpas_ticket_children_' . $ticket_id);
        
        // Clear user-specific ticket caches
        $user_id = get_current_user_id();
        if ($user_id) {
            delete_transient('wpas_user_tickets_' . $user_id);
            wp_cache_delete('user_tickets_' . $user_id, 'tickets');
        }
        
        // Trigger cache refresh for Awesome Support if functions are available
        if (function_exists('wpas_clear_tickets_cache')) {
            wpas_clear_tickets_cache();
        }
        
        if (function_exists('wpas_clear_replies_cache')) {
            wpas_clear_replies_cache($ticket_id);
        }
        
        error_log('Priority Ticket Payment: Cleared all caches for ticket ' . $ticket_id . ($reply_id ? ' and reply ' . $reply_id : ''));
        
        return true;
    }
    
    /**
     * Check if persistent object cache is in use
     */
    public static function has_persistent_object_cache() {
        // Check for common persistent cache plugins/systems
        if (defined('WP_REDIS_CLIENT') && WP_REDIS_CLIENT) {
            return true;
        }
        
        if (class_exists('Memcached') && function_exists('wp_cache_add_global_groups')) {
            return true;
        }
        
        if (defined('WP_CACHE') && WP_CACHE) {
            return true;
        }
        
        // Check if object cache is external (not file-based)
        if (wp_using_ext_object_cache()) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Force refresh ticket display for immediate visibility
     */
    public static function refresh_ticket_display($ticket_id) {
        // Clear ticket-specific caches
        self::clear_ticket_caches($ticket_id);
        
        // Update ticket modified date to trigger cache refresh
        wp_update_post(array(
            'ID' => $ticket_id,
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', 1)
        ));
        
        // Trigger action for other plugins to refresh their caches
        do_action('priority_ticket_payment_refresh_display', $ticket_id);
        
        return true;
    }
    
    /**
     * Handle form submission for immediate cache clearing and redirect
     */
    public static function handle_form_submission() {
        // Check if this is a ticket reply form submission
        if (!isset($_POST['wpas_reply_ticket']) || !isset($_POST['wpas_ticket_id'])) {
            return;
        }
        
        $ticket_id = intval($_POST['wpas_ticket_id']);
        if (!$ticket_id) {
            return;
        }
        
        // Check if this is a priority ticket
        $is_priority_ticket = get_post_meta($ticket_id, '_priority_ticket_submission_id', true);
        if (!$is_priority_ticket) {
            return;
        }
        
        // Clear caches after form processing
        add_action('wp', function() use ($ticket_id) {
            self::clear_ticket_caches($ticket_id);
            self::refresh_ticket_display($ticket_id);
        }, 99);
        
        // Add redirect to refresh the page after submission
        add_action('wpas_after_reply_added', function($reply_id, $reply_ticket_id) use ($ticket_id) {
            if ($reply_ticket_id == $ticket_id) {
                // Clear caches once more after reply is added
                self::clear_ticket_caches($ticket_id, $reply_id);
                
                // Redirect to refresh the page
                $redirect_url = get_permalink($ticket_id);
                if ($redirect_url) {
                    wp_safe_redirect($redirect_url . '?cache_cleared=' . time());
                    exit;
                }
            }
        }, 10, 2);
    }
    
    /**
     * Handle native Awesome Support reply additions
     */
    public static function handle_native_reply_added($reply_id, $ticket_id) {
        if (!self::is_awesome_support_active()) {
            return;
        }
        
        // Check if this is a priority ticket
        $is_priority_ticket = get_post_meta($ticket_id, '_priority_ticket_submission_id', true);
        if (!$is_priority_ticket) {
            return;
        }
        
        // Clear all caches for immediate visibility
        self::clear_ticket_caches($ticket_id, $reply_id);
        self::refresh_ticket_display($ticket_id);
        
        error_log('Priority Ticket Payment: Native reply added, caches cleared for ticket ' . $ticket_id . ' reply ' . $reply_id);
    }
    
    /**
     * Handle post status transitions to catch manual status changes
     */
    public static function handle_status_transition($new_status, $old_status, $post) {
        // Only handle ticket posts
        if ($post->post_type !== 'ticket') {
            return;
        }
        
        // Check if this is a priority ticket
        $is_priority_ticket = get_post_meta($post->ID, '_priority_ticket_submission_id', true);
        if (!$is_priority_ticket) {
            return;
        }
        
        error_log('Priority Ticket Payment: Status transition for ticket ' . $post->ID . ' from ' . $old_status . ' to ' . $new_status);
        
        // If manually set to closed, ensure proper meta is set
        if ($new_status === 'closed') {
            update_post_meta($post->ID, '_wpas_status', '3');
            update_post_meta($post->ID, '_wpas_ticket_status', '3');
        }
    }
    
    /**
     * Handle before reply submit to detect close action
     */
    public static function handle_before_reply_submit($ticket_id) {
        // Store close action in temporary meta if present
        if (isset($_POST['wpas_do']) && $_POST['wpas_do'] === 'reply_close') {
            update_post_meta($ticket_id, '_priority_ticket_pending_close', '1');
            error_log('Priority Ticket Payment: Detected Reply & Close action for ticket ' . $ticket_id);
        }
    }
    
    /**
     * Handle after reply submit to process close action
     */
    public static function handle_after_reply_submit($reply_id, $ticket_id) {
        // Check if this is a priority ticket
        $is_priority_ticket = get_post_meta($ticket_id, '_priority_ticket_submission_id', true);
        if (!$is_priority_ticket) {
            return;
        }

        // Check if close action was pending
        $pending_close = get_post_meta($ticket_id, '_priority_ticket_pending_close', true);
        if ($pending_close === '1') {
            // Remove the temporary meta
            delete_post_meta($ticket_id, '_priority_ticket_pending_close');

            // Force close the ticket using Awesome Support's proper method
            self::force_close_ticket_properly($ticket_id, $reply_id);

            error_log('Priority Ticket Payment: Processed Reply & Close for ticket ' . $ticket_id . ' after reply ' . $reply_id);
        }

        // Clear caches and refresh display
        self::clear_ticket_caches($ticket_id, $reply_id);
        self::refresh_ticket_display($ticket_id);
    }

    /**
     * Force close using Awesome Support's native mechanism (like admin close button)
     */
    public static function force_close_with_awesome_support($ticket_id) {
        if (!self::is_awesome_support_active()) {
            error_log('Priority Ticket Payment: Cannot close ticket - Awesome Support not active');
            return false;
        }

        error_log('Priority Ticket Payment: Force closing ticket ' . $ticket_id . ' using native AS method');

        // Simulate the admin close action by calling wpas_close_ticket directly
        // This is the same function called by the admin close button
        if (function_exists('wpas_close_ticket')) {
            // Add our hooks before closing to ensure proper handling
            add_action('wpas_ticket_after_close', function($ticket_id) {
                error_log('Priority Ticket Payment: Ticket ' . $ticket_id . ' closed via wpas_ticket_after_close hook');
                // Clear caches after close
                self::clear_ticket_caches($ticket_id);
            }, 10, 1);

            // Use Awesome Support's native close function
            $result = wpas_close_ticket($ticket_id);
            
            if ($result) {
                error_log('Priority Ticket Payment: Successfully closed ticket ' . $ticket_id . ' using wpas_close_ticket');
                return true;
            } else {
                error_log('Priority Ticket Payment: wpas_close_ticket failed, trying alternative methods');
            }
        }

        // Fallback: Try the admin close action simulation
        if (function_exists('wpas_admin_close_ticket')) {
            $result = wpas_admin_close_ticket($ticket_id);
            if ($result) {
                error_log('Priority Ticket Payment: Successfully closed ticket ' . $ticket_id . ' using wpas_admin_close_ticket');
                return true;
            }
        }

        // Fallback 2: Simulate the exact same process as admin close
        return self::simulate_admin_close_action($ticket_id);
    }

    /**
     * Simulate the admin close action exactly like the close button
     */
    public static function simulate_admin_close_action($ticket_id) {
        // Set the ticket status to closed exactly like Awesome Support does
        
        // First, trigger the before close action
        do_action('wpas_ticket_before_close', $ticket_id);
        
        // Update the post status to closed
        $result = wp_update_post(array(
            'ID' => $ticket_id,
            'post_status' => 'closed'
        ));
        
        if (is_wp_error($result)) {
            error_log('Priority Ticket Payment: Error updating post status: ' . $result->get_error_message());
            return false;
        }
        
        // Set the status meta to closed (using term ID 3 for closed)
        update_post_meta($ticket_id, '_wpas_status', 3);
        
        // Set the taxonomy term for ticket status
        $closed_terms = get_terms(array(
            'taxonomy' => 'ticket_status',
            'slug' => 'closed',
            'hide_empty' => false
        ));
        
        if (!empty($closed_terms) && !is_wp_error($closed_terms)) {
            wp_set_object_terms($ticket_id, array($closed_terms[0]->term_id), 'ticket_status');
            error_log('Priority Ticket Payment: Set closed taxonomy term ID: ' . $closed_terms[0]->term_id);
        }
        
        // Update close time
        update_post_meta($ticket_id, '_wpas_close_time', current_time('mysql'));
        update_post_meta($ticket_id, '_priority_ticket_closed_time', current_time('mysql'));
        
        // Trigger the after close action
        do_action('wpas_ticket_after_close', $ticket_id);
        
        // Clear all caches
        self::clear_ticket_caches($ticket_id);
        
        error_log('Priority Ticket Payment: Successfully simulated admin close for ticket ' . $ticket_id);
        return true;
    }

    /**
     * Properly close ticket using Awesome Support's native functions
     */
    public static function force_close_ticket_properly($ticket_id, $reply_id = null) {
        // Use the new native method
        return self::force_close_with_awesome_support($ticket_id);
    }

    /**
     * Manually close ticket with proper Awesome Support status handling
     */
    private static function manual_close_with_proper_status($ticket_id) {
        // Find the correct closed status term
        $closed_term_id = null;
        
        // Get all ticket status terms
        $status_terms = get_terms(array(
            'taxonomy' => 'ticket_status',
            'hide_empty' => false,
        ));

        if (!is_wp_error($status_terms) && !empty($status_terms)) {
            foreach ($status_terms as $term) {
                // Look for closed status (ID 3 is typically closed in Awesome Support)
                if ($term->term_id == 3 || 
                    $term->slug === 'closed' || 
                    stripos($term->name, 'closed') !== false ||
                    stripos($term->slug, 'close') !== false) {
                    $closed_term_id = $term->term_id;
                    error_log('Priority Ticket Payment: Found closed status term: ' . $term->name . ' (ID: ' . $term->term_id . ')');
                    break;
                }
            }
        }

        if (!$closed_term_id) {
            error_log('Priority Ticket Payment: Could not find closed status term for ticket ' . $ticket_id);
            return false;
        }

        // Update the ticket status taxonomy
        $taxonomy_result = wp_set_object_terms($ticket_id, array($closed_term_id), 'ticket_status');
        if (is_wp_error($taxonomy_result)) {
            error_log('Priority Ticket Payment: Error setting closed taxonomy: ' . $taxonomy_result->get_error_message());
            return false;
        }

        // Update post status
        $post_result = wp_update_post(array(
            'ID' => $ticket_id,
            'post_status' => 'closed'
        ));
        
        if (is_wp_error($post_result)) {
            error_log('Priority Ticket Payment: Error updating post status: ' . $post_result->get_error_message());
        }

        // Update all Awesome Support meta fields
        update_post_meta($ticket_id, '_wpas_status', $closed_term_id);
        update_post_meta($ticket_id, '_wpas_ticket_status', $closed_term_id);
        update_post_meta($ticket_id, '_priority_ticket_force_closed', current_time('mysql'));

        // Trigger Awesome Support hooks
        do_action('wpas_ticket_status_updated', $ticket_id, 'open', 'closed');
        do_action('wpas_close_ticket', $ticket_id);

        error_log('Priority Ticket Payment: Successfully closed ticket ' . $ticket_id . ' with status ID ' . $closed_term_id);
        return true;
    }
    
    /**
     * Send email notification to client when ticket receives a reply
     */
    public static function send_reply_notification_email($reply_id, $ticket_id) {
        if (!self::is_awesome_support_active()) {
            return false;
        }

        // Check if email was already sent for this reply to prevent duplicates
        $already_sent = get_post_meta($reply_id, '_priority_ticket_notification_sent', true);
        if ($already_sent) {
            error_log('Priority Ticket Payment: Email notification already sent for reply ' . $reply_id . ' - skipping duplicate');
            return true;
        }
        
        // Also check for notification attempts in the last 30 seconds to prevent rapid duplicates
        $last_attempt = get_post_meta($reply_id, '_priority_ticket_notification_attempt', true);
        if ($last_attempt && (time() - strtotime($last_attempt)) < 30) {
            error_log('Priority Ticket Payment: Email notification attempted recently for reply ' . $reply_id . ' - preventing duplicate');
            return true;
        }

        // Get ticket information
        $ticket = get_post($ticket_id);
        if (!$ticket) {
            error_log('Priority Ticket Payment: Could not find ticket ' . $ticket_id . ' for reply notification');
            return false;
        }

        // Get ticket author (customer)
        $customer = get_user_by('id', $ticket->post_author);
        if (!$customer || empty($customer->user_email)) {
            error_log('Priority Ticket Payment: No valid customer email found for ticket ' . $ticket_id);
            return false;
        }

        // Get additional customer email from form data if available
        $submission_id = get_post_meta($ticket_id, '_priority_ticket_submission_id', true);
        $customer_email = $customer->user_email;
        $customer_name = $customer->display_name ?: 'Liebe Klientin';

        if ($submission_id) {
            $submission = Priority_Ticket_Payment_Database::get_submission($submission_id);
            if ($submission && is_array($submission['form_data'])) {
                $form_data = $submission['form_data'];
                if (is_string($form_data)) {
                    $form_data = unserialize($form_data);
                }

                // Use contact email from form if available
                if (is_array($form_data) && !empty($form_data['contact_email'])) {
                    $customer_email = $form_data['contact_email'];
                }

                // Get customer name from form data
                if (is_array($form_data) && !empty($form_data['name'])) {
                    $customer_name = $form_data['name'];
                }
            }
        }

        // Create ticket link - try to get Awesome Support ticket URL
        $ticket_link = self::get_ticket_view_url($ticket_id);

        // Email subject in German (customizable via settings)
        $custom_subject = Priority_Ticket_Payment::get_option('reply_notification_subject', '');
        $subject = !empty($custom_subject) ? $custom_subject : 'Antwort auf Ihr Coaching Ticket';

        // Get custom email template or use default
        $custom_template = Priority_Ticket_Payment::get_option('reply_notification_template', '');

        if (!empty($custom_template)) {
            // Use custom template with placeholders
            $html_message = str_replace(
                array('[customer_name]', '[ticket_link]'),
                array(esc_html($customer_name), esc_url($ticket_link)),
                $custom_template
            );
        } else {
            // Use default template
            $html_message = sprintf(
                'Liebe Klientin,<br><br>
Sie haben eine Antwort auf Ihr Coaching Ticket erhalten. Bitte loggen Sie sich
in Ihr Konto auf unserer Website ein. Dort finden Sie alle Informationen oder
folgen Sie dem nachfolgenden Link zu Ihrer Ticket Übersicht.<br><br>
<a href="%s" target="_blank" style="display:inline-block;padding:10px 20px;background:#4A90E2;color:#fff;border-radius:4px;text-decoration:none;">Ticket Übersicht ansehen</a><br><br>
Das Ticket bleibt geöffnet, damit Sie darauf ggf. antworten können, falls es
zu diesem Thema noch eine Nachfrage gibt.<br><br>
Beste Grüße<br><br>
Ihre Coachin von<br>
UMGANG UND SORGERECHT',
                esc_url($ticket_link)
            );
        }

        // Plain text fallback
        $text_message = sprintf(
            'Liebe Klientin,

Sie haben eine Antwort auf Ihr Coaching Ticket erhalten. Bitte loggen Sie sich
in Ihr Konto auf unserer Website ein. Dort finden Sie alle Informationen oder
folgen Sie dem nachfolgenden Link zu Ihrer Ticket Übersicht.

Ticket Übersicht ansehen: %s

Das Ticket bleibt geöffnet, damit Sie darauf ggf. antworten können, falls es
zu diesem Thema noch eine Nachfrage gibt.

Beste Grüße

Ihre Coachin von
UMGANG UND SORGERECHT',
            $ticket_link
        );

        // Enhanced content validation and fallback
        if (empty($html_message) || strlen(trim(strip_tags($html_message))) < 10) {
            $html_message = sprintf(
                'Liebe %s,<br><br>Sie haben eine Antwort auf Ihr Coaching Ticket erhalten.<br><br>Beste Grüße<br>UMGANG UND SORGERECHT',
                esc_html($customer_name)
            );
        }

        if (empty($text_message) || strlen(trim($text_message)) < 10) {
            $text_message = sprintf(
                'Liebe %s,

Sie haben eine Antwort auf Ihr Coaching Ticket erhalten.

Beste Grüße
UMGANG UND SORGERECHT',
                $customer_name
            );
        }

        // Ensure both content types are present and not empty for Post SMTP
        if (empty($html_message) && empty($text_message)) {
            $fallback_content = 'Your ticket has been updated. Please log in to your account to view the response.';
            $html_message = $fallback_content;
            $text_message = $fallback_content;
        } elseif (empty($html_message) && !empty($text_message)) {
            $html_message = nl2br(esc_html($text_message));
        } elseif (!empty($html_message) && empty($text_message)) {
            $text_message = wp_strip_all_tags($html_message);
        }

        // Prepare enhanced email payload for Post SMTP compatibility
        $email_data = array(
            'to' => $customer_email,
            'subject' => $subject,
            'message' => $html_message,
            'htmlContent' => $html_message,
            'textContent' => $text_message,
            'content_type' => 'text/html',
            'charset' => 'UTF-8',
            'from_name' => get_option('blogname'),
            'from_email' => get_option('admin_email'),
            'reply_to' => get_option('admin_email'),
            'ticket_id' => $ticket_id,
            'reply_id' => $reply_id
        );

        // Log email payload for debugging
        error_log('Priority Ticket Payment: Email payload prepared for ticket ' . $ticket_id . ' - To: ' . $customer_email . ', Subject: ' . $subject);
        error_log('Priority Ticket Payment: HTML Content Length: ' . strlen($html_message) . ', Text Content Length: ' . strlen($text_message));

        // Mark as sending attempt to prevent duplicates
        update_post_meta($reply_id, '_priority_ticket_notification_attempt', current_time('mysql'));

        // Debug: Check Postmark detection
        $is_postmark_detected = self::is_postmark_active();
        error_log('Priority Ticket Payment: Postmark detection result: ' . ($is_postmark_detected ? 'YES' : 'NO'));
        
        // Run comprehensive Postmark debug (only once per request)
        static $debug_run = false;
        if (!$debug_run) {
            $debug_run = true;
            self::debug_postmark_config();
        }
        
        // Debug: Log detected email service info
        if (class_exists('PostmarkMail')) {
            error_log('Priority Ticket Payment: PostmarkMail class detected');
        }
        if (class_exists('Postmark\\PostmarkClient')) {
            error_log('Priority Ticket Payment: Postmark\\PostmarkClient class detected');
        }
        if (function_exists('postmark_wp_mail')) {
            error_log('Priority Ticket Payment: postmark_wp_mail function detected');
        }
        if (defined('POSTMARK_API_TOKEN') && POSTMARK_API_TOKEN) {
            error_log('Priority Ticket Payment: POSTMARK_API_TOKEN constant detected');
        }
        if (get_option('postmark_enabled') === 'yes') {
            error_log('Priority Ticket Payment: postmark_enabled option set to yes');
        }
        
        // Debug: Check for other Postmark indicators
        $postmark_options = get_option('postmark_settings', array());
        if (!empty($postmark_options)) {
            error_log('Priority Ticket Payment: postmark_settings found: ' . print_r(array_keys($postmark_options), true));
        }

        // Enhanced email service filter for better compatibility - prevent duplicates
        // Support for PostSMTP, Postmark, and other email services
        
        // Remove any existing filters to prevent duplicates
        remove_all_filters('postman_wp_mail_array');
        remove_all_filters('wp_mail');
        
        // PostSMTP filter (if using PostSMTP)
        add_filter('postman_wp_mail_array', function($mail_array) use ($html_message, $text_message, $reply_id) {
            return self::apply_email_service_filter($mail_array, $html_message, $text_message, $reply_id, 'postsmtp');
        }, 5, 1);
        
        // Generic wp_mail filter (works with Postmark and other services)
        add_filter('wp_mail', function($mail_array) use ($html_message, $text_message, $reply_id) {
            $service_type = self::is_postmark_active() ? 'postmark' : 'wp_mail';
            return self::apply_email_service_filter($mail_array, $html_message, $text_message, $reply_id, $service_type);
        }, 5, 1);
        
        // Additional Postmark-specific filters (if using Postmark plugin)
        if (self::is_postmark_active()) {
            add_filter('postmark_wp_mail', function($mail_array) use ($html_message, $text_message, $reply_id) {
                error_log('Priority Ticket Payment: postmark_wp_mail filter triggered for reply ' . $reply_id);
                return self::apply_email_service_filter($mail_array, $html_message, $text_message, $reply_id, 'postmark');
            }, 5, 1);
        }
        
        // Try additional Postmark filters that might be used by different Postmark plugins
        add_filter('postmark_mail', function($mail_array) use ($html_message, $text_message, $reply_id) {
            error_log('Priority Ticket Payment: postmark_mail filter triggered for reply ' . $reply_id);
            return self::apply_email_service_filter($mail_array, $html_message, $text_message, $reply_id, 'postmark');
        }, 5, 1);
        
        add_filter('wp_mail_postmark', function($mail_array) use ($html_message, $text_message, $reply_id) {
            error_log('Priority Ticket Payment: wp_mail_postmark filter triggered for reply ' . $reply_id);
            return self::apply_email_service_filter($mail_array, $html_message, $text_message, $reply_id, 'postmark');
        }, 5, 1);

        // Force Postmark fields directly into wp_mail if Postmark is detected
        if (self::is_postmark_active()) {
            add_action('phpmailer_init', function($phpmailer) use ($html_message, $text_message, $reply_id) {
                error_log('Priority Ticket Payment: phpmailer_init triggered for Postmark - reply ' . $reply_id);
                
                // Set both HTML and text body for Postmark
                if (method_exists($phpmailer, 'isHTML')) {
                    $phpmailer->isHTML(true);
                }
                $phpmailer->Body = $html_message;
                $phpmailer->AltBody = $text_message;
                
                // Add custom properties for Postmark API
                if (property_exists($phpmailer, 'CustomVars')) {
                    $phpmailer->CustomVars['HtmlBody'] = $html_message;
                    $phpmailer->CustomVars['TextBody'] = $text_message;
                }
                
                error_log('Priority Ticket Payment: Set PHPMailer Body: ' . strlen($html_message) . ' chars, AltBody: ' . strlen($text_message) . ' chars');
            }, 1);
        }
        
        // Set comprehensive headers for wp_mail
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>',
            'Reply-To: ' . get_option('admin_email')
        );

        // Add a very early filter to catch and modify wp_mail arguments for Postmark
        $wp_mail_filter_applied = false;
        add_filter('wp_mail', function($atts) use ($html_message, $text_message, $reply_id, &$wp_mail_filter_applied) {
            if (!$wp_mail_filter_applied && self::is_postmark_active()) {
                $wp_mail_filter_applied = true;
                error_log('Priority Ticket Payment: Early wp_mail filter applied for Postmark - reply ' . $reply_id);
                
                // Ensure message is HTML
                $atts['message'] = $html_message;
                
                // Add all possible Postmark field variations
                $atts['HtmlBody'] = $html_message;
                $atts['TextBody'] = $text_message;
                $atts['html_body'] = $html_message;
                $atts['text_body'] = $text_message;
                $atts['body'] = $html_message;
                $atts['htmlContent'] = $html_message;
                $atts['textContent'] = $text_message;
                
                // Ensure headers include HTML content type
                if (!is_array($atts['headers'])) {
                    $atts['headers'] = array();
                }
                
                $has_content_type = false;
                foreach ($atts['headers'] as $header) {
                    if (stripos($header, 'content-type') !== false) {
                        $has_content_type = true;
                        break;
                    }
                }
                
                if (!$has_content_type) {
                    $atts['headers'][] = 'Content-Type: text/html; charset=UTF-8';
                }
                
                error_log('Priority Ticket Payment: Modified wp_mail args for Postmark: ' . implode(', ', array_keys($atts)));
            }
            return $atts;
        }, 1);

        // Enhanced error handling with multiple sending attempts
        $sent = false;
        $last_error = '';
        
        try {
            // Primary attempt: Send with HTML content
            $sent = wp_mail($customer_email, $subject, $html_message, $headers);
            
            if ($sent) {
                error_log('Priority Ticket Payment: Primary email send successful');
            } else {
                $last_error = 'Primary wp_mail failed';
                error_log('Priority Ticket Payment: Primary email send failed, trying fallback methods');
                
                // Fallback 1: Try with minimal headers
                $minimal_headers = array('Content-Type: text/html; charset=UTF-8');
                $sent = wp_mail($customer_email, $subject, $html_message, $minimal_headers);
                
                if ($sent) {
                    error_log('Priority Ticket Payment: Minimal headers method successful');
                } else {
                    $last_error = 'Minimal headers method failed';
                    
                    // Fallback 2: Try plain text only
                    $text_headers = array('Content-Type: text/plain; charset=UTF-8');
                    $sent = wp_mail($customer_email, $subject, $text_message, $text_headers);
                    
                    if ($sent) {
                        error_log('Priority Ticket Payment: Plain text fallback successful');
                    } else {
                        $last_error = 'Plain text fallback failed';
                        error_log('Priority Ticket Payment: All primary methods failed, trying alternative sending');
                    }
                }
            }
            
        } catch (Exception $e) {
            $last_error = 'Email sending exception: ' . $e->getMessage();
            error_log('Priority Ticket Payment: ' . $last_error);
            $sent = false;
        }

        // Remove the filters after use to prevent interference with other emails
        remove_all_filters('postman_wp_mail_array');
        remove_all_filters('wp_mail');
        remove_all_filters('postmark_wp_mail');
        remove_all_filters('postmark_mail');
        remove_all_filters('wp_mail_postmark');
        remove_all_actions('phpmailer_init');

        // Log results and update meta
        if ($sent) {
            error_log('Priority Ticket Payment: Reply notification email sent successfully to ' . $customer_email . ' for ticket ' . $ticket_id);
            
            // Log successful sending
            update_post_meta($reply_id, '_priority_ticket_notification_sent', current_time('mysql'));
            update_post_meta($reply_id, '_priority_ticket_notification_email', $customer_email);
            update_post_meta($reply_id, '_priority_ticket_notification_method', 'wp_mail');
            
            // Trigger action for Post SMTP or other plugins
            do_action('priority_ticket_payment_email_sent', $email_data, $reply_id, $ticket_id);
            
        } else {
            error_log('Priority Ticket Payment: Failed to send reply notification email to ' . $customer_email . ' for ticket ' . $ticket_id . '. Last error: ' . $last_error);
            
            // Log failure details
            update_post_meta($reply_id, '_priority_ticket_notification_failed', current_time('mysql'));
            update_post_meta($reply_id, '_priority_ticket_notification_error', $last_error);
            
            // Try alternative sending method if available
            $sent = self::try_alternative_email_sending($email_data, $reply_id, $ticket_id);
            
            // If all methods fail, still save the ticket but log the issue
            if (!$sent) {
                error_log('Priority Ticket Payment: All email sending methods failed for ticket ' . $ticket_id . '. Ticket will remain saved.');
                // Notify admin about email failure
                self::notify_admin_of_email_failure($ticket_id, $reply_id, $customer_email, $last_error);
            }
        }

                return $sent;
    }

    /**
     * Apply email service filter for multiple email providers
     */
    public static function apply_email_service_filter($mail_array, $html_message, $text_message, $reply_id, $service_type) {
        // Prevent duplicate processing by checking if we've already processed this email
        static $processed_emails = array();
        
        // Create unique key for this email/service combination
        $email_key = md5($mail_array['to'] . $mail_array['subject'] . $reply_id . $service_type);
        
        if (isset($processed_emails[$email_key])) {
            error_log('Priority Ticket Payment: Duplicate email detected for reply ' . $reply_id . ' (' . $service_type . ') - skipping filter');
            return $mail_array;
        }
        
        $processed_emails[$email_key] = true;
        
        // Ensure content is available and not empty
        if (empty($html_message) && empty($text_message)) {
            $fallback_content = 'Your ticket has been updated. Please log in to your account to view the response.';
            $html_message = $fallback_content;
            $text_message = $fallback_content;
        } elseif (empty($html_message) && !empty($text_message)) {
            $html_message = nl2br(esc_html($text_message));
        } elseif (!empty($html_message) && empty($text_message)) {
            $text_message = wp_strip_all_tags($html_message);
        }
        
        // Apply service-specific formatting
        switch ($service_type) {
            case 'postsmtp':
                // PostSMTP specific fields
                $mail_array['message'] = $html_message;
                $mail_array['htmlContent'] = $html_message;
                $mail_array['textContent'] = $text_message;
                $mail_array['content_type'] = 'text/html';
                $mail_array['charset'] = 'UTF-8';
                
                // Additional fields for PostSMTP API compatibility
                if (!isset($mail_array['alt_body']) || empty($mail_array['alt_body'])) {
                    $mail_array['alt_body'] = $text_message;
                }
                
                error_log('Priority Ticket Payment: Applied PostSMTP filter for reply ' . $reply_id . ' - HTML: ' . strlen($html_message) . ' chars, Text: ' . strlen($text_message) . ' chars');
                break;
                
                         case 'wp_mail':
             case 'postmark':
                // Generic wp_mail format (works with Postmark, WP Mail SMTP, etc.)
                $mail_array['message'] = $html_message;
                
                // Postmark specific fields (if detected or explicitly set)
                if ($service_type === 'postmark' || self::is_postmark_active()) {
                    // Try multiple Postmark field name variations
                    $mail_array['HtmlBody'] = $html_message;
                    $mail_array['TextBody'] = $text_message;
                    $mail_array['html_body'] = $html_message;  // Some plugins use lowercase
                    $mail_array['text_body'] = $text_message;  // Some plugins use lowercase
                    $mail_array['body'] = $html_message;       // Fallback
                    $mail_array['htmlContent'] = $html_message; // Alternative
                    $mail_array['textContent'] = $text_message; // Alternative
                    
                    // Ensure message content is set
                    if (empty($mail_array['message'])) {
                        $mail_array['message'] = $html_message;
                    }
                    
                    error_log('Priority Ticket Payment: Applied Postmark-compatible filter for reply ' . $reply_id . ' - HtmlBody: ' . strlen($html_message) . ' chars, TextBody: ' . strlen($text_message) . ' chars');
                    error_log('Priority Ticket Payment: Postmark email array keys: ' . implode(', ', array_keys($mail_array)));
                } else {
                    // Generic email service
                    $mail_array['html_body'] = $html_message;
                    $mail_array['text_body'] = $text_message;
                }
                
                // Standard wp_mail fields
                if (!isset($mail_array['headers']) || !is_array($mail_array['headers'])) {
                    $mail_array['headers'] = array();
                }
                
                // Ensure content-type header is set
                $has_content_type = false;
                foreach ($mail_array['headers'] as $header) {
                    if (stripos($header, 'content-type') !== false) {
                        $has_content_type = true;
                        break;
                    }
                }
                
                if (!$has_content_type) {
                    $mail_array['headers'][] = 'Content-Type: text/html; charset=UTF-8';
                }
                
                error_log('Priority Ticket Payment: Applied wp_mail filter for reply ' . $reply_id . ' - HTML: ' . strlen($html_message) . ' chars, Text: ' . strlen($text_message) . ' chars');
                break;
        }
        
        return $mail_array;
    }

    /**
     * Check if Postmark is active
     */
    public static function is_postmark_active() {
        // Check for common Postmark plugin indicators
        $checks = array(
            'PostmarkMail class' => class_exists('PostmarkMail'),
            'Postmark\\PostmarkClient class' => class_exists('Postmark\\PostmarkClient'),
            'postmark_wp_mail function' => function_exists('postmark_wp_mail'),
            'POSTMARK_API_TOKEN constant' => (defined('POSTMARK_API_TOKEN') && POSTMARK_API_TOKEN),
            'postmark_enabled option' => (get_option('postmark_enabled') === 'yes'),
            'postmark_settings option' => !empty(get_option('postmark_settings')),
            'Postmark plugin class' => class_exists('Postmark'),
            'PostmarkWP class' => class_exists('PostmarkWP'),
            'wp_mail_postmark function' => function_exists('wp_mail_postmark'),
        );
        
        $is_active = false;
        foreach ($checks as $check_name => $result) {
            if ($result) {
                error_log('Priority Ticket Payment: Postmark detection - ' . $check_name . ': TRUE');
                $is_active = true;
            }
        }
        
        return $is_active;
    }

    /**
     * Debug Postmark configuration - call this to see what's detected
     */
    public static function debug_postmark_config() {
        error_log('=== POSTMARK DEBUG START ===');
        
        // Check all possible Postmark indicators
        $checks = array(
            'PostmarkMail class' => class_exists('PostmarkMail'),
            'Postmark\\PostmarkClient class' => class_exists('Postmark\\PostmarkClient'),
            'postmark_wp_mail function' => function_exists('postmark_wp_mail'),
            'POSTMARK_API_TOKEN constant' => (defined('POSTMARK_API_TOKEN') && POSTMARK_API_TOKEN),
            'postmark_enabled option' => (get_option('postmark_enabled') === 'yes'),
            'postmark_settings option' => !empty(get_option('postmark_settings')),
            'Postmark plugin class' => class_exists('Postmark'),
            'PostmarkWP class' => class_exists('PostmarkWP'),
            'wp_mail_postmark function' => function_exists('wp_mail_postmark'),
        );
        
        foreach ($checks as $check_name => $result) {
            error_log('Postmark Check - ' . $check_name . ': ' . ($result ? 'TRUE' : 'FALSE'));
        }
        
        // Check WordPress mail settings
        error_log('WordPress admin email: ' . get_option('admin_email'));
        error_log('WordPress blogname: ' . get_option('blogname'));
        
        // Check for common Postmark plugin options
        $postmark_options = get_option('postmark_settings', array());
        if (!empty($postmark_options)) {
            error_log('Postmark settings keys: ' . implode(', ', array_keys($postmark_options)));
        }
        
        // Check active plugins
        $active_plugins = get_option('active_plugins', array());
        $postmark_plugins = array_filter($active_plugins, function($plugin) {
            return stripos($plugin, 'postmark') !== false;
        });
        
        if (!empty($postmark_plugins)) {
            error_log('Active Postmark plugins: ' . implode(', ', $postmark_plugins));
        } else {
            error_log('No Postmark plugins found in active plugins list');
        }
        
        // Check for wp_mail overrides
        if (function_exists('wp_mail')) {
            $reflection = new ReflectionFunction('wp_mail');
            error_log('wp_mail function file: ' . $reflection->getFileName());
        }
        
        error_log('=== POSTMARK DEBUG END ===');
    }
    
    /**
     * Notify admin when email sending fails
     */
    private static function notify_admin_of_email_failure($ticket_id, $reply_id, $customer_email, $error) {
        $admin_email = get_option('admin_email');
        if (!$admin_email || $admin_email === $customer_email) {
            return; // Don't send admin notification if no admin email or same as customer
        }
        
        $subject = 'Email Notification Failed - Ticket #' . $ticket_id;
        $message = sprintf(
            'An email notification failed to send for ticket #%d.

Customer Email: %s
Reply ID: %d
Error: %s

The ticket has been saved successfully, but the customer was not notified via email.
Please manually contact the customer if needed.

Ticket URL: %s',
            $ticket_id,
            $customer_email,
            $reply_id,
            $error,
            admin_url('post.php?post=' . $ticket_id . '&action=edit')
        );
        
        // Use simple headers for admin notification
        $headers = array('Content-Type: text/plain; charset=UTF-8');
        wp_mail($admin_email, $subject, $message, $headers);
        
        error_log('Priority Ticket Payment: Sent email failure notification to admin for ticket ' . $ticket_id);
    }
    
    /**
     * Try alternative email sending methods if wp_mail fails
     */
    private static function try_alternative_email_sending($email_data, $reply_id, $ticket_id) {
        error_log('Priority Ticket Payment: Attempting alternative email sending methods');
        
        // Ensure we have valid content for alternative sending
        $html_content = !empty($email_data['htmlContent']) ? $email_data['htmlContent'] : (!empty($email_data['textContent']) ? $email_data['textContent'] : '');
        $text_content = !empty($email_data['textContent']) ? $email_data['textContent'] : (!empty($email_data['htmlContent']) ? wp_strip_all_tags($email_data['htmlContent']) : '');
        
        // Fallback content if both are empty
        if (empty($html_content) && empty($text_content)) {
            $fallback_content = 'Your ticket has been updated. Please log in to your account to view the response.';
            $html_content = $fallback_content;
            $text_content = $fallback_content;
        } elseif (empty($html_content) && !empty($text_content)) {
            $html_content = nl2br(esc_html($text_content));
        } elseif (!empty($html_content) && empty($text_content)) {
            $text_content = wp_strip_all_tags($html_content);
        }
        
        // Method 1: Try with email service specific filters and enhanced content validation
        if (class_exists('PostmanWpMail') || function_exists('postman_wp_mail') || self::is_postmark_active()) {
            try {
                // Clear any existing filters first to prevent duplicates
                remove_all_filters('postman_wp_mail_array');
                remove_all_filters('wp_mail');
                
                // Determine email service type
                $service_type = 'unknown';
                if (class_exists('PostmanWpMail') || function_exists('postman_wp_mail')) {
                    $service_type = 'postsmtp';
                } elseif (self::is_postmark_active()) {
                    $service_type = 'postmark';
                }
                
                // Enhanced email service filter with comprehensive content validation
                $filter_function = function($mail_array) use ($html_content, $text_content, $reply_id, $service_type) {
                    // Prevent duplicate processing
                    static $processed_alternative_emails = array();
                    $email_key = md5($mail_array['to'] . $mail_array['subject'] . $reply_id . 'alternative_' . $service_type);
                    
                    if (isset($processed_alternative_emails[$email_key])) {
                        error_log('Priority Ticket Payment: Duplicate alternative email detected for reply ' . $reply_id . ' (' . $service_type . ') - skipping');
                        return $mail_array;
                    }
                    
                    $processed_alternative_emails[$email_key] = true;
                    
                    // Use the unified filter method
                    return self::apply_email_service_filter($mail_array, $html_content, $text_content, $reply_id, $service_type);
                };
                
                // Apply filters based on service type
                if ($service_type === 'postsmtp') {
                    add_filter('postman_wp_mail_array', $filter_function, 5, 1);
                } else {
                    add_filter('wp_mail', $filter_function, 5, 1);
                }
                
                // Try sending with explicit content-type and enhanced headers
                $alt_headers = array(
                    'Content-Type: text/html; charset=UTF-8',
                    'MIME-Version: 1.0',
                    'From: ' . $email_data['from_name'] . ' <' . $email_data['from_email'] . '>',
                    'Reply-To: ' . $email_data['reply_to']
                );
                
                $sent = wp_mail($email_data['to'], $email_data['subject'], $html_content, $alt_headers);
                
                // Remove filters after use to prevent interference
                remove_all_filters('postman_wp_mail_array');
                remove_all_filters('wp_mail');
                remove_all_filters('postmark_wp_mail');
                
                if ($sent) {
                    error_log('Priority Ticket Payment: Post SMTP alternative method successful');
                    update_post_meta($reply_id, '_priority_ticket_notification_sent', current_time('mysql'));
                    update_post_meta($reply_id, '_priority_ticket_notification_method', 'post_smtp_alternative');
                    return true;
                }
                
            } catch (Exception $e) {
                error_log('Priority Ticket Payment: Post SMTP alternative method failed: ' . $e->getMessage());
                // Remove filter in case of error
                remove_all_filters('postman_wp_mail_array');
            }
        }
        
        // Method 2: Try plain text only
        try {
            $text_headers = array(
                'Content-Type: text/plain; charset=UTF-8',
                'From: ' . $email_data['from_name'] . ' <' . $email_data['from_email'] . '>',
                'Reply-To: ' . $email_data['reply_to']
            );
            
            $sent = wp_mail($email_data['to'], $email_data['subject'], $text_content, $text_headers);
            
            if ($sent) {
                error_log('Priority Ticket Payment: Plain text fallback successful');
                update_post_meta($reply_id, '_priority_ticket_notification_sent', current_time('mysql'));
                update_post_meta($reply_id, '_priority_ticket_notification_method', 'plain_text_fallback');
                return true;
            }
            
        } catch (Exception $e) {
            error_log('Priority Ticket Payment: Plain text fallback failed: ' . $e->getMessage());
        }
        
        // Method 3: Try minimal content
        try {
            $minimal_content = 'Your ticket has been updated. Please log in to your account to view the response.';
            $minimal_headers = array(
                'Content-Type: text/plain; charset=UTF-8',
                'From: ' . get_option('admin_email')
            );
            
            $sent = wp_mail($email_data['to'], 'Ticket Update', $minimal_content, $minimal_headers);
            
            if ($sent) {
                error_log('Priority Ticket Payment: Minimal content method successful');
                update_post_meta($reply_id, '_priority_ticket_notification_sent', current_time('mysql'));
                update_post_meta($reply_id, '_priority_ticket_notification_method', 'minimal_fallback');
                return true;
            }
            
        } catch (Exception $e) {
            error_log('Priority Ticket Payment: Minimal content method failed: ' . $e->getMessage());
        }
        
        error_log('Priority Ticket Payment: All alternative email sending methods failed');
        return false;
    }
    
    /**
     * Enable Post SMTP logging for debugging
     */
    public static function enable_post_smtp_logging() {
        // Enable Post SMTP debug logging if plugin is active
        if (class_exists('PostmanOptions') || function_exists('postman_get_options')) {
            add_filter('postman_wp_mail_result', array(__CLASS__, 'log_post_smtp_result'), 10, 2);
            add_action('postman_wp_mail_failed', array(__CLASS__, 'log_post_smtp_failure'), 10, 3);
            add_action('postman_wp_mail_success', array(__CLASS__, 'log_post_smtp_success'), 10, 2);
            
            error_log('Priority Ticket Payment: Post SMTP logging hooks enabled');
        }
        
        // Enable WordPress mail debug logging
        add_action('wp_mail_failed', array(__CLASS__, 'log_wp_mail_failure'), 10, 1);
        add_filter('wp_mail', array(__CLASS__, 'log_wp_mail_attempt'), 10, 1);
        
        error_log('Priority Ticket Payment: WordPress mail logging hooks enabled');
    }
    
    /**
     * Initialize Reply & Close handling with proper hook timing
     */
    public static function init_reply_close_handling() {
        // Hook into Awesome Support's reply processing to detect close actions
        add_action('wpas_insert_reply', array(__CLASS__, 'handle_reply_insertion'), 5, 2);
        add_action('wpas_after_reply_added', array(__CLASS__, 'handle_reply_close_action'), 5, 2);
        
        // Also hook into the admin close action specifically
        add_action('wpas_ticket_before_close', array(__CLASS__, 'handle_admin_close_action'), 10, 1);
        

    }
    
    /**
     * Handle reply insertion to detect close actions
     */
    public static function handle_reply_insertion($reply_id, $data) {
        if (!isset($data['post_parent'])) {
            return;
        }
        
        $ticket_id = $data['post_parent'];
        
        // Check if this is a priority ticket
        $is_priority_ticket = get_post_meta($ticket_id, '_priority_ticket_submission_id', true);
        if (!$is_priority_ticket) {
            return;
        }
        
        // Check if this is a close action
        $close_ticket = isset($_POST['wpas_reply_close']) || 
                       (isset($_POST['wpas_do']) && $_POST['wpas_do'] === 'reply_close') ||
                       get_post_meta($reply_id, '_wpas_close_ticket', true) === 'yes';
        
        if ($close_ticket) {
            // Mark this reply for closing
            update_post_meta($reply_id, '_priority_ticket_should_close', '1');
            update_post_meta($ticket_id, '_priority_ticket_pending_close_reply', $reply_id);
            error_log('Priority Ticket Payment: Marked reply ' . $reply_id . ' for closing ticket ' . $ticket_id);
        }
    }
    
    /**
     * Handle the close action after reply is added
     */
    public static function handle_reply_close_action($reply_id, $ticket_id) {
        // Check if this reply should close the ticket
        $should_close = get_post_meta($reply_id, '_priority_ticket_should_close', true) === '1';
        $pending_close_reply = get_post_meta($ticket_id, '_priority_ticket_pending_close_reply', true);
        
        if ($should_close || $pending_close_reply == $reply_id) {
            // Clean up meta
            delete_post_meta($reply_id, '_priority_ticket_should_close');
            delete_post_meta($ticket_id, '_priority_ticket_pending_close_reply');
            
            // Force close the ticket using Awesome Support's native method
            self::force_close_with_awesome_support($ticket_id);
            
            error_log('Priority Ticket Payment: Executed close action for ticket ' . $ticket_id . ' after reply ' . $reply_id);
        }
    }
    
    /**
     * Handle admin close action
     */
    public static function handle_admin_close_action($ticket_id) {
        $is_priority_ticket = get_post_meta($ticket_id, '_priority_ticket_submission_id', true);
        if ($is_priority_ticket) {
            error_log('Priority Ticket Payment: Admin close action detected for priority ticket ' . $ticket_id);
        }
    }
    
    /**
     * Log Post SMTP email results
     */
    public static function log_post_smtp_result($result, $log) {
        if (isset($log['original_subject']) && strpos($log['original_subject'], 'Coaching-Ticket') !== false) {
            error_log('Priority Ticket Payment: Post SMTP Result - ' . ($result ? 'SUCCESS' : 'FAILED') . ' for subject: ' . $log['original_subject']);
            
            if (isset($log['original_to'])) {
                error_log('Priority Ticket Payment: Post SMTP To: ' . $log['original_to']);
            }
            
            if (!$result && isset($log['session_transcript'])) {
                error_log('Priority Ticket Payment: Post SMTP Error Transcript: ' . substr($log['session_transcript'], 0, 500));
            }
        }
        
        return $result;
    }
    
    /**
     * Log Post SMTP failures
     */
    public static function log_post_smtp_failure($error, $email, $transcript) {
        if (isset($email['subject']) && strpos($email['subject'], 'Coaching-Ticket') !== false) {
            error_log('Priority Ticket Payment: Post SMTP Failure - Error: ' . $error);
            error_log('Priority Ticket Payment: Post SMTP Email: ' . print_r($email, true));
            
            if ($transcript) {
                error_log('Priority Ticket Payment: Post SMTP Transcript: ' . substr($transcript, 0, 1000));
            }
        }
    }
    
    /**
     * Log Post SMTP success
     */
    public static function log_post_smtp_success($email, $transcript) {
        if (isset($email['subject']) && strpos($email['subject'], 'Coaching-Ticket') !== false) {
            error_log('Priority Ticket Payment: Post SMTP Success for subject: ' . $email['subject']);
            
            if (isset($email['to'])) {
                error_log('Priority Ticket Payment: Post SMTP Delivered to: ' . (is_array($email['to']) ? implode(', ', $email['to']) : $email['to']));
            }
        }
    }
    
    /**
     * Log WordPress mail failures
     */
    public static function log_wp_mail_failure($wp_error) {
        if (strpos($wp_error->get_error_message(), 'Coaching-Ticket') !== false || 
            strpos(print_r($wp_error->get_error_data(), true), 'Coaching-Ticket') !== false) {
            
            error_log('Priority Ticket Payment: WP Mail Failed - Error: ' . $wp_error->get_error_message());
            error_log('Priority Ticket Payment: WP Mail Error Data: ' . print_r($wp_error->get_error_data(), true));
        }
    }
    
    /**
     * Log WordPress mail attempts
     */
    public static function log_wp_mail_attempt($args) {
        if (isset($args['subject']) && strpos($args['subject'], 'Coaching-Ticket') !== false) {
            error_log('Priority Ticket Payment: WP Mail Attempt - To: ' . (is_array($args['to']) ? implode(', ', $args['to']) : $args['to']));
            error_log('Priority Ticket Payment: WP Mail Subject: ' . $args['subject']);
            error_log('Priority Ticket Payment: WP Mail Headers: ' . print_r($args['headers'], true));
            error_log('Priority Ticket Payment: WP Mail Message Length: ' . strlen($args['message']));
            
            // Log first 200 characters of message for debugging
            error_log('Priority Ticket Payment: WP Mail Message Preview: ' . substr(strip_tags($args['message']), 0, 200) . '...');
        }
        
        return $args;
    }
    
    /**
     * Send admin notification email when new ticket is created
     */
    public static function send_admin_notification_email($ticket_id, $submission, $form_data) {
        // Get admin email from settings or fallback to WordPress admin email
        $admin_email = Priority_Ticket_Payment::get_option('priority_ticket_admin_email', '');
        
        if (empty($admin_email) || !is_email($admin_email)) {
            $admin_email = get_option('admin_email');
        }
        
        // Validate admin email
        if (empty($admin_email) || !is_email($admin_email)) {
            error_log('Priority Ticket Payment: Invalid admin email for notification - skipping admin notification');
            return false;
        }
        
        // Ensure form_data is an array
        if (!is_array($form_data)) {
            if (is_string($form_data)) {
                $form_data = unserialize($form_data);
            }
            if (!is_array($form_data)) {
                $form_data = array();
            }
        }
        
        // Get customer name for email subject
        $customer_name = 'Unbekannter Kunde';
        $name_fields = array('name', 'full_name', 'customer_name');
        foreach ($name_fields as $field) {
            if (!empty($form_data[$field]) && trim($form_data[$field]) !== '') {
                $customer_name = trim($form_data[$field]);
                break;
            }
        }
        
        // Build email subject
        $subject = '[Neue Ticket-Anfrage] ' . $customer_name;
        
        // Build ticket content using the same formatter as for tickets
        $ticket_content = self::build_ticket_content($form_data, null, $submission['attachments'] ?? array());
        
        // Add ticket metadata to email
        $email_content = array();
        $email_content[] = '<h3>Neue Priority Ticket Anfrage</h3>';
        $email_content[] = '<p><strong>Ticket ID:</strong> #' . $ticket_id . '</p>';
        $email_content[] = '<p><strong>Submission ID:</strong> #' . $submission['id'] . '</p>';
        $email_content[] = '<p><strong>Preis:</strong> €' . number_format($submission['price'], 2) . '</p>';
        $email_content[] = '<p><strong>Erstellt:</strong> ' . $submission['created_at'] . '</p>';
        $email_content[] = '<hr>';
        $email_content[] = '<h4>Ticket Details:</h4>';
        
        // Convert plain text ticket content to HTML
        $html_content = nl2br(esc_html($ticket_content));
        $email_content[] = '<div style="background:#f9f9f9;padding:15px;border-left:4px solid #4A90E2;margin:10px 0;">';
        $email_content[] = $html_content;
        $email_content[] = '</div>';
        
        // Add admin link
        if (function_exists('admin_url')) {
            $ticket_edit_url = admin_url('post.php?post=' . $ticket_id . '&action=edit');
            $email_content[] = '<p><a href="' . esc_url($ticket_edit_url) . '" style="display:inline-block;padding:10px 20px;background:#4A90E2;color:#fff;border-radius:4px;text-decoration:none;">Ticket im Admin bearbeiten</a></p>';
        }
        
        $email_message = implode("\n", $email_content);
        
        // Email headers
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>',
            'Reply-To: ' . get_option('admin_email')
        );
        
        // Send email
        $sent = wp_mail($admin_email, $subject, $email_message, $headers);
        
        if ($sent) {
            error_log('Priority Ticket Payment: Admin notification email sent successfully to ' . $admin_email . ' for ticket ' . $ticket_id);
            
            // Log the notification
            update_post_meta($ticket_id, '_priority_ticket_admin_notification_sent', current_time('mysql'));
            update_post_meta($ticket_id, '_priority_ticket_admin_notification_email', $admin_email);
        } else {
            error_log('Priority Ticket Payment: Failed to send admin notification email to ' . $admin_email . ' for ticket ' . $ticket_id);
        }
        
        return $sent;
    }
    
    /**
     * Get ticket view URL for customer access
     */
    public static function get_ticket_view_url($ticket_id) {
        // Try to get Awesome Support ticket view URL
        if (function_exists('wpas_get_tickets_list_page_url')) {
            $tickets_page_url = wpas_get_tickets_list_page_url();
            if ($tickets_page_url) {
                return add_query_arg('ticket_id', $ticket_id, $tickets_page_url);
            }
        }
        
        // Fallback 1: Try to find Awesome Support pages
        $as_pages = get_option('wpas_options', array());
        if (isset($as_pages['ticket_list'])) {
            $page_id = $as_pages['ticket_list'];
            $page_url = get_permalink($page_id);
            if ($page_url) {
                return add_query_arg('ticket_id', $ticket_id, $page_url);
            }
        }
        
        // Fallback 2: Look for pages with Awesome Support shortcodes
        $pages = get_pages();
        foreach ($pages as $page) {
            $content = get_post_field('post_content', $page->ID);
            if (strpos($content, '[tickets]') !== false || strpos($content, '[ticket_list]') !== false) {
                return add_query_arg('ticket_id', $ticket_id, get_permalink($page->ID));
            }
        }
        
        // Fallback 3: Direct post link (may not work depending on AS configuration)
        $direct_url = get_permalink($ticket_id);
        if ($direct_url) {
            return $direct_url;
        }
        
        // Final fallback: Account/login page
        if (function_exists('wc_get_account_endpoint_url')) {
            return wc_get_account_endpoint_url('orders');
        }
        
        // Last resort: Site home page with notice
        return add_query_arg('ticket_notification', $ticket_id, home_url());
    }
    
    /**
     * AJAX handler for Reply & Close functionality
     */
    public static function ajax_reply_and_close() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'wpas_reply_ticket')) {
            wp_send_json_error(__('Security check failed', 'priority-ticket-payment'));
        }
        
        // Check permissions
        if (!current_user_can('edit_ticket')) {
            wp_send_json_error(__('Insufficient permissions', 'priority-ticket-payment'));
        }
        
        $ticket_id = intval($_POST['ticket_id']);
        $reply_content = sanitize_textarea_field($_POST['reply_content']);
        
        if (!$ticket_id || empty($reply_content)) {
            wp_send_json_error(__('Missing required data', 'priority-ticket-payment'));
        }
        
        // Check if this is a priority ticket
        $is_priority_ticket = get_post_meta($ticket_id, '_priority_ticket_submission_id', true);
        if (!$is_priority_ticket) {
            wp_send_json_error(__('Not a priority ticket', 'priority-ticket-payment'));
        }
        
        // Create the reply
        $reply_data = array(
            'post_title' => 'Reply',
            'post_content' => $reply_content,
            'post_status' => 'read',
            'post_type' => 'ticket_reply',
            'post_parent' => $ticket_id,
            'post_author' => get_current_user_id(),
        );
        
        $reply_id = wp_insert_post($reply_data);
        
        if (is_wp_error($reply_id)) {
            wp_send_json_error(__('Failed to create reply', 'priority-ticket-payment'));
        }
        
        // Mark this reply as a close action
        update_post_meta($reply_id, '_wpas_close_ticket', 'yes');
        
        // Clear caches for immediate visibility
        self::clear_ticket_caches($ticket_id, $reply_id);
        
        // Close the ticket using proper method
        $close_result = self::force_close_ticket_properly($ticket_id, $reply_id);
        
        if ($close_result) {
            // Force refresh display
            self::refresh_ticket_display($ticket_id);
            
            wp_send_json_success(array(
                'message' => __('Reply added and ticket closed successfully', 'priority-ticket-payment'),
                'reply_id' => $reply_id,
                'ticket_id' => $ticket_id,
                'refresh_required' => true,
                'ticket_url' => get_permalink($ticket_id)
            ));
        } else {
            wp_send_json_error(__('Reply added but failed to close ticket', 'priority-ticket-payment'));
        }
    }
} 