<?php
/**
 * Payment handling for Priority Ticket Payment
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Priority_Ticket_Payment_Payment_Handler {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
    }
    
    /**
     * Initialize payment handling
     */
    public function init() {
        // Only proceed if WooCommerce integration is enabled
        if (Priority_Ticket_Payment::get_option('enable_woocommerce_integration', 'no') !== 'yes') {
            return;
        }
        
        // Hook into WooCommerce thank you page with high priority
        add_action('woocommerce_thankyou', array($this, 'handle_thank_you_redirect'), 1, 1);
        
        // Also hook into template redirect for better coverage
        add_action('template_redirect', array($this, 'handle_template_redirect'), 1);
        
        // Add JavaScript-based redirect as fallback
        add_action('woocommerce_thankyou', array($this, 'add_javascript_redirect_fallback'), 999, 1);
        
        // Add additional hooks for payment processing
        add_action('woocommerce_order_status_completed', array($this, 'handle_order_completion'), 20, 1);
        add_action('woocommerce_order_status_processing', array($this, 'handle_order_processing'), 20, 1);
        
        error_log('Priority Ticket Payment: Payment handler initialized');
    }
    
    /**
     * Handle custom thank you page redirect
     */
    public function handle_thank_you_redirect($order_id) {
        if (!$order_id) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('Priority Ticket Payment: Invalid order ID in thank you redirect: ' . $order_id);
            return;
        }
        
        // Check if we've already redirected this order (prevent loops)
        if (get_transient('priority_ticket_redirected_' . $order_id)) {
            error_log('Priority Ticket Payment: Order ' . $order_id . ' already redirected, skipping');
            return;
        }
        
        // Check if this order contains priority ticket products
        $has_priority_ticket = $this->order_contains_priority_ticket($order);
        
        if (!$has_priority_ticket) {
            error_log('Priority Ticket Payment: Order ' . $order_id . ' does not contain priority ticket products');
            return;
        }
        
        // Get custom thank you page URL from settings
        $custom_thank_you_url = Priority_Ticket_Payment::get_option('custom_thank_you_page_url', '');
        
        if (empty($custom_thank_you_url)) {
            error_log('Priority Ticket Payment: No custom thank you URL configured for order ' . $order_id);
            return; // Fall back to default WooCommerce thank you page
        }
        
        // Validate URL
        $validated_url = $this->validate_and_sanitize_url($custom_thank_you_url);
        
        if (!$validated_url) {
            error_log('Priority Ticket Payment: Invalid custom thank you URL: ' . $custom_thank_you_url);
            return; // Fall back to default
        }
        
        // Mark this order as redirected to prevent loops
        set_transient('priority_ticket_redirected_' . $order_id, true, 3600); // 1 hour
        
        // Build redirect URL with order information
        $redirect_url = $this->build_thank_you_redirect_url($validated_url, $order);
        
        error_log('Priority Ticket Payment: Redirecting order ' . $order_id . ' to custom thank you page: ' . $redirect_url);
        
        // Output a script to prevent JavaScript fallback
        echo '<script>window.priorityTicketRedirected = true;</script>';
        
        // Perform the redirect
        wp_safe_redirect($redirect_url, 302);
        exit;
    }
    
    /**
     * Handle template redirect for better coverage
     */
    public function handle_template_redirect() {
        // Only process on WooCommerce order received page
        if (!is_wc_endpoint_url('order-received')) {
            return;
        }
        
        global $wp;
        
        // Get order ID from URL
        $order_id = isset($wp->query_vars['order-received']) ? absint($wp->query_vars['order-received']) : 0;
        
        if (!$order_id) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Check if we've already redirected this order (prevent loops)
        if (get_transient('priority_ticket_redirected_' . $order_id)) {
            return;
        }
        
        // Check if this order contains priority ticket products
        $has_priority_ticket = $this->order_contains_priority_ticket($order);
        
        if (!$has_priority_ticket) {
            return;
        }
        
        // Get custom thank you page URL from settings
        $custom_thank_you_url = Priority_Ticket_Payment::get_option('custom_thank_you_page_url', '');
        
        if (empty($custom_thank_you_url)) {
            error_log('Priority Ticket Payment: No custom thank you URL configured for template redirect of order ' . $order_id);
            return;
        }
        
        // Validate URL
        $validated_url = $this->validate_and_sanitize_url($custom_thank_you_url);
        
        if (!$validated_url) {
            error_log('Priority Ticket Payment: Invalid custom thank you URL for template redirect: ' . $custom_thank_you_url);
            return;
        }
        
        // Mark this order as redirected to prevent loops
        set_transient('priority_ticket_redirected_' . $order_id, true, 3600); // 1 hour
        
        // Build redirect URL with order information
        $redirect_url = $this->build_thank_you_redirect_url($validated_url, $order);
        
        error_log('Priority Ticket Payment: Template redirecting order ' . $order_id . ' to custom thank you page: ' . $redirect_url);
        
        // Output a script to prevent JavaScript fallback
        echo '<script>window.priorityTicketRedirected = true;</script>';
        
        // Perform the redirect
        wp_safe_redirect($redirect_url, 302);
        exit;
    }
    
    /**
     * Check if order contains priority ticket products
     */
    private function order_contains_priority_ticket($order) {
        $items = $order->get_items();
        
        foreach ($items as $item) {
            $product_id = $item->get_product_id();
            $is_priority_ticket = get_post_meta($product_id, '_priority_ticket_product', true);
            
            if ($is_priority_ticket === 'yes') {
                error_log('Priority Ticket Payment: Found priority ticket product in order ' . $order->get_id() . ' - Product ID: ' . $product_id);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Validate and sanitize URL
     */
    private function validate_and_sanitize_url($url) {
        // Trim whitespace
        $url = trim($url);
        
        if (empty($url)) {
            return false;
        }
        
        // Add protocol if missing
        if (!preg_match('~^(?:f|ht)tps?://~i', $url)) {
            $url = 'https://' . $url;
        }
        
        // Validate URL format
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // Additional security checks
        $parsed_url = parse_url($url);
        
        // Must have host
        if (empty($parsed_url['host'])) {
            return false;
        }
        
        // Block localhost and private IP ranges for security
        if (in_array($parsed_url['host'], array('localhost', '127.0.0.1', '0.0.0.0'))) {
            return false;
        }
        
        return esc_url_raw($url);
    }
    
    /**
     * Build redirect URL with order parameters
     */
    private function build_thank_you_redirect_url($base_url, $order) {
        $order_id = $order->get_id();
        $order_key = $order->get_order_key();
        
        // Get ticket metadata if available
        $ticket_token = $order->get_meta('_priority_ticket_token');
        $submission_id = $order->get_meta('_priority_ticket_submission_id');
        $ticket_tier = $order->get_meta('_priority_ticket_tier');
        
        // Build query parameters
        $params = array(
            'order_id' => $order_id,
            'order_key' => $order_key,
            'order_total' => $order->get_total(),
            'currency' => $order->get_currency(),
        );
        
        // Add ticket-specific parameters if available
        if (!empty($ticket_token)) {
            $params['ticket_token'] = $ticket_token;
        }
        
        if (!empty($submission_id)) {
            $params['submission_id'] = $submission_id;
        }
        
        if (!empty($ticket_tier)) {
            $params['ticket_tier'] = $ticket_tier;
        }
        
        // Add customer information
        $params['customer_name'] = $order->get_formatted_billing_full_name();
        $params['customer_email'] = $order->get_billing_email();
        
        // Build final URL
        $redirect_url = add_query_arg($params, $base_url);
        
        return $redirect_url;
    }
    
    /**
     * Add JavaScript redirect as fallback
     */
    public function add_javascript_redirect_fallback($order_id) {
        if (!$order_id) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Don't add fallback if we already redirected
        if (get_transient('priority_ticket_redirected_' . $order_id)) {
            return;
        }
        
        // Check if this order contains priority ticket products
        $has_priority_ticket = $this->order_contains_priority_ticket($order);
        
        if (!$has_priority_ticket) {
            return;
        }
        
        // Get custom thank you page URL from settings
        $custom_thank_you_url = Priority_Ticket_Payment::get_option('custom_thank_you_page_url', '');
        
        if (empty($custom_thank_you_url)) {
            return;
        }
        
        // Validate URL
        $validated_url = $this->validate_and_sanitize_url($custom_thank_you_url);
        
        if (!$validated_url) {
            return;
        }
        
        // Build redirect URL with order information
        $redirect_url = $this->build_thank_you_redirect_url($validated_url, $order);
        
        error_log('Priority Ticket Payment: Adding JavaScript fallback redirect for order ' . $order_id . ' to: ' . $redirect_url);
        
        // Output JavaScript redirect as fallback
        echo '<script type="text/javascript">
        setTimeout(function() {
            if (!window.priorityTicketRedirected) {
                window.priorityTicketRedirected = true;
                console.log("Priority Ticket Payment: Redirecting via JavaScript fallback to ' . esc_js($redirect_url) . '");
                window.location.href = "' . esc_js($redirect_url) . '";
            }
        }, 1000);
        </script>';
    }
    
    /**
     * Handle order completion
     */
    public function handle_order_completion($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Check if this is a priority ticket order
        if ($this->order_contains_priority_ticket($order)) {
            error_log('Priority Ticket Payment: Priority ticket order completed - Order ID: ' . $order_id);
            
            // Additional completion processing can be added here
            $this->log_order_completion($order);
        }
    }
    
    /**
     * Handle order processing status
     */
    public function handle_order_processing($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Check if this is a priority ticket order
        if ($this->order_contains_priority_ticket($order)) {
            error_log('Priority Ticket Payment: Priority ticket order processing - Order ID: ' . $order_id);
            
            // Additional processing logic can be added here
            $this->log_order_processing($order);
        }
    }
    
    /**
     * Log order completion details
     */
    private function log_order_completion($order) {
        $log_data = array(
            'order_id' => $order->get_id(),
            'order_total' => $order->get_total(),
            'customer_email' => $order->get_billing_email(),
            'ticket_token' => $order->get_meta('_priority_ticket_token'),
            'submission_id' => $order->get_meta('_priority_ticket_submission_id'),
            'ticket_tier' => $order->get_meta('_priority_ticket_tier'),
            'completion_time' => current_time('mysql'),
        );
        
        error_log('Priority Ticket Payment: Order completion data - ' . json_encode($log_data));
    }
    
    /**
     * Log order processing details
     */
    private function log_order_processing($order) {
        $log_data = array(
            'order_id' => $order->get_id(),
            'order_total' => $order->get_total(),
            'customer_email' => $order->get_billing_email(),
            'processing_time' => current_time('mysql'),
        );
        
        error_log('Priority Ticket Payment: Order processing data - ' . json_encode($log_data));
    }
    
    /**
     * Get order statistics for priority tickets
     */
    public static function get_priority_ticket_order_stats() {
        global $wpdb;
        
        // Get orders with priority ticket products from last 30 days
        $query = "
            SELECT 
                COUNT(DISTINCT p.ID) as total_orders,
                SUM(pm_total.meta_value) as total_revenue,
                AVG(pm_total.meta_value) as avg_order_value
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
            INNER JOIN {$wpdb->postmeta} pm_ticket ON p.ID = pm_ticket.post_id AND pm_ticket.meta_key = '_priority_ticket_token'
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND p.post_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ";
        
        $results = $wpdb->get_row($query, ARRAY_A);
        
        return $results ?: array(
            'total_orders' => 0,
            'total_revenue' => 0,
            'avg_order_value' => 0,
        );
    }
    
    /**
     * Validate thank you page URL setting
     */
    public static function validate_thank_you_url($url) {
        if (empty($url)) {
            return true; // Empty is valid (will use default)
        }
        
        // Create instance to use validation method
        $instance = new self();
        $validated = $instance->validate_and_sanitize_url($url);
        
        return !empty($validated);
    }
} 