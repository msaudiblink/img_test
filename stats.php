<?php
/**
 * Analytics Endpoint with Persistent Access
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
        'by_id' => []
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
        'by_id' => []
    ];
    
    if (!file_exists($counterFile)) {
        error_log("Counter file does not exist: $counterFile");
        return $defaultData;
    }
    
    // Read file with shared lock to allow concurrent reads
    $fp = fopen($counterFile, 'r');
    if (!$fp) {
        error_log("Failed to open counter file: $counterFile");
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
            return $defaultData;
        }
        
        $decodedData = json_decode($fileContent, true);
        if (!is_array($decodedData)) {
            error_log("Invalid JSON in counter file: $counterFile");
            return $defaultData;
        }
        
        return $decodedData;
    } else {
        error_log("Couldn't get shared lock on counter file: $counterFile");
        fclose($fp);
        return $defaultData;
    }
}

// Function to parse log file for detailed information
function getDetailedRequestLog($logFile, $limit = 100) {
    $logs = [];
    
    if (!file_exists($logFile)) {
        error_log("Log file does not exist: $logFile");
        return $logs;
    }
    
    // Read log file with error handling
    $lines = @file($logFile);
    if ($lines === false) {
        error_log("Failed to read log file: $logFile");
        return $logs;
    }
    
    $lines = array_slice($lines, -$limit); // Get the most recent entries
    
    foreach ($lines as $line) {
        // Parse log line
        if (preg_match('/\[(.*?)\] ID: (.*?) \| IP: (.*?) \| User-Agent: (.*?)$/', $line, $matches)) {
            $logs[] = [
                'timestamp' => $matches[1],
                'id' => $matches[2],
                'ip' => $matches[3],
                'user_agent' => $matches[4]
            ];
        }
    }
    
    return $logs;
}

// Authenticate the request
authenticateRequest();

// Process query parameters
$id = $_GET['id'] ?? null;
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
    'log_file_exists' => file_exists($logFile),
    'log_file_readable' => file_exists($logFile) ? is_readable($logFile) : null,
    'counter_file_exists' => file_exists($counterFile),
    'counter_file_readable' => file_exists($counterFile) ? is_readable($counterFile) : null,
    'php_user' => posix_getpwuid(posix_geteuid())['name'] ?? 'unknown'
];

// Prepare response
$response = [
    'status' => 'success',
    'timestamp' => date('Y-m-d H:i:s'),
    'data' => [
        'total_requests' => $countData['total'] ?? 0
    ]
];

// Filter by ID if specified
if ($id !== null) {
    $response['data']['id'] = $id;
    $response['data']['request_count'] = $countData['by_id'][$id] ?? 0;
} else {
    $response['data']['by_id'] = $countData['by_id'] ?? [];
}

// Include detailed log information if requested
if ($detailed) {
    $logs = getDetailedRequestLog($logFile, $limit);
    
    // Filter logs by ID if specified
    if ($id !== null) {
        $logs = array_filter($logs, function($log) use ($id) {
            return $log['id'] === $id;
        });
    }
    
    $response['data']['recent_requests'] = array_values($logs);
}

// Include debug info temporarily (remove in production)
if (isset($_GET['debug']) && $_GET['debug'] === 'true') {
    $response['debug'] = $debugInfo;
}

// Include reset results if reset was requested
if ($reset) {
    $response['reset_result'] = $resetResult;
    
    // If reset was successful, update the data in the response to reflect the reset
    if ($resetResult['counter_reset']) {
        $response['data']['total_requests'] = 0;
        $response['data']['by_id'] = [];
        if ($id !== null) {
            $response['data']['request_count'] = 0;
        }
    }
    
    if ($resetResult['logs_reset'] && $detailed) {
        $response['data']['recent_requests'] = [];
    }
}

echo json_encode($response, JSON_PRETTY_PRINT);