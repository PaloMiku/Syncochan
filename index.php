<?php
// Serve static files from content/ or show simple index
$contentDir = __DIR__ . '/content';

// Get the request URI path (remove query string)
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestUri = urldecode($requestUri);

// Remove leading slash if present
$path = ltrim($requestUri, '/');

// Security: prevent directory traversal
if (strpos($path, '..') !== false) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

// Try to find the file in content directory
$file = $contentDir . '/' . $path;

// If path is empty or is a directory, try index.html
if (empty($path) || is_dir($file)) {
    if (is_dir($file)) {
        $indexFile = rtrim($file, '/') . '/index.html';
    } else {
        $indexFile = $contentDir . '/index.html';
    }
    
    if (is_file($indexFile)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($indexFile);
        exit;
    }
}

// Try to serve the file directly
if (is_file($file)) {
    // Get MIME type
    $mime = mime_content_type($file) ?: 'application/octet-stream';
    
    // Set appropriate headers
    header('Content-Type: ' . $mime);
    
    // Add caching headers for static assets
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    if (in_array($ext, ['css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'woff', 'woff2', 'ttf', 'eot'])) {
        header('Cache-Control: public, max-age=31536000');
    }
    
    readfile($file);
    exit;
}

// For Nuxt.js SPA routes, fall back to index.html (client-side routing)
if (is_file($contentDir . '/index.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($contentDir . '/index.html');
    exit;
}

// No content found
http_response_code(404);
if (is_file($contentDir . '/404.html')) {
    readfile($contentDir . '/404.html');
} else {
    echo "No content. Visit backend/admin.php to configure.";
}
