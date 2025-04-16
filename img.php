<?php
/**
 * Image Retrieval Endpoint
 * 
 * This script serves actual image files based on ID parameter
 * and properly tracks request counts regardless of debug mode.
 */

// Define log directory and ensure it exists with proper permissions
$logDir = __DIR__ . '/logs';
if (!file_exists($logDir)) {
    mkdir($logDir, 0755, true);
}

// Define log and counter file paths
$logFile = $logDir . '/request_log.txt';
$counterFile = $logDir . '/request_counter.json';

// Define images directory (create this and add some images)
$imagesDir = __DIR__ . '/images';
if (!file_exists($imagesDir)) {
    mkdir($imagesDir, 0755, true);
}

// Always log key paths
error_log("Image endpoint using log file: $logFile");
error_log("Image endpoint using counter file: $counterFile");

// Function to log request to file with error handling
function logRequest($id, $logFile) {
    $timestamp = date('Y-m-d H:i:s');
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    // Format log entry
    $logEntry = sprintf(
        "[%s] ID: %s | IP: %s | User-Agent: %s\n",
        $timestamp,
        $id,
        $ipAddress,
        $userAgent
    );
    
    // Create log file if it doesn't exist
    if (!file_exists($logFile)) {
        touch($logFile);
        chmod($logFile, 0664); // Make writable by web server
        error_log("Created new log file: $logFile");
    }
    
    // Append to log file with error handling
    $result = file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    
    if ($result === false) {
        error_log("Failed to write to log file: $logFile");
        
        // Try an alternative method if the first fails
        $fp = fopen($logFile, 'a');
        if ($fp) {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, $logEntry);
                flock($fp, LOCK_UN);
                $result = true;
                error_log("Successfully wrote log using alternative method");
            } else {
                error_log("Could not get lock for alternative write method");
            }
            fclose($fp);
        } else {
            error_log("Could not open log file for alternative write method");
        }
    } else {
        error_log("Successfully logged request for ID: $id");
    }
    
    return $result !== false;
}

// Function to update and retrieve counter with file locking and error handling
function trackRequestCount($id, $counterFile) {
    // Initialize default data
    $counterData = [
        'total' => 0,
        'by_id' => []
    ];
    
    // Create counter file if it doesn't exist
    if (!file_exists($counterFile)) {
        $initResult = file_put_contents($counterFile, json_encode($counterData, JSON_PRETTY_PRINT), LOCK_EX);
        chmod($counterFile, 0664); // Make writable by web server
        
        if ($initResult === false) {
            error_log("Failed to create counter file: $counterFile");
        } else {
            error_log("Created new counter file: $counterFile");
        }
    }
    
    // Make sure counter file exists and is readable/writable
    if (!file_exists($counterFile)) {
        error_log("Counter file doesn't exist after creation attempt: $counterFile");
        return $counterData;
    }
    
    if (!is_readable($counterFile)) {
        error_log("Counter file is not readable: $counterFile");
        return $counterData;
    }
    
    if (!is_writable($counterFile)) {
        error_log("Counter file is not writable: $counterFile");
        return $counterData;
    }
    
    // Read existing counter data with file locking
    $fp = fopen($counterFile, 'r+');
    if (!$fp) {
        error_log("Failed to open counter file: $counterFile");
        
        // Try an alternative reading method
        $existingData = file_get_contents($counterFile);
        if ($existingData !== false) {
            $decodedData = json_decode($existingData, true);
            if (is_array($decodedData)) {
                $counterData = $decodedData;
                error_log("Read counter data using alternative method");
            }
        }
        
        // Update the counters in memory
        $counterData['total']++;
        if (!isset($counterData['by_id'][$id])) {
            $counterData['by_id'][$id] = 0;
        }
        $counterData['by_id'][$id]++;
        
        // Try to write it back
        $writeResult = file_put_contents($counterFile, json_encode($counterData, JSON_PRETTY_PRINT), LOCK_EX);
        if ($writeResult === false) {
            error_log("Failed to write counter data using alternative method");
        } else {
            error_log("Updated counter data using alternative method");
        }
        
        return [
            'total' => $counterData['total'],
            'id_count' => $counterData['by_id'][$id]
        ];
    }
    
    // Acquire exclusive lock
    if (flock($fp, LOCK_EX)) {
        $fileContent = '';
        while (!feof($fp)) {
            $fileContent .= fread($fp, 8192);
        }
        
        if (!empty($fileContent)) {
            $decodedData = json_decode($fileContent, true);
            if (is_array($decodedData)) {
                $counterData = $decodedData;
                error_log("Successfully read existing counter data");
            } else {
                error_log("Counter file contains invalid JSON, using default data");
            }
        } else {
            error_log("Counter file is empty, using default data");
        }
        
        // Update counters
        $counterData['total']++;
        if (!isset($counterData['by_id'][$id])) {
            $counterData['by_id'][$id] = 0;
        }
        $counterData['by_id'][$id]++;
        
        // Write updated data back to file
        ftruncate($fp, 0);
        rewind($fp);
        $writeResult = fwrite($fp, json_encode($counterData, JSON_PRETTY_PRINT));
        
        if ($writeResult === false) {
            error_log("Failed to write updated counter data");
        } else {
            error_log("Successfully updated counter data");
        }
        
        // Release the lock
        flock($fp, LOCK_UN);
    } else {
        error_log("Could not acquire lock for counter file");
    }
    
    fclose($fp);
    
    error_log("Counter stats: total=" . $counterData['total'] . ", id_count=" . $counterData['by_id'][$id]);
    
    return [
        'total' => $counterData['total'],
        'id_count' => $counterData['by_id'][$id]
    ];
}

