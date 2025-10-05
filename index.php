<?php
// Simple router: serve static HTML from content/ with optional header/footer injection
// - request path /foo -> content/foo.html OR content/foo/index.html
// - prevent directory traversal
// - inject content/_header.html and content/_footer.html if present

// Configuration
$contentDir = __DIR__ . '/content';
$headerFile = $contentDir . '/_header.html';
$footerFile = $contentDir . '/_footer.html';
$notFoundFile = $contentDir . '/404.html';

// Get the request URI path (without query string)
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Normalize and decode URI
$path = urldecode($uri);

// Prevent null byte
if (strpos($path, "\0") !== false) {
    http_response_code(400);
    echo "Bad request";
    exit;
}

// Remove leading slash
if (strncmp($path, '/', 1) === 0) {
    $path = substr($path, 1);
}

// Default to index
if ($path === '') {
    $path = 'index.html';
}

// Candidate files to check (in order)
$candidates = [];

// If the path already has an extension, prefer it
if (pathinfo($path, PATHINFO_EXTENSION) !== '') {
    $candidates[] = $path;
} else {
    // try path + .html
    $candidates[] = $path . '.html';
    // try directory index
    $candidates[] = rtrim($path, '/') . '/index.html';
}

// Resolve secure file path within content dir
function resolve_in_dir($baseDir, $relative) {
    $full = $baseDir . '/' . $relative;
    $realBase = realpath($baseDir);
    $realFull = realpath($full);
    if ($realFull === false) return false;
    // Ensure the resolved path is inside the base dir
    if (strpos($realFull, $realBase) !== 0) return false;
    return $realFull;
}

$found = false;
$foundPath = '';
foreach ($candidates as $cand) {
    // normalize candidate path to remove any .. segments
    $norm = preg_replace('#/+#','/', $cand);
    $resolved = resolve_in_dir($contentDir, $norm);
    if ($resolved && is_file($resolved) && is_readable($resolved)) {
        $found = true;
        $foundPath = $resolved;
        break;
    }
}

if (!$found) {
    // serve 404 page if exists
    if (is_file($notFoundFile) && is_readable($notFoundFile)) {
        http_response_code(404);
        // optionally inject header/footer
        if (is_file($headerFile)) readfile($headerFile);
        readfile($notFoundFile);
        if (is_file($footerFile)) readfile($footerFile);
        exit;
    }
    http_response_code(404);
    echo "404 Not Found";
    exit;
}

// At this point, $foundPath points to the real file on disk
// Determine Content-Type: for html files, force text/html; else try to guess
$mime = 'text/html';
$ext = strtolower(pathinfo($foundPath, PATHINFO_EXTENSION));
if ($ext !== 'html' && function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $det = finfo_file($finfo, $foundPath);
    if ($det) $mime = $det;
    finfo_close($finfo);
} elseif ($ext !== 'html') {
    // fallback mapping for common types
    $map = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
    ];
    if (isset($map[$ext])) $mime = $map[$ext];
}

header('Content-Type: ' . $mime . '; charset=utf-8');

// If html, inject header/footer for consistent layout
if ($ext === 'html') {
    if (is_file($headerFile) && is_readable($headerFile)) readfile($headerFile);
    readfile($foundPath);
    if (is_file($footerFile) && is_readable($footerFile)) readfile($footerFile);
} else {
    // binary-safe passthrough
    $fp = fopen($foundPath, 'rb');
    if ($fp) {
        while (!feof($fp)) {
            echo fread($fp, 8192);
        }
        fclose($fp);
    }
}

exit;

?>