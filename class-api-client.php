<?php
/**
 * Vapi.ai API Client
 * Version: 3.2.0 - Added bulk delete functionality and improved error handling
 */

if (!defined('ABSPATH')) {
    exit;
}

class VapiCallLogs_Api_Client {
    
    private $base_url = 'https://api.vapi.ai';
    
    /**
     * Test API connection
     */
    public function test_connection($api_key) {
        $url = $this->base_url . '/call?limit=1';
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 15
        );
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            error_log('VAPI API Test Connection Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code === 200) {
            error_log('VAPI API Test Connection: SUCCESS');
            return true;
        } else {
            error_log('VAPI API Test Connection Failed: HTTP ' . $response_code);
            return false;
        }
    }
    
    /**
     * Fetch call logs from API
     */
    public function fetch_call_logs($organization_id, $api_key, $filters = array()) {
        $url = $this->base_url . '/call';
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 30
        );
        
        // Build query parameters
        $query_params = array();
        
        // Use limit of 100 (Vapi's max limit as of 2025)
        $query_params['limit'] = '100';
        
        if (!empty($filters['status_filter'])) {
            $query_params['status'] = $filters['status_filter'];
        }
        
        // Fix date format for Vapi API
        if (!empty($filters['date_from'])) {
            // Use proper ISO 8601 format without timezone
            $query_params['createdAtGt'] = $filters['date_from'] . 'T00:00:00.000Z';
        }
        if (!empty($filters['date_to'])) {
            $query_params['createdAtLt'] = $filters['date_to'] . 'T23:59:59.999Z';
        }
        
        if (!empty($query_params)) {
            $url .= '?' . http_build_query($query_params);
        }
        
        error_log('Vapi API Request for Org ' . $organization_id . ': ' . $url);
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            error_log('Vapi API Error for Org ' . $organization_id . ': ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            error_log('Vapi API HTTP Error for Org ' . $organization_id . ': ' . $response_code);
            error_log('Vapi API Error Response: ' . $response_body);
            
            // Try to decode error message
            $error_data = json_decode($response_body, true);
            if (isset($error_data['message'])) {
                error_log('Vapi API Error Message: ' . $error_data['message']);
            }
            
            return false;
        }
        
        $data = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Vapi API JSON Error for Org ' . $organization_id . ': ' . json_last_error_msg());
            return false;
        }
        
        // Log first 500 chars of response for debugging
        error_log('VAPI API Response for Org ' . $organization_id . ' (first 500 chars): ' . substr($response_body, 0, 500));
        
        // The API returns a direct array with calls
        if (is_array($data)) {
            error_log('VAPI: Found ' . count($data) . ' calls for Org ' . $organization_id);
            return array('data' => $data);
        }
        
        // Fallback for other formats
        if (isset($data['data']) && is_array($data['data'])) {
            error_log('VAPI: Found ' . count($data['data']) . ' calls in wrapped format for Org ' . $organization_id);
            return $data;
        }
        
        error_log('VAPI: No valid data found for Org ' . $organization_id);
        return array('data' => array());
    }
    
    /**
     * Delete a single call from Vapi
     */
    public function delete_call($api_key, $call_id) {
        $url = $this->base_url . '/call/' . $call_id;
        
        error_log('VAPI DELETE: Attempting to delete call ' . $call_id);
        error_log('VAPI DELETE: URL = ' . $url);
        
        $args = array(
            'method' => 'DELETE',
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 15
        );
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            error_log('VAPI DELETE ERROR: Failed to delete call ' . $call_id . ': ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        error_log('VAPI DELETE: Response code = ' . $response_code);
        error_log('VAPI DELETE: Response body = ' . substr($response_body, 0, 500));
        
        // Success codes for DELETE are typically 200, 202, or 204
        if ($response_code >= 200 && $response_code < 300) {
            error_log('VAPI DELETE SUCCESS: Call ' . $call_id . ' deleted successfully');
            return true;
        }
        
        error_log('VAPI DELETE FAILED: Call ' . $call_id . '. HTTP Code: ' . $response_code);
        error_log('VAPI DELETE ERROR RESPONSE: ' . $response_body);
        return false;
    }
    
    /**
     * Bulk delete calls from Vapi with progress tracking
     * NEW FUNCTIONALITY for the test file
     */
    public function bulk_delete_calls($api_key, $call_ids, $delay_ms = 250) {
        if (empty($call_ids)) {
            return array('success' => 0, 'failed' => 0, 'errors' => array());
        }
        
        $total = count($call_ids);
        $success_count = 0;
        $failed_count = 0;
        $errors = array();
        
        error_log('VAPI BULK DELETE: Starting deletion of ' . $total . ' calls');
        
        foreach ($call_ids as $index => $call_id) {
            $progress = $index + 1;
            error_log('VAPI BULK DELETE: Progress ' . $progress . '/' . $total . ' - Deleting call ' . $call_id);
            
            if ($this->delete_call($api_key, $call_id)) {
                $success_count++;
            } else {
                $failed_count++;
                $errors[] = $call_id;
            }
            
            // Add delay to avoid rate limiting
            if ($delay_ms > 0 && $progress < $total) {
                usleep($delay_ms * 1000); // Convert ms to microseconds
            }
            
            // Stop if too many failures (more than 20% failure rate)
            if ($failed_count > 5 && ($failed_count / $progress) > 0.2) {
                error_log('VAPI BULK DELETE: Stopping due to high failure rate');
                break;
            }
        }
        
        $result = array(
            'success' => $success_count,
            'failed' => $failed_count,
            'errors' => $errors,
            'total_processed' => $success_count + $failed_count
        );
        
        error_log('VAPI BULK DELETE COMPLETED: ' . json_encode($result));
        
        return $result;
    }
    
    /**
     * Delete calls with chunking for very large batches
     */
    public function bulk_delete_calls_chunked($api_key, $call_ids, $chunk_size = 10, $delay_between_chunks_seconds = 5) {
        if (empty($call_ids)) {
            return array('success' => 0, 'failed' => 0, 'errors' => array());
        }
        
        $chunks = array_chunk($call_ids, $chunk_size);
        $total_success = 0;
        $total_failed = 0;
        $all_errors = array();
        
        error_log('VAPI BULK DELETE CHUNKED: Processing ' . count($call_ids) . ' calls in ' . count($chunks) . ' chunks of ' . $chunk_size);
        
        foreach ($chunks as $chunk_index => $chunk) {
            error_log('VAPI BULK DELETE CHUNKED: Processing chunk ' . ($chunk_index + 1) . '/' . count($chunks));
            
            $chunk_result = $this->bulk_delete_calls($api_key, $chunk, 200);
            
            $total_success += $chunk_result['success'];
            $total_failed += $chunk_result['failed'];
            $all_errors = array_merge($all_errors, $chunk_result['errors']);
            
            // Delay between chunks to be nice to the API
            if ($chunk_index < count($chunks) - 1 && $delay_between_chunks_seconds > 0) {
                error_log('VAPI BULK DELETE CHUNKED: Waiting ' . $delay_between_chunks_seconds . ' seconds before next chunk');
                sleep($delay_between_chunks_seconds);
            }
        }
        
        return array(
            'success' => $total_success,
            'failed' => $total_failed,
            'errors' => $all_errors,
            'total_processed' => $total_success + $total_failed,
            'chunks_processed' => count($chunks)
        );
    }
    
    /**
     * Fetch ALL call logs with pagination
     */
    public function fetch_all_call_logs($organization_id, $api_key, $filters = array()) {
        $all_calls = array();
        $offset = 0;
        $limit = 100; // Max limit per request
        $has_more = true;
        
        while ($has_more) {
            $url = $this->base_url . '/call';
            
            $args = array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ),
                'timeout' => 30
            );
            
            // Build query parameters
            $query_params = array();
            $query_params['limit'] = (string)$limit;
            $query_params['offset'] = (string)$offset;
            
            if (!empty($filters['status_filter'])) {
                $query_params['status'] = $filters['status_filter'];
            }
            
            if (!empty($filters['date_from'])) {
                $query_params['createdAtGt'] = $filters['date_from'] . 'T00:00:00.000Z';
            }
            if (!empty($filters['date_to'])) {
                $query_params['createdAtLt'] = $filters['date_to'] . 'T23:59:59.999Z';
            }
            
            if (!empty($query_params)) {
                $url .= '?' . http_build_query($query_params);
            }
            
            error_log('VAPI: Fetching calls with offset ' . $offset . ' for Org ' . $organization_id);
            
            $response = wp_remote_get($url, $args);
            
            if (is_wp_error($response)) {
                error_log('VAPI: Error fetching page at offset ' . $offset . ': ' . $response->get_error_message());
                break;
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                error_log('VAPI: HTTP Error ' . $response_code . ' at offset ' . $offset);
                break;
            }
            
            $response_body = wp_remote_retrieve_body($response);
            $data = json_decode($response_body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('VAPI: JSON Error at offset ' . $offset);
                break;
            }
            
            // Check if we got data
            if (is_array($data) && !empty($data)) {
                $all_calls = array_merge($all_calls, $data);
                error_log('VAPI: Got ' . count($data) . ' calls at offset ' . $offset);
                
                // If we got less than limit, we've reached the end
                if (count($data) < $limit) {
                    $has_more = false;
                } else {
                    $offset += $limit;
                }
            } else {
                // No more data
                $has_more = false;
            }
            
            // Safety limit - max 10 pages
            if ($offset >= 1000) {
                error_log('VAPI: Reached maximum offset limit');
                break;
            }
        }
        
        error_log('VAPI: Total calls fetched: ' . count($all_calls) . ' for Org ' . $organization_id);
        return array('data' => $all_calls);
    }
    
    /**
     * Get API rate limit info (if available)
     */
    public function get_rate_limit_info($api_key) {
        $url = $this->base_url . '/call?limit=1';
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 15
        );
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $headers = wp_remote_retrieve_headers($response);
        
        return array(
            'rate_limit' => $headers['x-ratelimit-limit'] ?? null,
            'rate_remaining' => $headers['x-ratelimit-remaining'] ?? null,
            'rate_reset' => $headers['x-ratelimit-reset'] ?? null,
            'all_headers' => $headers
        );
    }
    
    /**
     * Debug API endpoints
     */
    public function debug_api_endpoints($api_key, $organization_name) {
        $endpoints = array(
            'calls' => '/call?limit=10',
            'assistants' => '/assistant?limit=5',
            'phone-numbers' => '/phone-number?limit=5'
        );
        
        $results = array();
        
        foreach ($endpoints as $name => $endpoint) {
            $url = $this->base_url . $endpoint;
            
            $args = array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ),
                'timeout' => 30
            );
            
            $response = wp_remote_get($url, $args);
            
            $result = array(
                'endpoint' => $name,
                'url' => $url,
                'success' => false,
                'http_code' => 0,
                'error' => '',
                'data' => null,
                'headers' => array()
            );
            
            if (is_wp_error($response)) {
                $result['error'] = $response->get_error_message();
            } else {
                $result['http_code'] = wp_remote_retrieve_response_code($response);
                $result['headers'] = wp_remote_retrieve_headers($response);
                $result['success'] = ($result['http_code'] === 200);
                
                if ($result['success']) {
                    $body = wp_remote_retrieve_body($response);
                    $result['data'] = json_decode($body, true);
                    
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $result['error'] = 'JSON Parse Error: ' . json_last_error_msg();
                        $result['success'] = false;
                    }
                } else {
                    $body = wp_remote_retrieve_body($response);
                    $result['error'] = 'HTTP ' . $result['http_code'] . ' - ' . $body;
                }
            }
            
            $results[] = $result;
        }
        
        return $results;
    }
}
?>