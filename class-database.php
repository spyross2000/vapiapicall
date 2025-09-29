<?php
/**
 * Enhanced Database operations for Vapi Call Logs Plugin
 * Version: 3.1.2 - Fixed audio storage with organization ID and name
 */

if (!defined('ABSPATH')) {
    exit;
}

class VapiCallLogs_Database {
    
    private $organizations_table;
    private $call_logs_table;
    private $billing_settings_table;
    
    public function __construct() {
        global $wpdb;
        $this->organizations_table = $wpdb->prefix . 'vapi_organizations';
        $this->call_logs_table = $wpdb->prefix . 'vapi_call_logs';
        $this->billing_settings_table = $wpdb->prefix . 'vapi_billing_settings';
        
        // Schedule cleanup cron job
        add_action('vapi_cleanup_old_logs', array($this, 'cleanup_old_logs'));
        if (!wp_next_scheduled('vapi_cleanup_old_logs')) {
            wp_schedule_event(time(), 'daily', 'vapi_cleanup_old_logs');
        }
    }
    
    /**
     * Get existing call IDs for an organization
     */
    public function get_existing_call_ids($organization_id) {
        $org = $this->get_organization($organization_id);
        if (!$org) {
            return array();
        }
        
        $db = $this->get_org_db_connection($organization_id);
        $table_name = $org->use_separate_db ? 'vapi_calls_org_' . $organization_id : $this->call_logs_table;
        
        $where = "";
        $where_values = array();
        
        if (!$org->use_separate_db) {
            $where = "WHERE organization_id = %d";
            $where_values[] = $organization_id;
        }
        
        $sql = "SELECT call_id FROM $table_name $where";
        
        if (!empty($where_values)) {
            $sql = $db->prepare($sql, $where_values);
        }
        
        $results = $db->get_col($sql);
        
        return $results ?: array();
    }
    
    /**
     * Get a specific call by ID
     */
    public function get_call_by_id($organization_id, $call_id) {
        $org = $this->get_organization($organization_id);
        if (!$org) {
            return null;
        }
        
        $db = $this->get_org_db_connection($organization_id);
        $table_name = $org->use_separate_db ? 'vapi_calls_org_' . $organization_id : $this->call_logs_table;
        
        $where_clauses = array("call_id = %s");
        $where_values = array($call_id);
        
        if (!$org->use_separate_db) {
            $where_clauses[] = "organization_id = %d";
            $where_values[] = $organization_id;
        }
        
        $where = "WHERE " . implode(" AND ", $where_clauses);
        $sql = "SELECT * FROM $table_name $where LIMIT 1";
        $sql = $db->prepare($sql, $where_values);
        
        return $db->get_row($sql);
    }
    
    /**
     * Update an existing call log
     */
    public function update_call_log($organization_id, $call) {
        $org = $this->get_organization($organization_id);
        if (!$org) {
            return false;
        }
        
        $db = $this->get_org_db_connection($organization_id);
        $table_name = $org->use_separate_db ? 'vapi_calls_org_' . $organization_id : $this->call_logs_table;
        
        $duration = 0;
        
        // Προτεραιότητα: duration από API, μετά υπολογισμός από timestamps
        if (isset($call['duration'])) {
            // Το Vapi API δίνει το duration σε milliseconds
            // Αν η τιμή είναι πολύ μεγάλη (>3600), πιθανώς είναι σε milliseconds
            $api_duration = intval($call['duration']);
            if ($api_duration > 3600) {
                // Μετατροπή από milliseconds σε seconds
                $duration = intval($api_duration / 1000);
            } else {
                // Ήδη σε seconds
                $duration = $api_duration;
            }
        } elseif (isset($call['endedAt']) && isset($call['createdAt'])) {
            // Υπολογισμός από timestamps
            $duration = strtotime($call['endedAt']) - strtotime($call['createdAt']);
        }
        
        // Έλεγχος λογικότητας - αν το duration είναι πάνω από 24 ώρες, κάτι πάει λάθος
        if ($duration > 86400) {
            error_log('VAPI: Suspicious duration detected for call ' . $call['id'] . ': ' . $duration . ' seconds. Using timestamp calculation.');
            if (isset($call['endedAt']) && isset($call['createdAt'])) {
                $duration = strtotime($call['endedAt']) - strtotime($call['createdAt']);
            } else {
                $duration = 0;
            }
        }
        
        $cost = isset($call['cost']) ? floatval($call['cost']) : 0;
        
        $data = array(
            'phone_number' => $call['customer']['number'] ?? '',
            'duration' => $duration,
            'status' => $call['status'],
            'cost' => $cost,
            'recording_url' => $call['recordingUrl'] ?? '',
            'transcript' => isset($call['transcript']) ? json_encode($call['transcript']) : '',
            'messages' => isset($call['messages']) ? json_encode($call['messages']) : '',
            'call_data' => json_encode($call),
            'updated_at' => current_time('mysql')
        );
        
        $where = array('call_id' => $call['id']);
        
        if (!$org->use_separate_db) {
            $where['organization_id'] = $organization_id;
        }
        
        $result = $db->update($table_name, $data, $where);
        
        return $result !== false;
    }
    
