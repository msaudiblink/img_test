<?php
/**
 * Analytics Endpoint with Tag Filtering
 * 
 * This script provides access to request statistics for the image endpoint,
 * with the ability to filter by ID or tag.
 */

// Set appropriate content type header
header('Content-Type: application/json');

// Define log directory and file paths
$logDir = __DIR__ . '/logs';
$logFile = $logDir . '/request_log.txt';
$counterFile = $logDir . '/request_counter.json';

// Basic authentication function (simplified for now)
function authenticateRequest() {
    // In a real implementation, you would use a more secure authentication method
    // Consider implementing proper authentication, especially for reset functionality
    return true;
}

// Function to reset counter and logs
function resetTracking($counterFile, $logFile) {
    $result = [
        'counter_reset' => false,
        'logs_reset' => false
    ];
    
    // Reset counter file
    $defaultData = [
        'total' => 0,
        'by_id' => [],
        'by_tag' => []
    ];
    
    $counterResult = file_put_contents(
        $counterFile, 
        json_encode($defaultData, JSON_PRETTY_PRINT), 
        LOCK_EX
    );
    
    $result['counter_reset'] = ($counterResult !== false);
    
    // Reset log file
    $logResult = file_put_contents($logFile, '', LOCK_EX);
    $result['logs_reset'] = ($logResult !== false);
    
    return $result;
}

// Function to get request count data with error handling
function getRequestCountData($counterFile) {
    $defaultData = [
        'total' => 0,
        'by_id' => [],
        'by_tag' => []
    ];
    
    if (!file_exists($counterFile)) {
        error_log("Counter file does not exist: $counterFile");
        return $defaultData;
    }
    
    // Simple direct file read first for fallback
    $simpleRead = file_get_contents($counterFile);
    if ($simpleRead === false) {
        error_log("Failed to read counter file with file_get_contents: $counterFile");
    } else {
        error_log("Simple file read successful. File size: " . strlen($simpleRead));
    }
    
    // Read file with shared lock to allow concurrent reads
    $fp = fopen($counterFile, 'r');
    if (!$fp) {
        error_log("Failed to open counter file: $counterFile");
        
        // Fallback to direct file reading if fopen fails
        if ($simpleRead !== false) {
            $decodedData = json_decode($simpleRead, true);
            if (is_array($decodedData)) {
                error_log("Using fallback file reading method");
                // Make sure by_tag exists (for backward compatibility)
                if (!isset($decodedData['by_tag'])) {
                    $decodedData['by_tag'] = [];
                }
                return $decodedData;
            }
        }
        
        return $defaultData;
    }
    
    // Acquire shared lock for reading
    if (flock($fp, LOCK_SH)) {
        $fileContent = '';
        while (!feof($fp)) {
            $fileContent .= fread($fp, 8192);
        }
        
        // Release lock
        flock($fp, LOCK_UN);
        fclose($fp);
        
        if (empty($fileContent)) {
            error_log("Counter file is empty");
            return $defaultData;
        }
        
        $decodedData = json_decode($fileContent, true);
        if (!is_array($decodedData)) {
            error_log("Invalid JSON in counter file. Content: " . substr($fileContent, 0, 200));
            return $defaultData;
        }
        
        // Make sure by_tag exists (for backward compatibility)
        if (!isset($decodedData['by_tag'])) {
            $decodedData['by_tag'] = [];
        }
        
        return $decodedData;
    } else {
        error_log("Couldn't get shared lock on counter file: $counterFile");
        fclose($fp);
        
        // Fallback to direct file reading if locking fails
        if ($simpleRead !== false) {
            $decodedData = json_decode($simpleRead, true);
            if (is_array($decodedData)) {
                error_log("Using fallback file reading method after lock failure");
                // Make sure by_tag exists (for backward compatibility)
                if (!isset($decodedData['by_tag'])) {
                    $decodedData['by_tag'] = [];
                }
                return $decodedData;
            }
        }
        
        return $defaultData;
    }
}

