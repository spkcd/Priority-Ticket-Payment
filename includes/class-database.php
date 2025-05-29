<?php
/**
 * Database operations for Priority Ticket Payment
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Priority_Ticket_Payment_Database {
    
    /**
     * Get table name with WordPress prefix
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'priority_ticket_submissions';
    }
    
    /**
     * Insert a new priority ticket submission
     */
    public static function insert_submission($data) {
        global $wpdb;
        
        $table_name = self::get_table_name();
        
        $defaults = array(
            'user_id' => get_current_user_id(),
            'form_data' => '',
            'attachments' => '',
            'price' => 0.00,
            'payment_status' => 'pending',
            'order_id' => null,
            'token' => null,
            'awesome_support_ticket_id' => null,
        );
        
        $data = wp_parse_args($data, $defaults);
        
        // Serialize form_data and attachments if they are arrays
        if (is_array($data['form_data'])) {
            $data['form_data'] = serialize($data['form_data']);
        }
        
        if (is_array($data['attachments'])) {
            $data['attachments'] = serialize($data['attachments']);
        }
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'user_id' => $data['user_id'],
                'form_data' => $data['form_data'],
                'attachments' => $data['attachments'],
                'price' => $data['price'],
                'payment_status' => $data['payment_status'],
                'order_id' => $data['order_id'],
                'token' => $data['token'],
                'awesome_support_ticket_id' => $data['awesome_support_ticket_id'],
            ),
            array('%d', '%s', '%s', '%f', '%s', '%d', '%s', '%d')
        );
        
        if ($result === false) {
            return false;
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Update a priority ticket submission
     */
    public static function update_submission($id, $data) {
        global $wpdb;
        
        $table_name = self::get_table_name();
        
        // Serialize form_data and attachments if they are arrays
        if (isset($data['form_data']) && is_array($data['form_data'])) {
            $data['form_data'] = serialize($data['form_data']);
        }
        
        if (isset($data['attachments']) && is_array($data['attachments'])) {
            $data['attachments'] = serialize($data['attachments']);
        }
        
        $where = array('id' => $id);
        $where_format = array('%d');
        
        // Determine data types for formatting
        $data_format = array();
        foreach ($data as $key => $value) {
            switch ($key) {
                case 'user_id':
                case 'order_id':
                    $data_format[] = '%d';
                    break;
                case 'price':
                    $data_format[] = '%f';
                    break;
                default:
                    $data_format[] = '%s';
                    break;
            }
        }
        
        return $wpdb->update($table_name, $data, $where, $data_format, $where_format);
    }
    
    /**
     * Get a single submission by ID
     */
    public static function get_submission($id) {
        global $wpdb;
        
        $table_name = self::get_table_name();
        
        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id),
            ARRAY_A
        );
        
        if ($result) {
            // Unserialize form_data and attachments
            if (!empty($result['form_data'])) {
                $unserialized = @unserialize($result['form_data']);
                if ($unserialized !== false) {
                    $result['form_data'] = $unserialized;
                }
            }
            
            if (!empty($result['attachments'])) {
                $unserialized = @unserialize($result['attachments']);
                if ($unserialized !== false) {
                    $result['attachments'] = $unserialized;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Get submissions with optional filters
     */
    public static function get_submissions($args = array()) {
        global $wpdb;
        
        $table_name = self::get_table_name();
        
        $defaults = array(
            'user_id' => null,
            'payment_status' => null,
            'order_id' => null,
            'limit' => 20,
            'offset' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC',
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $where_clauses = array();
        $where_values = array();
        
        if (!is_null($args['user_id'])) {
            $where_clauses[] = 'user_id = %d';
            $where_values[] = $args['user_id'];
        }
        
        if (!is_null($args['payment_status'])) {
            $where_clauses[] = 'payment_status = %s';
            $where_values[] = $args['payment_status'];
        }
        
        if (!is_null($args['order_id'])) {
            $where_clauses[] = 'order_id = %d';
            $where_values[] = $args['order_id'];
        }
        
        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }
        
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        if (!$orderby) {
            $orderby = 'created_at DESC';
        }
        
        $limit_sql = '';
        if ($args['limit'] > 0) {
            $limit_sql = sprintf('LIMIT %d OFFSET %d', $args['limit'], $args['offset']);
        }
        
        $query = "SELECT * FROM $table_name $where_sql ORDER BY $orderby $limit_sql";
        
        if (!empty($where_values)) {
            $query = $wpdb->prepare($query, $where_values);
        }
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        // Unserialize form_data and attachments for all results
        foreach ($results as &$result) {
            if (!empty($result['form_data'])) {
                $unserialized = @unserialize($result['form_data']);
                if ($unserialized !== false) {
                    $result['form_data'] = $unserialized;
                }
            }
            
            if (!empty($result['attachments'])) {
                $unserialized = @unserialize($result['attachments']);
                if ($unserialized !== false) {
                    $result['attachments'] = $unserialized;
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Get submission count with optional filters
     */
    public static function get_submissions_count($args = array()) {
        global $wpdb;
        
        $table_name = self::get_table_name();
        
        $where_clauses = array();
        $where_values = array();
        
        if (isset($args['user_id']) && !is_null($args['user_id'])) {
            $where_clauses[] = 'user_id = %d';
            $where_values[] = $args['user_id'];
        }
        
        if (isset($args['payment_status']) && !is_null($args['payment_status'])) {
            $where_clauses[] = 'payment_status = %s';
            $where_values[] = $args['payment_status'];
        }
        
        if (isset($args['order_id']) && !is_null($args['order_id'])) {
            $where_clauses[] = 'order_id = %d';
            $where_values[] = $args['order_id'];
        }
        
        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }
        
        $query = "SELECT COUNT(*) FROM $table_name $where_sql";
        
        if (!empty($where_values)) {
            $query = $wpdb->prepare($query, $where_values);
        }
        
        return (int) $wpdb->get_var($query);
    }
    
    /**
     * Delete a submission
     */
    public static function delete_submission($id) {
        global $wpdb;
        
        $table_name = self::get_table_name();
        
        return $wpdb->delete(
            $table_name,
            array('id' => $id),
            array('%d')
        );
    }
    
    /**
     * Update payment status
     */
    public static function update_payment_status($id, $status, $order_id = null) {
        global $wpdb;
        
        $table_name = self::get_table_name();
        
        $data = array('payment_status' => $status);
        $format = array('%s');
        
        if (!is_null($order_id)) {
            $data['order_id'] = $order_id;
            $format[] = '%d';
        }
        
        return $wpdb->update(
            $table_name,
            $data,
            array('id' => $id),
            $format,
            array('%d')
        );
    }
    
    /**
     * Get submissions by payment status
     */
    public static function get_submissions_by_status($status) {
        return self::get_submissions(array('payment_status' => $status));
    }
    
    /**
     * Get submissions by user
     */
    public static function get_user_submissions($user_id) {
        return self::get_submissions(array('user_id' => $user_id));
    }
    
    /**
     * Get submission by order ID
     */
    public static function get_submission_by_order($order_id) {
        global $wpdb;
        
        $table_name = self::get_table_name();
        
        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE order_id = %d", $order_id),
            ARRAY_A
        );
        
        if ($result) {
            // Unserialize form_data and attachments
            if (!empty($result['form_data'])) {
                $unserialized = @unserialize($result['form_data']);
                if ($unserialized !== false) {
                    $result['form_data'] = $unserialized;
                }
            }
            
            if (!empty($result['attachments'])) {
                $unserialized = @unserialize($result['attachments']);
                if ($unserialized !== false) {
                    $result['attachments'] = $unserialized;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Get submission by token
     */
    public static function get_submission_by_token($token) {
        global $wpdb;
        
        $table_name = self::get_table_name();
        
        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE token = %s", $token),
            ARRAY_A
        );
        
        if ($result) {
            // Unserialize form_data and attachments
            if (!empty($result['form_data'])) {
                $unserialized = @unserialize($result['form_data']);
                if ($unserialized !== false) {
                    $result['form_data'] = $unserialized;
                }
            }
            
            if (!empty($result['attachments'])) {
                $unserialized = @unserialize($result['attachments']);
                if ($unserialized !== false) {
                    $result['attachments'] = $unserialized;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Update submission token
     */
    public static function update_submission_token($id, $token) {
        global $wpdb;
        
        $table_name = self::get_table_name();
        
        return $wpdb->update(
            $table_name,
            array('token' => $token),
            array('id' => $id),
            array('%s'),
            array('%d')
        );
    }
    
    /**
     * Get submission by Awesome Support ticket ID
     */
    public static function get_submission_by_ticket_id($ticket_id) {
        global $wpdb;
        
        $table_name = self::get_table_name();
        
        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE awesome_support_ticket_id = %d", $ticket_id),
            ARRAY_A
        );
        
        if ($result) {
            // Unserialize form_data and attachments
            if (!empty($result['form_data'])) {
                $unserialized = @unserialize($result['form_data']);
                if ($unserialized !== false) {
                    $result['form_data'] = $unserialized;
                }
            }
            
            if (!empty($result['attachments'])) {
                $unserialized = @unserialize($result['attachments']);
                if ($unserialized !== false) {
                    $result['attachments'] = $unserialized;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Update Awesome Support ticket ID
     */
    public static function update_ticket_id($id, $ticket_id) {
        global $wpdb;
        
        $table_name = self::get_table_name();
        
        return $wpdb->update(
            $table_name,
            array('awesome_support_ticket_id' => $ticket_id),
            array('id' => $id),
            array('%d'),
            array('%d')
        );
    }
    
    /**
     * Get old pending submissions for cleanup
     */
    public static function get_old_pending_submissions($hours_old = 48) {
        global $wpdb;
        
        $table_name = self::get_table_name();
        $cutoff_time = date('Y-m-d H:i:s', strtotime("-{$hours_old} hours"));
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, attachments FROM {$table_name} 
                 WHERE payment_status = 'pending_payment' 
                 AND created_at < %s",
                $cutoff_time
            ),
            ARRAY_A
        );
        
        // Unserialize attachments for each result
        foreach ($results as &$result) {
            if (!empty($result['attachments'])) {
                $unserialized = @unserialize($result['attachments']);
                if ($unserialized !== false) {
                    $result['attachments'] = $unserialized;
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Delete submissions by IDs
     */
    public static function delete_submissions_by_ids($ids) {
        if (empty($ids) || !is_array($ids)) {
            return 0;
        }
        
        global $wpdb;
        $table_name = self::get_table_name();
        
        // Sanitize IDs
        $ids = array_map('intval', $ids);
        $ids_placeholder = implode(',', array_fill(0, count($ids), '%d'));
        
        $deleted_count = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE id IN ({$ids_placeholder})",
                $ids
            )
        );
        
        return $deleted_count;
    }
    
    /**
     * Get cleanup statistics
     */
    public static function get_cleanup_stats() {
        global $wpdb;
        
        $table_name = self::get_table_name();
        
        // Count pending submissions older than 48 hours
        $old_pending = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} 
                 WHERE payment_status = 'pending_payment' 
                 AND created_at < %s",
                date('Y-m-d H:i:s', strtotime('-48 hours'))
            )
        );
        
        // Count all pending submissions
        $total_pending = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE payment_status = %s",
                'pending_payment'
            )
        );
        
        // Count completed submissions
        $completed = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE payment_status = %s",
                'completed'
            )
        );
        
        return array(
            'old_pending_submissions' => (int) $old_pending,
            'total_pending_submissions' => (int) $total_pending,
            'completed_submissions' => (int) $completed,
            'cleanup_eligible' => (int) $old_pending > 0,
        );
    }
} 