<?php
/**
 * Elementor integration utilities for Priority Ticket Payment
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Priority_Ticket_Payment_Elementor_Utils {
    
    /**
     * Test if Elementor Pro is available
     */
    public static function is_elementor_pro_active() {
        return defined('ELEMENTOR_PRO_VERSION') && class_exists('\ElementorPro\Modules\Forms\Module');
    }
    
    /**
     * Validate form ID exists
     */
    public static function validate_form_id($form_id) {
        if (empty($form_id)) {
            return false;
        }
        
        // Basic validation - Elementor form IDs are typically alphanumeric
        return preg_match('/^[a-zA-Z0-9_-]+$/', $form_id);
    }
    
    /**
     * Get all available Elementor forms (for admin dropdown)
     */
    public static function get_available_forms() {
        if (!self::is_elementor_pro_active()) {
            return array();
        }
        
        $forms = array();
        
        // Query for posts that contain Elementor forms
        $query_args = array(
            'post_type' => array('page', 'post', 'elementor_library'),
            'meta_query' => array(
                array(
                    'key' => '_elementor_data',
                    'compare' => 'EXISTS'
                )
            ),
            'posts_per_page' => -1,
        );
        
        $posts = get_posts($query_args);
        
        foreach ($posts as $post) {
            $elementor_data = get_post_meta($post->ID, '_elementor_data', true);
            if (!empty($elementor_data)) {
                $data = json_decode($elementor_data, true);
                $forms = array_merge($forms, self::extract_forms_from_data($data, $post));
            }
        }
        
        return $forms;
    }
    
    /**
     * Extract form data from Elementor data
     */
    private static function extract_forms_from_data($data, $post, $forms = array()) {
        if (!is_array($data)) {
            return $forms;
        }
        
        foreach ($data as $element) {
            if (isset($element['widgetType']) && $element['widgetType'] === 'form') {
                $settings = isset($element['settings']) ? $element['settings'] : array();
                $form_id = isset($settings['form_id']) ? $settings['form_id'] : '';
                $form_name = isset($settings['form_name']) ? $settings['form_name'] : '';
                
                if (!empty($form_id)) {
                    $forms[] = array(
                        'id' => $form_id,
                        'name' => $form_name ?: ('Form ' . $form_id),
                        'post_title' => $post->post_title,
                        'post_id' => $post->ID,
                    );
                }
            }
            
            // Recursively check nested elements
            if (isset($element['elements'])) {
                $forms = self::extract_forms_from_data($element['elements'], $post, $forms);
            }
        }
        
        return $forms;
    }
    
    /**
     * Debug form submission data
     */
    public static function debug_form_data($record) {
        if (!WP_DEBUG) {
            return;
        }
        
        $debug_data = array(
            'form_id' => $record->get_form_settings('id'),
            'form_name' => $record->get_form_settings('form_name'),
            'fields' => $record->get('fields'),
            'meta' => $record->get('meta'),
        );
        
        error_log('Priority Ticket Payment - Elementor Form Debug: ' . print_r($debug_data, true));
    }
    
    /**
     * Validate field mapping
     */
    public static function validate_field_mapping($fields) {
        $required_fields = array('name', 'email');
        $missing_fields = array();
        
        foreach ($required_fields as $required_field) {
            $found = false;
            
            foreach ($fields as $field) {
                $field_name = strtolower($field['title']);
                if (strpos($field_name, $required_field) !== false) {
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $missing_fields[] = $required_field;
            }
        }
        
        return array(
            'valid' => empty($missing_fields),
            'missing_fields' => $missing_fields,
        );
    }
    
    /**
     * Generate test data for development
     */
    public static function generate_test_data() {
        return array(
            'name' => array(
                'id' => 'name',
                'title' => 'Name',
                'value' => 'John Doe',
                'type' => 'text',
            ),
            'email' => array(
                'id' => 'email',
                'title' => 'Email',
                'value' => 'john@example.com',
                'type' => 'email',
            ),
            'phone' => array(
                'id' => 'phone',
                'title' => 'Phone',
                'value' => '+1-555-123-4567',
                'type' => 'tel',
            ),
            'urgency' => array(
                'id' => 'urgency',
                'title' => 'Urgency',
                'value' => 'High',
                'type' => 'select',
            ),
            'message' => array(
                'id' => 'message',
                'title' => 'Message',
                'value' => 'This is a test priority support request.',
                'type' => 'textarea',
            ),
        );
    }
    
    /**
     * Get priority tickets upload directory info
     */
    public static function get_upload_directory_info() {
        $wp_upload_dir = wp_upload_dir();
        $priority_tickets_dir = $wp_upload_dir['basedir'] . '/priority-tickets';
        $priority_tickets_url = $wp_upload_dir['baseurl'] . '/priority-tickets';
        
        return array(
            'path' => $priority_tickets_dir,
            'url' => $priority_tickets_url,
            'exists' => file_exists($priority_tickets_dir),
            'writable' => is_writable($priority_tickets_dir),
            'file_count' => file_exists($priority_tickets_dir) ? count(glob($priority_tickets_dir . '/*')) : 0,
        );
    }
    
    /**
     * Clean up old attachment files (older than specified days)
     */
    public static function cleanup_old_files($days_old = 30) {
        $upload_info = self::get_upload_directory_info();
        
        if (!$upload_info['exists']) {
            return array(
                'success' => false,
                'message' => 'Upload directory does not exist'
            );
        }
        
        $files = glob($upload_info['path'] . '/*');
        $deleted_count = 0;
        $cutoff_time = time() - ($days_old * 24 * 60 * 60);
        
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoff_time) {
                // Check if file is still referenced in database
                if (!self::is_file_referenced_in_db($file)) {
                    if (unlink($file)) {
                        $deleted_count++;
                    }
                }
            }
        }
        
        return array(
            'success' => true,
            'deleted_count' => $deleted_count,
            'message' => sprintf('Cleaned up %d old files', $deleted_count)
        );
    }
    
    /**
     * Check if file is still referenced in database
     */
    private static function is_file_referenced_in_db($file_path) {
        global $wpdb;
        
        $table_name = Priority_Ticket_Payment_Database::get_table_name();
        
        // Search for the file path in serialized attachments data
        $query = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE attachments LIKE %s",
            '%' . $wpdb->esc_like($file_path) . '%'
        );
        
        $count = $wpdb->get_var($query);
        
        return $count > 0;
    }
    
    /**
     * Get file size statistics for priority tickets directory
     */
    public static function get_file_size_stats() {
        $upload_info = self::get_upload_directory_info();
        
        if (!$upload_info['exists']) {
            return array(
                'total_size' => 0,
                'file_count' => 0,
                'avg_size' => 0,
                'largest_file' => null,
            );
        }
        
        $files = glob($upload_info['path'] . '/*');
        $total_size = 0;
        $largest_file = null;
        $largest_size = 0;
        
        foreach ($files as $file) {
            if (is_file($file)) {
                $size = filesize($file);
                $total_size += $size;
                
                if ($size > $largest_size) {
                    $largest_size = $size;
                    $largest_file = array(
                        'name' => basename($file),
                        'size' => $size,
                        'date' => filemtime($file),
                    );
                }
            }
        }
        
        $file_count = count($files);
        
        return array(
            'total_size' => $total_size,
            'file_count' => $file_count,
            'avg_size' => $file_count > 0 ? $total_size / $file_count : 0,
            'largest_file' => $largest_file,
            'total_size_formatted' => size_format($total_size),
            'avg_size_formatted' => $file_count > 0 ? size_format($total_size / $file_count) : '0 B',
        );
    }
    
    /**
     * Validate attachment data structure
     */
    public static function validate_attachment_data($attachment) {
        $required_fields = array('path', 'filename', 'original_name', 'size', 'type');
        
        foreach ($required_fields as $field) {
            if (!isset($attachment[$field]) || empty($attachment[$field])) {
                return array(
                    'valid' => false,
                    'error' => 'Missing required field: ' . $field
                );
            }
        }
        
        // Check if file exists
        if (!file_exists($attachment['path'])) {
            return array(
                'valid' => false,
                'error' => 'File does not exist: ' . $attachment['path']
            );
        }
        
        // Verify file size matches
        $actual_size = filesize($attachment['path']);
        if ($actual_size !== (int)$attachment['size']) {
            return array(
                'valid' => false,
                'error' => 'File size mismatch'
            );
        }
        
        return array('valid' => true);
    }
    
    /**
     * Get user ticket priority based on purchase history and coaching status
     *
     * @param int $user_id WordPress user ID
     * @return string 'B' (50€ for coaching clients), or 'C' (100€ for others)
     */
    public static function get_user_ticket_priority($user_id) {
        if (!$user_id) return 'C';

        // Get coaching product IDs from settings
        $coaching_ids = explode(',', Priority_Ticket_Payment::get_option('coaching_product_ids', ''));

        // Check if user has purchased coaching products -> Ticket B (50€)
        foreach ($coaching_ids as $pid) {
            if (wc_customer_bought_product('', $user_id, trim($pid))) {
                return 'B';
            }
        }

        // All other users (guest users or users with no qualifying purchases) get Ticket C (100€)
        return 'C';
    }
} 