// Function to parse log file for detailed information
function getDetailedRequestLog($logFile, $limit = 100, $filterById = null, $filterByTag = null) {
    $logs = [];
    
    if (!file_exists($logFile)) {
        error_log("Log file does not exist: $logFile");
        return $logs;
    }
    
    // Try direct file reading first
    $contents = @file_get_contents($logFile);
    if ($contents === false) {
        error_log("Failed to read log file with file_get_contents");
    } else {
        $lines = explode("\n", $contents);
        $lines = array_filter($lines); // Remove empty lines
        
        // Get the most recent entries
        $lines = array_slice($lines, -$limit);
        
        foreach ($lines as $line) {
            // Updated regex to match logs with tag field
            if (preg_match('/\[(.*?)\] ID: (.*?) \| Tag: (.*?) \| IP: (.*?) \| User-Agent: (.*?)$/', $line, $matches)) {
                $logEntry = [
                    'timestamp' => $matches[1],
                    'id' => $matches[2],
                    'tag' => $matches[3],
                    'ip' => $matches[4],
                    'user_agent' => $matches[5]
                ];
                
                // Apply filters if specified
                $includeEntry = true;
                
                if ($filterById !== null && $logEntry['id'] !== $filterById) {
                    $includeEntry = false;
                }
                
                if ($filterByTag !== null && $logEntry['tag'] !== $filterByTag) {
                    $includeEntry = false;
                }
                
                if ($includeEntry) {
                    $logs[] = $logEntry;
                }
            } 
            // Fallback for old log format without tag
            else if (preg_match('/\[(.*?)\] ID: (.*?) \| IP: (.*?) \| User-Agent: (.*?)$/', $line, $matches)) {
                $logEntry = [
                    'timestamp' => $matches[1],
                    'id' => $matches[2],
                    'tag' => '',
                    'ip' => $matches[3],
                    'user_agent' => $matches[4]
                ];
                
                // Apply filters if specified
                $includeEntry = true;
                
                if ($filterById !== null && $logEntry['id'] !== $filterById) {
                    $includeEntry = false;
                }
                
                if ($filterByTag !== null && $filterByTag !== '') {
                    $includeEntry = false; // Old format doesn't have tags
                }
                
                if ($includeEntry) {
                    $logs[] = $logEntry;
                }
            }
        }
        
        if (count($logs) > 0) {
            error_log("Successfully parsed " . count($logs) . " log entries using direct method");
            return $logs;
        }
    }
    
    // Fallback to file() function if the above fails
    $lines = @file($logFile);
    if ($lines === false) {
        error_log("Failed to read log file with file(): $logFile");
        return $logs;
    }
    
    $lines = array_slice($lines, -$limit); // Get the most recent entries
    
    foreach ($lines as $line) {
        // Updated regex to match logs with tag field
        if (preg_match('/\[(.*?)\] ID: (.*?) \| Tag: (.*?) \| IP: (.*?) \| User-Agent: (.*?)$/', $line, $matches)) {
            $logEntry = [
                'timestamp' => $matches[1],
                'id' => $matches[2],
                'tag' => $matches[3],
                'ip' => $matches[4],
                'user_agent' => $matches[5]
            ];
            
            // Apply filters if specified
            $includeEntry = true;
            
            if ($filterById !== null && $logEntry['id'] !== $filterById) {
                $includeEntry = false;
            }
            
            if ($filterByTag !== null && $logEntry['tag'] !== $filterByTag) {
                $includeEntry = false;
            }
            
            if ($includeEntry) {
                $logs[] = $logEntry;
            }
        }
        // Fallback for old log format without tag
        else if (preg_match('/\[(.*?)\] ID: (.*?) \| IP: (.*?) \| User-Agent: (.*?)$/', $line, $matches)) {
            $logEntry = [
                'timestamp' => $matches[1],
                'id' => $matches[2],
                'tag' => '',
                'ip' => $matches[3],
                'user_agent' => $matches[4]
            ];
            
            // Apply filters if specified
            $includeEntry = true;
            
            if ($filterById !== null && $logEntry['id'] !== $filterById) {
                $includeEntry = false;
            }
            
            if ($filterByTag !== null && $filterByTag !== '') {
                $includeEntry = false; // Old format doesn't have tags
            }
            
            if ($includeEntry) {
                $logs[] = $logEntry;
            }
        }
    }
    
    error_log("Parsed " . count($logs) . " log entries using file() method");
    return $logs;
}

// Authenticate the request
authenticateRequest();

