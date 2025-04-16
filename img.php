<?php
/**
 * Image Retrieval Endpoint
 * 
 * This script serves actual image files based on ID parameter
 * and tracks request counts.
 */

// Define log directory and ensure it exists with proper permissions
$logDir = __DIR__ . '/logs';
if (!file_exists($logDir)) {
    mkdir($logDir, 0755, true);
}

// Define log and counter file paths with ABSOLUTE paths
$logFile = $logDir . '/request_log.txt';
$counterFile = $logDir . '/request_counter.json';

// Define images directory (create this and add some images)
$imagesDir = __DIR__ . '/images';
if (!file_exists($imagesDir)) {
    mkdir($imagesDir, 0755, true);
}

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
    }
    
    // Append to log file with error handling
    $result = file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    
    if ($result === false) {
        error_log("Failed to write to log file: $logFile");
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
        file_put_contents($counterFile, json_encode($counterData, JSON_PRETTY_PRINT), LOCK_EX);
        chmod($counterFile, 0664); // Make writable by web server
    }
    
    // Read existing counter data with file locking
    $fp = fopen($counterFile, 'r+');
    if ($fp) {
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
                }
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
            fwrite($fp, json_encode($counterData, JSON_PRETTY_PRINT));
            
            // Release the lock
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
    
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

// Debug logging to track what's happening
error_log("Processing request for ID: $id");
error_log("Log file path: $logFile");
error_log("Counter file path: $counterFile");

// Log the request
$logSuccess = logRequest($id, $logFile);
error_log("Log success: " . ($logSuccess ? 'true' : 'false'));

// Track request count
$countStats = trackRequestCount($id, $counterFile);
error_log("Counter stats: " . json_encode($countStats));

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