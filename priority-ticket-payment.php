<?php
/**
 * Plugin Name: Priority Ticket Payment
 * Plugin URI: https://sparkwebstudio.com/priority-ticket-payment
 * Description: A comprehensive WordPress plugin for managing priority ticket submissions with payment integration for WooCommerce and Awesome Support. Supports Elementor Pro forms with automatic ticket creation, priority assignment, and agent management.
 * Version: 1.1.0
 * Author: SPARKWEBStudio
 * Author URI: https://sparkwebstudio.com/
 * Text Domain: priority-ticket-payment
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Network: false
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('PRIORITY_TICKET_PAYMENT_VERSION', '1.1.0');
define('PRIORITY_TICKET_PAYMENT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PRIORITY_TICKET_PAYMENT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('PRIORITY_TICKET_PAYMENT_PLUGIN_FILE', __FILE__);

/**
 * Main Priority Ticket Payment Class
 */
class Priority_Ticket_Payment {
    
    /**
     * Single instance of the class
     */
    private static $_instance = null;
    
    /**
     * Table name for priority ticket submissions
     */
    public static $table_name = 'wp_priority_ticket_submissions';
    
    /**
     * Main Instance
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('init', array($this, 'init'));
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        
        // Register activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Register scheduled cleanup hook
        add_action('priority_ticket_payment_daily_cleanup', array($this, 'run_daily_cleanup'));
        
        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        }
        
        // Frontend hooks
        add_action('wp_enqueue_scripts', array($this, 'frontend_enqueue_scripts'));
        
        // Handle order completion - create support tickets
        add_action('woocommerce_order_status_completed', array($this, 'handle_order_completion'), 10, 1);
        
        // Auto-complete priority ticket orders when payment is processed
        add_action('woocommerce_order_status_processing', array($this, 'auto_complete_priority_ticket_orders'), 10, 1);
        add_action('woocommerce_payment_complete', array($this, 'auto_complete_priority_ticket_orders'), 10, 1);
        
        // Mark products as priority ticket products when created by the plugin
        add_action('priority_ticket_payment_product_created', array($this, 'mark_product_as_priority_ticket'), 10, 1);
    }
    
    /**
     * Initialize the plugin
     */
    public function init() {
        // Initialize components here
        $this->load_dependencies();
        
        // Check for WooCommerce integration
        if (class_exists('WooCommerce')) {
            $this->init_woocommerce_integration();
        }
        
        // Check for Awesome Support integration
        if (class_exists('Awesome_Support')) {
            $this->init_awesome_support_integration();
        }
    }
    
    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain('priority-ticket-payment', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }
    
    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        require_once PRIORITY_TICKET_PAYMENT_PLUGIN_PATH . 'includes/class-database.php';
        require_once PRIORITY_TICKET_PAYMENT_PLUGIN_PATH . 'includes/class-admin.php';
        require_once PRIORITY_TICKET_PAYMENT_PLUGIN_PATH . 'includes/class-frontend.php';
        require_once PRIORITY_TICKET_PAYMENT_PLUGIN_PATH . 'includes/class-ajax.php';
        require_once PRIORITY_TICKET_PAYMENT_PLUGIN_PATH . 'includes/class-elementor-integration.php';
        require_once PRIORITY_TICKET_PAYMENT_PLUGIN_PATH . 'includes/class-elementor-utils.php';
        require_once PRIORITY_TICKET_PAYMENT_PLUGIN_PATH . 'includes/class-awesome-support-utils.php';
        
        // Instantiate classes
        new Priority_Ticket_Payment_Admin();
        new Priority_Ticket_Payment_Frontend();
        new Priority_Ticket_Payment_Ajax();
        
        // Only load Elementor integration if Elementor Pro is active
        if (defined('ELEMENTOR_PRO_VERSION')) {
            new Priority_Ticket_Payment_Elementor_Integration();
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        $this->create_database_table();
        $this->set_default_options();
        
        // Create upload directory for attachments
        $this->create_upload_directory();
        
        // Schedule daily cleanup
        $this->schedule_daily_cleanup();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up if needed (but don't drop table by default)
        $this->clear_scheduled_cleanup();
        flush_rewrite_rules();
    }
    
    /**
     * Create custom database table
     */
    private function create_database_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'priority_ticket_submissions';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            form_data longtext NOT NULL,
            attachments longtext,
            price decimal(10,2) NOT NULL DEFAULT 0.00,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            payment_status varchar(20) NOT NULL DEFAULT 'pending',
            order_id bigint(20) unsigned NULL,
            token varchar(255) NULL,
            awesome_support_ticket_id bigint(20) unsigned NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY payment_status (payment_status),
            KEY order_id (order_id),
            KEY token (token),
            KEY awesome_support_ticket_id (awesome_support_ticket_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Store database version
        add_option('priority_ticket_payment_db_version', PRIORITY_TICKET_PAYMENT_VERSION);
    }
    
    /**
     * Drop custom database table (used for complete uninstall)
     */
    public static function drop_database_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'priority_ticket_submissions';
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        
        delete_option('priority_ticket_payment_db_version');
    }
    
