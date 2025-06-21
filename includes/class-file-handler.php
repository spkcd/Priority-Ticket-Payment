<?php
/**
 * File Handler for Priority Ticket Payment
 * Handles secure file downloads with original filenames
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Priority_Ticket_Payment_File_Handler {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'handle_file_download'));
    }
    
    /**
     * Handle secure file downloads
     */
    public function handle_file_download() {
        // Check if this is a file download request
        if (!isset($_GET['ptp_download']) || !isset($_GET['file']) || !isset($_GET['token'])) {
            return;
        }
        
        // Verify nonce for security
        if (!wp_verify_nonce($_GET['token'], 'ptp_file_download')) {
            wp_die(__('Security check failed. Invalid download token.', 'priority-ticket-payment'), 'Access Denied', array('response' => 403));
        }
        
        $file_hash = sanitize_text_field($_GET['file']);
        
        // Get file information from database
        $file_info = $this->get_file_info_by_hash($file_hash);
        
        if (!$file_info) {
            wp_die(__('File not found or access denied.', 'priority-ticket-payment'), 'File Not Found', array('response' => 404));
        }
        
        // Check if file exists on disk
        if (!file_exists($file_info['path'])) {
            wp_die(__('File not found on server.', 'priority-ticket-payment'), 'File Not Found', array('response' => 404));
        }
        
        // Serve the file with original filename
        $this->serve_file($file_info['path'], $file_info['original_name'], $file_info['mime_type']);
    }
    
    /**
     * Get file information by hash
     */
    private function get_file_info_by_hash($file_hash) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'priority_ticket_submissions';
        
        // Search for the file in attachments data
        $submissions = $wpdb->get_results(
            "SELECT attachments FROM {$table_name} WHERE attachments IS NOT NULL AND attachments != ''"
        );
        
        foreach ($submissions as $submission) {
            $attachments = maybe_unserialize($submission->attachments);
            if (is_array($attachments)) {
                foreach ($attachments as $attachment) {
                    if (is_array($attachment) && isset($attachment['filename'])) {
                        // Create hash from filename for security
                        $hash = md5($attachment['filename'] . NONCE_SALT);
                        if ($hash === $file_hash) {
                            return array(
                                'path' => $attachment['path'],
                                'original_name' => $attachment['original_name'],
                                'mime_type' => isset($attachment['mime_type']) ? $attachment['mime_type'] : 'application/octet-stream'
                            );
                        }
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * Serve file with proper headers
     */
    private function serve_file($file_path, $original_name, $mime_type) {
        // Clean output buffer
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set headers
        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: attachment; filename="' . $original_name . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: private, must-revalidate');
        header('Pragma: private');
        header('Expires: 0');
        
        // Serve file
        readfile($file_path);
        exit;
    }
    
    /**
     * Generate secure download URL for a file
     */
    public static function generate_download_url($attachment) {
        if (!is_array($attachment) || !isset($attachment['filename'])) {
            return '';
        }
        
        // Create hash from filename for security
        $file_hash = md5($attachment['filename'] . NONCE_SALT);
        $token = wp_create_nonce('ptp_file_download');
        
        return add_query_arg(array(
            'ptp_download' => '1',
            'file' => $file_hash,
            'token' => $token
        ), home_url());
    }
}

// Initialize the file handler
new Priority_Ticket_Payment_File_Handler(); 