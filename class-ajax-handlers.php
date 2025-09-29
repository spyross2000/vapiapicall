<?php
/**
 * Enhanced AJAX Handlers for Vapi Call Logs Plugin
 * Version: 3.3.4 - Fixed duplicate functions and sync response
 */

if (!defined('ABSPATH')) {
    exit;
}

class VapiCallLogs_Ajax_Handlers {
    
    private $database;
    private $api_client;
    
    public function __construct($database = null, $api_client = null) {
        $this->database = $database ?: new VapiCallLogs_Database();
        $this->api_client = $api_client ?: new VapiCallLogs_Api_Client();
        
        // Register AJAX handlers
        add_action('wp_ajax_get_vapi_call_logs', array($this, 'get_call_logs'));
        add_action('wp_ajax_sync_vapi_data', array($this, 'sync_vapi_data'));
        add_action('wp_ajax_test_vapi_connection', array($this, 'test_connection'));
        add_action('wp_ajax_vapi_debug_raw', array($this, 'debug_raw'));
        add_action('wp_ajax_add_organization', array($this, 'add_organization'));
        add_action('wp_ajax_delete_organization', array($this, 'delete_organization'));
        add_action('wp_ajax_update_organization', array($this, 'update_organization'));
        add_action('wp_ajax_test_db_connection', array($this, 'test_db_connection'));
        add_action('wp_ajax_prepare_org_database', array($this, 'prepare_org_database'));
        add_action('wp_ajax_run_manual_cleanup', array($this, 'run_manual_cleanup'));
        
        // Sync Center handlers
        add_action('wp_ajax_save_global_sync_settings', array($this, 'save_global_sync_settings'));
        add_action('wp_ajax_sync_all_organizations', array($this, 'sync_all_organizations'));
        add_action('wp_ajax_reset_all_sync_schedules', array($this, 'reset_all_sync_schedules'));
        
        // Billing handlers
        add_action('wp_ajax_update_billing_rate', array($this, 'update_billing_rate'));
        add_action('wp_ajax_export_billing_csv', array($this, 'export_billing_csv'));
        add_action('wp_ajax_nopriv_export_billing_csv', array($this, 'export_billing_csv'));
    }
    