    /**
     * Set default plugin options
     */
    private function set_default_options() {
        $default_options = array(
            'enable_woocommerce_integration' => 'yes',
            'enable_awesome_support_integration' => 'yes',
            'default_ticket_price' => '50.00',
            'currency_symbol' => '$',
            'payment_methods' => array('stripe', 'paypal'),
            'require_payment_before_submission' => 'yes',
            'max_file_size' => '10', // MB
            'allowed_file_types' => array('pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif'),
            // Form Mapping defaults
            'ticket_form_id_a' => '',
            'ticket_form_id_b' => '',
            'ticket_form_id_c' => '',
            'product_id_a' => '',
            'product_id_b' => '',
            'coaching_product_ids' => '',
        );
        
        add_option('priority_ticket_payment_settings', $default_options);
    }
    
    /**
     * Create upload directory for attachments
     */
    private function create_upload_directory() {
        $upload_dir = wp_upload_dir();
        
        // Create main plugin upload directory for general attachments
        $plugin_upload_dir = $upload_dir['basedir'] . '/priority-ticket-attachments';
        
        if (!file_exists($plugin_upload_dir)) {
            wp_mkdir_p($plugin_upload_dir);
            
            // Create .htaccess file for security
            $htaccess_content = "Options -Indexes\n";
            $htaccess_content .= "deny from all\n";
            file_put_contents($plugin_upload_dir . '/.htaccess', $htaccess_content);
            
            // Create index.php file for security
            file_put_contents($plugin_upload_dir . '/index.php', '<?php // Silence is golden');
        }
        
        // Create priority-tickets directory for Elementor form uploads
        $priority_tickets_dir = $upload_dir['basedir'] . '/priority-tickets';
        
        if (!file_exists($priority_tickets_dir)) {
            wp_mkdir_p($priority_tickets_dir);
            
            // Create .htaccess file for security
            $htaccess_content = "Options -Indexes\n";
            $htaccess_content .= "deny from all\n";
            file_put_contents($priority_tickets_dir . '/.htaccess', $htaccess_content);
            
            // Create index.php file for security
            file_put_contents($priority_tickets_dir . '/index.php', '<?php // Silence is golden');
        }
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Priority Tickets', 'priority-ticket-payment'),
            __('Priority Tickets', 'priority-ticket-payment'),
            'manage_options',
            'priority-ticket-payment',
            array($this, 'admin_page'),
            'dashicons-tickets-alt',
            30
        );
        
        add_submenu_page(
            'priority-ticket-payment',
            __('Submissions', 'priority-ticket-payment'),
            __('Submissions', 'priority-ticket-payment'),
            'manage_options',
            'priority-ticket-payment',
            array($this, 'admin_page')
        );
        
