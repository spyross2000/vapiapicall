<?php
/**
 * Direct sync test to bypass AJAX issues
 * Place this file in: /wp-content/plugins/vapi-call-logs/test-sync.php
 * Access via: https://database.wearebigg.com/wp-content/plugins/vapi-call-logs/test-sync.php?org_id=10
 */

// Load WordPress
require_once('../../../wp-load.php');

// Check if user is logged in and is admin
if (!is_user_logged_in() || !current_user_can('manage_options')) {
    die('Access denied. Please login as admin.');
}

// Increase limits
@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', 300);
@set_time_limit(300);

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get organization ID from URL
$org_id = isset($_GET['org_id']) ? intval($_GET['org_id']) : 0;

if (!$org_id) {
    die('Please provide org_id parameter. Example: ?org_id=10');
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Sync for Organization <?php echo $org_id; ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .log-entry {
            padding: 5px 10px;
            margin: 5px 0;
            background: #f8f9fa;
            border-left: 3px solid #007cba;
            font-family: monospace;
            font-size: 12px;
        }
        .error {
            background: #fee;
            border-left-color: #dc3545;
            color: #721c24;
        }
        .success {
            background: #d4edda;
            border-left-color: #28a745;
            color: #155724;
        }
        .warning {
            background: #fff3cd;
            border-left-color: #ffc107;
            color: #856404;
        }
        h1 {
            color: #333;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .stat-box {
            padding: 15px;
            background: #007cba;
            color: white;
            border-radius: 5px;
            text-align: center;
        }
        .stat-box .number {
            font-size: 24px;
            font-weight: bold;
        }
        .stat-box .label {
            font-size: 12px;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Testing Sync for Organization ID: <?php echo $org_id; ?></h1>
        
        <div id="log">
            <?php
            function log_message($message, $type = 'info') {
                $class = '';
                $prefix = '📝';
                
                switch($type) {
                    case 'error':
                        $class = 'error';
                        $prefix = '❌';
                        break;
                    case 'success':
                        $class = 'success';
                        $prefix = '✅';
                        break;
                    case 'warning':
                        $class = 'warning';
                        $prefix = '⚠️';
                        break;
                }
                
                echo '<div class="log-entry ' . $class . '">';
                echo $prefix . ' ' . date('H:i:s') . ' - ' . htmlspecialchars($message);
                echo '</div>';
                flush();
                ob_flush();
            }
            
            try {
                log_message("Starting sync test for organization $org_id");
                
                // Load plugin classes
                log_message("Loading plugin classes...");
                require_once(__DIR__ . '/includes/class-database.php');
                require_once(__DIR__ . '/includes/class-api-client.php');
                
                // Initialize
                $database = new VapiCallLogs_Database();
                $api_client = new VapiCallLogs_Api_Client();
                
                // Get organization
                log_message("Fetching organization details...");
                $org = $database->get_organization($org_id);
                
                if (!$org) {
                    throw new Exception("Organization not found with ID: $org_id");
                }
                
                log_message("Organization found: " . $org->name, 'success');
                log_message("API Key: " . (empty($org->api_key) ? 'NOT SET' : 'SET (hidden)'));
                
                if (empty($org->api_key)) {
                    throw new Exception("API key is not configured for this organization");
                }
                
                // Test API connection
                log_message("Testing API connection...");
                $test_result = $api_client->test_connection($org->api_key);
                
                if (!$test_result) {
                    throw new Exception("API connection test failed - check API key");
                }
                
                log_message("API connection successful!", 'success');
                
                // Setup filters
                $sync_days = get_option('vapi_global_sync_days', 14);
                $filters = array();
                
                if (!empty($org->last_sync)) {
                    log_message("Last sync: " . $org->last_sync);
                    $filters['date_from'] = date('Y-m-d', strtotime($org->last_sync));
                } else {
                    log_message("First sync - getting last $sync_days days");
                    $filters['date_from'] = date('Y-m-d', strtotime("-$sync_days days"));
                }
                
                log_message("Date filter: from " . $filters['date_from']);
                
                // Fetch call logs
                log_message("Fetching call logs from Vapi API...");
                $call_logs = $api_client->fetch_call_logs($org->id, $org->api_key, $filters);
                
                if ($call_logs === false) {
                    throw new Exception("Failed to fetch call logs from API");
                }
                
                if (!isset($call_logs['data']) || !is_array($call_logs['data'])) {
                    throw new Exception("Invalid API response format");
                }
                
                $total_calls = count($call_logs['data']);
                log_message("Found $total_calls calls from API", 'success');
                
                if ($total_calls === 0) {
                    log_message("No new calls to process", 'warning');
                    $database->update_last_sync($org_id);
                } else {
                    // Get existing calls
                    log_message("Checking existing calls in database...");
                    $existing_calls = $database->get_existing_call_ids($org_id);
                    log_message("Found " . count($existing_calls) . " existing calls in database");
                    
                    $new_calls = 0;
                    $updated_calls = 0;
                    $skipped_calls = 0;
                    
                    // Process each call
                    log_message("Processing calls...");
                    
                    foreach ($call_logs['data'] as $index => $call) {
                        if ($index % 10 === 0 && $index > 0) {
                            log_message("Processed $index of $total_calls calls...");
                        }
                        
                        if (in_array($call['id'], $existing_calls)) {
                            // Check if needs update
                            $existing_call = $database->get_call_by_id($org_id, $call['id']);
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
                                    $database->update_call_log($org_id, $call);
                                    $updated_calls++;
                                } else {
                                    $skipped_calls++;
                                }
                            } else {
                                $skipped_calls++;
                            }
                        } else {
                            // New call
                            $stored = $database->store_single_call_log($org_id, $call);
                            if ($stored) {
                                $new_calls++;
                            }
                        }
                    }
                    
                    // Update last sync
                    $database->update_last_sync($org_id);
                    
                    log_message("Sync completed successfully!", 'success');
                    
                    // Show stats
                    echo '<div class="stats">';
                    echo '<div class="stat-box"><div class="number">' . $total_calls . '</div><div class="label">Total Calls</div></div>';
                    echo '<div class="stat-box"><div class="number">' . $new_calls . '</div><div class="label">New Calls</div></div>';
                    echo '<div class="stat-box"><div class="number">' . $updated_calls . '</div><div class="label">Updated</div></div>';
                    echo '<div class="stat-box"><div class="number">' . $skipped_calls . '</div><div class="label">Unchanged</div></div>';
                    echo '</div>';
                    
                    log_message("Summary: $new_calls new, $updated_calls updated, $skipped_calls unchanged", 'success');
                }
                
            } catch (Exception $e) {
                log_message("ERROR: " . $e->getMessage(), 'error');
                error_log('VAPI TEST SYNC ERROR: ' . $e->getMessage());
            }
            
            // Show memory usage
            log_message("Memory usage: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB");
            log_message("Peak memory: " . round(memory_get_peak_usage() / 1024 / 1024, 2) . " MB");
            
            ?>
        </div>
        
        <div style="margin-top: 30px; padding: 20px; background: #f0f8ff; border-radius: 5px;">
            <h3>🔧 Troubleshooting Tips:</h3>
            <ul>
                <li>If this works but AJAX doesn't, the issue is likely with AJAX timeout or server configuration</li>
                <li>Check your server's error logs for more details</li>
                <li>Try increasing PHP memory_limit and max_execution_time in php.ini</li>
                <li>For ModSecurity issues, add exception for admin-ajax.php</li>
            </ul>
            
            <h3>📝 Quick Actions:</h3>
            <a href="?org_id=<?php echo $org_id; ?>" style="padding: 10px 20px; background: #007cba; color: white; text-decoration: none; border-radius: 5px; display: inline-block; margin: 5px;">🔄 Run Again</a>
            <a href="<?php echo admin_url('admin.php?page=vapi-call-logs-organizations'); ?>" style="padding: 10px 20px; background: #666; color: white; text-decoration: none; border-radius: 5px; display: inline-block; margin: 5px;">← Back to Organizations</a>
        </div>
    </div>
</body>
</html>