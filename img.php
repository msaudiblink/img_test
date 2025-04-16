<?php
/**
 * Enhanced Image Retrieval Endpoint with Tag Support
 * 
 * This script serves image files based on document_id parameter
 * using a CSV file as a mapping database with caching for better performance.
 * Includes support for tracking requests by tags.
 */

// Define directory paths
$logDir = __DIR__ . '/logs';
$imagesDir = __DIR__ . '/images';
$dataDir = __DIR__ . '/data';
$cacheDir = __DIR__ . '/cache';

// Ensure directories exist
foreach ([$logDir, $imagesDir, $dataDir, $cacheDir] as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Define file paths
$logFile = $logDir . '/request_log.txt';
$counterFile = $logDir . '/request_counter.json';
$csvFile = $dataDir . '/document_image_mapping.csv';
$csvCacheFile = $cacheDir . '/document_image_mapping_cache.php';

// Check for cache reset request
$resetCache = isset($_GET['reset_cache']) && $_GET['reset_cache'] === 'true';
if ($resetCache && file_exists($csvCacheFile)) {
    unlink($csvCacheFile);
    error_log("Cache file reset: $csvCacheFile");
}

// Check if CSV file exists
if (!file_exists($csvFile)) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Image mapping database not found'
    ]);
    exit;
}

// Function to log request to file with tag support
function logRequest($id, $tag, $logFile) {
    $timestamp = date('Y-m-d H:i:s');
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    // Format log entry with tag
    $logEntry = sprintf(
        "[%s] ID: %s | Tag: %s | IP: %s | User-Agent: %s\n",
        $timestamp,
        $id,
        $tag,
        $ipAddress,
        $userAgent
    );
    
    // Create log file if it doesn't exist
    if (!file_exists($logFile)) {
        touch($logFile);
        chmod($logFile, 0664);
    }
    
    // Append to log file with error handling
    $result = file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    
    if ($result === false) {
        error_log("Failed to write to log file: $logFile");
        
        // Try an alternative method
        $fp = fopen($logFile, 'a');
        if ($fp) {
            fwrite($fp, $logEntry);
            fclose($fp);
            $result = true;
        }
    }
    
    return $result !== false;
}

// Function to update and retrieve counter with tag support
function trackRequestCount($id, $tag, $counterFile) {
    // Initialize default data
    $counterData = [
        'total' => 0,
        'by_id' => [],
        'by_tag' => []
    ];
    
    // Create counter file if it doesn't exist
    if (!file_exists($counterFile)) {
        file_put_contents($counterFile, json_encode($counterData, JSON_PRETTY_PRINT), LOCK_EX);
        chmod($counterFile, 0664);
    }
    
    // Read existing counter data
    $fileContent = file_get_contents($counterFile);
    if ($fileContent !== false) {
        $decodedData = json_decode($fileContent, true);
        if (is_array($decodedData)) {
            $counterData = $decodedData;
            
            // Make sure by_tag exists (for backward compatibility)
            if (!isset($counterData['by_tag'])) {
                $counterData['by_tag'] = [];
            }
        }
    }
    
    // Update total counter
    $counterData['total']++;
    
    // Update ID counter
    if (!isset($counterData['by_id'][$id])) {
        $counterData['by_id'][$id] = 0;
    }
    $counterData['by_id'][$id]++;
    
    // Update tag counter
    if (!empty($tag)) {
        if (!isset($counterData['by_tag'][$tag])) {
            $counterData['by_tag'][$tag] = [
                'total' => 0,
                'by_id' => []
            ];
        }
        
        $counterData['by_tag'][$tag]['total']++;
        
        if (!isset($counterData['by_tag'][$tag]['by_id'][$id])) {
            $counterData['by_tag'][$tag]['by_id'][$id] = 0;
        }
        $counterData['by_tag'][$tag]['by_id'][$id]++;
    }
    
    // Save updated counter data
    file_put_contents($counterFile, json_encode($counterData, JSON_PRETTY_PRINT), LOCK_EX);
    
    // Return stats specific to this request
    $result = [
        'total' => $counterData['total'],
        'id_count' => $counterData['by_id'][$id]
    ];
    
    if (!empty($tag)) {
        $result['tag'] = $tag;
        $result['tag_total'] = $counterData['by_tag'][$tag]['total'];
        $result['tag_id_count'] = $counterData['by_tag'][$tag]['by_id'][$id];
    }
    
    return $result;
}