        add_submenu_page(
            'priority-ticket-payment',
            __('Settings', 'priority-ticket-payment'),
            __('Settings', 'priority-ticket-payment'),
            'manage_options',
            'priority-ticket-payment-settings',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'priority-ticket-payment',
            __('Cleanup', 'priority-ticket-payment'),
            __('Cleanup', 'priority-ticket-payment'),
            'manage_options',
            'priority-ticket-payment-cleanup',
            array($this, 'cleanup_page')
        );
    }
    
    /**
     * Admin page callback
     */
    public function admin_page() {
        Priority_Ticket_Payment_Admin::render_submissions_page();
    }
    
    /**
     * Settings page callback
     */
    public function settings_page() {
        Priority_Ticket_Payment_Admin::render_settings_page();
    }
    
    /**
     * Cleanup page callback
     */
    public function cleanup_page() {
        Priority_Ticket_Payment_Admin::render_cleanup_page();
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function admin_enqueue_scripts($hook) {
        if (strpos($hook, 'priority-ticket-payment') !== false) {
            wp_enqueue_style(
                'priority-ticket-payment-admin',
                PRIORITY_TICKET_PAYMENT_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                PRIORITY_TICKET_PAYMENT_VERSION
            );
            
            wp_enqueue_script(
                'priority-ticket-payment-admin',
                PRIORITY_TICKET_PAYMENT_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery'),
                PRIORITY_TICKET_PAYMENT_VERSION,
                true
            );
            
            // Localize script for admin AJAX
            wp_localize_script('priority-ticket-payment-admin', 'priority_ticket_payment_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('priority_ticket_payment_admin_nonce'),
            ));
        }
    }
    
    /**
     * Enqueue frontend scripts and styles
     */
    public function frontend_enqueue_scripts() {
        wp_enqueue_style(
            'priority-ticket-payment-frontend',
            PRIORITY_TICKET_PAYMENT_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            PRIORITY_TICKET_PAYMENT_VERSION
        );
        
        wp_enqueue_script(
            'priority-ticket-payment-frontend',
            PRIORITY_TICKET_PAYMENT_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            PRIORITY_TICKET_PAYMENT_VERSION,
            true
        );
        
        // Localize script for AJAX
        wp_localize_script('priority-ticket-payment-frontend', 'priority_ticket_payment_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('priority_ticket_payment_nonce'),
        ));
    }
    
    /**
     * Initialize WooCommerce integration
     */
    private function init_woocommerce_integration() {
        // Register WooCommerce hooks
        add_action('init', array($this, 'register_woocommerce_hooks'));
    }
    
    /**
     * Register WooCommerce hooks
     */
    public function register_woocommerce_hooks() {
        // Save priority ticket metadata to WooCommerce orders
        add_action('woocommerce_checkout_create_order', function($order, $data) {
            // Save metadata from URL parameters (for static handler method)
            if (isset($_GET['ticket_id'])) {
                $order->update_meta_data('_priority_ticket_token', sanitize_text_field($_GET['ticket_id']));
                
                if (isset($_GET['submission_id'])) {
                    $order->update_meta_data('_priority_ticket_submission_id', absint($_GET['submission_id']));
                }
                
                if (isset($_GET['tier'])) {
                    $order->update_meta_data('_priority_ticket_tier', sanitize_text_field($_GET['tier']));
                }
                
                // Log metadata save for debugging
                error_log(sprintf(
                    'Priority Ticket Payment: Saved metadata to order - Token: %s, Submission: %s, Tier: %s',
                    sanitize_text_field($_GET['ticket_id']),
                    isset($_GET['submission_id']) ? absint($_GET['submission_id']) : 'N/A',
                    isset($_GET['tier']) ? sanitize_text_field($_GET['tier']) : 'N/A'
                ));
            }
        }, 10, 2);
        
        // Handle order completion - create support tickets
        add_action('woocommerce_order_status_completed', array($this, 'handle_order_completion'), 10, 1);
        
        // Auto-complete priority ticket orders when payment is processed
        add_action('woocommerce_order_status_processing', array($this, 'auto_complete_priority_ticket_orders'), 10, 1);
        add_action('woocommerce_payment_complete', array($this, 'auto_complete_priority_ticket_orders'), 10, 1);
        
        // Mark products as priority ticket products when created by the plugin
        add_action('priority_ticket_payment_product_created', array($this, 'mark_product_as_priority_ticket'), 10, 1);
        
        // Additional WooCommerce integration hooks can be added here
        // add_filter('woocommerce_product_data_tabs', array($this, 'add_priority_ticket_product_tab'));
    }
    
    /**
     * Initialize Awesome Support integration
     */
    private function init_awesome_support_integration() {
        // Initialize Awesome Support utilities
        Priority_Ticket_Payment_Awesome_Support_Utils::init();
        
        add_action('init', array($this, 'register_awesome_support_hooks'));
    }
    
    /**
     * Register Awesome Support hooks
     */
    public function register_awesome_support_hooks() {
        // Additional Awesome Support integration hooks can be added here
        // The main functionality is handled by the utils class
        
        // Example: Add custom priority levels or modify ticket behavior
        // add_filter('wpas_ticket_priority_options', array($this, 'add_priority_ticket_option'));
    }
    
    /**
     * Get plugin option
     */
    public static function get_option($key, $default = '') {
        $options = get_option('priority_ticket_payment_settings', array());
        return isset($options[$key]) ? $options[$key] : $default;
    }
    
    /**
     * Update plugin option
     */
    public static function update_option($key, $value) {
        $options = get_option('priority_ticket_payment_settings', array());
        $options[$key] = $value;
        update_option('priority_ticket_payment_settings', $options);
    }
    
    /**
     * Schedule daily cleanup event
     */
    private function schedule_daily_cleanup() {
        if (!wp_next_scheduled('priority_ticket_payment_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'priority_ticket_payment_daily_cleanup');
        }
    }
    
    /**
     * Clear scheduled cleanup event
     */
    private function clear_scheduled_cleanup() {
        $timestamp = wp_next_scheduled('priority_ticket_payment_daily_cleanup');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'priority_ticket_payment_daily_cleanup');
        }
    }
    
    /**
     * Run daily cleanup of old pending submissions
     */
    public function run_daily_cleanup() {
        $this->cleanup_old_pending_submissions();
    }
    
    /**
     * Clean up old pending submissions and their files
     */
    private function cleanup_old_pending_submissions() {
        // Get old pending submissions
        $old_submissions = Priority_Ticket_Payment_Database::get_old_pending_submissions(48);
        
        $deleted_submissions = 0;
        $deleted_files = 0;
        $submission_ids = array();
        
        foreach ($old_submissions as $submission) {
            $submission_ids[] = $submission['id'];
            
            // Delete associated files first
            if (!empty($submission['attachments']) && is_array($submission['attachments'])) {
                foreach ($submission['attachments'] as $attachment) {
                    if (isset($attachment['path']) && file_exists($attachment['path'])) {
                        if (unlink($attachment['path'])) {
                            $deleted_files++;
                        }
                    }
                }
            }
        }
        
        // Delete submissions from database in batch
        if (!empty($submission_ids)) {
            $deleted_submissions = Priority_Ticket_Payment_Database::delete_submissions_by_ids($submission_ids);
        }
        
        // Log cleanup results
        if ($deleted_submissions > 0 || $deleted_files > 0) {
            error_log(sprintf(
                'Priority Ticket Payment: Daily cleanup completed - Deleted %d submissions and %d files',
                $deleted_submissions,
                $deleted_files
            ));
        }
        
        // Also run general file cleanup for orphaned files
        $this->cleanup_orphaned_files();
        
        // Trigger action for extensibility
        do_action('priority_ticket_payment_daily_cleanup_completed', $deleted_submissions, $deleted_files);
        
        return array(
            'deleted_submissions' => $deleted_submissions,
            'deleted_files' => $deleted_files
        );
    }
    
    /**
     * Clean up orphaned files in priority-tickets directory
     */
    private function cleanup_orphaned_files() {
        $upload_dir = wp_upload_dir();
        $priority_tickets_dir = $upload_dir['basedir'] . '/priority-tickets';
        
        if (!file_exists($priority_tickets_dir)) {
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'priority_ticket_submissions';
        
        $files = glob($priority_tickets_dir . '/*');
        $orphaned_count = 0;
        
        foreach ($files as $file) {
            if (!is_file($file) || basename($file) === '.htaccess' || basename($file) === 'index.php') {
                continue;
            }
            
            // Check if file is referenced in any submission
            $file_referenced = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} WHERE attachments LIKE %s",
                    '%' . $wpdb->esc_like($file) . '%'
                )
            );
            
            // If not referenced, delete the file
            if (!$file_referenced) {
                if (unlink($file)) {
                    $orphaned_count++;
                }
            }
        }
        
        if ($orphaned_count > 0) {
            error_log(sprintf(
                'Priority Ticket Payment: Cleaned up %d orphaned files',
                $orphaned_count
            ));
        }
    }
    
    /**
     * Manual cleanup trigger (for admin use)
     */
    public static function trigger_manual_cleanup() {
        $instance = self::instance();
        return $instance->cleanup_old_pending_submissions();
    }
    
    /**
     * Create ticket for completed submission (manual creation from admin)
     */
    public static function create_ticket_for_completed_submission($submission_id) {
        $submission = Priority_Ticket_Payment_Database::get_submission($submission_id);
        if (!$submission) {
            error_log('Priority Ticket Payment: Submission not found for ID: ' . $submission_id);
            return false;
        }
        
        // Check if submission is completed
        if ($submission['payment_status'] !== 'completed') {
            error_log('Priority Ticket Payment: Submission ' . $submission_id . ' is not completed (status: ' . $submission['payment_status'] . ')');
            return false;
        }
        
        // Check if ticket already exists
        if (!empty($submission['awesome_support_ticket_id'])) {
            error_log('Priority Ticket Payment: Ticket already exists for submission ' . $submission_id);
            return false; // Ticket already exists
        }
        
        // Check if Awesome Support integration is enabled
        if (self::get_option('enable_awesome_support_integration', 'yes') !== 'yes') {
            error_log('Priority Ticket Payment: Awesome Support integration is disabled');
            return false;
        }
        
        // Check if Awesome Support is available
        if (!class_exists('Priority_Ticket_Payment_Awesome_Support_Utils')) {
            error_log('Priority Ticket Payment: Awesome Support utils class not found');
            return false;
        }
        
        if (!Priority_Ticket_Payment_Awesome_Support_Utils::is_awesome_support_active()) {
            error_log('Priority Ticket Payment: Awesome Support plugin not active - wpas_insert_ticket function not found');
            
            // Create a simple WordPress post as fallback
            error_log('Priority Ticket Payment: Creating fallback ticket as WordPress post');
            return self::create_fallback_ticket($submission);
        }
        
        error_log('Priority Ticket Payment: Creating ticket for submission ' . $submission_id);
        
        // Get or create a mock order if one exists
        $order = null;
        if (!empty($submission['order_id'])) {
            $order = wc_get_order($submission['order_id']);
        }
        
        // If no order, create a minimal mock order object for ticket creation
        if (!$order) {
            error_log('Priority Ticket Payment: No order found, creating mock order data');
            $order = new Priority_Ticket_Payment_Mock_Order($submission);
        }
        
        // Get user priority from form data or default to A
        $form_data = is_string($submission['form_data']) ? unserialize($submission['form_data']) : $submission['form_data'];
        $user_priority = isset($form_data['user_priority']) ? $form_data['user_priority'] : 'A';
        
        error_log('Priority Ticket Payment: Creating ticket with priority: ' . $user_priority);
        
        // Create ticket
        $ticket_id = Priority_Ticket_Payment_Awesome_Support_Utils::create_ticket_from_submission(
            $submission,
            $order,
            $user_priority
        );
        
        if (!is_wp_error($ticket_id) && $ticket_id) {
            // Update submission with ticket ID
            Priority_Ticket_Payment_Database::update_ticket_id($submission_id, $ticket_id);
            
            error_log('Priority Ticket Payment: Successfully created ticket ' . $ticket_id . ' for submission ' . $submission_id);
            
            // Add order note if we have a real order
            if (is_object($order) && method_exists($order, 'add_order_note')) {
                $order->add_order_note(sprintf(
                    __('Priority support ticket created manually: #%d', 'priority-ticket-payment'),
                    $ticket_id
                ));
            }
            
            return $ticket_id;
        } else {
            $error_message = is_wp_error($ticket_id) ? $ticket_id->get_error_message() : 'Unknown error';
            error_log('Priority Ticket Payment: Failed to create ticket for submission ' . $submission_id . ': ' . $error_message);
            return false;
        }
    }
    
    /**
     * Create a fallback ticket as WordPress post when Awesome Support is not available
     */
    public static function create_fallback_ticket($submission) {
        $form_data = is_string($submission['form_data']) ? unserialize($submission['form_data']) : $submission['form_data'];
        
        // Build ticket title
        $ticket_title = isset($form_data['ticket_subject']) ? $form_data['ticket_subject'] : 'Priority Support Request #' . $submission['id'];
        
        // Build ticket content
        $content_parts = array();
        $content_parts[] = '**PRIORITY SUPPORT REQUEST**';
        $content_parts[] = '';
        $content_parts[] = '**Submission ID:** ' . $submission['id'];
        $content_parts[] = '**Price:** $' . number_format($submission['price'], 2);
        $content_parts[] = '**Created:** ' . $submission['created_at'];
        $content_parts[] = '';
        
        if (!empty($form_data['ticket_description'])) {
            $content_parts[] = '**Description:**';
            $content_parts[] = $form_data['ticket_description'];
            $content_parts[] = '';
        }
        
        if (!empty($form_data['contact_email'])) {
            $content_parts[] = '**Contact Email:** ' . $form_data['contact_email'];
        }
        
        if (!empty($form_data['contact_phone'])) {
            $content_parts[] = '**Contact Phone:** ' . $form_data['contact_phone'];
        }
        
        if (!empty($form_data['coach'])) {
            $content_parts[] = '**Preferred Coach:** ' . $form_data['coach'];
        }
        
        if (!empty($form_data['website'])) {
            $content_parts[] = '**Website:** ' . $form_data['website'];
        }
        
        $content_parts[] = '';
        $content_parts[] = '**NOTE:** This ticket was created as a fallback because Awesome Support plugin is not active.';
        $content_parts[] = 'To use full ticket functionality, please install and activate Awesome Support plugin.';
        
        $ticket_content = implode("\n", $content_parts);
        
        // Create WordPress post
        $post_data = array(
            'post_title' => $ticket_title,
            'post_content' => $ticket_content,
            'post_status' => 'publish',
            'post_type' => 'post', // Use regular post since we don't have Awesome Support
            'post_author' => $submission['user_id'] ?: 1,
            'meta_input' => array(
                '_priority_ticket_submission_id' => $submission['id'],
                '_priority_ticket_fallback' => 'yes',
                '_priority_ticket_price' => $submission['price'],
                '_priority_ticket_token' => $submission['token'],
            )
        );
        
        $post_id = wp_insert_post($post_data);
        
        if ($post_id) {
            error_log('Priority Ticket Payment: Created fallback ticket as post ID: ' . $post_id);
            return $post_id;
        } else {
            error_log('Priority Ticket Payment: Failed to create fallback ticket');
            return false;
        }
    }
    
    /**
     * Handle order completion - create support tickets
     */
    public function handle_order_completion($order_id) {
        error_log('Priority Ticket Payment: Order completion handler called for order ID: ' . $order_id);
        
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('Priority Ticket Payment: Could not retrieve order ' . $order_id);
            return;
        }
        
        error_log('Priority Ticket Payment: Order retrieved successfully. Status: ' . $order->get_status());
        
        // Check if this order contains priority ticket products
        $has_priority_ticket = false;
        $submission_id = null;
        $ticket_token = null;
        $user_tier = null;
        
        // Check order meta first
        $submission_id = $order->get_meta('_priority_ticket_submission_id');
        $ticket_token = $order->get_meta('_priority_ticket_token');
        $user_tier = $order->get_meta('_priority_ticket_tier');
        
        error_log('Priority Ticket Payment: Order meta - Submission ID: ' . $submission_id . ', Token: ' . $ticket_token . ', Tier: ' . $user_tier);
        
        // Also check if any order items are priority ticket products
        $priority_products = array();
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $is_priority_ticket = get_post_meta($product_id, '_priority_ticket_product', true);
            
            error_log('Priority Ticket Payment: Checking product ' . $product_id . ' - Is priority ticket: ' . ($is_priority_ticket ?: 'not set'));
            
            if ($is_priority_ticket === 'yes') {
                $has_priority_ticket = true;
                $priority_products[] = $product_id;
                error_log('Priority Ticket Payment: Found priority ticket product: ' . $product_id);
            }
        }
        
        error_log('Priority Ticket Payment: Has priority ticket: ' . ($has_priority_ticket ? 'YES' : 'NO') . ', Priority products: ' . implode(', ', $priority_products));
        
        // If no submission ID in meta, try to find by token from order notes or other methods
        if (!$submission_id && $ticket_token) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'priority_ticket_submissions';
            $submission_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_name WHERE token = %s",
                $ticket_token
            ));
            error_log('Priority Ticket Payment: Found submission by token: ' . ($submission_id ?: 'none'));
        }
        
        if (!$has_priority_ticket && !$submission_id) {
            error_log('Priority Ticket Payment: Order ' . $order_id . ' is not a priority ticket order and has no submission ID - skipping');
            return;
        }
        
        // If we have submission_id, process even without priority ticket products
        if (!$submission_id) {
            error_log('Priority Ticket Payment: Order ' . $order_id . ' is missing submission ID - cannot process');
            return;
        }
        
        // Get submission from database
        $submission = Priority_Ticket_Payment_Database::get_submission($submission_id);
        if (!$submission) {
            error_log('Priority Ticket Payment: Could not find submission ' . $submission_id);
            return;
        }
        
        error_log('Priority Ticket Payment: Found submission ' . $submission_id . ' with current status: ' . $submission['payment_status']);
        
        // Update submission status to completed
        $update_result = Priority_Ticket_Payment_Database::update_submission($submission_id, array(
            'payment_status' => 'completed',
            'order_id' => $order_id,
        ));
        
        if ($update_result !== false) {
            error_log('Priority Ticket Payment: Successfully updated submission ' . $submission_id . ' status to completed');
        } else {
            error_log('Priority Ticket Payment: FAILED to update submission ' . $submission_id . ' status to completed');
        }
        
        // Create Awesome Support ticket if integration is enabled
        if (self::get_option('enable_awesome_support_integration', 'yes') === 'yes') {
            // Properly handle form_data deserialization
            $form_data = $submission['form_data'];
            if (is_string($form_data)) {
                $form_data = unserialize($form_data);
            }
            if (!is_array($form_data)) {
                $form_data = array();
            }
            
            // Detect user priority - try multiple sources
            $user_priority = null;
            
            // First, try from form_data
            if (isset($form_data['user_priority']) && !empty($form_data['user_priority'])) {
                $user_priority = $form_data['user_priority'];
            }
            
            // Second, try from order meta (user_tier)
            if (!$user_priority && $user_tier) {
                $user_priority = $user_tier;
            }
            
            // Third, try to detect from user ID and price
            if (!$user_priority) {
                if (class_exists('Priority_Ticket_Payment_Elementor_Utils')) {
                    $detected_priority = Priority_Ticket_Payment_Elementor_Utils::get_user_ticket_priority($submission['user_id']);
                    $user_priority = $detected_priority;
                } else {
                    // Fallback: determine from price
                    $price = floatval($submission['price']);
                    if ($price >= 100) {
                        $user_priority = 'A';
                    } elseif ($price >= 50) {
                        $user_priority = 'B';
                    } else {
                        $user_priority = 'C';
                    }
                }
            }
            
            error_log('Priority Ticket Payment: Creating Awesome Support ticket with priority: ' . $user_priority . ' (from form_data: ' . (isset($form_data['user_priority']) ? $form_data['user_priority'] : 'NOT SET') . ', from order meta: ' . ($user_tier ?: 'NOT SET') . ')');
            
            $ticket_id = Priority_Ticket_Payment_Awesome_Support_Utils::create_ticket_from_submission(
                $submission,
                $order,
                $user_priority
            );
            
            if (!is_wp_error($ticket_id)) {
                // Update submission with ticket ID
                Priority_Ticket_Payment_Database::update_ticket_id($submission_id, $ticket_id);
                
                error_log('Priority Ticket Payment: Created Awesome Support ticket ' . $ticket_id . ' for order ' . $order_id);
                
                // Add order note
                $order->add_order_note(sprintf(
                    __('Priority support ticket created: #%d', 'priority-ticket-payment'),
                    $ticket_id
                ));
            } else {
                error_log('Priority Ticket Payment: Failed to create ticket: ' . $ticket_id->get_error_message());
                
                // Add order note about failure
                $order->add_order_note(sprintf(
                    __('Failed to create support ticket: %s', 'priority-ticket-payment'),
                    $ticket_id->get_error_message()
                ));
            }
        } else {
            error_log('Priority Ticket Payment: Awesome Support integration is disabled');
        }
        
        // Trigger action for extensibility
        do_action('priority_ticket_payment_order_completed', $order_id, $submission_id, $submission);
        
        error_log('Priority Ticket Payment: Order completion processing finished for order ' . $order_id);
    }
    
    /**
     * Auto-complete priority ticket orders when payment is processed
     */
    public function auto_complete_priority_ticket_orders($order_id) {
        error_log('Priority Ticket Payment: Auto-complete handler called for order ID: ' . $order_id);
        
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('Priority Ticket Payment: Could not retrieve order for auto-completion: ' . $order_id);
            return;
        }
        
        // Don't process if already completed
        if ($order->get_status() === 'completed') {
            error_log('Priority Ticket Payment: Order ' . $order_id . ' already completed');
            return;
        }
        
        // Check if this order contains priority ticket products
        $has_priority_ticket = false;
        $submission_id = $order->get_meta('_priority_ticket_submission_id');
        $ticket_token = $order->get_meta('_priority_ticket_token');
        
        error_log('Priority Ticket Payment: Auto-complete check - Order meta: Submission ID: ' . $submission_id . ', Token: ' . $ticket_token);
        
        // Check if any order items are priority ticket products
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $is_priority_ticket = get_post_meta($product_id, '_priority_ticket_product', true);
            
            if ($is_priority_ticket === 'yes') {
                $has_priority_ticket = true;
                error_log('Priority Ticket Payment: Auto-complete - Found priority ticket product: ' . $product_id);
                break;
            }
        }
        
        // Also check if we have submission metadata (even without product flag)
        if (!$has_priority_ticket && ($submission_id || $ticket_token)) {
            $has_priority_ticket = true;
            error_log('Priority Ticket Payment: Auto-complete - Found priority ticket metadata');
        }
        
        if (!$has_priority_ticket) {
            error_log('Priority Ticket Payment: Order ' . $order_id . ' is not a priority ticket order - skipping auto-completion');
            return;
        }
        
        // Auto-complete the order
        error_log('Priority Ticket Payment: Auto-completing priority ticket order ' . $order_id);
        
        $order->update_status('completed', __('Priority ticket order auto-completed after payment.', 'priority-ticket-payment'));
        
        // Add order note
        $order->add_order_note(__('Order automatically completed - Priority ticket product detected.', 'priority-ticket-payment'));
        
        error_log('Priority Ticket Payment: Successfully auto-completed order ' . $order_id);
    }
    
    /**
     * Mark product as priority ticket product
     */
    public function mark_product_as_priority_ticket($product_id) {
        if (!$product_id || !get_post($product_id)) {
            error_log('Priority Ticket Payment: Invalid product ID for marking as priority ticket: ' . $product_id);
            return false;
        }
        
        // Mark the product as a priority ticket product
        update_post_meta($product_id, '_priority_ticket_product', 'yes');
        
        // Add a note about when this was marked
        update_post_meta($product_id, '_priority_ticket_marked_date', current_time('mysql'));
        
        error_log('Priority Ticket Payment: Successfully marked product ' . $product_id . ' as priority ticket product');
        
        return true;
    }
}

