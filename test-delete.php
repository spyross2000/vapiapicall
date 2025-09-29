<?php
/**
 * Complete Sync and Delete Test for Vapi Call Logs Plugin
 * Tests fetching, syncing, and deleting calls
 */

// Load WordPress
$wp_load_path = dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php';
if (file_exists($wp_load_path)) {
    require_once($wp_load_path);
} else {
    die('WordPress environment not found');
}

// Check if user is admin
if (!current_user_can('manage_options')) {
    die('Access denied - Admin only');
}

// Get parameters
$org_id = isset($_GET['org_id']) ? intval($_GET['org_id']) : 0;
$action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
$days = isset($_GET['days']) ? intval($_GET['days']) : 30;

if (!$org_id) {
    die('Please provide organization ID via ?org_id=X parameter');
}

// Load classes
require_once(dirname(__FILE__) . '/includes/class-database.php');
require_once(dirname(__FILE__) . '/includes/class-api-client.php');

$database = new VapiCallLogs_Database();
$api_client = new VapiCallLogs_Api_Client();

// Get organization
$org = $database->get_organization($org_id);
if (!$org) {
    die('Organization not found');
}

if (empty($org->api_key)) {
    die('No API key configured for this organization');
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Vapi Complete Sync Test</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; max-width: 1400px; margin: 0 auto; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .info { color: blue; }
        .warning { color: orange; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .button { 
            padding: 8px 16px; 
            background: #0073aa; 
            color: white; 
            text-decoration: none; 
            border-radius: 3px; 
            display: inline-block; 
            margin: 5px; 
            border: none;
            cursor: pointer;
        }
        .button:hover { background: #005a87; }
        .button.danger { background: #dc3232; }
        .button.danger:hover { background: #a00; }
        .button.success { background: #46b450; }
        .button.warning { background: #ffb900; }
        pre { background: #f5f5f5; padding: 10px; overflow-x: auto; border: 1px solid #ddd; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
        .stat-card { background: #f9f9f9; border: 1px solid #ddd; padding: 15px; border-radius: 5px; }
        .stat-card h3 { margin-top: 0; color: #555; font-size: 14px; }
        .stat-card .number { font-size: 24px; font-weight: bold; color: #0073aa; }
        .section { margin: 30px 0; padding: 20px; background: white; border: 1px solid #ddd; }
    </style>
</head>
<body>
    <h1>Complete Sync & Delete Test</h1>
    <p><strong>Organization:</strong> <?php echo esc_html($org->name); ?> (ID: <?php echo $org->id; ?>)</p>
    <p><strong>API Key:</strong> <?php echo substr($org->api_key, 0, 10); ?>...</p>
    
    <div class="section">
        <h2>📊 Step 1: Database Status</h2>
        <?php
        // Check database for existing calls
        $db_calls = $database->get_stored_call_logs($org->id);
        $db_call_count = count($db_calls);
        ?>
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Calls in Database</h3>
                <div class="number"><?php echo $db_call_count; ?></div>
            </div>
            <?php if ($db_call_count > 0): ?>
                <?php 
                $latest_call = $db_calls[0];
                $oldest_call = end($db_calls);
                ?>
                <div class="stat-card">
                    <h3>Latest Call</h3>
                    <div><?php echo date('Y-m-d H:i', strtotime($latest_call->created_at)); ?></div>
                </div>
                <div class="stat-card">
                    <h3>Oldest Call</h3>
                    <div><?php echo date('Y-m-d H:i', strtotime($oldest_call->created_at)); ?></div>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($db_call_count > 0): ?>
            <details>
                <summary>Show first 5 database calls</summary>
                <table>
                    <thead>
                        <tr><th>Call ID</th><th>Phone</th><th>Status</th><th>Duration</th><th>Created</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($db_calls, 0, 5) as $call): ?>
                            <tr>
                                <td><code><?php echo esc_html(substr($call->call_id, 0, 15)); ?>...</code></td>
                                <td><?php echo esc_html($call->phone_number); ?></td>
                                <td><?php echo esc_html($call->status); ?></td>
                                <td><?php echo $call->duration; ?>s</td>
                                <td><?php echo date('Y-m-d H:i', strtotime($call->created_at)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        <?php endif; ?>
    </div>
    
    <div class="section">
        <h2>🔍 Step 2: Fetch from Vapi API</h2>
        
        <form method="get" style="margin-bottom: 20px;">
            <input type="hidden" name="org_id" value="<?php echo $org_id; ?>">
            <input type="hidden" name="action" value="fetch">
            <label>
                Fetch calls from last 
                <select name="days">
                    <option value="1" <?php selected($days, 1); ?>>1 day</option>
                    <option value="7" <?php selected($days, 7); ?>>7 days</option>
                    <option value="14" <?php selected($days, 14); ?>>14 days</option>
                    <option value="30" <?php selected($days, 30); ?>>30 days</option>
                    <option value="60" <?php selected($days, 60); ?>>60 days</option>
                    <option value="90" <?php selected($days, 90); ?>>90 days</option>
                    <option value="0">All calls (no date filter)</option>
                </select>
            </label>
            <input type="submit" value="Fetch Calls" class="button">
        </form>
        
        <?php
        if ($action === 'fetch' || $action === '') {
            echo '<p>Fetching calls from Vapi API...</p>';
            
            $filters = array();
            if ($days > 0) {
                $filters['date_from'] = date('Y-m-d', strtotime('-' . $days . ' days'));
                echo '<p>Date filter: From ' . $filters['date_from'] . ' to today</p>';
            } else {
                echo '<p>Fetching ALL calls (no date filter)</p>';
            }
            
            $api_calls = $api_client->fetch_call_logs($org->id, $org->api_key, $filters);
            
            if ($api_calls && isset($api_calls['data'])) {
                $api_call_count = count($api_calls['data']);
                echo '<p class="success">✓ Found ' . $api_call_count . ' calls from Vapi API</p>';
                
                if ($api_call_count > 0) {
                    // Group by status
                    $status_counts = array();
                    foreach ($api_calls['data'] as $call) {
                        $status = $call['status'] ?? 'unknown';
                        $status_counts[$status] = ($status_counts[$status] ?? 0) + 1;
                    }
                    
                    echo '<div class="stats-grid">';
                    foreach ($status_counts as $status => $count) {
                        echo '<div class="stat-card">';
                        echo '<h3>' . ucfirst($status) . '</h3>';
                        echo '<div class="number">' . $count . '</div>';
                        echo '</div>';
                    }
                    echo '</div>';
                    
                    ?>
                    <details>
                        <summary>Show first 10 API calls</summary>
                        <table>
                            <thead>
                                <tr>
                                    <th>Call ID</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th>Duration</th>
                                    <th>Created</th>
                                    <th>Recording</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($api_calls['data'], 0, 10) as $call): ?>
                                    <tr>
                                        <td><code title="<?php echo esc_attr($call['id']); ?>"><?php echo esc_html(substr($call['id'], 0, 15)); ?>...</code></td>
                                        <td><?php echo isset($call['customer']['number']) ? esc_html($call['customer']['number']) : 'N/A'; ?></td>
                                        <td><?php echo esc_html($call['status']); ?></td>
                                        <td><?php echo isset($call['duration']) ? $call['duration'] . 's' : 'N/A'; ?></td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($call['createdAt'])); ?></td>
                                        <td><?php echo !empty($call['recordingUrl']) ? '✓' : '✗'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </details>
                    
                    <!-- Store calls data for other actions -->
                    <?php $_SESSION['vapi_test_calls'] = $api_calls['data']; ?>
                    
                    <div style="margin-top: 20px;">
                        <h3>Available Actions:</h3>
                        <p>
                            <a href="?org_id=<?php echo $org_id; ?>&action=sync&days=<?php echo $days; ?>" 
                               class="button success">
                                💾 Sync These Calls to Database
                            </a>
                            <a href="?org_id=<?php echo $org_id; ?>&action=delete_test&days=<?php echo $days; ?>" 
                               class="button warning">
                                🧪 Test Delete Single Call
                            </a>
                            <a href="?org_id=<?php echo $org_id; ?>&action=delete_all&days=<?php echo $days; ?>" 
                               class="button danger" 
                               onclick="return confirm('This will delete ALL <?php echo $api_call_count; ?> calls from Vapi. Are you sure?');">
                                🗑️ Delete All from Vapi
                            </a>
                        </p>
                    </div>
                    <?php
                }
            } else {
                echo '<p class="error">✗ Failed to fetch calls from Vapi API</p>';
                echo '<p>Check the error log for details.</p>';
            }
        }
        ?>
    </div>
    
    <?php if ($action === 'sync'): ?>
    <div class="section">
        <h2>💾 Step 3: Sync to Database</h2>
        <?php
        // Refetch the calls
        $filters = array();
        if ($days > 0) {
            $filters['date_from'] = date('Y-m-d', strtotime('-' . $days . ' days'));
        }
        
        $api_calls = $api_client->fetch_call_logs($org->id, $org->api_key, $filters);
        
        if ($api_calls && isset($api_calls['data'])) {
            $new_count = 0;
            $updated_count = 0;
            $skipped_count = 0;
            
            // Get existing call IDs
            $existing_call_ids = $database->get_existing_call_ids($org->id);
            
            foreach ($api_calls['data'] as $call) {
                if (in_array($call['id'], $existing_call_ids)) {
                    // Update existing call
                    if ($database->update_call_log($org->id, $call)) {
                        $updated_count++;
                    } else {
                        $skipped_count++;
                    }
                } else {
                    // Store new call
                    if ($database->store_single_call_log($org->id, $call)) {
                        $new_count++;
                    } else {
                        $skipped_count++;
                    }
                }
            }
            
            // Update last sync time
            $database->update_last_sync($org->id);
            
            echo '<p class="success">✓ Sync completed!</p>';
            echo '<div class="stats-grid">';
            echo '<div class="stat-card"><h3>New Calls</h3><div class="number">' . $new_count . '</div></div>';
            echo '<div class="stat-card"><h3>Updated</h3><div class="number">' . $updated_count . '</div></div>';
            echo '<div class="stat-card"><h3>Skipped</h3><div class="number">' . $skipped_count . '</div></div>';
            echo '</div>';
            
            echo '<p><a href="?org_id=' . $org_id . '" class="button">← Back to Overview</a></p>';
        }
        ?>
    </div>
    <?php endif; ?>
    
    <?php if ($action === 'delete_test'): ?>
    <div class="section">
        <h2>🧪 Test Delete Single Call</h2>
        <?php
        // Refetch the calls
        $filters = array();
        if ($days > 0) {
            $filters['date_from'] = date('Y-m-d', strtotime('-' . $days . ' days'));
        }
        
        $api_calls = $api_client->fetch_call_logs($org->id, $org->api_key, $filters);
        
        if ($api_calls && isset($api_calls['data']) && count($api_calls['data']) > 0) {
            $test_call = $api_calls['data'][0];
            echo '<p>Testing delete with call: <code>' . esc_html($test_call['id']) . '</code></p>';
            echo '<p>Status: ' . esc_html($test_call['status']) . '</p>';
            echo '<p>Created: ' . esc_html($test_call['createdAt']) . '</p>';
            
            if (isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
                echo '<p>Attempting to delete...</p>';
                
                $deleted = $api_client->delete_call($org->api_key, $test_call['id']);
                
                if ($deleted) {
                    echo '<p class="success">✓ Call successfully deleted from Vapi!</p>';
                } else {
                    echo '<p class="error">✗ Failed to delete call</p>';
                    echo '<p>Check error logs for details.</p>';
                }
                
                echo '<p><a href="?org_id=' . $org_id . '&action=fetch&days=' . $days . '" class="button">Refresh Call List</a></p>';
            } else {
                echo '<p><a href="?org_id=' . $org_id . '&action=delete_test&days=' . $days . '&confirm=yes" class="button danger">Confirm Delete</a></p>';
            }
        } else {
            echo '<p class="warning">No calls available to test delete.</p>';
        }
        ?>
    </div>
    <?php endif; ?>
    
    <?php if ($action === 'delete_all'): ?>
    <div class="section">
        <h2>🗑️ Delete All Calls from Vapi</h2>
        <?php
        if (isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
            // Refetch the calls
            $filters = array();
            if ($days > 0) {
                $filters['date_from'] = date('Y-m-d', strtotime('-' . $days . ' days'));
            }
            
            $api_calls = $api_client->fetch_call_logs($org->id, $org->api_key, $filters);
            
            if ($api_calls && isset($api_calls['data'])) {
                $call_ids = array_column($api_calls['data'], 'id');
                $total = count($call_ids);
                
                echo '<p>Deleting ' . $total . ' calls...</p>';
                
                $results = $api_client->bulk_delete_calls($org->api_key, $call_ids, 200);
                
                echo '<div class="stats-grid">';
                echo '<div class="stat-card"><h3>Successful</h3><div class="number success">' . $results['success'] . '</div></div>';
                echo '<div class="stat-card"><h3>Failed</h3><div class="number error">' . $results['failed'] . '</div></div>';
                echo '</div>';
                
                if ($results['failed'] > 0 && !empty($results['errors'])) {
                    echo '<details>';
                    echo '<summary>Failed call IDs</summary>';
                    echo '<pre>' . print_r($results['errors'], true) . '</pre>';
                    echo '</details>';
                }
                
                echo '<p><a href="?org_id=' . $org_id . '" class="button">← Back to Overview</a></p>';
            }
        } else {
            echo '<p class="warning">⚠️ This action cannot be undone!</p>';
            echo '<p><a href="?org_id=' . $org_id . '&action=delete_all&days=' . $days . '&confirm=yes" class="button danger">Yes, Delete All Calls</a></p>';
            echo '<p><a href="?org_id=' . $org_id . '" class="button">Cancel</a></p>';
        }
        ?>
    </div>
    <?php endif; ?>
    
    <div class="section">
        <h2>🔧 Error Log (Recent Vapi Entries)</h2>
        <?php
        // Show recent error log entries
        $error_log = ini_get('error_log');
        if ($error_log && file_exists($error_log)) {
            $log_content = file_get_contents($error_log);
            $log_lines = explode("\n", $log_content);
            $recent_vapi_logs = array();
            
            foreach (array_reverse($log_lines) as $line) {
                if (stripos($line, 'VAPI') !== false) {
                    $recent_vapi_logs[] = $line;
                    if (count($recent_vapi_logs) >= 10) break;
                }
            }
            
            if (!empty($recent_vapi_logs)) {
                echo '<pre style="max-height: 300px; overflow-y: auto;">';
                foreach ($recent_vapi_logs as $log) {
                    if (stripos($log, 'ERROR') !== false) {
                        echo '<span class="error">' . esc_html($log) . '</span>' . "\n";
                    } elseif (stripos($log, 'SUCCESS') !== false) {
                        echo '<span class="success">' . esc_html($log) . '</span>' . "\n";
                    } else {
                        echo esc_html($log) . "\n";
                    }
                }
                echo '</pre>';
            } else {
                echo '<p>No recent Vapi log entries found.</p>';
            }
        } else {
            echo '<p>Error log not accessible.</p>';
        }
        ?>
    </div>
    
    <hr>
    
    <div style="margin-top: 30px;">
        <a href="?page=vapi-call-logs-organizations" class="button">← Organizations</a>
        <a href="?page=vapi-call-logs-sync-center" class="button">Sync Center</a>
        <a href="?page=vapi-call-logs&organization=<?php echo $org_id; ?>" class="button">View Call Logs</a>
    </div>
</body>
</html>