    /**
     * Update billing rate for organization
     */
    public function update_billing_rate() {
        if (!wp_verify_nonce($_POST['nonce'], 'vapi_call_logs_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $organization_id = intval($_POST['organization_id'] ?? 0);
        $billing_rate = floatval($_POST['billing_rate'] ?? 0);
        
        if (!$organization_id || $billing_rate < 0) {
            wp_send_json_error(array('message' => 'Invalid data'));
        }
        
        $result = $this->database->update_billing_settings($organization_id, $billing_rate);
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Billing rate updated successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to update billing rate'));
        }
    }
    
    /**
     * Export billing data to CSV
     */
    public function export_billing_csv() {
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'vapi_call_logs_nonce')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $organization_id = isset($_GET['organization']) ? intval($_GET['organization']) : null;
        $month = isset($_GET['month']) ? intval($_GET['month']) : null;
        $year = isset($_GET['year']) ? intval($_GET['year']) : null;
        
        // Build date range
        $start_date = null;
        $end_date = null;
        
        if ($month && $year) {
            $start_date = date('Y-m-d', mktime(0, 0, 0, $month, 1, $year));
            $end_date = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));
        } elseif ($year) {
            $start_date = $year . '-01-01';
            $end_date = $year . '-12-31';
        }
        
        // Get the data
        $data = $this->database->export_billing_csv($organization_id, $start_date, $end_date);
        
        if (empty($data)) {
            wp_die('No data found for the selected criteria');
        }
        
        // Set headers for CSV download
        $filename = 'billing-report-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Open output stream
        $output = fopen('php://output', 'w');
        
        // Add BOM for UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Add headers
        $headers = array(
            'Organization',
            'Month',
            'Total Calls',
            'Total Minutes',
            'Actual Cost (EUR)',
            'Billing Rate/Min',
            'Total Billing (EUR)',
            'Profit (EUR)'
        );
        fputcsv($output, $headers);
        
        // Add data rows
        foreach ($data as $row) {
            // Calculate profit
            $row['Profit (EUR)'] = $row['Total Billing (EUR)'] - $row['Actual Cost (EUR)'];
            fputcsv($output, $row);
        }
        
        // Add summary row
        $summary = array(
            'TOTAL',
            '',
            array_sum(array_column($data, 'Total Calls')),
            array_sum(array_column($data, 'Total Minutes')),
            array_sum(array_column($data, 'Actual Cost (EUR)')),
            '',
            array_sum(array_column($data, 'Total Billing (EUR)')),
            array_sum(array_column($data, 'Total Billing (EUR)')) - array_sum(array_column($data, 'Actual Cost (EUR)'))
        );
        fputcsv($output, $summary);
        
        fclose($output);
        exit;
    }
    
    /**
     * Format duration from seconds to minutes
     */
    private function format_duration($seconds) {
        if (!$seconds || $seconds <= 0) {
            return '0 min';
        }
        
        $minutes = $seconds / 60;
        return number_format($minutes, 1) . ' min';
    }
    
    /**
     * Get call logs from DATABASE ONLY - no API calls
     */
    public function get_call_logs() {
        if (!wp_verify_nonce($_POST['nonce'], 'vapi_call_logs_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $organization_id = intval($_POST['organization_id'] ?? 0);
        $status_filter = sanitize_text_field($_POST['status_filter'] ?? '');
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');
        $phone_search = sanitize_text_field($_POST['phone_search'] ?? '');
        
        // Get organizations for filter dropdown
        if ($organization_id) {
            $organizations = array($this->database->get_organization($organization_id));
            $organizations = array_filter($organizations);
        } else {
            $organizations = $this->database->get_organizations(true);
        }
        
        $all_calls = array();
        $organizations_map = array();
        
        foreach ($organizations as $org) {
            $organizations_map[$org->id] = $org->name;
            
            // Get calls from database ONLY
            $db_calls = $this->database->get_stored_call_logs($org->id, array(
                'status_filter' => $status_filter,
                'date_from' => $date_from,
                'date_to' => $date_to,
                'phone_search' => $phone_search
            ));
            
            foreach ($db_calls as $call) {
                // Convert database record to display format
                $call_data = json_decode($call->call_data, true) ?: array();
                
                // Ensure duration is properly set (it's stored in seconds in the database)
                $duration_seconds = intval($call->duration);
                
                // Use local audio URL if available, otherwise use original recording URL
                $audio_url = null;
                if (!empty($call->local_audio_path)) {
                    $audio_url = $this->database->get_audio_url($call->local_audio_path);
                } elseif (!empty($call->recording_url)) {
                    $audio_url = $call->recording_url;
                }
                
                $call_array = array_merge($call_data, array(
                    'id' => $call->call_id,
                    'organization_id' => $org->id,
                    'organization_name' => $org->name,
                    'phone_number' => $call->phone_number,
                    'duration' => $duration_seconds,
                    'duration_formatted' => $this->format_duration($duration_seconds),
                    'status' => $call->status,
                    'cost' => $call->cost,
                    'recordingUrl' => $audio_url,
                    'has_local_audio' => !empty($call->local_audio_path),
                    'transcript' => json_decode($call->transcript, true),
                    'messages' => json_decode($call->messages, true),
                    'createdAt' => $call->created_at,
                    'endedAt' => isset($call_data['endedAt']) ? $call_data['endedAt'] : null,
                    'customer' => array('number' => $call->phone_number)
                ));
                $all_calls[] = $call_array;
            }
        }
        
        // Sort by creation date (newest first)
        usort($all_calls, function($a, $b) {
            return strtotime($b['createdAt']) - strtotime($a['createdAt']);
        });
        
        wp_send_json_success(array(
            'calls' => $all_calls,
            'organizations' => $organizations_map,
            'source' => 'database'
        ));
    }
    
    /**
     * Sync data from Vapi API to database with audio download
     * ENHANCED VERSION with detailed progress reporting
     */
    public function sync_vapi_data() {
        error_log('VAPI SYNC: Starting sync process');
        
        // Increase PHP limits for large syncs
        @set_time_limit(300); // 5 minutes
        @ini_set('memory_limit', '256M');
        
        if (!wp_verify_nonce($_POST['nonce'], 'vapi_call_logs_nonce')) {
            error_log('VAPI SYNC ERROR: Nonce verification failed');
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            error_log('VAPI SYNC ERROR: Insufficient permissions');
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $organization_id = intval($_POST['organization_id'] ?? 0);
        
        // Check if we should delete from Vapi after import
        $global_delete_after_sync = get_option('vapi_global_delete_after_sync', false);
        
        // Use the delete_after_import parameter if provided, otherwise use global setting
        $delete_after_import = isset($_POST['delete_after_import']) ? 
            filter_var($_POST['delete_after_import'], FILTER_VALIDATE_BOOLEAN) : 
            $global_delete_after_sync;
        
        error_log('VAPI SYNC: Organization ID = ' . $organization_id);
        
        if (!$organization_id) {
            error_log('VAPI SYNC ERROR: Invalid organization ID');
            wp_send_json_error(array('message' => 'Invalid organization ID'));
            return;
        }
        
        $org = $this->database->get_organization($organization_id);
        
        if (!$org) {
            error_log('VAPI SYNC ERROR: Organization not found in database');
            wp_send_json_error(array('message' => 'Organization not found'));
            return;
        }
        
        error_log('VAPI SYNC: Organization found - Name: ' . $org->name);
        error_log('VAPI SYNC: API Key exists: ' . (!empty($org->api_key) ? 'Yes' : 'No'));
        
        if (empty($org->api_key)) {
            error_log('VAPI SYNC ERROR: API key is empty');
            wp_send_json_error(array('message' => 'API key not configured'));
            return;
        }
        
        // Check if using separate DB and ensure it's set up
        if ($org->use_separate_db && !$org->db_table_created) {
            error_log('VAPI SYNC: Setting up separate database');
            $setup_result = $this->database->setup_org_database($org->id);
            if (!$setup_result['success']) {
                error_log('VAPI SYNC ERROR: Failed to setup database: ' . $setup_result['message']);
                wp_send_json_error(array('message' => 'Failed to setup database: ' . $setup_result['message']));
                return;
            }
        }
        
        // Check if this is first sync
        $is_first_sync = empty($org->last_sync);
        $filters = array();
        
        // Get sync days from global settings
        $sync_days = get_option('vapi_global_sync_days', 14);
        $sync_days = max(1, min(30, $sync_days)); // Ensure it's between 1-30 days
        
        if ($is_first_sync) {
            error_log('VAPI SYNC: This is the first sync for this organization');
            $filters['date_from'] = date('Y-m-d', strtotime('-' . $sync_days . ' days'));
        } else {
            error_log('VAPI SYNC: Last sync was at ' . $org->last_sync);
            $last_sync_date = strtotime($org->last_sync);
            $max_days_ago = strtotime('-' . $sync_days . ' days');
            
            if ($last_sync_date < $max_days_ago) {
                $filters['date_from'] = date('Y-m-d', $max_days_ago);
                error_log('VAPI SYNC: Last sync older than ' . $sync_days . ' days, using ' . $sync_days . '-day limit');
            } else {
                $filters['date_from'] = date('Y-m-d', $last_sync_date);
            }
        }
        
        error_log('VAPI SYNC: Date filter from: ' . ($filters['date_from'] ?? 'none'));
        
        // First attempt with calculated date
        error_log('VAPI SYNC: Calling API for organization ' . $org->id);
        $call_logs = $this->api_client->fetch_call_logs($org->id, $org->api_key, $filters);
        
        // If failed due to retention limit, try with sync_days
        if ($call_logs === false) {
            error_log('VAPI SYNC: First attempt failed, trying with ' . $sync_days . '-day limit');
            $filters['date_from'] = date('Y-m-d', strtotime('-' . $sync_days . ' days'));
            $call_logs = $this->api_client->fetch_call_logs($org->id, $org->api_key, $filters);
            
            if ($call_logs === false) {
                error_log('VAPI SYNC ERROR: API call failed even with ' . $sync_days . '-day limit');
                wp_send_json_error(array('message' => 'Failed to connect to Vapi API. Your subscription may only allow access to the last ' . $sync_days . ' days of call history.'));
                return;
            }
        }
        
        if (!$call_logs || !isset($call_logs['data'])) {
            error_log('VAPI SYNC ERROR: Invalid API response structure');
            wp_send_json_error(array('message' => 'Failed to fetch data from Vapi API - invalid response format'));
            return;
        }
        
        $total_calls = count($call_logs['data']);
        error_log('VAPI SYNC: API returned ' . $total_calls . ' calls');
        
        if ($total_calls === 0) {
            // Update last sync time even if no calls
            $this->database->update_last_sync($organization_id);
            wp_send_json_success(array(
                'message' => 'No calls found for this organization in the last ' . $sync_days . ' days',
                'stats' => array(
                    'total' => 0,
                    'new' => 0,
                    'updated' => 0,
                    'skipped' => 0,
                    'audio_downloaded' => 0,
                    'deleted' => 0
                )
            ));
            return;
        }
        
        $new_calls = 0;
        $updated_calls = 0;
        $skipped_calls = 0;
        $audio_downloaded = 0;
        $audio_skipped = 0;
        $calls_to_delete = array();
        
        // Get existing call IDs from database
        $existing_calls = $this->database->get_existing_call_ids($organization_id);
        error_log('VAPI SYNC: Found ' . count($existing_calls) . ' existing calls in database');
        
        // Determine if we should download audio
        $skip_audio_download = $is_first_sync && $total_calls > 20;
        
        if ($skip_audio_download) {
            error_log('VAPI SYNC: Skipping audio downloads for first sync with ' . $total_calls . ' calls');
        }
        
        // Process each call
        $processed = 0;
        foreach ($call_logs['data'] as $call) {
            $processed++;
            
            // Log progress every 10 calls
            if ($processed % 10 === 0) {
                error_log('VAPI SYNC: Progress - processed ' . $processed . ' of ' . $total_calls . ' calls');
            }
            
            try {
                // Check if call already exists in database
                if (in_array($call['id'], $existing_calls)) {
                    // Check if we need to update the call
                    $existing_call = $this->database->get_call_by_id($organization_id, $call['id']);
                    
                    if ($existing_call) {
                        $existing_call_data = json_decode($existing_call->call_data, true);
                        $needs_update = false;
                        
                        if ($existing_call->status !== $call['status']) {
                            $needs_update = true;
                        }
                        
                        if (isset($call['endedAt']) && 
                            (!isset($existing_call_data['endedAt']) || 
                             $existing_call_data['endedAt'] !== $call['endedAt'])) {
                            $needs_update = true;
                        }
                        
                        if ($needs_update) {
                            $updated = $this->database->update_call_log($organization_id, $call);
                            if ($updated) {
                                $updated_calls++;
                                
                                // Download audio if not already downloaded and not skipping
                                if (!$skip_audio_download && !empty($call['recordingUrl']) && empty($existing_call->local_audio_path)) {
                                    $local_audio_path = $this->database->download_and_store_audio(
                                        $call['recordingUrl'],
                                        $call['id'],
                                        $organization_id
                                    );
                                    if ($local_audio_path) {
                                        $audio_downloaded++;
                                        $this->database->update_call_audio_path($organization_id, $call['id'], $local_audio_path);
                                    }
                                }
                            }
                        } else {
                            $skipped_calls++;
                        }
                    } else {
                        $skipped_calls++;
                    }
                    continue;
                }
                
                // This is a new call - process it
                $local_audio_path = null;
                
                // Only download audio if not skipping
                if (!$skip_audio_download && !empty($call['recordingUrl'])) {
                    error_log('VAPI SYNC: Downloading audio for call ' . $call['id']);
                    $local_audio_path = $this->database->download_and_store_audio(
                        $call['recordingUrl'],
                        $call['id'],
                        $organization_id
                    );
                    if ($local_audio_path) {
                        $audio_downloaded++;
                        error_log('VAPI SYNC: Audio downloaded successfully: ' . $local_audio_path);
                    } else {
                        error_log('VAPI SYNC: Failed to download audio for call ' . $call['id']);
                    }
                } else if (!empty($call['recordingUrl'])) {
                    $audio_skipped++;
                }
                
                // Store new call in database
                $stored = $this->database->store_single_call_log($organization_id, $call, $local_audio_path);
                if ($stored) {
                    $new_calls++;
                    if ($delete_after_import) {
                        $calls_to_delete[] = $call['id'];
                    }
                } else {
                    error_log('VAPI SYNC ERROR: Failed to store call ' . $call['id']);
                    global $wpdb;
                    if ($wpdb->last_error) {
                        error_log('VAPI SYNC ERROR: Database error - ' . $wpdb->last_error);
                    }
                }
            } catch (Exception $e) {
                error_log('VAPI SYNC ERROR: Exception processing call ' . $call['id'] . ': ' . $e->getMessage());
                continue;
            }
        }
        
        // Delete calls from Vapi if requested
        $deleted_count = 0;
        if ($delete_after_import && !empty($calls_to_delete)) {
            error_log('VAPI SYNC: Deleting ' . count($calls_to_delete) . ' calls from Vapi using DELETE API');
            foreach ($calls_to_delete as $call_id) {
                if ($this->api_client->delete_call($org->api_key, $call_id)) {
                    $deleted_count++;
                    error_log('VAPI SYNC: Successfully deleted call ' . $call_id . ' from Vapi');
                } else {
                    error_log('VAPI SYNC: Failed to delete call ' . $call_id . ' from Vapi');
                }
                
                // Small delay to avoid rate limiting
                usleep(100000); // 0.1 second delay
            }
        }
        
        // Update last sync time
        $this->database->update_last_sync($organization_id);
        
        $message = sprintf(
            'Sync completed: %d calls from last %d days - %d new, %d updated, %d unchanged',
            $total_calls,
            $sync_days,
            $new_calls,
            $updated_calls,
            $skipped_calls
        );
        
        if ($skip_audio_download && $audio_skipped > 0) {
            $message .= sprintf(', %d audio downloads skipped (run sync again to download)', $audio_skipped);
        } else if ($audio_downloaded > 0) {
            $message .= sprintf(', %d audio files downloaded to local storage', $audio_downloaded);
        }
        
        if ($delete_after_import) {
            $message .= sprintf(', %d calls deleted from Vapi', $deleted_count);
        }
        
        error_log('VAPI SYNC: ' . $message);
        
        wp_send_json_success(array(
            'message' => $message,
            'stats' => array(
                'total' => $total_calls,
                'new' => $new_calls,
                'updated' => $updated_calls,
                'skipped' => $skipped_calls,
                'audio_downloaded' => $audio_downloaded,
                'audio_skipped' => $audio_skipped,
                'deleted' => $deleted_count
            )
        ));
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        if (!wp_verify_nonce($_POST['nonce'], 'vapi_call_logs_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $organization_id = intval($_POST['organization_id'] ?? 0);
        if (!$organization_id) {
            wp_send_json_error(array('message' => __('Please select an organization', 'vapi-call-logs')));
        }
        
        $org = $this->database->get_organization($organization_id);
        
        if (!$org || empty($org->api_key) || !$org->is_active) {
            wp_send_json_error(array('message' => __('Organization not found or API key not configured', 'vapi-call-logs')));
        }
        
        $test_result = $this->api_client->test_connection($org->api_key);
        
        if ($test_result) {
            wp_send_json_success(array('message' => __('API connection successful!', 'vapi-call-logs')));
        } else {
            wp_send_json_error(array('message' => __('API connection failed', 'vapi-call-logs')));
        }
    }
    
    /**
     * Debug raw API data
     */
    public function debug_raw() {
        if (!wp_verify_nonce($_POST['nonce'], 'vapi_call_logs_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $organization_id = intval($_POST['organization_id'] ?? 0);
        if (!$organization_id) {
            wp_send_json_error(array('message' => 'Invalid organization ID'));
        }
        
        $org = $this->database->get_organization($organization_id);
        
        if (!$org || empty($org->api_key)) {
            wp_send_json_error(array('message' => 'Organization not found or API key not configured'));
        }
        
        $call_logs = $this->api_client->fetch_call_logs($org->id, $org->api_key, array());
        
        wp_send_json_success(array(
            'raw_data' => $call_logs,
            'organization' => $org->name
        ));
    }
    
    /**
     * Test and prepare custom database connection
     */
    public function test_db_connection() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'vapi_call_logs_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $host = sanitize_text_field($_POST['host'] ?? '');
        $name = sanitize_text_field($_POST['name'] ?? '');
        $user = sanitize_text_field($_POST['user'] ?? '');
        $password = $_POST['password'] ?? '';
        $port = intval($_POST['port'] ?? 3306);
        $organization_id = intval($_POST['organization_id'] ?? 0);
        
        if (empty($host) || empty($name) || empty($user)) {
            wp_send_json_error(array('message' => __('Please fill in all database fields', 'vapi-call-logs')));
            return;
        }
        
        // Create new database connection
        $test_db = new wpdb($user, $password, $name, $host);
        
        // Check for connection errors
        if ($test_db->error) {
            $error_message = 'Connection failed: ';
            if (is_wp_error($test_db->error)) {
                $error_message .= $test_db->error->get_error_message();
            } else if (is_string($test_db->error)) {
                $error_message .= $test_db->error;
            } else {
                $error_message .= 'Unknown error';
            }
            wp_send_json_error(array('message' => $error_message));
            return;
        }
        
        $test_db->suppress_errors(true);
        $test_db->hide_errors();
        
        $test_table = 'vapi_test_' . time();
        $result = $test_db->query("CREATE TABLE IF NOT EXISTS $test_table (id INT PRIMARY KEY)");
        
        if ($result === false) {
            $error = $test_db->last_error ?: 'Cannot create tables in this database. Check permissions.';
            wp_send_json_error(array('message' => $error));
            return;
        }
        
        $test_db->query("DROP TABLE IF EXISTS $test_table");
        
        if ($organization_id) {
            $table_name = 'vapi_calls_org_' . $organization_id;
            $charset_collate = $test_db->get_charset_collate();
            
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
            
            $create_result = $test_db->query($sql);
            
            if ($create_result !== false) {
                global $wpdb;
                $wpdb->update(
                    $wpdb->prefix . 'vapi_organizations',
                    array('db_table_created' => 1),
                    array('id' => $organization_id)
                );
                
                wp_send_json_success(array(
                    'message' => 'Connection successful and database prepared!',
                    'table_created' => true
                ));
                return;
            }
        }
        
        wp_send_json_success(array(
            'message' => 'Connection successful!',
            'table_created' => false
        ));
    }
    
    /**
     * Prepare organization database
     */
    public function prepare_org_database() {
        if (!wp_verify_nonce($_POST['nonce'], 'vapi_call_logs_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $organization_id = intval($_POST['organization_id'] ?? 0);
        
        if (!$organization_id) {
            wp_send_json_error(array('message' => __('Invalid organization ID', 'vapi-call-logs')));
        }
        
        $result = $this->database->setup_org_database($organization_id);
        
        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    /**
     * Run manual cleanup
     */
    public function run_manual_cleanup() {
        if (!wp_verify_nonce($_POST['nonce'], 'vapi_call_logs_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'vapi-call-logs')));
        }
        
        $this->database->cleanup_old_logs();
        
        wp_send_json_success(array(
            'message' => __('Cleanup completed successfully!', 'vapi-call-logs')
        ));
    }
    
    /**
     * Add organization
     */
    public function add_organization() {
        if (!wp_verify_nonce($_POST['nonce'], 'vapi_call_logs_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $name = sanitize_text_field($_POST['name'] ?? '');
        $api_key = trim(sanitize_text_field($_POST['api_key'] ?? ''));
        $description = sanitize_textarea_field($_POST['description'] ?? '');
        $storage_settings = $_POST['storage_settings'] ?? array();
        
        if (empty($name) || empty($api_key)) {
            wp_send_json_error(array('message' => __('Name and API key are required', 'vapi-call-logs')));
        }
        
        // Test API key before saving
        error_log('VAPI ADD ORG: Testing API key: ' . substr($api_key, 0, 10) . '...');
        $test_result = $this->api_client->test_connection($api_key);
        if (!$test_result) {
            error_log('VAPI ADD ORG ERROR: API key test failed');
            wp_send_json_error(array('message' => __('Invalid API key - connection test failed', 'vapi-call-logs')));
        }
        
        if (!empty($storage_settings['use_separate_db'])) {
            // Get database settings
            $db_host = $storage_settings['db_host'] ?? 'localhost';
            $db_port = $storage_settings['db_port'] ?? 3306;
            $db_name = $storage_settings['db_name'] ?? '';
            $db_user = $storage_settings['db_user'] ?? '';
            $db_password = $storage_settings['db_password'] ?? '';
            
            // Validate required fields
            if (empty($db_host) || empty($db_name) || empty($db_user)) {
                wp_send_json_error(array('message' => 'Database host, name, and user are required'));
            }
            
            // Create new database connection
            $test_db = new wpdb($db_user, $db_password, $db_name, $db_host);
            
            // Check for connection errors
            if ($test_db->error) {
                $error_message = 'Database connection failed: ';
                if (is_wp_error($test_db->error)) {
                    $error_message .= $test_db->error->get_error_message();
                } else if (is_string($test_db->error)) {
                    $error_message .= $test_db->error;
                } else {
                    $error_message .= 'Unknown error';
                }
                wp_send_json_error(array('message' => $error_message));
            }
            
            // Test if we can query the database
            $test_db->suppress_errors(true);
            $test_result = $test_db->query("SELECT 1");
            if ($test_result === false) {
                $error_message = 'Database query failed: ';
                if ($test_db->last_error) {
                    $error_message .= $test_db->last_error;
                } else {
                    $error_message .= 'Unable to execute queries on this database';
                }
                wp_send_json_error(array('message' => $error_message));
            }
        }
        
        $result = $this->database->add_organization($name, $api_key, $description, $storage_settings);
        
        if ($result === false) {
            wp_send_json_error(array('message' => __('Failed to add organization', 'vapi-call-logs')));
        }
        
        error_log('VAPI ADD ORG: Organization created with ID: ' . $result);
        
        if (!empty($storage_settings['use_separate_db']) && $result) {
            $setup_result = $this->database->setup_org_database($result);
            if (!$setup_result['success']) {
                error_log('VAPI: Failed to setup database for new org ' . $result);
            }
        }
        
        wp_send_json_success(array('message' => __('Organization added successfully', 'vapi-call-logs')));
    }
    
    /**
     * Delete organization
     */
    public function delete_organization() {
        if (!wp_verify_nonce($_POST['nonce'], 'vapi_call_logs_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $organization_id = intval($_POST['organization_id'] ?? 0);
        if (!$organization_id) {
            wp_send_json_error(array('message' => __('Invalid organization ID', 'vapi-call-logs')));
        }
        
        $result = $this->database->delete_organization($organization_id);
        
        if ($result === false) {
            wp_send_json_error(array('message' => __('Failed to delete organization', 'vapi-call-logs')));
        }
        
        wp_send_json_success(array('message' => __('Organization deleted successfully', 'vapi-call-logs')));
    }
    
    /**
     * Update organization - FIXED to handle password fields properly
     */
    public function update_organization() {
        if (!wp_verify_nonce($_POST['nonce'], 'vapi_call_logs_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $organization_id = intval($_POST['organization_id'] ?? 0);
        $name = sanitize_text_field($_POST['name'] ?? '');
        $api_key = trim(sanitize_text_field($_POST['api_key'] ?? ''));
        $description = sanitize_textarea_field($_POST['description'] ?? '');
        $storage_settings = $_POST['storage_settings'] ?? array();
        
        if (!$organization_id || empty($name)) {
            wp_send_json_error(array('message' => __('Organization ID and name are required', 'vapi-call-logs')));
        }
        
        // Get current organization to check if API key is being updated
        $current_org = $this->database->get_organization($organization_id);
        if (!$current_org) {
            wp_send_json_error(array('message' => __('Organization not found', 'vapi-call-logs')));
        }
        
        // If API key is provided, test it. If empty, keep the existing one
        if (!empty($api_key)) {
            error_log('VAPI UPDATE ORG: Testing new API key for org ' . $organization_id . ': ' . substr($api_key, 0, 10) . '...');
            $test_result = $this->api_client->test_connection($api_key);
            if (!$test_result) {
                error_log('VAPI UPDATE ORG ERROR: API key test failed');
                wp_send_json_error(array('message' => __('Invalid API key - connection test failed', 'vapi-call-logs')));
            }
        } else {
            // Keep existing API key
            $api_key = $current_org->api_key;
            error_log('VAPI UPDATE ORG: Keeping existing API key for org ' . $organization_id);
        }
        
        if (!empty($storage_settings['use_separate_db'])) {
            // Get database settings
            $db_host = $storage_settings['db_host'] ?? '';
            $db_port = $storage_settings['db_port'] ?? 3306;
            $db_name = $storage_settings['db_name'] ?? '';
            $db_user = $storage_settings['db_user'] ?? '';
            $db_password = $storage_settings['db_password'] ?? '';
            
            // If database password is empty, keep the existing one
            if (empty($db_password) && $current_org->use_separate_db) {
                $storage_settings['db_password'] = $current_org->db_password;
            }
            
            // Validate required fields
            if (empty($db_host) || empty($db_name) || empty($db_user)) {
                wp_send_json_error(array('message' => 'Database host, name, and user are required'));
            }
            
            // Only test connection if we have all the credentials
            if (!empty($storage_settings['db_password'])) {
                // Create new database connection
                $test_db = new wpdb($db_user, $storage_settings['db_password'], $db_name, $db_host);
                
                // Check for connection errors
                if ($test_db->error) {
                    $error_message = 'Database connection failed: ';
                    if (is_wp_error($test_db->error)) {
                        $error_message .= $test_db->error->get_error_message();
                    } else if (is_string($test_db->error)) {
                        $error_message .= $test_db->error;
                    } else {
                        $error_message .= 'Unknown error';
                    }
                    wp_send_json_error(array('message' => $error_message));
                }
                
                // Test if we can query the database
                $test_db->suppress_errors(true);
                $test_result = $test_db->query("SELECT 1");
                if ($test_result === false) {
                    $error_message = 'Database query failed: ';
                    if ($test_db->last_error) {
                        $error_message .= $test_db->last_error;
                    } else {
                        $error_message .= 'Unable to execute queries on this database';
                    }
                    wp_send_json_error(array('message' => $error_message));
                }
            }
        }
        
        $result = $this->database->update_organization($organization_id, $name, $api_key, $description, $storage_settings);
        
        if ($result === false) {
            wp_send_json_error(array('message' => __('Failed to update organization', 'vapi-call-logs')));
        }
        
        wp_send_json_success(array('message' => __('Organization updated successfully', 'vapi-call-logs')));
    }
    
    /**
     * Save global sync settings
     */
    public function save_global_sync_settings() {
        if (!wp_verify_nonce($_POST['nonce'], 'vapi_call_logs_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $retention_days = intval($_POST['retention_days'] ?? 30);
        $sync_days = intval($_POST['sync_days'] ?? 14);
        $sync_frequency = sanitize_text_field($_POST['sync_frequency'] ?? 'hourly');
        $delete_after_sync = isset($_POST['delete_after_sync']) && $_POST['delete_after_sync'] === 'true';
        
        // Validate values
        $retention_days = max(0, min(365, $retention_days));
        $sync_days = max(1, min(30, $sync_days));
        
        $valid_frequencies = array('every_5_minutes', 'every_15_minutes', 'every_30_minutes', 'hourly', 'twicedaily', 'daily');
        if (!in_array($sync_frequency, $valid_frequencies)) {
            $sync_frequency = 'hourly';
        }
        
        // Save settings
        update_option('vapi_global_retention_days', $retention_days);
        update_option('vapi_global_sync_days', $sync_days);
        update_option('vapi_global_sync_frequency', $sync_frequency);
        update_option('vapi_global_delete_after_sync', $delete_after_sync);
        
        wp_send_json_success(array('message' => 'Global sync settings saved successfully'));
    }
    
    /**
     * Sync all organizations
     */
    public function sync_all_organizations() {
        if (!wp_verify_nonce($_POST['nonce'], 'vapi_call_logs_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $organizations = $this->database->get_organizations(true);
        $sync_count = 0;
        
        foreach ($organizations as $org) {
            if (!empty($org->api_key)) {
                // Trigger sync for each organization (background process)
                wp_schedule_single_event(time() + ($sync_count * 30), 'vapi_auto_sync_org_' . $org->id);
                $sync_count++;
            }
        }
        
        wp_send_json_success(array(
            'message' => sprintf('Scheduled sync for %d organizations', $sync_count)
        ));
    }
    
    /**
     * Reset all sync schedules
     */
    public function reset_all_sync_schedules() {
        if (!wp_verify_nonce($_POST['nonce'], 'vapi_call_logs_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        // Get auto-sync instance and clear all schedules
        $auto_sync = VapiCallLogs_Auto_Sync::get_instance();
        $auto_sync->clear_all_schedules();
        
        // Also clear individual organization schedules
        $organizations = $this->database->get_organizations();
        foreach ($organizations as $org) {
            delete_option('vapi_auto_sync_' . $org->id);
            delete_option('vapi_sync_interval_' . $org->id);
            delete_option('vapi_last_auto_sync_' . $org->id);
            delete_option('vapi_last_auto_sync_error_' . $org->id);
            delete_option('vapi_last_auto_sync_stats_' . $org->id);
        }
        
        wp_send_json_success(array('message' => 'All sync schedules have been reset'));
    }
}
?>