/**
 * Mock Order class for ticket creation when no real order exists
 */
class Priority_Ticket_Payment_Mock_Order {
    private $submission;
    
    public function __construct($submission) {
        $this->submission = $submission;
    }
    
    public function get_id() {
        return 0;
    }
    
    public function get_formatted_billing_full_name() {
        $user = get_user_by('id', $this->submission['user_id']);
        return $user ? $user->display_name : 'Unknown Customer';
    }
    
    public function get_billing_email() {
        $user = get_user_by('id', $this->submission['user_id']);
        if ($user && $user->user_email) {
            return $user->user_email;
        }
        
        // Try to get email from form data
        $form_data = is_string($this->submission['form_data']) ? unserialize($this->submission['form_data']) : $this->submission['form_data'];
        return isset($form_data['contact_email']) ? $form_data['contact_email'] : '';
    }
    
    public function get_date_created() {
        return new DateTime($this->submission['created_at']);
    }
    
    public function get_formatted_order_total() {
        return '$' . number_format($this->submission['price'], 2);
    }
    
    public function get_payment_method_title() {
        return 'Manual Processing';
    }
    
    public function add_order_note($note) {
        error_log('Priority Ticket Payment: Mock order note: ' . $note);
    }
}

/**
 * Returns the main instance of Priority_Ticket_Payment
 */
