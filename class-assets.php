<?php
/**
 * Assets management for Vapi Call Logs Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class VapiCallLogs_Assets {
    
    /**
     * Create assets files
     */
    public function create_assets_files() {
        // Create assets directory
        $assets_dir = VAPI_CALL_LOGS_PLUGIN_DIR . 'assets/';
        if (!file_exists($assets_dir)) {
            wp_mkdir_p($assets_dir);
        }
        
        // Create admin.js file
        $js_content = $this->get_admin_js_content();
        file_put_contents($assets_dir . 'admin.js', $js_content);
        
        // Create admin.css file
        $css_content = $this->get_admin_css_content();
        file_put_contents($assets_dir . 'admin.css', $css_content);
        
        return true;
    }
    
    /**
     * Get admin JavaScript content
     */
    private function get_admin_js_content() {
        return 'jQuery(document).ready(function($) {
    console.log("Vapi Call Logs Multi-Org Admin JS loaded");
    
    var allCallsData = [];
    
    function loadCallLogs() {
        console.log("Loading call logs...");
        $("#vapi-loading").show();
        $("#vapi-call-logs-tbody").html("<tr><td colspan=\'9\'>Loading...</td></tr>");
        
        $.post(vapiAjax.ajax_url, {
            action: "get_vapi_call_logs",
            nonce: vapiAjax.nonce,
            organization_id: $("#organization-filter").val() || "",
            status_filter: $("#status-filter").val() || "",
            date_from: $("#date-from").val() || "",
            date_to: $("#date-to").val() || "",
            phone_search: $("#phone-search").val() || ""
        })
        .done(function(response) {
            console.log("Response received:", response);
            $("#vapi-loading").hide();
            
            if (response.success && response.data) {
                displayCallLogs(response.data);
            } else {
                $("#vapi-call-logs-tbody").html("<tr><td colspan=\'9\'>Error: " + (response.data?.message || "No data") + "</td></tr>");
            }
        })
        .fail(function(xhr, status, error) {
            console.error("AJAX failed:", error);
            $("#vapi-loading").hide();
            $("#vapi-call-logs-tbody").html("<tr><td colspan=\'9\'>Network error: " + error + "</td></tr>");
        });
    }
    
    function displayCallLogs(apiData) {
        console.log("Displaying data:", apiData);
        var tbody = $("#vapi-call-logs-tbody");
        tbody.empty();
        
        var calls = apiData.calls || [];
        var organizations = apiData.organizations || {};
        
        if (!calls || calls.length === 0) {
            tbody.html("<tr><td colspan=\'9\'>No calls found</td></tr>");
            $("#total-logs").text("0 items");
            return;
        }
        
        var phoneSearch = $("#phone-search").val();
        if (phoneSearch) {
            calls = calls.filter(function(call) {
                var phoneNumber = call.customer?.number || call.phoneNumberId || "";
                return phoneNumber.toLowerCase().includes(phoneSearch.toLowerCase());
            });
        }
        
        allCallsData = calls;
        $("#total-logs").text(calls.length + " items");
        
        calls.forEach(function(call, index) {
            var callId = call.id || "unknown_" + index;
            var shortId = callId.length > 8 ? callId.substring(0, 8) + "..." : callId;
            
            var orgName = organizations[call.organization_id] || "Unknown Org";
            
            var phoneNumber = "N/A";
            if (call.customer && call.customer.number) {
                phoneNumber = call.customer.number;
            } else if (call.phoneNumberId) {
                phoneNumber = call.phoneNumberId;
            }
            
            // Διόρθωση του duration - εμφάνιση σε λεπτά
            var duration = "0 min";
            if (call.duration_formatted) {
                // Χρήση του προ-διαμορφωμένου duration από τον server
                duration = call.duration_formatted;
            } else if (call.duration) {
                // Μετατροπή από δευτερόλεπτα σε λεπτά
                var totalSeconds = parseInt(call.duration);
                var minutes = (totalSeconds / 60).toFixed(1);
                duration = minutes + " min";
            } else if (call.endedAt && call.createdAt) {
                // Υπολογισμός από timestamps ως fallback
                var seconds = Math.round((new Date(call.endedAt) - new Date(call.createdAt)) / 1000);
                var minutes = (seconds / 60).toFixed(1);
                duration = minutes + " min";
            }
            
            var status = call.status || "unknown";
            var statusClass = "status-" + status.replace(/[^a-z0-9]/gi, "-").toLowerCase();
            
            // Διόρθωση timezone - Μετατροπή από UTC σε τοπική ώρα
            var createdAt = "N/A";
            if (call.createdAt) {
                var date = new Date(call.createdAt);
                // Το toLocaleString χρησιμοποιεί αυτόματα το timezone του browser
                createdAt = date.toLocaleString("el-GR", {
                    timeZone: "Europe/Athens",
                    day: "2-digit",
                    month: "2-digit", 
                    year: "numeric",
                    hour: "2-digit",
                    minute: "2-digit"
                });
            }
            var recordingIcon = call.recordingUrl ? "🎵" : "❌";
            
            var row = "<tr>" +
                "<td><input type=\'checkbox\' value=\'" + callId + "\'></td>" +
                "<td><span class=\'org-badge\'>" + orgName + "</span></td>" +
                "<td><code>" + shortId + "</code></td>" +
                "<td>" + phoneNumber + "</td>" +
                "<td>" + duration + "</td>" +
                "<td><span class=\'status-badge " + statusClass + "\'>" + status + "</span></td>" +
                "<td>" + recordingIcon + "</td>" +
                "<td>" + createdAt + "</td>" +
                "<td><button class=\'button button-small view-details\' data-call-index=\'" + index + "\'>Details</button></td>" +
                "</tr>";
            
            tbody.append(row);
        });
    }
    
    function showCallDetails(callIndex) {
        var call = allCallsData[callIndex];
        if (!call) {
            alert("Call data not found");
            return;
        }
        
        var content = "<div class=\'call-details\'>";
        content += "<h3>Call Information</h3>";
        content += "<div class=\'detail-grid\'>";
        content += "<div class=\'detail-item\'><strong>Organization:</strong> " + (call.organization_name || \'N/A\') + "</div>";
        content += "<div class=\'detail-item\'><strong>Call ID:</strong> " + (call.id || \'N/A\') + "</div>";
        content += "<div class=\'detail-item\'><strong>Phone Number:</strong> " + (call.customer?.number || call.phoneNumberId || \'N/A\') + "</div>";
        content += "<div class=\'detail-item\'><strong>Status:</strong> <span class=\'status-badge status-" + (call.status || \'unknown\').toLowerCase() + "\'>" + (call.status || \'Unknown\') + "</span></div>";
        content += "<div class=\'detail-item\'><strong>Type:</strong> " + (call.type || \'N/A\') + "</div>";
        
        // Διόρθωση timezone για Started και Ended
        if (call.createdAt) {
            var startDate = new Date(call.createdAt);
            var startFormatted = startDate.toLocaleString("el-GR", {
                timeZone: "Europe/Athens",
                day: "2-digit",
                month: "2-digit", 
                year: "numeric",
                hour: "2-digit",
                minute: "2-digit",
                second: "2-digit"
            });
            content += "<div class=\'detail-item\'><strong>Started:</strong> " + startFormatted + "</div>";
        } else {
            content += "<div class=\'detail-item\'><strong>Started:</strong> N/A</div>";
        }
        
        if (call.endedAt) {
            var endDate = new Date(call.endedAt);
            var endFormatted = endDate.toLocaleString("el-GR", {
                timeZone: "Europe/Athens",
                day: "2-digit",
                month: "2-digit", 
                year: "numeric",
                hour: "2-digit",
                minute: "2-digit",
                second: "2-digit"
            });
            content += "<div class=\'detail-item\'><strong>Ended:</strong> " + endFormatted + "</div>";
        } else {
            content += "<div class=\'detail-item\'><strong>Ended:</strong> N/A</div>";
        }
        
        // Duration σε λεπτά στο modal
        if (call.duration) {
            var totalSeconds = parseInt(call.duration);
            var minutes = (totalSeconds / 60).toFixed(1);
            content += "<div class=\'detail-item\'><strong>Duration:</strong> " + minutes + " min</div>";
        } else if (call.endedAt && call.createdAt) {
            var seconds = Math.round((new Date(call.endedAt) - new Date(call.createdAt)) / 1000);
            var minutes = (seconds / 60).toFixed(1);
            content += "<div class=\'detail-item\'><strong>Duration:</strong> " + minutes + " min</div>";
        }
        
        if (call.cost) {
            content += "<div class=\'detail-item\'><strong>Cost:</strong> €" + parseFloat(call.cost).toFixed(4) + "</div>";
        }
        
        content += "</div>";
        
        // Recording section
        content += "<h3>Recording</h3>";
        if (call.recordingUrl) {
            content += "<div class=\'recording-section\'>";
            content += "<audio controls preload=\'metadata\' style=\'width: 100%; margin: 10px 0;\'>";
            content += "<source src=\'" + call.recordingUrl + "\' type=\'audio/wav\'>";
            content += "<source src=\'" + call.recordingUrl + "\' type=\'audio/mpeg\'>";
            content += "Your browser does not support the audio element.";
            content += "</audio>";
            content += "<div class=\'recording-actions\'>";
            content += "<a href=\'" + call.recordingUrl + "\' target=\'_blank\' class=\'button button-secondary\'>Open Recording</a> ";
            content += "<a href=\'" + call.recordingUrl + "\' download=\'call_" + call.id + ".wav\' class=\'button button-secondary\'>Download</a>";
            content += "</div>";
            content += "</div>";
        } else {
            content += "<p style=\'text-align: center; color: #666; font-style: italic; padding: 20px;\'>No recording available for this call.</p>";
        }
        
        // Transcript section
        if (call.transcript) {
            content += "<h3>Transcript</h3>";
            content += "<pre style=\'white-space: pre-wrap; background: #f5f5f5; padding: 15px; border-radius: 4px; max-height: 300px; overflow-y: auto;\'>";
            content += (call.transcript || "No transcript available");
            content += "</pre>";
        }
        
        // Messages section
        if (call.messages && Array.isArray(call.messages)) {
            content += "<h3>Conversation (" + call.messages.length + " messages)</h3>";
            content += "<div class=\'messages-section\' style=\'max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 4px;\'>";
            call.messages.forEach(function(message, i) {
                var role = message.role || \'unknown\';
                var roleClass = role === \'user\' ? \'user-message\' : \'assistant-message\';
                content += "<div class=\'message " + roleClass + "\' style=\'margin: 10px 0; padding: 10px; border-radius: 4px; " + 
                          (role === \'user\' ? \'background: #e3f2fd;\' : \'background: #f3e5f5;\') + "\'>";
                content += "<strong>" + role.charAt(0).toUpperCase() + role.slice(1) + ":</strong> ";
                content += (message.content || message.text || \'No content\');
                if (message.timestamp) {
                    content += "<div style=\'font-size: 0.8em; color: #666; margin-top: 5px;\'>" + new Date(message.timestamp).toLocaleTimeString() + "</div>";
                }
                content += "</div>";
            });
            content += "</div>";
        }
        
        // Raw data section
        content += "<h3>Technical Details</h3>";
        content += "<details>";
        content += "<summary>Raw Call Data (Click to expand)</summary>";
        content += "<pre style=\'background: #f8f9fa; padding: 15px; border-radius: 4px; font-size: 12px; max-height: 300px; overflow-y: auto;\'>";
        content += JSON.stringify(call, null, 2);
        content += "</pre>";
        content += "</details>";
        
        content += "</div>";
        
        $("#call-details-content").html(content);
        $("#call-details-modal").fadeIn(200);
    }
    
    // Event handlers
    $("#refresh-logs, #filter-logs").click(loadCallLogs);
    
    $("#phone-search").on("input", function() {
        var searchTerm = $(this).val();
        if (searchTerm.length >= 3 || searchTerm.length === 0) {
            loadCallLogs();
        }
    });
    
    $("#clear-search").click(function() {
        $("#phone-search").val("");
        loadCallLogs();
    });
    
    $("#organization-filter").change(function() {
        loadCallLogs();
    });
    
    // Modal handlers
    $(document).on("click", ".view-details", function() {
        var callIndex = parseInt($(this).data("call-index"));
        showCallDetails(callIndex);
    });
    
    $(".close").click(function() {
        $("#call-details-modal").fadeOut(200);
    });
    
    $("#call-details-modal").click(function(e) {
        if (e.target === this) {
            $(this).fadeOut(200);
        }
    });
    
    $(document).keyup(function(e) {
        if (e.keyCode === 27) {
            $("#call-details-modal").fadeOut(200);
        }
    });
    
    // Load initial data
    if ($("#vapi-call-logs-table").length) {
        loadCallLogs();
    }
});';
    }
    
    /**
     * Get admin CSS content
     */
    private function get_admin_css_content() {
        return '.vapi-call-logs-filters {
    margin: 20px 0;
    background: #fff;
    padding: 15px;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
}

.vapi-call-logs-filters input,
.vapi-call-logs-filters select {
    margin-right: 10px;
    margin-bottom: 5px;
}

.phone-search-container {
    position: relative;
    display: inline-block;
    margin-right: 10px;
}

#phone-search {
    padding-right: 30px;
    width: 200px;
}

#clear-search {
    position: absolute;
    right: 5px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    cursor: pointer;
    color: #666;
    font-size: 16px;
    padding: 2px 5px;
}

#clear-search:hover {
    color: #000;
}

.org-badge {
    background: #2271b1;
    color: white;
    padding: 3px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-badge {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-queued { background: #fff3cd; color: #856404; }
.status-ringing { background: #cce5ff; color: #0056b3; }
.status-in-progress { background: #d1ecf1; color: #0c5460; }
.status-ended { background: #d4edda; color: #155724; }
.status-failed { background: #f8d7da; color: #721c24; }
.status-unknown { background: #e2e3e5; color: #383d41; }

#vapi-loading {
    text-align: center;
    padding: 20px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    margin: 20px 0;
}

#call-details-modal {
    position: fixed;
    z-index: 100000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.7);
    display: none;
    overflow-y: auto;
    padding: 20px 0;
}

.modal-content {
    background-color: #fefefe;
    margin: 20px auto;
    padding: 0;
    border: none;
    width: 90%;
    max-width: 1000px;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
    position: relative;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-content h2 {
    background: #f8f9fa;
    margin: 0;
    padding: 20px;
    border-bottom: 1px solid #dee2e6;
    font-size: 1.4em;
    color: #495057;
}

.close {
    position: absolute;
    right: 20px;
    top: 15px;
    color: #aaa;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    z-index: 1;
    line-height: 1;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.2s ease;
}

.close:hover,
.close:focus {
    color: #000;
    background-color: #f8f9fa;
}

#call-details-content {
    padding: 20px;
}

.organizations-table {
    width: 100%;
    border-collapse: collapse;
    margin: 20px 0;
}

.organizations-table th,
.organizations-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

.organizations-table th {
    background: #f8f9fa;
    font-weight: 600;
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

.detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
    margin: 15px 0;
}

.detail-item {
    padding: 10px;
    background: #f8f9fa;
    border-radius: 4px;
}

.recording-section {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 4px;
    margin: 10px 0;
}

.recording-actions {
    margin-top: 10px;
}

.messages-section .user-message {
    background: #e3f2fd !important;
}

.messages-section .assistant-message {
    background: #f3e5f5 !important;
}

@media (max-width: 768px) {
    .modal-content {
        width: 95%;
        margin: 10px auto;
        max-height: 95vh;
    }
    
    .detail-grid {
        grid-template-columns: 1fr;
    }
    
    .vapi-call-logs-filters input,
    .vapi-call-logs-filters select,
    .vapi-call-logs-filters button {
        width: 100%;
        margin-bottom: 10px;
        margin-right: 0;
    }
    
    .phone-search-container {
        width: 100%;
    }
    
    #phone-search {
        width: 100%;
    }
}';
    }
}
?>