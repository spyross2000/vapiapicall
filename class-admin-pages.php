<?php
/**
 * Admin Pages for Vapi Call Logs Plugin
 * Version: 3.2.3 - Fixed headers already sent issue
 */

if (!defined('ABSPATH')) {
    exit;
}

class VapiCallLogs_Admin_Pages {
    
    private $database;
    private $api_client;
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
    }
    
    public function set_database($database) {
        $this->database = $database;
    }
    
    public function set_api_client($api_client) {
        $this->api_client = $api_client;
    }
    
    public function add_admin_menu() {
        // Main menu page
        add_menu_page(
            __('Vapi Call Logs', 'vapi-call-logs'),
            __('Vapi Call Logs', 'vapi-call-logs'),
            'manage_options',
            'vapi-call-logs',
            array($this, 'admin_page'),
            'dashicons-phone',
            30
        );
        
        // Call Logs submenu
        add_submenu_page(
            'vapi-call-logs',
            __('Call Logs', 'vapi-call-logs'),
            __('Call Logs', 'vapi-call-logs'),
            'manage_options',
            'vapi-call-logs',
            array($this, 'admin_page')
        );
        
        // Billing submenu
        add_submenu_page(
            'vapi-call-logs',
            __('Billing', 'vapi-call-logs'),
            __('Billing', 'vapi-call-logs'),
            'manage_options',
            'vapi-call-logs-billing',
            array($this, 'billing_page')
        );
        
        // Sync Center submenu (new)
        add_submenu_page(
            'vapi-call-logs',
            __('Sync Center', 'vapi-call-logs'),
            __('Sync Center', 'vapi-call-logs'),
            'manage_options',
            'vapi-call-logs-sync-center',
            array($this, 'sync_center_page')
        );
        
        // Organizations submenu
        add_submenu_page(
            'vapi-call-logs',
            __('Organizations', 'vapi-call-logs'),
            __('Organizations', 'vapi-call-logs'),
            'manage_options',
            'vapi-call-logs-organizations',
            array($this, 'organizations_page')
        );
        
        // Settings submenu
        add_submenu_page(
            'vapi-call-logs',
            __('Settings', 'vapi-call-logs'),
            __('Settings', 'vapi-call-logs'),
            'manage_options',
            'vapi-call-logs-settings',
            array($this, 'settings_page')
        );
    }
    
    public function admin_init() {
        register_setting('vapi_call_logs_settings', 'vapi_refresh_interval');
        
        add_settings_section(
            'vapi_call_logs_main',
            __('General Settings', 'vapi-call-logs'),
            null,
            'vapi-call-logs-settings'
        );
        
        add_settings_field(
            'vapi_refresh_interval',
            __('Refresh Interval (seconds)', 'vapi-call-logs'),
            array($this, 'refresh_interval_field'),
            'vapi-call-logs-settings',
            'vapi_call_logs_main'
        );
    }
    
    public function refresh_interval_field() {
        $interval = get_option('vapi_refresh_interval', 30);
        echo '<input type="number" name="vapi_refresh_interval" value="' . esc_attr($interval) . '" min="10" max="300" />';
        echo '<p class="description">' . __('How often to refresh call logs automatically (10-300 seconds)', 'vapi-call-logs') . '</p>';
    }
    
    public function admin_enqueue_scripts($hook) {
        if (strpos($hook, 'vapi-call-logs') === false) {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_enqueue_script('vapi-call-logs-admin', VAPI_CALL_LOGS_PLUGIN_URL . 'assets/admin.js', array('jquery'), VAPI_CALL_LOGS_VERSION, true);
        wp_enqueue_style('vapi-call-logs-admin', VAPI_CALL_LOGS_PLUGIN_URL . 'assets/admin.css', array(), VAPI_CALL_LOGS_VERSION);
        
        // Localize script with ajax url and nonce
        wp_localize_script('vapi-call-logs-admin', 'vapiAjax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('vapi_call_logs_nonce'),
        ));
    }
    
    public function admin_page() {
        $organizations = $this->database->get_organizations(true);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="notice notice-info">
                <p><?php _e('Data displayed here is from your local database only. Use the Sync button in Organizations to import new data from Vapi.', 'vapi-call-logs'); ?></p>
            </div>
            
            <div class="vapi-call-logs-filters">
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <select id="organization-filter">
                            <option value=""><?php _e('All Organizations', 'vapi-call-logs'); ?></option>
                            <?php foreach ($organizations as $org): ?>
                                <option value="<?php echo esc_attr($org->id); ?>">
                                    <?php echo esc_html($org->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <select id="status-filter">
                            <option value=""><?php _e('All Status', 'vapi-call-logs'); ?></option>
                            <option value="queued"><?php _e('Queued', 'vapi-call-logs'); ?></option>
                            <option value="ringing"><?php _e('Ringing', 'vapi-call-logs'); ?></option>
                            <option value="in-progress"><?php _e('In Progress', 'vapi-call-logs'); ?></option>
                            <option value="ended"><?php _e('Ended', 'vapi-call-logs'); ?></option>
                        </select>
                        
                        <input type="date" id="date-from" placeholder="<?php _e('From Date', 'vapi-call-logs'); ?>">
                        <input type="date" id="date-to" placeholder="<?php _e('To Date', 'vapi-call-logs'); ?>">
                        
                        <div class="phone-search-container">
                            <input type="text" id="phone-search" placeholder="<?php _e('Search phone number...', 'vapi-call-logs'); ?>" />
                            <button type="button" id="clear-search" title="Clear search">&times;</button>
                        </div>
                        
                        <button type="button" id="filter-logs" class="button"><?php _e('Filter', 'vapi-call-logs'); ?></button>
                        <button type="button" id="refresh-logs" class="button button-primary"><?php _e('Refresh', 'vapi-call-logs'); ?></button>
                    </div>
                    
                    <div class="alignright">
                        <span class="displaying-num" id="total-logs"><?php _e('0 items', 'vapi-call-logs'); ?></span>
                    </div>
                </div>
            </div>
            
            <div id="vapi-loading" style="display: none;">
                <p><?php _e('Loading call logs from database...', 'vapi-call-logs'); ?></p>
            </div>
            
            <table class="wp-list-table widefat fixed striped" id="vapi-call-logs-table">
                <thead>
                    <tr>
                        <th class="check-column"><input type="checkbox" id="cb-select-all"></th>
                        <th><?php _e('Organization', 'vapi-call-logs'); ?></th>
                        <th><?php _e('Call ID', 'vapi-call-logs'); ?></th>
                        <th><?php _e('Phone Number', 'vapi-call-logs'); ?></th>
                        <th><?php _e('Duration', 'vapi-call-logs'); ?></th>
                        <th><?php _e('Status', 'vapi-call-logs'); ?></th>
                        <th><?php _e('Recording', 'vapi-call-logs'); ?></th>
                        <th><?php _e('Date', 'vapi-call-logs'); ?></th>
                        <th><?php _e('Actions', 'vapi-call-logs'); ?></th>
                    </tr>
                </thead>
                <tbody id="vapi-call-logs-tbody">
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px;">
                            <p><?php _e('Loading data from database...', 'vapi-call-logs'); ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <div id="call-details-modal" style="display: none;">
                <div class="modal-content">
                    <span class="close">&times;</span>
                    <h2><?php _e('Call Details', 'vapi-call-logs'); ?></h2>
                    <div id="call-details-content"></div>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function organizations_page() {
        $organizations = $this->database->get_organizations();
        ?>
        <div class="wrap">
            <h1>
                <?php _e('Organizations', 'vapi-call-logs'); ?>
                <button type="button" class="button button-primary" onclick="openAddOrgModal()">
                    <?php _e('Add Organization', 'vapi-call-logs'); ?>
                </button>
            </h1>
            
            <div class="notice notice-info">
                <p><?php _e('Manage your Vapi.ai organizations. Sync settings are now handled in the', 'vapi-call-logs'); ?> 
                   <a href="?page=vapi-call-logs-sync-center"><?php _e('Sync Center', 'vapi-call-logs'); ?></a>
                </p>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('ID', 'vapi-call-logs'); ?></th>
                        <th><?php _e('Organization', 'vapi-call-logs'); ?></th>
                        <th><?php _e('API Key', 'vapi-call-logs'); ?></th>
                        <th><?php _e('Database', 'vapi-call-logs'); ?></th>
                        <th><?php _e('Status', 'vapi-call-logs'); ?></th>
                        <th><?php _e('Actions', 'vapi-call-logs'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($organizations as $org): ?>
                        <tr>
                            <td>
                                <strong>#<?php echo esc_html($org->id); ?></strong>
                            </td>
                            <td>
                                <strong><?php echo esc_html($org->name); ?></strong>
                                <?php if ($org->description): ?>
                                    <br><small style="color: #666;"><?php echo esc_html($org->description); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($org->api_key)): ?>
                                    <code><?php echo esc_html(substr($org->api_key, 0, 10) . '...'); ?></code>
                                <?php else: ?>
                                    <span style="color: red;"><?php _e('Not set', 'vapi-call-logs'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($org->use_separate_db): ?>
                                    <span class="storage-badge storage-custom">
                                        <?php _e('External DB', 'vapi-call-logs'); ?>
                                        <br><small><?php echo esc_html($org->db_host . '/' . $org->db_name); ?></small>
                                    </span>
                                <?php else: ?>
                                    <span class="storage-badge storage-default"><?php _e('Local DB', 'vapi-call-logs'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="org-status <?php echo $org->is_active ? 'active' : 'inactive'; ?>">
                                    <?php echo $org->is_active ? __('Active', 'vapi-call-logs') : __('Inactive', 'vapi-call-logs'); ?>
                                </span>
                            </td>
                            <td>
                                <button class="button button-small" onclick="editOrganization(<?php echo esc_attr($org->id); ?>)" 
                                        data-org='<?php echo esc_attr(json_encode($org)); ?>'>
                                    <?php _e('Edit', 'vapi-call-logs'); ?>
                                </button>
                                <button class="button button-small" onclick="deleteOrganization(<?php echo esc_attr($org->id); ?>)">
                                    <?php _e('Delete', 'vapi-call-logs'); ?>
                                </button>
                                <a href="?page=vapi-call-logs-sync-center" class="button button-small">
                                    <?php _e('Sync Settings', 'vapi-call-logs'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Simplified Organization Modal -->
        <?php $this->render_simplified_organization_modal(); ?>
        
        <!-- JavaScript -->
        <?php $this->render_simplified_organizations_js(); ?>
        <?php
    }
    
    private function render_simplified_organization_modal() {
        ?>
        <div id="org-modal-backdrop" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 99999;">
            <div id="org-modal" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 0; border-radius: 8px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto;">
                <div style="padding: 20px; border-bottom: 1px solid #ddd;">
                    <h2 id="modal-title" style="margin: 0;"><?php _e('Add Organization', 'vapi-call-logs'); ?></h2>
                </div>
                
                <div style="padding: 30px;">
                    <!-- Basic Information -->
                    <div style="margin-bottom: 30px;">
                        <h3><?php _e('Organization Details', 'vapi-call-logs'); ?></h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Name', 'vapi-call-logs'); ?> *</th>
                                <td><input type="text" id="org-name" style="width: 100%;" placeholder="Organization Name"></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Vapi.ai API Key', 'vapi-call-logs'); ?> *</th>
                                <td><input type="password" id="org-api-key" style="width: 100%;" placeholder="API Key"></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Description', 'vapi-call-logs'); ?></th>
                                <td><textarea id="org-description" style="width: 100%; height: 80px;" placeholder="Optional description"></textarea></td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- External Database Settings -->
                    <div style="margin-bottom: 30px;">
                        <h3><?php _e('Database Settings', 'vapi-call-logs'); ?></h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Storage Location', 'vapi-call-logs'); ?></th>
                                <td>
                                    <label>
                                        <input type="radio" name="db-storage" value="local" checked onchange="toggleDatabaseFields(false)"> 
                                        <?php _e('Local WordPress Database', 'vapi-call-logs'); ?>
                                    </label>
                                    <br>
                                    <label style="margin-top: 10px; display: block;">
                                        <input type="radio" name="db-storage" value="external" onchange="toggleDatabaseFields(true)"> 
                                        <?php _e('External Database', 'vapi-call-logs'); ?>
                                    </label>
                                    <p class="description"><?php _e('Choose where to store call data for this organization', 'vapi-call-logs'); ?></p>
                                </td>
                            </tr>
                        </table>
                        
                        <div id="external-db-fields" style="display: none; margin-top: 20px; padding: 20px; background: #f9f9f9; border-radius: 4px;">
                            <h4><?php _e('External Database Connection', 'vapi-call-logs'); ?></h4>
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php _e('Host', 'vapi-call-logs'); ?></th>
                                    <td><input type="text" id="db-host" style="width: 100%;" placeholder="localhost"></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Database Name', 'vapi-call-logs'); ?></th>
                                    <td><input type="text" id="db-name" style="width: 100%;" placeholder="database_name"></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Username', 'vapi-call-logs'); ?></th>
                                    <td><input type="text" id="db-user" style="width: 100%;" placeholder="username"></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Password', 'vapi-call-logs'); ?></th>
                                    <td><input type="password" id="db-password" style="width: 100%;" placeholder="password"></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Port', 'vapi-call-logs'); ?></th>
                                    <td><input type="number" id="db-port" value="3306" style="width: 100%;"></td>
                                </tr>
                            </table>
                            <p class="submit">
                                <button type="button" class="button" onclick="testDatabaseConnection()" id="test-db-btn">
                                    <?php _e('Test Connection', 'vapi-call-logs'); ?>
                                </button>
                                <span id="db-test-result" style="margin-left: 15px;"></span>
                            </p>
                        </div>
                    </div>
                </div>
                
                <div style="padding: 20px; border-top: 1px solid #ddd; text-align: right; background: #f5f5f5;">
                    <button type="button" class="button" onclick="closeOrgModal()"><?php _e('Cancel', 'vapi-call-logs'); ?></button>
                    <button type="button" class="button button-primary" onclick="saveOrganization()"><?php _e('Save Organization', 'vapi-call-logs'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function render_simplified_organizations_js() {
        ?>
        <script>
        var currentEditId = null;
        
        function toggleDatabaseFields(show) {
            jQuery('#external-db-fields').toggle(show);
        }
        
        function openAddOrgModal() {
            currentEditId = null;
            jQuery('#modal-title').text('Add Organization');
            jQuery('#org-modal input, #org-modal textarea').val('');
            jQuery('input[name="db-storage"][value="local"]').prop('checked', true);
            jQuery('#external-db-fields').hide();
            jQuery('#org-modal-backdrop').show();
        }
        
        function editOrganization(id) {
            currentEditId = id;
            jQuery('#modal-title').text('Edit Organization');
            var btn = jQuery('[onclick*="editOrganization(' + id + ')"]');
            var orgData = JSON.parse(btn.attr('data-org'));
            
            jQuery('#org-name').val(orgData.name);
            jQuery('#org-api-key').val(''); // Don't pre-fill password field
            jQuery('#org-description').val(orgData.description);
            
            if (orgData.use_separate_db == 1) {
                jQuery('input[name="db-storage"][value="external"]').prop('checked', true);
                jQuery('#external-db-fields').show();
                jQuery('#db-host').val(orgData.db_host);
                jQuery('#db-port').val(orgData.db_port);
                jQuery('#db-name').val(orgData.db_name);
                jQuery('#db-user').val(orgData.db_user);
                // Don't pre-fill password field
            } else {
                jQuery('input[name="db-storage"][value="local"]').prop('checked', true);
                jQuery('#external-db-fields').hide();
            }
            
            jQuery('#org-modal-backdrop').show();
        }
        
        function closeOrgModal() {
            jQuery('#org-modal-backdrop').hide();
        }
        
        function testDatabaseConnection() {
            var host = jQuery('#db-host').val();
            var name = jQuery('#db-name').val();
            var user = jQuery('#db-user').val();
            var password = jQuery('#db-password').val();
            var port = jQuery('#db-port').val();
            
            if (!host || !name || !user) {
                alert('Please fill in host, database name, and username');
                return;
            }
            
            jQuery('#test-db-btn').prop('disabled', true).text('Testing...');
            jQuery('#db-test-result').text('');
            
            jQuery.post(vapiAjax.ajax_url, {
                action: 'test_db_connection',
                nonce: vapiAjax.nonce,
                host: host,
                name: name,
                user: user,
                password: password,
                port: port,
                organization_id: currentEditId
            })
            .done(function(response) {
                if (response.success) {
                    jQuery('#db-test-result').html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                } else {
                    jQuery('#db-test-result').html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                }
            })
            .fail(function() {
                jQuery('#db-test-result').html('<span style="color: red;">✗ Network error</span>');
            })
            .always(function() {
                jQuery('#test-db-btn').prop('disabled', false).text('Test Connection');
            });
        }
        
        function saveOrganization() {
            // Validate required fields
            if (!jQuery('#org-name').val().trim()) {
                alert('Organization name is required');
                return;
            }
            
            // For edit mode, API key is optional (keep existing if empty)
            // For new org, API key is required
            if (!currentEditId && !jQuery('#org-api-key').val().trim()) {
                alert('API key is required for new organizations');
                return;
            }
            
            var useExternalDb = jQuery('input[name="db-storage"]:checked').val() === 'external';
            
            var data = {
                action: currentEditId ? 'update_organization' : 'add_organization',
                nonce: vapiAjax.nonce,
                name: jQuery('#org-name').val(),
                api_key: jQuery('#org-api-key').val(),
                description: jQuery('#org-description').val(),
                storage_settings: {
                    use_separate_db: useExternalDb ? 1 : 0
                }
            };
            
            if (currentEditId) {
                data.organization_id = currentEditId;
            }
            
            if (useExternalDb) {
                data.storage_settings.db_host = jQuery('#db-host').val();
                data.storage_settings.db_port = jQuery('#db-port').val();
                data.storage_settings.db_name = jQuery('#db-name').val();
                data.storage_settings.db_user = jQuery('#db-user').val();
                data.storage_settings.db_password = jQuery('#db-password').val();
            }
            
            jQuery.post(vapiAjax.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    alert('Organization saved successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + (response.data?.message || 'Failed to save'));
                }
            })
            .fail(function() {
                alert('Network error. Please try again.');
            });
        }
        
        function deleteOrganization(id) {
            if (!confirm('Delete this organization and all its data?')) {
                return;
            }
            
            jQuery.post(vapiAjax.ajax_url, {
                action: 'delete_organization',
                nonce: vapiAjax.nonce,
                organization_id: id
            })
            .done(function(response) {
                if (response.success) {
                    alert('Organization deleted successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + (response.data?.message || 'Failed to delete'));
                }
            })
            .fail(function() {
                alert('Network error. Please try again.');
            });
        }
        </script>
        
        <style>
        .storage-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .storage-default {
            background: #e3f2fd;
            color: #1565c0;
        }
        .storage-custom {
            background: #f3e5f5;
            color: #7b1fa2;
        }
        .org-status {
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .org-status.active {
            background: #d4edda;
            color: #155724;
        }
        .org-status.inactive {
            background: #f8d7da;
            color: #721c24;
        }
        </style>
        <?php
    }
    
    public function billing_page() {
        $organizations = $this->database->get_organizations(true);
        $selected_org = isset($_GET['organization']) ? intval($_GET['organization']) : null;
        $selected_month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
        $selected_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
        
        // Get billing data
        $billing_data = $this->database->get_billing_data($selected_org, $selected_month, $selected_year);
        
        ?>
        <div class="wrap">
            <h1>
                <?php _e('Billing Management', 'vapi-call-logs'); ?>
                <button type="button" class="button button-primary" onclick="exportBillingCSV()" style="float: right;">
                    <?php _e('Export to CSV', 'vapi-call-logs'); ?>
                </button>
            </h1>
            
            <!-- Filters -->
            <div class="tablenav top">
                <div class="alignleft actions">
                    <select id="billing-org-filter" onchange="filterBilling()">
                        <option value=""><?php _e('All Organizations', 'vapi-call-logs'); ?></option>
                        <?php foreach ($organizations as $org): ?>
                            <option value="<?php echo $org->id; ?>" <?php echo $selected_org == $org->id ? 'selected' : ''; ?>>
                                <?php echo esc_html($org->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select id="billing-month-filter" onchange="filterBilling()">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $selected_month == $m ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    
                    <select id="billing-year-filter" onchange="filterBilling()">
                        <?php 
                        $current_year = date('Y');
                        for ($y = $current_year; $y >= $current_year - 2; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $selected_year == $y ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    
                    <button type="button" class="button" onclick="filterBilling()">
                        <?php _e('Filter', 'vapi-call-logs'); ?>
                    </button>
                </div>
            </div>
            
            <!-- Summary Cards -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0;">
                <?php
                $total_minutes = 0;
                $total_cost = 0;
                $total_billing = 0;
                
                foreach ($billing_data as $data) {
                    $total_minutes += $data->total_minutes;
                    $total_cost += $data->actual_cost;
                    $billing_amount = $data->total_minutes * ($data->billing_rate_per_minute ?: 0.05);
                    $total_billing += $billing_amount;
                }
                ?>
                
                <div class="card" style="padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <h3 style="margin-top: 0; color: #666; font-size: 14px;"><?php _e('Total Minutes', 'vapi-call-logs'); ?></h3>
                    <p style="font-size: 32px; font-weight: bold; margin: 0; color: #2271b1;">
                        <?php echo number_format($total_minutes, 2); ?>
                    </p>
                </div>
                
                <div class="card" style="padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <h3 style="margin-top: 0; color: #666; font-size: 14px;"><?php _e('Actual Cost', 'vapi-call-logs'); ?></h3>
                    <p style="font-size: 32px; font-weight: bold; margin: 0; color: #d63638;">
                        €<?php echo number_format($total_cost, 2); ?>
                    </p>
                </div>
                
                <div class="card" style="padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <h3 style="margin-top: 0; color: #666; font-size: 14px;"><?php _e('Total Billing', 'vapi-call-logs'); ?></h3>
                    <p style="font-size: 32px; font-weight: bold; margin: 0; color: #00a32a;">
                        €<?php echo number_format($total_billing, 2); ?>
                    </p>
                </div>
                
                <div class="card" style="padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <h3 style="margin-top: 0; color: #666; font-size: 14px;"><?php _e('Profit Margin', 'vapi-call-logs'); ?></h3>
                    <p style="font-size: 32px; font-weight: bold; margin: 0; color: #8e44ad;">
                        €<?php echo number_format($total_billing - $total_cost, 2); ?>
                    </p>
                </div>
            </div>
            
            <!-- Billing Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Organization', 'vapi-call-logs'); ?></th>
                        <th><?php _e('Period', 'vapi-call-logs'); ?></th>
                        <th><?php _e('Total Calls', 'vapi-call-logs'); ?></th>
                        <th><?php _e('Total Minutes', 'vapi-call-logs'); ?></th>
                        <th><?php _e('Actual Cost', 'vapi-call-logs'); ?></th>
                        <th><?php _e('Cost/Min', 'vapi-call-logs'); ?></th>
                        <th><?php _e('Billing Rate/Min', 'vapi-call-logs'); ?></th>
                        <th><?php _e('Total Billing', 'vapi-call-logs'); ?></th>
                        <th><?php _e('Actions', 'vapi-call-logs'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($billing_data)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center;">
                                <?php _e('No billing data found for the selected period.', 'vapi-call-logs'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($billing_data as $data): 
                            $billing_settings = $this->database->get_billing_settings($data->organization_id);
                            $billing_rate = $billing_settings->billing_rate_per_minute ?: 0.05;
                            $billing_amount = $data->total_minutes * $billing_rate;
                            $actual_cost_per_min = $data->total_minutes > 0 ? $data->actual_cost / $data->total_minutes : 0;
                        ?>
                            <tr>
                                <td><strong><?php echo esc_html($data->organization_name); ?></strong></td>
                                <td><?php echo date('F Y', mktime(0, 0, 0, $data->month, 1, $data->year)); ?></td>
                                <td><?php echo number_format($data->total_calls); ?></td>
                                <td><?php echo number_format($data->total_minutes, 2); ?> min</td>
                                <td>€<?php echo number_format($data->actual_cost, 2); ?></td>
                                <td>€<?php echo number_format($actual_cost_per_min, 4); ?></td>
                                <td>
                                    <input type="number" 
                                           id="billing-rate-<?php echo $data->organization_id; ?>"
                                           value="<?php echo number_format($billing_rate, 4); ?>" 
                                           step="0.0001" 
                                           min="0" 
                                           style="width: 100px;"
                                           onchange="updateBillingRate(<?php echo $data->organization_id; ?>, this.value)">
                                </td>
                                <td>
                                    <strong style="color: #00a32a;">
                                        €<span id="total-billing-<?php echo $data->organization_id; ?>">
                                            <?php echo number_format($billing_amount, 2); ?>
                                        </span>
                                    </strong>
                                </td>
                                <td>
                                    <button class="button button-small" onclick="viewBillingDetails(<?php echo $data->organization_id; ?>)">
                                        <?php _e('Details', 'vapi-call-logs'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <script>
        function filterBilling() {
            var org = jQuery('#billing-org-filter').val();
            var month = jQuery('#billing-month-filter').val();
            var year = jQuery('#billing-year-filter').val();
            
            var url = '?page=vapi-call-logs-billing';
            if (org) url += '&organization=' + org;
            url += '&month=' + month + '&year=' + year;
            
            window.location.href = url;
        }
        
        function updateBillingRate(orgId, rate) {
            jQuery.post(vapiAjax.ajax_url, {
                action: 'update_billing_rate',
                nonce: vapiAjax.nonce,
                organization_id: orgId,
                billing_rate: rate
            })
            .done(function(response) {
                if (response.success) {
                    // Recalculate total
                    var minutes = parseFloat(jQuery('#billing-rate-' + orgId).closest('tr').find('td:eq(3)').text());
                    var newTotal = minutes * parseFloat(rate);
                    jQuery('#total-billing-' + orgId).text(newTotal.toFixed(2));
                    
                    // Show success message
                    alert('Billing rate updated successfully!');
                } else {
                    alert('Error updating billing rate');
                }
            });
        }
        
        function exportBillingCSV() {
            var org = jQuery('#billing-org-filter').val();
            var month = jQuery('#billing-month-filter').val();
            var year = jQuery('#billing-year-filter').val();
            
            window.location.href = vapiAjax.ajax_url + '?action=export_billing_csv&nonce=' + vapiAjax.nonce + 
                                   '&organization=' + org + '&month=' + month + '&year=' + year;
        }
        
        function viewBillingDetails(orgId) {
            // Redirect to call logs with org filter
            window.location.href = '?page=vapi-call-logs&organization=' + orgId;
        }
        </script>
        <?php
    }
    
    public function settings_page() {
        $organizations = $this->database->get_organizations(true);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('vapi_call_logs_settings');
                do_settings_sections('vapi-call-logs-settings');
                submit_button();
                ?>
            </form>
            
            <div class="card">
                <h2><?php _e('API Connection Test', 'vapi-call-logs'); ?></h2>
                <p><?php _e('Test the API connection for a specific organization.', 'vapi-call-logs'); ?></p>
                <select id="test-org-select">
                    <option value=""><?php _e('Choose an organization...', 'vapi-call-logs'); ?></option>
                    <?php foreach ($organizations as $org): ?>
                        <option value="<?php echo esc_attr($org->id); ?>"><?php echo esc_html($org->name); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="test-api-connection" class="button"><?php _e('Test Connection', 'vapi-call-logs'); ?></button>
                <div id="api-test-result"></div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $("#test-api-connection").click(function(e) {
                e.preventDefault();
                var selectedOrgId = $("#test-org-select").val();
                if (!selectedOrgId) {
                    $("#api-test-result").html("<p style='color: orange;'>Please select an organization first</p>");
                    return;
                }
                
                var $btn = $(this);
                $btn.text("Testing...").prop("disabled", true);
                
                $.post(vapiAjax.ajax_url, {
                    action: 'test_vapi_connection',
                    nonce: vapiAjax.nonce,
                    organization_id: selectedOrgId
                })
                .done(function(response) {
                    $btn.text("Test Connection").prop("disabled", false);
                    if (response.success) {
                        $("#api-test-result").html("<p style='color: green;'>✔ " + response.data.message + "</p>");
                    } else {
                        $("#api-test-result").html("<p style='color: red;'>✗ " + response.data.message + "</p>");
                    }
                })
                .fail(function() {
                    $btn.text("Test Connection").prop("disabled", false);
                    $("#api-test-result").html("<p style='color: red;'>✗ Network error</p>");
                });
            });
        });
        </script>
        <?php
    }
    
    public function sync_center_page() {
        $organizations = $this->database->get_organizations(true);
        ?>
        <div class="wrap vapi-sync-center" style="max-width: none;">
            <h1><?php _e('Sync Center', 'vapi-call-logs'); ?></h1>
            
            <div class="notice notice-info">
                <p><?php _e('Manage data synchronization from Vapi.ai API to your local database.', 'vapi-call-logs'); ?></p>
            </div>
            
            <!-- Global Sync Settings -->
            <div class="card responsive-card" style="max-width: none;">
                <h2><?php _e('Global Default Settings', 'vapi-call-logs'); ?></h2>
                <p class="description"><?php _e('These are default settings for new organizations. Each organization can have its own custom settings.', 'vapi-call-logs'); ?></p>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="global-retention-days"><?php _e('Default Data Retention (Days)', 'vapi-call-logs'); ?></label>
                        <input type="number" id="global-retention-days" value="<?php echo get_option('vapi_global_retention_days', 30); ?>" min="0" max="365" />
                        <p class="description"><?php _e('Default days to keep call data (0 = forever)', 'vapi-call-logs'); ?></p>
                    </div>
                    
                    <div class="form-group">
                        <label for="global-sync-days"><?php _e('Default Sync Range (Days)', 'vapi-call-logs'); ?></label>
                        <input type="number" id="global-sync-days" value="<?php echo get_option('vapi_global_sync_days', 14); ?>" min="1" max="30" />
                        <p class="description"><?php _e('Default days back to sync from Vapi API', 'vapi-call-logs'); ?></p>
                    </div>
                    
                    <div class="form-group">
                        <label for="global-sync-frequency"><?php _e('Default Auto-Sync Frequency', 'vapi-call-logs'); ?></label>
                        <select id="global-sync-frequency">
                            <option value="every_5_minutes" <?php selected(get_option('vapi_global_sync_frequency', 'hourly'), 'every_5_minutes'); ?>><?php _e('Every 5 Minutes', 'vapi-call-logs'); ?></option>
                            <option value="every_15_minutes" <?php selected(get_option('vapi_global_sync_frequency', 'hourly'), 'every_15_minutes'); ?>><?php _e('Every 15 Minutes', 'vapi-call-logs'); ?></option>
                            <option value="every_30_minutes" <?php selected(get_option('vapi_global_sync_frequency', 'hourly'), 'every_30_minutes'); ?>><?php _e('Every 30 Minutes', 'vapi-call-logs'); ?></option>
                            <option value="hourly" <?php selected(get_option('vapi_global_sync_frequency', 'hourly'), 'hourly'); ?>><?php _e('Every Hour', 'vapi-call-logs'); ?></option>
                            <option value="twicedaily" <?php selected(get_option('vapi_global_sync_frequency', 'hourly'), 'twicedaily'); ?>><?php _e('Twice Daily', 'vapi-call-logs'); ?></option>
                            <option value="daily" <?php selected(get_option('vapi_global_sync_frequency', 'hourly'), 'daily'); ?>><?php _e('Once Daily', 'vapi-call-logs'); ?></option>
                        </select>
                        <p class="description"><?php _e('Default frequency for new organizations', 'vapi-call-logs'); ?></p>
                    </div>
                    
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" id="global-delete-after-sync" <?php checked(get_option('vapi_global_delete_after_sync', false)); ?> />
                            <span><?php _e('Default: Delete from Vapi after sync', 'vapi-call-logs'); ?></span>
                        </label>
                        <p class="description warning-text"><?php _e('⚠️ Warning: This permanently removes data from Vapi servers!', 'vapi-call-logs'); ?></p>
                    </div>
                </div>
                <p class="submit">
                    <button type="button" class="button button-primary" onclick="saveGlobalSyncSettings()">
                        <?php _e('Save Default Settings', 'vapi-call-logs'); ?>
                    </button>
                </p>
            </div>
            
            <!-- Organization Sync Management -->
            <div class="card responsive-card" style="max-width: none;">
                <h2><?php _e('Organization Sync Management', 'vapi-call-logs'); ?></h2>
                
                <?php if (empty($organizations)): ?>
                    <p><?php _e('No active organizations found.', 'vapi-call-logs'); ?> 
                       <a href="?page=vapi-call-logs-organizations"><?php _e('Add organizations first', 'vapi-call-logs'); ?></a>
                    </p>
                <?php else: ?>
                    <div class="organizations-sync-container">
                        <?php foreach ($organizations as $org): ?>
                            <?php 
                            $auto_sync_enabled = get_option('vapi_auto_sync_' . $org->id, false);
                            $sync_interval = get_option('vapi_sync_interval_' . $org->id, get_option('vapi_global_sync_frequency', 'hourly'));
                            $last_sync = get_option('vapi_last_auto_sync_' . $org->id, null);
                            $hook = 'vapi_auto_sync_org_' . $org->id;
                            $next_run = wp_next_scheduled($hook);
                            
                            // Get per-organization settings
                            $org_retention_days = get_option('vapi_retention_days_' . $org->id, get_option('vapi_global_retention_days', 30));
                            $org_sync_days = get_option('vapi_sync_days_' . $org->id, get_option('vapi_global_sync_days', 14));
                            $org_delete_after_sync = get_option('vapi_delete_after_sync_' . $org->id, get_option('vapi_global_delete_after_sync', false));
                            ?>
                            <div class="org-sync-card">
                                <div class="org-header">
                                    <h3><?php echo esc_html($org->name); ?> <small>(ID: <?php echo $org->id; ?>)</small></h3>
                                    <div class="org-sync-status">
                                        <?php if ($auto_sync_enabled): ?>
                                            <span class="status-badge active">Auto-Sync Active</span>
                                        <?php else: ?>
                                            <span class="status-badge inactive">Auto-Sync Disabled</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="org-sync-grid">
                                    <!-- Auto-Sync Toggle -->
                                    <div class="sync-control-group">
                                        <label><?php _e('Auto-Sync', 'vapi-call-logs'); ?></label>
                                        <label class="switch">
                                            <input type="checkbox" 
                                                   onchange="toggleAutoSync(<?php echo $org->id; ?>, this.checked)"
                                                   <?php checked($auto_sync_enabled); ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    
                                    <!-- Frequency -->
                                    <div class="sync-control-group">
                                        <label for="sync-freq-<?php echo $org->id; ?>"><?php _e('Frequency', 'vapi-call-logs'); ?></label>
                                        <select id="sync-freq-<?php echo $org->id; ?>" 
                                                onchange="updateSyncFrequency(<?php echo $org->id; ?>, this.value)"
                                                <?php disabled(!$auto_sync_enabled); ?>>
                                            <option value="every_5_minutes" <?php selected($sync_interval, 'every_5_minutes'); ?>><?php _e('Every 5 Minutes', 'vapi-call-logs'); ?></option>
                                            <option value="every_15_minutes" <?php selected($sync_interval, 'every_15_minutes'); ?>><?php _e('Every 15 Minutes', 'vapi-call-logs'); ?></option>
                                            <option value="every_30_minutes" <?php selected($sync_interval, 'every_30_minutes'); ?>><?php _e('Every 30 Minutes', 'vapi-call-logs'); ?></option>
                                            <option value="hourly" <?php selected($sync_interval, 'hourly'); ?>><?php _e('Every Hour', 'vapi-call-logs'); ?></option>
                                            <option value="twicedaily" <?php selected($sync_interval, 'twicedaily'); ?>><?php _e('Twice Daily', 'vapi-call-logs'); ?></option>
                                            <option value="daily" <?php selected($sync_interval, 'daily'); ?>><?php _e('Once Daily', 'vapi-call-logs'); ?></option>
                                        </select>
                                    </div>
                                    
                                    <!-- Data Retention -->
                                    <div class="sync-control-group">
                                        <label for="retention-<?php echo $org->id; ?>"><?php _e('Data Retention (Days)', 'vapi-call-logs'); ?></label>
                                        <input type="number" 
                                               id="retention-<?php echo $org->id; ?>" 
                                               value="<?php echo $org_retention_days; ?>" 
                                               min="0" max="365"
                                               onchange="updateOrgSetting(<?php echo $org->id; ?>, 'retention_days', this.value)">
                                        <small><?php _e('0 = forever', 'vapi-call-logs'); ?></small>
                                    </div>
                                    
                                    <!-- Sync Range -->
                                    <div class="sync-control-group">
                                        <label for="sync-range-<?php echo $org->id; ?>"><?php _e('Sync Range (Days)', 'vapi-call-logs'); ?></label>
                                        <input type="number" 
                                               id="sync-range-<?php echo $org->id; ?>" 
                                               value="<?php echo $org_sync_days; ?>" 
                                               min="1" max="30"
                                               onchange="updateOrgSetting(<?php echo $org->id; ?>, 'sync_days', this.value)">
                                        <small><?php _e('Max 30 days', 'vapi-call-logs'); ?></small>
                                    </div>
                                    
                                    <!-- Delete After Sync -->
                                    <div class="sync-control-group">
                                        <label><?php _e('Delete from Vapi', 'vapi-call-logs'); ?></label>
                                        <label class="checkbox-label">
                                            <input type="checkbox" 
                                                   id="delete-after-<?php echo $org->id; ?>"
                                                   <?php checked($org_delete_after_sync); ?>
                                                   onchange="updateOrgSetting(<?php echo $org->id; ?>, 'delete_after_sync', this.checked)">
                                            <span><?php _e('Delete after sync', 'vapi-call-logs'); ?></span>
                                        </label>
                                    </div>
                                    
                                    <!-- Sync Info -->
                                    <div class="sync-info-group">
                                        <div class="sync-times">
                                            <div>
                                                <strong><?php _e('Last Sync:', 'vapi-call-logs'); ?></strong>
                                                <?php if ($last_sync): ?>
                                                    <?php echo human_time_diff(strtotime($last_sync), current_time('timestamp')) . ' ' . __('ago', 'vapi-call-logs'); ?>
                                                <?php else: ?>
                                                    <?php _e('Never', 'vapi-call-logs'); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <strong><?php _e('Next Sync:', 'vapi-call-logs'); ?></strong>
                                                <?php if ($next_run && $auto_sync_enabled): ?>
                                                    <?php echo __('In', 'vapi-call-logs') . ' ' . human_time_diff($next_run, current_time('timestamp')); ?>
                                                <?php else: ?>
                                                    <?php _e('Not scheduled', 'vapi-call-logs'); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Actions -->
                                    <div class="sync-actions-group">
                                        <button class="button button-primary" onclick="manualSync(<?php echo $org->id; ?>)">
                                            <?php _e('Sync Now', 'vapi-call-logs'); ?>
                                        </button>
                                        <button class="button" onclick="viewOrgLogs(<?php echo $org->id; ?>)">
                                            <?php _e('View Logs', 'vapi-call-logs'); ?>
                                        </button>
                                        <button class="button" onclick="saveOrgSettings(<?php echo $org->id; ?>)">
                                            <?php _e('Save Settings', 'vapi-call-logs'); ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Bulk Actions -->
            <div class="card" style="margin-top: 20px;">
                <h2><?php _e('Bulk Actions', 'vapi-call-logs'); ?></h2>
                <p class="submit">
                    <button type="button" class="button" onclick="syncAllOrganizations()">
                        <?php _e('Sync All Organizations', 'vapi-call-logs'); ?>
                    </button>
                    <button type="button" class="button" onclick="cleanupOldData()">
                        <?php _e('Cleanup Old Data', 'vapi-call-logs'); ?>
                    </button>
                    <button type="button" class="button button-secondary" onclick="resetAllSyncSchedules()">
                        <?php _e('Reset All Schedules', 'vapi-call-logs'); ?>
                    </button>
                </p>
            </div>
        </div>
        
        <!-- Manual Sync Modal -->
        <div id="manual-sync-modal" style="display: none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.7);">
            <div style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 8px; width: 90%; max-width: 600px;">
                <h2 id="sync-modal-title"><?php _e('Manual Sync', 'vapi-call-logs'); ?></h2>
                
                <div style="margin: 20px 0;">
                    <label>
                        <input type="checkbox" id="sync-delete-after"> 
                        <?php _e('Delete from Vapi after sync', 'vapi-call-logs'); ?>
                    </label>
                </div>
                
                <div id="sync-progress-container" style="display: none; margin: 20px 0;">
                    <div style="background: #f0f0f0; height: 20px; border-radius: 10px; margin-bottom: 10px;">
                        <div id="sync-progress-bar" style="width: 0%; background: #4CAF50; height: 100%; border-radius: 10px; transition: width 0.3s;"></div>
                    </div>
                    <p id="sync-status-text" style="text-align: center;"></p>
                    <div id="sync-details-log" style="max-height: 200px; overflow-y: auto; background: #f8f9fa; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 12px; display: none;"></div>
                </div>
                
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="button" onclick="closeManualSyncModal()"><?php _e('Cancel', 'vapi-call-logs'); ?></button>
                    <button type="button" class="button button-primary" id="start-manual-sync" onclick="startManualSync()"><?php _e('Start Sync', 'vapi-call-logs'); ?></button>
                </div>
            </div>
        </div>
        
        <!-- Custom CSS for toggle switches and responsive design -->
        <style>
        /* Full Width Container */
        .vapi-sync-center {
            max-width: none !important;
        }
        
        /* Responsive Cards */
        .vapi-sync-center .card,
        .vapi-sync-center .responsive-card {
            margin: 20px 0;
            padding: 25px;
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
            max-width: none !important;
        }
        
        /* Form Grid for Global Settings */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin: 20px 0;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            font-weight: 600;
            margin-bottom: 8px;
            color: #23282d;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            max-width: 400px;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .form-group .description {
            margin-top: 5px;
            color: #666;
            font-size: 13px;
        }
        
        .form-group .warning-text {
            color: #d63638;
            font-weight: 500;
        }
        
        /* Organizations Container */
        .organizations-sync-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(600px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        /* Organization Card */
        .org-sync-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            background: #f9f9f9;
        }
        
        .org-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #ddd;
        }
        
        .org-header h3 {
            margin: 0;
            font-size: 18px;
            color: #23282d;
        }
        
        .org-header small {
            color: #666;
            font-weight: normal;
        }
        
        /* Status Badges */
        .status-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-badge.active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-badge.inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        /* Organization Sync Grid */
        .org-sync-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-top: 15px;
        }
        
        /* Sync Control Groups */
        .sync-control-group {
            display: flex;
            flex-direction: column;
        }
        
        .sync-control-group label {
            font-weight: 600;
            margin-bottom: 6px;
            font-size: 13px;
            color: #555;
        }
        
        .sync-control-group input,
        .sync-control-group select {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .sync-control-group small {
            margin-top: 4px;
            color: #999;
            font-size: 11px;
        }
        
        /* Sync Info Group */
        .sync-info-group {
            grid-column: span 2;
            background: white;
            padding: 12px;
            border-radius: 4px;
            border: 1px solid #e1e1e1;
        }
        
        .sync-times {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .sync-times strong {
            display: block;
            margin-bottom: 4px;
            color: #555;
            font-size: 12px;
        }
        
        /* Sync Actions */
        .sync-actions-group {
            grid-column: span 3;
            display: flex;
            gap: 10px;
            margin-top: 10px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
        }
        
        .sync-actions-group .button {
            flex: 1;
        }
        
        /* Checkbox Label */
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        
        .checkbox-label input {
            width: auto !important;
            margin: 0;
        }
        
        /* Toggle Switch */
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: #2196F3;
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        /* Responsive Design */
        @media screen and (max-width: 1400px) {
            .organizations-sync-container {
                grid-template-columns: 1fr;
            }
        }
        
        @media screen and (max-width: 1024px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .org-sync-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
            
            .sync-info-group,
            .sync-actions-group {
                grid-column: span 2;
            }
        }
        
        @media screen and (max-width: 782px) {
            .vapi-sync-center .card,
            .vapi-sync-center .responsive-card {
                margin: 15px -10px;
                padding: 20px 15px;
                border-radius: 0;
            }
            
            .organizations-sync-container {
                gap: 15px;
            }
            
            .org-sync-card {
                padding: 15px;
            }
            
            .org-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .org-sync-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .sync-info-group,
            .sync-actions-group {
                grid-column: span 1;
            }
            
            .sync-actions-group {
                flex-direction: column;
            }
            
            .sync-actions-group .button {
                width: 100%;
            }
        }
        
        @media screen and (max-width: 600px) {
            .sync-times {
                grid-template-columns: 1fr;
                gap: 10px;
            }
        }
        </style>
        
        <!-- JavaScript -->
        <script>
        var currentSyncOrgId = null;
        var orgSettings = {};
        
        function saveGlobalSyncSettings() {
            var data = {
                action: 'save_global_sync_settings',
                nonce: vapiAjax.nonce,
                retention_days: jQuery('#global-retention-days').val(),
                sync_days: jQuery('#global-sync-days').val(),
                sync_frequency: jQuery('#global-sync-frequency').val(),
                delete_after_sync: jQuery('#global-delete-after-sync').is(':checked')
            };
            
            jQuery.post(vapiAjax.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    alert('Global settings saved successfully!');
                } else {
                    alert('Error: ' + (response.data?.message || 'Failed to save settings'));
                }
            })
            .fail(function() {
                alert('Network error. Please try again.');
            });
        }
        
        function updateOrgSetting(orgId, settingName, value) {
            if (!orgSettings[orgId]) {
                orgSettings[orgId] = {};
            }
            orgSettings[orgId][settingName] = value;
            
            // Auto-save individual setting
            var data = {
                action: 'save_org_sync_setting',
                nonce: vapiAjax.nonce,
                organization_id: orgId,
                setting_name: settingName,
                setting_value: value
            };
            
            jQuery.post(vapiAjax.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    console.log('Setting saved for org ' + orgId);
                    // Show temporary success indicator
                    var input = jQuery('#' + settingName.replace('_', '-') + '-' + orgId);
                    if (input.length) {
                        input.css('border-color', '#46b450');
                        setTimeout(function() {
                            input.css('border-color', '');
                        }, 2000);
                    }
                }
            });
        }
        
        function saveOrgSettings(orgId) {
            var data = {
                action: 'save_org_sync_settings',
                nonce: vapiAjax.nonce,
                organization_id: orgId,
                retention_days: jQuery('#retention-' + orgId).val(),
                sync_days: jQuery('#sync-range-' + orgId).val(),
                delete_after_sync: jQuery('#delete-after-' + orgId).is(':checked')
            };
            
            jQuery.post(vapiAjax.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    alert('Organization settings saved successfully!');
                } else {
                    alert('Error: ' + (response.data?.message || 'Failed to save settings'));
                }
            })
            .fail(function() {
                alert('Network error. Please try again.');
            });
        }
        
        function viewOrgLogs(orgId) {
            window.location.href = '?page=vapi-call-logs&organization=' + orgId;
        }
        
        function toggleAutoSync(orgId, enabled) {
            var frequency = jQuery('#sync-freq-' + orgId).val();
            
            jQuery.post(vapiAjax.ajax_url, {
                action: 'update_auto_sync_settings',
                nonce: vapiAjax.nonce,
                organization_id: orgId,
                enabled: enabled,
                interval: frequency
            })
            .done(function(response) {
                if (response.success) {
                    jQuery('#sync-freq-' + orgId).prop('disabled', !enabled);
                    // Update status badge
                    var card = jQuery('#sync-freq-' + orgId).closest('.org-sync-card');
                    var statusBadge = card.find('.status-badge');
                    if (enabled) {
                        statusBadge.removeClass('inactive').addClass('active').text('Auto-Sync Active');
                    } else {
                        statusBadge.removeClass('active').addClass('inactive').text('Auto-Sync Disabled');
                    }
                    // Reload after 1 second to update next sync time
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    alert('Error: ' + (response.data?.message || 'Failed to update'));
                    // Revert checkbox
                    jQuery('[onchange*="toggleAutoSync(' + orgId + ')"]').prop('checked', !enabled);
                }
            })
            .fail(function() {
                alert('Network error. Please try again.');
                // Revert checkbox
                jQuery('[onchange*="toggleAutoSync(' + orgId + ')"]').prop('checked', !enabled);
            });
        }
        
        function updateSyncFrequency(orgId, frequency) {
            jQuery.post(vapiAjax.ajax_url, {
                action: 'update_auto_sync_settings',
                nonce: vapiAjax.nonce,
                organization_id: orgId,
                enabled: true,
                interval: frequency
            })
            .done(function(response) {
                if (response.success) {
                    // Show success indicator
                    var select = jQuery('#sync-freq-' + orgId);
                    select.css('border-color', '#46b450');
                    setTimeout(function() {
                        select.css('border-color', '');
                        location.reload(); // Reload to update next sync time
                    }, 1000);
                } else {
                    alert('Error: ' + (response.data?.message || 'Failed to update frequency'));
                }
            })
            .fail(function() {
                alert('Network error. Please try again.');
            });
        }
        
        function manualSync(orgId) {
            currentSyncOrgId = orgId;
            var deleteAfterSync = jQuery('#delete-after-' + orgId).is(':checked');
            jQuery('#sync-delete-after').prop('checked', deleteAfterSync);
            jQuery('#sync-progress-container').hide();
            jQuery('#manual-sync-modal').show();
        }
        
        function closeManualSyncModal() {
            jQuery('#manual-sync-modal').hide();
            currentSyncOrgId = null;
        }
        
        function startManualSync() {
            if (!currentSyncOrgId) return;
            
            jQuery('#start-manual-sync').prop('disabled', true);
            jQuery('#sync-progress-container').show();
            jQuery('#sync-details-log').show().html('Starting sync process...<br>');
            jQuery('#sync-progress-bar').css('width', '5%');
            jQuery('#sync-status-text').text('Initializing...');
            
            // Simulate progress updates
            setTimeout(function() {
                jQuery('#sync-progress-bar').css('width', '25%');
                jQuery('#sync-status-text').text('Connecting to Vapi API...');
                jQuery('#sync-details-log').append('Establishing connection...<br>');
            }, 500);
            
            setTimeout(function() {
                jQuery('#sync-progress-bar').css('width', '50%');
                jQuery('#sync-status-text').text('Fetching call logs...');
                jQuery('#sync-details-log').append('Retrieving data from API...<br>');
            }, 1000);
            
            // Perform actual sync
            jQuery.post(vapiAjax.ajax_url, {
                action: 'sync_vapi_data',
                nonce: vapiAjax.nonce,
                organization_id: currentSyncOrgId,
                delete_after_import: jQuery('#sync-delete-after').is(':checked')
            })
            .done(function(response) {
                jQuery('#sync-progress-bar').css('width', '100%');
                if (response.success) {
                    jQuery('#sync-status-text').html('<span style="color: green;">✔</span> Sync completed successfully');
                    if (response.data && response.data.stats) {
                        var stats = response.data.stats;
                        var details = '<strong>Sync Results:</strong><br>';
                        details += '• Total calls processed: ' + stats.total + '<br>';
                        details += '• New calls added: ' + stats.new + '<br>';
                        details += '• Existing calls updated: ' + stats.updated + '<br>';
                        details += '• Calls skipped: ' + stats.skipped + '<br>';
                        if (stats.audio_downloaded > 0) {
                            details += '• Audio files downloaded: ' + stats.audio_downloaded + '<br>';
                        }
                        if (stats.deleted > 0) {
                            details += '• Calls deleted from Vapi: ' + stats.deleted + '<br>';
                        }
                        jQuery('#sync-details-log').html(details);
                    }
                    setTimeout(function() {
                        closeManualSyncModal();
                        location.reload();
                    }, 3000);
                } else {
                    jQuery('#sync-progress-bar').css('width', '100%').css('background-color', '#dc3545');
                    jQuery('#sync-status-text').html('<span style="color: red;">✗</span> Sync failed');
                    jQuery('#sync-details-log').append('<span style="color: red;">Error: ' + (response.data?.message || 'Unknown error') + '</span><br>');
                }
            })
            .fail(function(xhr, status, error) {
                jQuery('#sync-progress-bar').css('width', '100%').css('background-color', '#dc3545');
                jQuery('#sync-status-text').html('<span style="color: red;">✗</span> Network error');
                jQuery('#sync-details-log').append('<span style="color: red;">Network connection failed: ' + error + '</span><br>');
            })
            .always(function() {
                jQuery('#start-manual-sync').prop('disabled', false);
            });
        }
        
        function syncAllOrganizations() {
            if (!confirm('Start sync for all active organizations?')) {
                return;
            }
            
            jQuery.post(vapiAjax.ajax_url, {
                action: 'sync_all_organizations',
                nonce: vapiAjax.nonce
            })
            .done(function(response) {
                if (response.success) {
                    alert('Bulk sync started successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + (response.data?.message || 'Failed to start bulk sync'));
                }
            })
            .fail(function() {
                alert('Network error. Please try again.');
            });
        }
        
        function cleanupOldData() {
            if (!confirm('Remove old data based on retention settings?')) {
                return;
            }
            
            jQuery.post(vapiAjax.ajax_url, {
                action: 'run_manual_cleanup',
                nonce: vapiAjax.nonce
            })
            .done(function(response) {
                if (response.success) {
                    alert('Cleanup completed successfully!');
                } else {
                    alert('Error: ' + (response.data?.message || 'Cleanup failed'));
                }
            });
        }
        
        function resetAllSyncSchedules() {
            if (!confirm('Reset all sync schedules? This will stop all auto-sync processes.')) {
                return;
            }
            
            jQuery.post(vapiAjax.ajax_url, {
                action: 'reset_all_sync_schedules',
                nonce: vapiAjax.nonce
            })
            .done(function(response) {
                if (response.success) {
                    alert('All sync schedules have been reset.');
                    location.reload();
                } else {
                    alert('Error: ' + (response.data?.message || 'Failed to reset schedules'));
                }
            });
        }
        
        function showSyncHistory(orgId) {
            // TODO: Implement sync history modal
            alert('Sync history feature coming soon for organization ID: ' + orgId);
        }
        
        // Initialize on document ready
        jQuery(document).ready(function($) {
            console.log('Sync Center JavaScript loaded');
            
            // Add change listeners for validation
            $('input[type="number"]').on('change', function() {
                var min = parseInt($(this).attr('min'));
                var max = parseInt($(this).attr('max'));
                var val = parseInt($(this).val());
                
                if (val < min) $(this).val(min);
                if (val > max) $(this).val(max);
            });
        });
        </script>
        /* Responsive Cards */
        .vapi-sync-center .card,
        .vapi-sync-center .responsive-card {
            margin: 20px 0;
            padding: 20px;
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        
        /* Form Grid */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .form-group label {
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            max-width: 400px;
        }
        
        .form-group .description {
            margin-top: 5px;
            color: #666;
            font-size: 13px;
        }
        
        .form-group .warning-text {
            color: #d63638;
            font-weight: 500;
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        
        .checkbox-label input {
            width: auto !important;
        }
        
        /* Table Responsive Wrapper */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 0 -20px;
            padding: 0 20px;
        }
        
        .sync-table {
            min-width: 600px;
            table-layout: auto;
        }
        
        .sync-frequency-select {
            width: auto;
            min-width: 80px;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        /* Toggle Switch */
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: #2196F3;
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        /* Mobile Only Elements */
        .mobile-only {
            display: none;
        }
        
        .mobile-info {
            margin-top: 10px;
            font-size: 12px;
            line-height: 1.5;
        }
        
        .mobile-label {
            font-weight: 600;
            color: #666;
        }
        
        /* Responsive Design */
        @media screen and (max-width: 1024px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-group input,
            .form-group select {
                max-width: 100%;
            }
        }
        
        @media screen and (max-width: 782px) {
            .vapi-sync-center .card,
            .vapi-sync-center .responsive-card {
                margin: 15px 0;
                padding: 15px;
            }
            
            .table-responsive {
                margin: 0 -15px;
                padding: 0 15px;
            }
            
            .hide-mobile {
                display: none !important;
            }
            
            .mobile-only {
                display: block;
            }
            
            /* Stack table on mobile */
            .sync-table thead {
                display: none;
            }
            
            .sync-table,
            .sync-table tbody,
            .sync-table tr,
            .sync-table td {
                display: block;
                width: 100%;
            }
            
            .sync-table tr {
                margin-bottom: 15px;
                border: 1px solid #ddd;
                padding: 10px;
                background: #fff;
            }
            
            .sync-table td {
                padding: 8px 0;
                border: none;
                position: relative;
                padding-left: 35%;
            }
            
            .sync-table td:before {
                content: attr(data-label);
                position: absolute;
                left: 0;
                width: 30%;
                padding-left: 10px;
                font-weight: 600;
                text-align: left;
            }
            
            .sync-table td:first-child {
                padding-left: 10px;
            }
            
            .sync-table td:first-child:before {
                display: none;
            }
            
            .action-buttons {
                justify-content: flex-start;
            }
            
            .action-buttons .button {
                flex: 0 1 auto;
            }
        }
        
        @media screen and (max-width: 600px) {
            .vapi-sync-center h1 {
                font-size: 23px;
                line-height: 1.3;
            }
            
            .vapi-sync-center h2 {
                font-size: 18px;
            }
            
            .button-primary,
            .button {
                padding: 6px 14px;
                font-size: 13px;
            }
            
            .sync-frequency-select {
                min-width: 100px;
                font-size: 13px;
            }
        }
        
        @media screen and (max-width: 480px) {
            .vapi-sync-center .card,
            .vapi-sync-center .responsive-card {
                margin: 10px -10px;
                padding: 15px 10px;
                border-left: none;
                border-right: none;
                border-radius: 0;
            }
            
            .table-responsive {
                margin: 0 -10px;
                padding: 0 10px;
            }
        }
        </style>
        
        <!-- JavaScript -->
        <script>
        var currentSyncOrgId = null;
        
        function saveGlobalSyncSettings() {
            var data = {
                action: 'save_global_sync_settings',
                nonce: vapiAjax.nonce,
                retention_days: jQuery('#global-retention-days').val(),
                sync_days: jQuery('#global-sync-days').val(),
                sync_frequency: jQuery('#global-sync-frequency').val(),
                delete_after_sync: jQuery('#global-delete-after-sync').is(':checked')
            };
            
            jQuery.post(vapiAjax.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    alert('<?php _e('Global settings saved successfully!', 'vapi-call-logs'); ?>');
                } else {
                    alert('Error: ' + (response.data?.message || 'Failed to save settings'));
                }
            })
            .fail(function() {
                alert('Network error. Please try again.');
            });
        }
        
        function toggleAutoSync(orgId, enabled) {
            var frequency = jQuery('#sync-freq-' + orgId).val();
            
            jQuery.post(vapiAjax.ajax_url, {
                action: 'update_auto_sync_settings',
                nonce: vapiAjax.nonce,
                organization_id: orgId,
                enabled: enabled,
                interval: frequency
            })
            .done(function(response) {
                if (response.success) {
                    jQuery('#sync-freq-' + orgId).prop('disabled', !enabled);
                    location.reload(); // Refresh to update next sync times
                } else {
                    alert('Error: ' + (response.data?.message || 'Failed to update'));
                    // Revert checkbox
                    jQuery('[onchange*="toggleAutoSync(' + orgId + ')"]').prop('checked', !enabled);
                }
            })
            .fail(function() {
                alert('Network error. Please try again.');
                // Revert checkbox
                jQuery('[onchange*="toggleAutoSync(' + orgId + ')"]').prop('checked', !enabled);
            });
        }
        
        function updateSyncFrequency(orgId, frequency) {
            jQuery.post(vapiAjax.ajax_url, {
                action: 'update_auto_sync_settings',
                nonce: vapiAjax.nonce,
                organization_id: orgId,
                enabled: true,
                interval: frequency
            })
            .done(function(response) {
                if (response.success) {
                    location.reload(); // Refresh to update next sync times
                } else {
                    alert('Error: ' + (response.data?.message || 'Failed to update frequency'));
                }
            });
        }
        
        function manualSync(orgId) {
            currentSyncOrgId = orgId;
            jQuery('#sync-delete-after').prop('checked', false);
            jQuery('#sync-progress-container').hide();
            jQuery('#manual-sync-modal').show();
        }
        
        function closeManualSyncModal() {
            jQuery('#manual-sync-modal').hide();
            currentSyncOrgId = null;
        }
        
        function startManualSync() {
            if (!currentSyncOrgId) return;
            
            jQuery('#start-manual-sync').prop('disabled', true);
            jQuery('#sync-progress-container').show();
            jQuery('#sync-details-log').show().html('Starting sync process...<br>');
            jQuery('#sync-progress-bar').css('width', '5%');
            jQuery('#sync-status-text').text('Initializing...');
            
            jQuery.post(vapiAjax.ajax_url, {
                action: 'sync_vapi_data',
                nonce: vapiAjax.nonce,
                organization_id: currentSyncOrgId,
                delete_after_import: jQuery('#sync-delete-after').is(':checked')
            })
            .done(function(response) {
                jQuery('#sync-progress-bar').css('width', '100%');
                if (response.success) {
                    jQuery('#sync-status-text').html('✔ Sync completed successfully');
                    if (response.data.stats) {
                        var stats = response.data.stats;
                        var details = '<strong>Sync Results:</strong><br>';
                        details += '• Total calls: ' + stats.total + '<br>';
                        details += '• New calls: ' + stats.new + '<br>';
                        details += '• Updated calls: ' + stats.updated + '<br>';
                        details += '• Audio downloaded: ' + stats.audio_downloaded + '<br>';
                        if (stats.deleted > 0) {
                            details += '• Deleted from Vapi: ' + stats.deleted + '<br>';
                        }
                        jQuery('#sync-details-log').html(details);
                    }
                    setTimeout(function() {
                        closeManualSyncModal();
                        location.reload();
                    }, 3000);
                } else {
                    jQuery('#sync-status-text').html('✗ Sync failed');
                    jQuery('#sync-details-log').append('<span style="color: red;">Error: ' + (response.data?.message || 'Unknown error') + '</span><br>');
                }
            })
            .fail(function() {
                jQuery('#sync-progress-bar').css('width', '100%');
                jQuery('#sync-status-text').html('✗ Network error');
                jQuery('#sync-details-log').append('<span style="color: red;">Network connection failed</span><br>');
            })
            .always(function() {
                jQuery('#start-manual-sync').prop('disabled', false);
            });
        }
        
        function syncAllOrganizations() {
            if (!confirm('<?php _e('Start sync for all active organizations?', 'vapi-call-logs'); ?>')) {
                return;
            }
            
            jQuery.post(vapiAjax.ajax_url, {
                action: 'sync_all_organizations',
                nonce: vapiAjax.nonce
            })
            .done(function(response) {
                if (response.success) {
                    alert('<?php _e('Bulk sync started successfully!', 'vapi-call-logs'); ?>');
                    location.reload();
                } else {
                    alert('Error: ' + (response.data?.message || 'Failed to start bulk sync'));
                }
            })
            .fail(function() {
                alert('Network error. Please try again.');
            });
        }
        
        function cleanupOldData() {
            if (!confirm('<?php _e('Remove old data based on retention settings?', 'vapi-call-logs'); ?>')) {
                return;
            }
            
            jQuery.post(vapiAjax.ajax_url, {
                action: 'run_manual_cleanup',
                nonce: vapiAjax.nonce
            })
            .done(function(response) {
                if (response.success) {
                    alert('<?php _e('Cleanup completed successfully!', 'vapi-call-logs'); ?>');
                } else {
                    alert('Error: ' + (response.data?.message || 'Cleanup failed'));
                }
            });
        }
        
        function resetAllSyncSchedules() {
            if (!confirm('<?php _e('Reset all sync schedules? This will stop all auto-sync processes.', 'vapi-call-logs'); ?>')) {
                return;
            }
            
            jQuery.post(vapiAjax.ajax_url, {
                action: 'reset_all_sync_schedules',
                nonce: vapiAjax.nonce
            })
            .done(function(response) {
                if (response.success) {
                    alert('<?php _e('All sync schedules have been reset.', 'vapi-call-logs'); ?>');
                    location.reload();
                } else {
                    alert('Error: ' + (response.data?.message || 'Failed to reset schedules'));
                }
            });
        }
        
        function showSyncHistory(orgId) {
            // TODO: Implement sync history modal
            alert('Sync history feature coming soon...');
        }
        </script>
        <?php
    }
}