function Priority_Ticket_Payment() {
    return Priority_Ticket_Payment::instance();
}

// Initialize the plugin
Priority_Ticket_Payment();

/**
 * Uninstall hook (called when plugin is deleted)
 */
register_uninstall_hook(__FILE__, 'priority_ticket_payment_uninstall');

function priority_ticket_payment_uninstall() {
    // Only drop table if the option is set to remove data on uninstall
    $remove_data = get_option('priority_ticket_payment_remove_data_on_uninstall', false);
    
    // Always clear scheduled events on uninstall
    $timestamp = wp_next_scheduled('priority_ticket_payment_daily_cleanup');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'priority_ticket_payment_daily_cleanup');
    }
    
    if ($remove_data) {
        Priority_Ticket_Payment::drop_database_table();
        delete_option('priority_ticket_payment_settings');
        delete_option('priority_ticket_payment_remove_data_on_uninstall');
        
        // Remove upload directories
        $upload_dir = wp_upload_dir();
        
        // Remove main plugin upload directory
        $plugin_upload_dir = $upload_dir['basedir'] . '/priority-ticket-attachments';
        if (file_exists($plugin_upload_dir)) {
            // Remove all files in directory
            $files = glob($plugin_upload_dir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($plugin_upload_dir);
        }
        
        // Remove priority-tickets directory
        $priority_tickets_dir = $upload_dir['basedir'] . '/priority-tickets';
        if (file_exists($priority_tickets_dir)) {
            // Remove all files in directory
            $files = glob($priority_tickets_dir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($priority_tickets_dir);
        }
    }
} 