    /**
     * Update the audio path for a call
     */
    public function update_call_audio_path($organization_id, $call_id, $audio_path) {
        $org = $this->get_organization($organization_id);
        if (!$org) {
            return false;
        }
        
        $db = $this->get_org_db_connection($organization_id);
        $table_name = $org->use_separate_db ? 'vapi_calls_org_' . $organization_id : $this->call_logs_table;
        
        $data = array(
            'local_audio_path' => $audio_path,
            'updated_at' => current_time('mysql')
        );
        
        $where = array('call_id' => $call_id);
        
        if (!$org->use_separate_db) {
            $where['organization_id'] = $organization_id;
        }
        
        $result = $db->update($table_name, $data, $where);
        
        return $result !== false;
    }
    
    /**
     * Get stored call logs from database only
     */
    public function get_stored_call_logs($organization_id, $filters = array()) {
        $org = $this->get_organization($organization_id);
        if (!$org) {
            return array();
        }
        
        $db = $this->get_org_db_connection($organization_id);
        $table_name = $org->use_separate_db ? 'vapi_calls_org_' . $organization_id : $this->call_logs_table;
        
        // Build WHERE clauses
        $where_clauses = array();
        $where_values = array();
        
        if (!$org->use_separate_db) {
            $where_clauses[] = "organization_id = %d";
            $where_values[] = $organization_id;
        }
        
        if (!empty($filters['status_filter'])) {
            $where_clauses[] = "status = %s";
            $where_values[] = $filters['status_filter'];
        }
        
        if (!empty($filters['date_from'])) {
            $where_clauses[] = "created_at >= %s";
            $where_values[] = $filters['date_from'] . ' 00:00:00';
        }
        
        if (!empty($filters['date_to'])) {
            $where_clauses[] = "created_at <= %s";
            $where_values[] = $filters['date_to'] . ' 23:59:59';
        }
        
        if (!empty($filters['phone_search'])) {
            $where_clauses[] = "phone_number LIKE %s";
            $where_values[] = '%' . $db->esc_like($filters['phone_search']) . '%';
        }
        
        $where = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
        
        $sql = "SELECT * FROM $table_name $where ORDER BY created_at DESC LIMIT 1000";
        
        if (!empty($where_values)) {
            $sql = $db->prepare($sql, $where_values);
        }
        
        $results = $db->get_results($sql);
        
        return $results ?: array();
    }
    
    /**
     * Update last sync time
     */
    public function update_last_sync($organization_id) {
        global $wpdb;
        
        return $wpdb->update(
            $this->organizations_table,
            array('last_sync' => current_time('mysql')),
            array('id' => $organization_id)
        );
    }
    
    /**
     * Get database connection for organization
     */
    private function get_org_db_connection($organization_id) {
        global $wpdb;
        
        $org = $this->get_organization($organization_id);
        
        if (!$org || !$org->use_separate_db) {
            return $wpdb;
        }
        
        // Create connection without port concatenation
        $custom_db = new wpdb(
            $org->db_user,
            $org->db_password,
            $org->db_name,
            $org->db_host  // Just the host, no port
        );
        
        // Check for connection errors properly
        if ($custom_db->error) {
            $error_msg = 'VAPI DB Error for Org ' . $organization_id . ': ';
            if (is_wp_error($custom_db->error)) {
                $error_msg .= $custom_db->error->get_error_message();
            } else if (is_string($custom_db->error)) {
                $error_msg .= $custom_db->error;
            } else {
                $error_msg .= 'Unknown connection error';
            }
            error_log($error_msg);
            return $wpdb;
        }
        
        if (!$org->db_table_created) {
            $this->setup_org_database($organization_id, $custom_db);
        }
        
        return $custom_db;
    }
    