// Process query parameters
$id = $_GET['id'] ?? null;
$tag = $_GET['tag'] ?? null;
$detailed = isset($_GET['detailed']) && $_GET['detailed'] === 'true';
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
$reset = isset($_GET['reset']) && $_GET['reset'] === 'true';

// Handle reset request if present
$resetResult = null;
if ($reset) {
    $resetResult = resetTracking($counterFile, $logFile);
}

// Get request count data
$countData = getRequestCountData($counterFile);

// Debug info for file permissions
$debugInfo = [
    'log_dir_exists' => file_exists($logDir),
    'log_dir_readable' => is_readable($logDir),
    'log_dir_writable' => is_writable($logDir),
    'log_file_exists' => file_exists($logFile),
    'log_file_readable' => file_exists($logFile) ? is_readable($logFile) : null,
    'log_file_writable' => file_exists($logFile) ? is_writable($logFile) : null,
    'counter_file_exists' => file_exists($counterFile),
    'counter_file_readable' => file_exists($counterFile) ? is_readable($counterFile) : null,
    'counter_file_writable' => file_exists($counterFile) ? is_writable($counterFile) : null,
    'counter_file_size' => file_exists($counterFile) ? filesize($counterFile) : null,
    'log_file_size' => file_exists($logFile) ? filesize($logFile) : null,
    'php_user' => posix_getpwuid(posix_geteuid())['name'] ?? 'unknown',
    'script_path' => __FILE__,
    'script_dir' => __DIR__
];

// Prepare response
$response = [
    'status' => 'success',
    'timestamp' => date('Y-m-d H:i:s'),
    'data' => [
        'total_requests' => $countData['total'] ?? 0
    ]
];

// Handle tag filter
if ($tag !== null) {
    $response['data']['tag'] = $tag;
    $response['data']['tag_total'] = $countData['by_tag'][$tag]['total'] ?? 0;
    $response['data']['by_id_with_tag'] = $countData['by_tag'][$tag]['by_id'] ?? [];
}
// Handle ID filter
elseif ($id !== null) {
    $response['data']['id'] = $id;
    $response['data']['request_count'] = $countData['by_id'][$id] ?? 0;
}
// No filter - return everything
else {
    $response['data']['by_id'] = $countData['by_id'] ?? [];
    
    // Include tag statistics
    $response['data']['tags'] = [];
    foreach ($countData['by_tag'] ?? [] as $tagName => $tagData) {
        $response['data']['tags'][$tagName] = [
            'total' => $tagData['total']
        ];
    }
}

// Include combined ID + tag stats if both filters are specified
if ($id !== null && $tag !== null) {
    $response['data']['id_tag_count'] = $countData['by_tag'][$tag]['by_id'][$id] ?? 0;
}

// Include detailed log information if requested
if ($detailed) {
    $logs = getDetailedRequestLog($logFile, $limit, $id, $tag);
    $response['data']['recent_requests'] = array_values($logs);
}

// Include debug info temporarily (remove in production)
if (isset($_GET['debug']) && $_GET['debug'] === 'true') {
    $response['debug'] = $debugInfo;
    
    // Add raw file contents for debugging if files exist
    if (file_exists($counterFile)) {
        $response['debug']['counter_file_content'] = file_get_contents($counterFile);
    }
    
    if (file_exists($logFile)) {
        $response['debug']['log_file_preview'] = substr(file_get_contents($logFile), 0, 500);
    }
}

// Include reset results if reset was requested
if ($reset) {
    $response['reset_result'] = $resetResult;
    
    // If reset was successful, update the data in the response to reflect the reset
    if ($resetResult['counter_reset']) {
        $response['data']['total_requests'] = 0;
        $response['data']['by_id'] = [];
        $response['data']['tags'] = [];
        
        if ($id !== null) {
            $response['data']['request_count'] = 0;
        }
        
        if ($tag !== null) {
            $response['data']['tag_total'] = 0;
            $response['data']['by_id_with_tag'] = [];
        }
        
        if ($id !== null && $tag !== null) {
            $response['data']['id_tag_count'] = 0;
        }
    }
    
    if ($resetResult['logs_reset'] && $detailed) {
        $response['data']['recent_requests'] = [];
    }
}

echo json_encode($response, JSON_PRETTY_PRINT);