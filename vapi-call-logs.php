<?php
/**
 * Plugin Name: Vapi.ai Call Logs - Multi Organization
 * Plugin URI: https://yoursite.com
 * Description: Ένα plugin που συνδέεται με το Vapi.ai API και εμφανίζει τα call logs από πολλά organizations
 * Version: 2.1.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: vapi-call-logs
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('VAPI_CALL_LOGS_VERSION', '2.1.0');
define('VAPI_CALL_LOGS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VAPI_CALL_LOGS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoload classes
spl_autoload_register(function($class) {
    if (strpos($class, 'VapiCallLogs_') === 0) {
        $class_name = str_replace('VapiCallLogs_', '', $class);
        $class_name = strtolower(str_replace('_', '-', $class_name));
        $file = VAPI_CALL_LOGS_PLUGIN_DIR . 'includes/class-' . $class_name . '.php';
        
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

/**
 * Main plugin class
 */
class VapiCallLogsPlugin {
    
    private $database;
    private $admin_pages;
    private $ajax_handlers;
    private $api_client;
    private $assets;
    private $auto_sync;
    
    public function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        add_action('init', array($this, 'load_textdomain'));
        
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        // Initialize components
        $this->database = new VapiCallLogs_Database();
        $this->api_client = new VapiCallLogs_Api_Client();
        $this->assets = new VapiCallLogs_Assets();
        
        // Initialize auto-sync BEFORE other components that might depend on it
        $this->auto_sync = VapiCallLogs_Auto_Sync::get_instance();
        
        // Initialize admin and ajax handlers
        $this->admin_pages = new VapiCallLogs_Admin_Pages();
        $this->ajax_handlers = new VapiCallLogs_Ajax_Handlers($this->database, $this->api_client);
        
        // Hook everything together
        $this->admin_pages->set_database($this->database);
        $this->admin_pages->set_api_client($this->api_client);
    }
    
    public function load_textdomain() {
        load_plugin_textdomain('vapi-call-logs', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    public function activate() {
        // Create database tables
        $this->database = new VapiCallLogs_Database();
        $this->database->create_tables();
        
        // Set default options
        if (!get_option('vapi_refresh_interval')) {
            add_option('vapi_refresh_interval', 30);
        }
        
        // Create assets
        $this->assets = new VapiCallLogs_Assets();
        $this->assets->create_assets_files();
        
        // Initialize auto-sync schedules
        $this->auto_sync = VapiCallLogs_Auto_Sync::get_instance();
        $this->auto_sync->setup_cron_schedules();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        // Clear all auto-sync schedules
        $this->auto_sync = VapiCallLogs_Auto_Sync::get_instance();
        $this->auto_sync->clear_all_schedules();
        
        // Cleanup if needed
        flush_rewrite_rules();
    }
}

// Initialize the plugin
try {
    $vapi_plugin = new VapiCallLogsPlugin();
    error_log('VAPI PLUGIN: Main class instantiated successfully');
} catch (Exception $e) {
    error_log('VAPI PLUGIN ERROR: ' . $e->getMessage());
    add_action('admin_notices', function() use ($e) {
        echo '<div class="notice notice-error"><p><strong>Vapi Plugin Error:</strong> ' . esc_html($e->getMessage()) . '</p></div>';
    });
}