// Function to build or get CSV cache
function buildOrGetCSVCache($csvFile, $csvCacheFile, $forceRebuild = false) {
    $rebuildCache = $forceRebuild;
    
    // Check if cache exists and is newer than CSV
    if (!$rebuildCache && file_exists($csvCacheFile)) {
        $csvModified = filemtime($csvFile);
        $cacheModified = filemtime($csvCacheFile);
        
        if ($csvModified > $cacheModified) {
            $rebuildCache = true;
            error_log("Rebuilding cache because CSV file is newer than cache");
        }
    } else if (!file_exists($csvCacheFile)) {
        $rebuildCache = true;
        error_log("Rebuilding cache because cache file doesn't exist");
    }
    
    // If we need to rebuild the cache
    if ($rebuildCache) {
        $startTime = microtime(true);
        error_log("Starting cache rebuild from CSV");
        
        // Read CSV file
        $handle = fopen($csvFile, 'r');
        if (!$handle) {
            error_log("Failed to open CSV file: $csvFile");
            return null;
        }
        
        // Read header row
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            error_log("Failed to read CSV header");
            return null;
        }
        
        // Find column indices
        $docIdIndex = array_search('document_id', $header);
        $imagePathIndex = array_search('image_path', $header);
        
        if ($docIdIndex === false || $imagePathIndex === false) {
            fclose($handle);
            error_log("CSV is missing required columns (document_id or image_path)");
            return null;
        }
        
        // Build mapping cache
        $mappingCache = [];
        $rowCount = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $rowCount++;
            if (isset($row[$docIdIndex]) && isset($row[$imagePathIndex]) && 
                !empty($row[$docIdIndex]) && !empty($row[$imagePathIndex])) {
                $mappingCache[$row[$docIdIndex]] = $row[$imagePathIndex];
            }
        }
        
        fclose($handle);
        
        // Save cache to PHP file for fast loading
        $cacheContent = "<?php\n// Generated " . date('Y-m-d H:i:s') . " from $csvFile\n// Contains " . count($mappingCache) . " mappings\nreturn " . var_export($mappingCache, true) . ";\n";
        file_put_contents($csvCacheFile, $cacheContent, LOCK_EX);
        chmod($csvCacheFile, 0644);
        
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        error_log("Cache rebuild complete. Processed $rowCount rows into " . count($mappingCache) . " mappings in {$duration}ms");
        
        return $mappingCache;
    } else {
        // Load existing cache
        $startTime = microtime(true);
        $mapping = include($csvCacheFile);
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        error_log("Loaded existing cache with " . count($mapping) . " mappings in {$duration}ms");
        return $mapping;
    }
}

// Function to get image path from document ID using cached CSV mapping
function getImagePathFromCache($documentId, $mapping, $imagesDir) {
    // Check if document ID exists in the mapping
    if (!isset($mapping[$documentId])) {
        error_log("No image path found for document ID: $documentId");
        return null;
    }
    
    $imagePath = $mapping[$documentId];
    
    // Determine the full image path
    // If the image path is a full path, use it directly
    if (file_exists($imagePath)) {
        return $imagePath;
    }
    
    // If it's a relative path, combine with the images directory
    $fullPath = $imagesDir . '/' . $imagePath;
    if (file_exists($fullPath)) {
        return $fullPath;
    }
    
    // If it's just a filename, check in images directory
    $filenameOnly = basename($imagePath);
    $fileInImagesDir = $imagesDir . '/' . $filenameOnly;
    if (file_exists($fileInImagesDir)) {
        return $fileInImagesDir;
    }
    
    // Try to find the file without caring about case sensitivity
    $files = scandir($imagesDir);
    foreach ($files as $file) {
        if (strtolower($file) === strtolower($filenameOnly)) {
            return $imagesDir . '/' . $file;
        }
    }
    
    error_log("Image file does not exist: $imagePath or $fullPath");
    return null;
}