// Function to get image path from ID
function getImagePath($id, $imagesDir) {
    // Map ID to an actual image file
    // This is where you would implement your logic to find the correct image
    // For now, we'll use a simple approach
    
    // Option 1: If you're using the ID as the filename
    $directFile = $imagesDir . '/' . $id . '.jpg';
    if (file_exists($directFile)) {
        return $directFile;
    }
    
    // Option 2: If you have a mapping system or database
    // This would be where you query your database to get the filename
    
    // Option 3: Return a default image if no matching image is found
    $defaultImage = $imagesDir . '/default.jpg';
    if (file_exists($defaultImage)) {
        return $defaultImage;
    }
    
    // Option 4: Use a placeholder service if no image exists
    return null;
}

// Check if ID parameter is provided
$id = $_GET['id'] ?? null;

if ($id === null) {
    // Return error if no ID provided
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing required parameter: id'
    ]);
    exit;
}

// Log the request - this should work regardless of debug parameter
$logSuccess = logRequest($id, $logFile);

// Track request count - this should work regardless of debug parameter
$countStats = trackRequestCount($id, $counterFile);

// Get the image path
$imagePath = getImagePath($id, $imagesDir);

// If debug mode is enabled, return info instead of image
if (isset($_GET['debug']) && $_GET['debug'] === 'true') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'debug',
        'id' => $id,
        'image_path' => $imagePath,
        'file_exists' => $imagePath ? file_exists($imagePath) : false,
        'log_success' => $logSuccess,
        'count_stats' => $countStats,
        'log_file' => $logFile,
        'counter_file' => $counterFile,
        'counter_file_exists' => file_exists($counterFile),
        'counter_file_readable' => is_readable($counterFile),
        'counter_file_writable' => is_writable($counterFile),
        'php_user' => posix_getpwuid(posix_geteuid())['name'] ?? 'unknown'
    ]);
    exit;
}

// If no image found, use a placeholder or return 404
if (!$imagePath || !file_exists($imagePath)) {
    // Option 1: Return a 404 error
    if (!isset($_GET['placeholder']) || $_GET['placeholder'] !== 'true') {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Image not found',
            'tracking_successful' => $logSuccess
        ]);
        exit;
    }
    
    // Option 2: Use a placeholder service
    // Redirect to a placeholder image service
    $width = $_GET['width'] ?? 300;
    $height = $_GET['height'] ?? 300;
    header('Location: https://via.placeholder.com/' . $width . 'x' . $height);
    exit;
}

// Determine content type based on file extension
$extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
$contentType = 'application/octet-stream'; // Default

switch ($extension) {
    case 'jpg':
    case 'jpeg':
        $contentType = 'image/jpeg';
        break;
    case 'png':
        $contentType = 'image/png';
        break;
    case 'gif':
        $contentType = 'image/gif';
        break;
    case 'webp':
        $contentType = 'image/webp';
        break;
    case 'svg':
        $contentType = 'image/svg+xml';
        break;
}

// Output the image
header('Content-Type: ' . $contentType);
header('Content-Length: ' . filesize($imagePath));
header('Cache-Control: max-age=86400'); // Cache for 24 hours
readfile($imagePath);