    /**
     * Setup organization database
     */
    public function setup_org_database($organization_id, $db_connection = null) {
        global $wpdb;
        
        $org = $this->get_organization($organization_id);
        if (!$org || !$org->use_separate_db) {
            return array('success' => false, 'message' => 'Organization not using separate database');
        }
        
        if (!$db_connection) {
            // Create connection without port concatenation
            $db_connection = new wpdb(
                $org->db_user,
                $org->db_password,
                $org->db_name,
                $org->db_host  // Just the host, no port
            );
            
            if ($db_connection->error) {
                $error_msg = 'Database connection failed: ';
                if (is_wp_error($db_connection->error)) {
                    $error_msg .= $db_connection->error->get_error_message();
                } else if (is_string($db_connection->error)) {
                    $error_msg .= $db_connection->error;
                } else {
                    $error_msg .= 'Unknown connection error';
                }
                return array('success' => false, 'message' => $error_msg);
            }
        }
        
        $table_name = 'vapi_calls_org_' . $organization_id;
        $charset_collate = $db_connection->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            call_id varchar(100) NOT NULL,
            phone_number varchar(20),
            duration int(11),
            status varchar(50),
            cost decimal(10,4),
            recording_url text,
            local_audio_path text,
            transcript longtext,
            messages longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            call_data longtext,
            PRIMARY KEY (id),
            UNIQUE KEY call_id (call_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        $result = $db_connection->query($sql);
        
        if ($result !== false) {
            $wpdb->update(
                $this->organizations_table,
                array('db_table_created' => 1),
                array('id' => $organization_id)
            );
            
            return array(
                'success' => true,
                'message' => 'Database prepared successfully! Table created: ' . $table_name
            );
        }
        
        return array(
            'success' => false,
            'message' => 'Failed to create table: ' . $db_connection->last_error
        );
    }
    
    /**
     * Create database tables
     */
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Organizations table with delete_after_import and last_sync fields
        $sql1 = "CREATE TABLE {$this->organizations_table} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(200) NOT NULL,
            api_key text NOT NULL,
            description text,
            is_active tinyint(1) DEFAULT 1,
            use_separate_db tinyint(1) DEFAULT 0,
            db_host varchar(255) DEFAULT NULL,
            db_name varchar(255) DEFAULT NULL,
            db_user varchar(255) DEFAULT NULL,
            db_password text DEFAULT NULL,
            db_port int DEFAULT 3306,
            db_table_created tinyint(1) DEFAULT 0,
            retention_days int DEFAULT 30,
            delete_after_import tinyint(1) DEFAULT 0,
            last_sync datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY name (name)
        ) $charset_collate;";
        