// Check if ID parameter is provided
$id = $_GET['id'] ?? null;
$tag = $_GET['tag'] ?? '';

// Handle cache info request
if (isset($_GET['cache_info']) && $_GET['cache_info'] === 'true') {
    header('Content-Type: application/json');
    
    $cacheInfo = [
        'status' => 'success',
        'csv_file' => $csvFile,
        'csv_file_exists' => file_exists($csvFile),
        'csv_file_size' => file_exists($csvFile) ? filesize($csvFile) : null,
        'csv_file_modified' => file_exists($csvFile) ? date('Y-m-d H:i:s', filemtime($csvFile)) : null,
        'cache_file' => $csvCacheFile,
        'cache_file_exists' => file_exists($csvCacheFile),
        'cache_file_size' => file_exists($csvCacheFile) ? filesize($csvCacheFile) : null,
        'cache_file_modified' => file_exists($csvCacheFile) ? date('Y-m-d H:i:s', filemtime($csvCacheFile)) : null,
    ];
    
    // Load cache to get entry count if it exists
    if (file_exists($csvCacheFile)) {
        $mapping = include($csvCacheFile);
        $cacheInfo['mapping_entries'] = count($mapping);
    }
    
    echo json_encode($cacheInfo, JSON_PRETTY_PRINT);
    exit;
}

// If this is just a cache reset request, return success message
if ($resetCache && !$id) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'message' => 'Cache has been reset. It will be rebuilt on the next request.',
        'cache_file' => $csvCacheFile,
        'cache_file_exists' => file_exists($csvCacheFile)
    ]);
    exit;
}

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

// Log the request with tag
logRequest($id, $tag, $logFile);

// Track request count with tag
$countStats = trackRequestCount($id, $tag, $counterFile);

// Get CSV mapping (cached for performance)
$startTime = microtime(true);
$mapping = buildOrGetCSVCache($csvFile, $csvCacheFile, $resetCache);
$cacheTime = microtime(true) - $startTime;

// Get the image path from cache
$imagePath = null;
if ($mapping) {
    $imagePath = getImagePathFromCache($id, $mapping, $imagesDir);
}

// If debug mode is enabled, return info instead of image
if (isset($_GET['debug']) && $_GET['debug'] === 'true') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'debug',
        'id' => $id,
        'tag' => $tag,
        'image_path' => $imagePath,
        'file_exists' => $imagePath ? file_exists($imagePath) : false,
        'count_stats' => $countStats,
        'csv_file' => $csvFile,
        'csv_cache_file' => $csvCacheFile,
        'cache_time_ms' => round($cacheTime * 1000, 2),
        'mapping_size' => $mapping ? count($mapping) : 0,
        'cache_exists' => file_exists($csvCacheFile),
        'cache_was_reset' => $resetCache
    ]);
    exit;
}

// If no image found, use a placeholder or return 404
if (!$imagePath || !file_exists($imagePath)) {
    // Return a 404 error
    if (!isset($_GET['placeholder']) || $_GET['placeholder'] !== 'true') {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Image not found for document ID: ' . $id
        ]);
        exit;
    }
    
    // Use a placeholder service
    $width = $_GET['width'] ?? 300;
    $height = $_GET['height'] ?? 300;
    header('Location: https://via.placeholder.com/' . $width . 'x' . $height . '?text=No+Image+For+' . urlencode($id));
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