        // Call logs table with local_audio_path field
        $sql2 = "CREATE TABLE {$this->call_logs_table} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            organization_id mediumint(9) NOT NULL,
            call_id varchar(100) NOT NULL,
            phone_number varchar(20),
            duration int(11),
            status varchar(50),
            cost decimal(10,4),
            recording_url text,
            local_audio_path text,
            transcript longtext,
            messages longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            call_data longtext,
            PRIMARY KEY (id),
            UNIQUE KEY call_org_unique (call_id, organization_id),
            KEY organization_id (organization_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Billing settings table
        $sql3 = "CREATE TABLE {$this->billing_settings_table} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            organization_id mediumint(9) NOT NULL,
            cost_per_minute decimal(10,4) DEFAULT 0.02,
            billing_rate_per_minute decimal(10,4) DEFAULT 0.05,
            currency varchar(3) DEFAULT 'EUR',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY org_unique (organization_id),
            KEY organization_id (organization_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
        
        // Create audio storage directory
        $this->create_audio_directory();
        
        // Create default organization if none exists
        $this->create_default_organization();
    }
    
    /**
     * Create audio storage directory
     */
    private function create_audio_directory() {
        $upload_dir = wp_upload_dir();
        $audio_dir = $upload_dir['basedir'] . '/vapi-call-recordings';
        
        if (!file_exists($audio_dir)) {
            wp_mkdir_p($audio_dir);
            
            // Add .htaccess for security but allow direct access
            $htaccess_content = "Options -Indexes\n";
            $htaccess_content .= "<FilesMatch '\.(wav|mp3|mp4|ogg)$'>\n";
            $htaccess_content .= "    Order Allow,Deny\n";
            $htaccess_content .= "    Allow from all\n";
            $htaccess_content .= "</FilesMatch>\n";
            file_put_contents($audio_dir . '/.htaccess', $htaccess_content);
        }
        
        return $audio_dir;
    }
    
    /**
     * Download and store audio file with organization name in filename
     */
    public function download_and_store_audio($recording_url, $call_id, $organization_id) {
        if (empty($recording_url)) {
            error_log('VAPI: Empty recording URL for call ' . $call_id);
            return null;
        }
        
        // Get organization info
        $org = $this->get_organization($organization_id);
        if (!$org) {
            error_log('VAPI: Organization not found for ID ' . $organization_id);
            return null;
        }
        
        // Clean organization name for filename (remove special characters)
        $org_name_clean = preg_replace('/[^a-zA-Z0-9_-]/', '_', $org->name);
        $org_name_clean = trim($org_name_clean, '_');
        if (empty($org_name_clean)) {
            $org_name_clean = 'org_' . $organization_id;
        }
        
        // Use WordPress uploads directory with our subfolder
        $upload_dir = wp_upload_dir();
        $audio_base_dir = $upload_dir['basedir'] . '/vapi-call-recordings';
        
        // Create organization-specific folder
        $org_folder = $audio_base_dir . '/' . $org_name_clean;
        
        if (!file_exists($org_folder)) {
            wp_mkdir_p($org_folder);
        }
        
        // Generate filename: callID.extension
        $extension = 'wav'; // Default to WAV
        $parsed_url = parse_url($recording_url);
        if (isset($parsed_url['path'])) {
            $path_info = pathinfo($parsed_url['path']);
            if (isset($path_info['extension'])) {
                $extension = strtolower($path_info['extension']);
            }
        }
        
        $filename = $call_id . '.' . $extension;
        $local_path = $org_folder . '/' . $filename;
        $relative_path = 'vapi-call-recordings/' . $org_name_clean . '/' . $filename;
        
        // Check if file already exists
        if (file_exists($local_path)) {
            error_log('VAPI: Audio file already exists: ' . $relative_path);
            return $relative_path;
        }
        
        error_log('VAPI: Starting audio download for call ' . $call_id . ' to organization folder: ' . $org_name_clean);
        
        // Download the file with increased timeout
        $response = wp_remote_get($recording_url, array(
            'timeout' => 120, // 2 minutes timeout for large audio files
            'sslverify' => false,
            'user-agent' => 'WordPress/Vapi-Plugin'
        ));
        
        if (is_wp_error($response)) {
            error_log('VAPI: Failed to download audio for call ' . $call_id . ': ' . $response->get_error_message());
            return null;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            error_log('VAPI: HTTP error downloading audio for call ' . $call_id . ': ' . $response_code);
            return null;
        }
        
        $audio_content = wp_remote_retrieve_body($response);
        if (empty($audio_content)) {
            error_log('VAPI: Empty audio content for call ' . $call_id);
            return null;
        }
        
        // Check file size
        $content_length = strlen($audio_content);
        error_log('VAPI: Downloaded audio content size: ' . number_format($content_length) . ' bytes for call ' . $call_id);
        
        if ($content_length < 1000) { // Less than 1KB is suspicious
            error_log('VAPI: Audio file too small for call ' . $call_id . ': ' . $content_length . ' bytes');
            return null;
        }
        
        // Save the file
        $result = file_put_contents($local_path, $audio_content);
        if ($result === false) {
            error_log('VAPI: Failed to save audio file for call ' . $call_id . ' to ' . $local_path);
            return null;
        }
        
        // Verify file was saved correctly
        if (!file_exists($local_path) || filesize($local_path) !== $content_length) {
            error_log('VAPI: File verification failed for call ' . $call_id);
            return null;
        }
        
        error_log('VAPI: Successfully downloaded and saved audio for call ' . $call_id . ' to ' . $relative_path . ' (' . number_format($result) . ' bytes)');
        return $relative_path;
    }
    
    /**
     * Get audio file URL from relative path
     */
    public function get_audio_url($relative_path) {
        if (empty($relative_path)) {
            return null;
        }
        
        $upload_dir = wp_upload_dir();
        $full_url = $upload_dir['baseurl'] . '/' . $relative_path;
        
        // Verify file exists before returning URL
        $full_path = $upload_dir['basedir'] . '/' . $relative_path;
        if (!file_exists($full_path)) {
            error_log('VAPI: Audio file not found: ' . $full_path);
            return null;
        }
        
        return $full_url;
    }
    
    /**
     * Store single call log with audio - FIXED VERSION
     * Uses INSERT instead of REPLACE to preserve existing records
     */
    public function store_single_call_log($organization_id, $call, $local_audio_path = null) {
        $org = $this->get_organization($organization_id);
        if (!$org) {
            return false;
        }
        
        $db = $this->get_org_db_connection($organization_id);
        $table_name = $org->use_separate_db ? 'vapi_calls_org_' . $organization_id : $this->call_logs_table;
        
        // First check if the call already exists
        $existing = $this->get_call_by_id($organization_id, $call['id']);
        if ($existing) {
            // Call already exists, update it instead
            return $this->update_call_log($organization_id, $call);
        }
        
        $duration = 0;
        
        // Προτεραιότητα: duration από API, μετά υπολογισμός από timestamps
        if (isset($call['duration'])) {
            // Το Vapi API δίνει το duration σε milliseconds
            // Αν η τιμή είναι πολύ μεγάλη (>3600), πιθανώς είναι σε milliseconds
            $api_duration = intval($call['duration']);
            if ($api_duration > 3600) {
                // Μετατροπή από milliseconds σε seconds
                $duration = intval($api_duration / 1000);
            } else {
                // Ήδη σε seconds
                $duration = $api_duration;
            }
        } elseif (isset($call['endedAt']) && isset($call['createdAt'])) {
            // Υπολογισμός από timestamps
            $duration = strtotime($call['endedAt']) - strtotime($call['createdAt']);
        }
        
        // Έλεγχος λογικότητας - αν το duration είναι πάνω από 24 ώρες, κάτι πάει λάθος
        if ($duration > 86400) {
            error_log('VAPI: Suspicious duration detected for call ' . $call['id'] . ': ' . $duration . ' seconds. Using timestamp calculation.');
            if (isset($call['endedAt']) && isset($call['createdAt'])) {
                $duration = strtotime($call['endedAt']) - strtotime($call['createdAt']);
            } else {
                $duration = 0;
            }
        }
        
        $cost = isset($call['cost']) ? floatval($call['cost']) : 0;
        
        $data = array(
            'call_id' => $call['id'],
            'phone_number' => $call['customer']['number'] ?? '',
            'duration' => $duration,  // Duration σε seconds
            'status' => $call['status'],
            'cost' => $cost,
            'recording_url' => $call['recordingUrl'] ?? '',
            'local_audio_path' => $local_audio_path,
            'transcript' => isset($call['transcript']) ? json_encode($call['transcript']) : '',
            'messages' => isset($call['messages']) ? json_encode($call['messages']) : '',
            'created_at' => $call['createdAt'],
            'call_data' => json_encode($call)
        );
        
        // Add organization_id only for default database
        if (!$org->use_separate_db) {
            $data['organization_id'] = $organization_id;
        }
        
        // Use INSERT instead of REPLACE to preserve existing records
        $result = $db->insert($table_name, $data);
        
        // If insert failed due to duplicate key, that's okay (call already exists)
        if ($result === false && $db->last_error && strpos($db->last_error, 'Duplicate entry') !== false) {
            // This is not an error - the call already exists
            return true;
        }
        
        return $result !== false;
    }
    
    /**
     * Get organization by ID
     */
    public function get_organization($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->organizations_table} WHERE id = %d",
            $id
        ));
    }
    
    /**
     * Get all organizations
     */
    public function get_organizations($active_only = false) {
        global $wpdb;
        
        $where = $active_only ? "WHERE is_active = 1" : "";
        return $wpdb->get_results("SELECT * FROM {$this->organizations_table} $where ORDER BY name ASC");
    }
    
    /**
     * Add organization with simplified settings (no retention/sync options)
     */
    public function add_organization($name, $api_key, $description = '', $storage_settings = array()) {
        global $wpdb;
        
        $data = array(
            'name' => $name,
            'api_key' => $api_key,
            'description' => $description,
            'use_separate_db' => $storage_settings['use_separate_db'] ?? 0,
            'is_active' => 1  // Ensure new organizations are active by default
        );
        
        if (!empty($storage_settings['use_separate_db'])) {
            $data['db_host'] = $storage_settings['db_host'] ?? '';
            $data['db_port'] = $storage_settings['db_port'] ?? 3306;
            $data['db_name'] = $storage_settings['db_name'] ?? '';
            $data['db_user'] = $storage_settings['db_user'] ?? '';
            $data['db_password'] = $storage_settings['db_password'] ?? '';
        }
        
        $result = $wpdb->insert($this->organizations_table, $data);
        
        return $result !== false ? $wpdb->insert_id : false;
    }
    
    /**
     * Update organization with simplified settings (no retention/sync options)
     */
    public function update_organization($id, $name, $api_key, $description = '', $storage_settings = array()) {
        global $wpdb;
        
        // Get current organization data first
        $current_org = $this->get_organization($id);
        if (!$current_org) {
            return false;
        }
        
        $data = array(
            'name' => $name,
            'api_key' => $api_key,
            'description' => $description,
            'use_separate_db' => $storage_settings['use_separate_db'] ?? 0
        );
        
        if (!empty($storage_settings['use_separate_db'])) {
            $data['db_host'] = $storage_settings['db_host'] ?? '';
            $data['db_port'] = $storage_settings['db_port'] ?? 3306;
            $data['db_name'] = $storage_settings['db_name'] ?? '';
            $data['db_user'] = $storage_settings['db_user'] ?? '';
            
            // Handle password field - if empty, keep existing password
            if (!empty($storage_settings['db_password'])) {
                $data['db_password'] = $storage_settings['db_password'];
            } else {
                // Keep existing password
                $data['db_password'] = $current_org->db_password;
            }
        }
        
        return $wpdb->update($this->organizations_table, $data, array('id' => $id));
    }
    
    /**
     * Delete organization
     */
    public function delete_organization($id) {
        global $wpdb;
        
        // Get organization info before deletion for cleanup
        $org = $this->get_organization($id);
        if ($org) {
            // Clean up audio files
            if ($org->use_separate_db) {
                $db = $this->get_org_db_connection($id);
                $table_name = 'vapi_calls_org_' . $id;
                $audio_files = $db->get_col("SELECT local_audio_path FROM $table_name WHERE local_audio_path IS NOT NULL");
            } else {
                $audio_files = $wpdb->get_col($wpdb->prepare(
                    "SELECT local_audio_path FROM {$this->call_logs_table} WHERE organization_id = %d AND local_audio_path IS NOT NULL",
                    $id
                ));
            }
            
            // Delete audio files
            foreach ($audio_files as $audio_path) {
                $this->delete_audio_file($audio_path);
            }
            
            // Delete organization folder if empty
            $upload_dir = wp_upload_dir();
            $org_name_clean = preg_replace('/[^a-zA-Z0-9_-]/', '_', $org->name);
            $org_name_clean = trim($org_name_clean, '_');
            $org_folder = $upload_dir['basedir'] . '/vapi-call-recordings/' . $org_name_clean;
            if (is_dir($org_folder) && count(scandir($org_folder)) == 2) { // Only . and ..
                rmdir($org_folder);
            }
        }
        
        // Delete call logs first
        $wpdb->delete($this->call_logs_table, array('organization_id' => $id));
        
        // Delete organization
        return $wpdb->delete($this->organizations_table, array('id' => $id));
    }
    
    /**
     * Create default organization if none exists
     */
    private function create_default_organization() {
        global $wpdb;
        
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->organizations_table}");
        
        if ($count == 0) {
            $this->add_organization(
                'Default Organization',
                '',
                'Default organization created automatically'
            );
        }
    }
    
    /**
     * Check if tables exist
     */
    public function tables_exist() {
        global $wpdb;
        
        $org_table = $wpdb->get_var("SHOW TABLES LIKE '{$this->organizations_table}'");
        $log_table = $wpdb->get_var("SHOW TABLES LIKE '{$this->call_logs_table}'");
        
        return $org_table && $log_table;
    }
    
    /**
     * Cleanup old logs based on global retention policy
     */
    public function cleanup_old_logs() {
        global $wpdb;
        
        $global_retention = get_option('vapi_global_retention_days', 30);
        
        if ($global_retention <= 0) {
            return; // No cleanup if retention is 0 (forever)
        }
        
        $organizations = $this->get_organizations();
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$global_retention} days"));
        
        foreach ($organizations as $org) {
            if ($org->use_separate_db) {
                $db = $this->get_org_db_connection($org->id);
                $table_name = 'vapi_calls_org_' . $org->id;
                
                // Also cleanup audio files
                $old_calls = $db->get_results($db->prepare(
                    "SELECT local_audio_path FROM $table_name WHERE created_at < %s AND local_audio_path IS NOT NULL",
                    $cutoff_date
                ));
                
                foreach ($old_calls as $call) {
                    $this->delete_audio_file($call->local_audio_path);
                }
                
                $db->query($db->prepare(
                    "DELETE FROM $table_name WHERE created_at < %s",
                    $cutoff_date
                ));
            } else {
                // Get old calls with audio files
                $old_calls = $wpdb->get_results($wpdb->prepare(
                    "SELECT local_audio_path FROM {$this->call_logs_table} 
                     WHERE organization_id = %d AND created_at < %s AND local_audio_path IS NOT NULL",
                    $org->id,
                    $cutoff_date
                ));
                
                foreach ($old_calls as $call) {
                    $this->delete_audio_file($call->local_audio_path);
                }
                
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$this->call_logs_table} WHERE organization_id = %d AND created_at < %s",
                    $org->id,
                    $cutoff_date
                ));
            }
        }
    }
    
    /**
     * Delete audio file from filesystem
     */
    private function delete_audio_file($relative_path) {
        if (empty($relative_path)) {
            return;
        }
        
        $upload_dir = wp_upload_dir();
        $full_path = $upload_dir['basedir'] . '/' . $relative_path;
        
        if (file_exists($full_path)) {
            unlink($full_path);
            error_log('VAPI: Deleted old audio file: ' . $relative_path);
        }
    }
    
    /**
     * Get billing data for organizations (including separate databases)
     */
    public function get_billing_data($organization_id = null, $month = null, $year = null) {
        global $wpdb;
        
        $all_billing_data = array();
        
        // If specific organization requested
        if ($organization_id) {
            $org = $this->get_organization($organization_id);
            if (!$org) {
                return array();
            }
            
            $billing_data = $this->get_billing_data_for_org($org, $month, $year);
            if ($billing_data) {
                $all_billing_data[] = $billing_data;
            }
        } else {
            // Get all organizations
            $organizations = $this->get_organizations();
            
            foreach ($organizations as $org) {
                $billing_data = $this->get_billing_data_for_org($org, $month, $year);
                if ($billing_data) {
                    $all_billing_data[] = $billing_data;
                }
            }
        }
        
        return $all_billing_data;
    }
    
    /**
     * Get billing data for a specific organization
     */
    private function get_billing_data_for_org($org, $month = null, $year = null) {
        global $wpdb;
        
        // Get the appropriate database connection
        $db = $this->get_org_db_connection($org->id);
        if (!$db) {
            $db = $wpdb;
        }
        
        // Determine the table name
        $table_name = $org->use_separate_db ? 'vapi_calls_org_' . $org->id : $this->call_logs_table;
        
        // Build WHERE clauses
        $where_clauses = array();
        $where_values = array();
        
        // For default database, filter by organization_id
        if (!$org->use_separate_db) {
            $where_clauses[] = "organization_id = %d";
            $where_values[] = $org->id;
        }
        
        if ($month && $year) {
            $where_clauses[] = "MONTH(created_at) = %d";
            $where_values[] = $month;
            $where_clauses[] = "YEAR(created_at) = %d";
            $where_values[] = $year;
        } elseif ($year) {
            $where_clauses[] = "YEAR(created_at) = %d";
            $where_values[] = $year;
        } else {
            // Default to current month if no period specified
            $where_clauses[] = "MONTH(created_at) = %d";
            $where_values[] = date('n');
            $where_clauses[] = "YEAR(created_at) = %d";
            $where_values[] = date('Y');
        }
        
        $where = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
        
        // Query for aggregated data
        $sql = "SELECT 
                COUNT(id) as total_calls,
                SUM(duration) as total_seconds,
                ROUND(SUM(duration) / 60, 2) as total_minutes,
                SUM(cost) as actual_cost,
                YEAR(created_at) as year,
                MONTH(created_at) as month
            FROM $table_name
            $where
            GROUP BY YEAR(created_at), MONTH(created_at)";
        
        if (!empty($where_values)) {
            $sql = $db->prepare($sql, $where_values);
        }
        
        $result = $db->get_row($sql);
        
        if (!$result || $result->total_calls == 0) {
            return null;
        }
        
        // Get billing settings
        $billing_settings = $this->get_billing_settings($org->id);
        
        // Add organization info to result
        $result->organization_id = $org->id;
        $result->organization_name = $org->name;
        $result->cost_per_minute = $billing_settings->cost_per_minute;
        $result->billing_rate_per_minute = $billing_settings->billing_rate_per_minute;
        $result->currency = $billing_settings->currency;
        
        return $result;
    }
    
    /**
     * Export billing data to CSV (including separate databases)
     */
    public function export_billing_csv($organization_id = null, $start_date = null, $end_date = null) {
        global $wpdb;
        
        $all_results = array();
        
        // If specific organization requested
        if ($organization_id) {
            $org = $this->get_organization($organization_id);
            if (!$org) {
                return array();
            }
            
            $results = $this->export_billing_csv_for_org($org, $start_date, $end_date);
            if (!empty($results)) {
                $all_results = array_merge($all_results, $results);
            }
        } else {
            // Get all organizations
            $organizations = $this->get_organizations();
            
            foreach ($organizations as $org) {
                $results = $this->export_billing_csv_for_org($org, $start_date, $end_date);
                if (!empty($results)) {
                    $all_results = array_merge($all_results, $results);
                }
            }
        }
        
        return $all_results;
    }
    
    /**
     * Export billing CSV data for a specific organization
     */
    private function export_billing_csv_for_org($org, $start_date = null, $end_date = null) {
        global $wpdb;
        
        // Get the appropriate database connection
        $db = $this->get_org_db_connection($org->id);
        if (!$db) {
            $db = $wpdb;
        }
        
        // Determine the table name
        $table_name = $org->use_separate_db ? 'vapi_calls_org_' . $org->id : $this->call_logs_table;
        
        // Get billing settings
        $billing_settings = $this->get_billing_settings($org->id);
        
        // Build WHERE clauses
        $where_clauses = array();
        $where_values = array();
        
        // For default database, filter by organization_id
        if (!$org->use_separate_db) {
            $where_clauses[] = "organization_id = %d";
            $where_values[] = $org->id;
        }
        
        if ($start_date && $end_date) {
            $where_clauses[] = "created_at BETWEEN %s AND %s";
            $where_values[] = $start_date . ' 00:00:00';
            $where_values[] = $end_date . ' 23:59:59';
        }
        
        $where = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
        
        $sql = "SELECT 
                '" . esc_sql($org->name) . "' as 'Organization',
                CONCAT(MONTHNAME(created_at), ' ', YEAR(created_at)) as 'Month',
                COUNT(id) as 'Total Calls',
                ROUND(SUM(duration) / 60, 2) as 'Total Minutes',
                ROUND(SUM(cost), 2) as 'Actual Cost (EUR)',
                " . floatval($billing_settings->billing_rate_per_minute) . " as 'Billing Rate/Min',
                ROUND(ROUND(SUM(duration) / 60, 2) * " . floatval($billing_settings->billing_rate_per_minute) . ", 2) as 'Total Billing (EUR)'
            FROM $table_name
            $where
            GROUP BY MONTH(created_at), YEAR(created_at)
            ORDER BY YEAR(created_at) DESC, MONTH(created_at) DESC";
        
        if (!empty($where_values)) {
            $sql = $db->prepare($sql, $where_values);
        }
        
        $results = $db->get_results($sql, ARRAY_A);
        
        return $results ?: array();
    }
    
    /**
     * Get or create billing settings for organization
     */
    public function get_billing_settings($organization_id) {
        global $wpdb;
        
        $settings = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->billing_settings_table} WHERE organization_id = %d",
            $organization_id
        ));
        
        // If no settings exist, create default
        if (!$settings) {
            $wpdb->insert($this->billing_settings_table, array(
                'organization_id' => $organization_id,
                'cost_per_minute' => 0.02,
                'billing_rate_per_minute' => 0.05,
                'currency' => 'EUR'
            ));
            
            return $this->get_billing_settings($organization_id);
        }
        
        return $settings;
    }
    
    /**
     * Update billing settings
     */
    public function update_billing_settings($organization_id, $billing_rate) {
        global $wpdb;
        
        // Check if settings exist
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->billing_settings_table} WHERE organization_id = %d",
            $organization_id
        ));
        
        if ($exists) {
            return $wpdb->update(
                $this->billing_settings_table,
                array('billing_rate_per_minute' => $billing_rate),
                array('organization_id' => $organization_id)
            );
        } else {
            return $wpdb->insert($this->billing_settings_table, array(
                'organization_id' => $organization_id,
                'billing_rate_per_minute' => $billing_rate,
                'cost_per_minute' => 0.02,
                'currency' => 'EUR'
            ));
        }
    }
}
?>