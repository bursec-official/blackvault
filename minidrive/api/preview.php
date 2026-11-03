<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
md_require_member();
md_release_session(); // otherwise the rest of the site waits while a video plays

$rel = trim($_GET['path'] ?? '', '/');
$abs = md_resolve_path($rel);

if ($abs === false || !is_file($abs)) {
    http_response_code(404);
    exit('File not found');
}

$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));

// Whitelist of types that can be shown as they are in the browser.
// svg/html/xml are deliberately NOT here: they can contain JavaScript, and
// served "inline" it would run on the same origin as the app (stored XSS).
$imageTypes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp'];
$videoTypes = ['mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg', 'mov' => 'video/quicktime'];
$audioTypes = ['mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4'];
$pdfTypes = ['pdf' => 'application/pdf'];
$textTypes = ['txt', 'md', 'log', 'csv', 'json', 'js', 'css', 'ini', 'conf', 'yaml', 'yml', 'sh', 'py'];

$mime = null;

if (isset($imageTypes[$ext])) {
    $mime = $imageTypes[$ext];
} elseif (isset($videoTypes[$ext])) {
    $mime = $videoTypes[$ext];
} elseif (isset($audioTypes[$ext])) {
    $mime = $audioTypes[$ext];
} elseif (isset($pdfTypes[$ext])) {
    $mime = $pdfTypes[$ext];
} elseif (in_array($ext, $textTypes, true)) {
    // Text files are always served as plain text, never with their real type
    // (e.g. .js, .html), so the browser only shows them and never runs them.
    $mime = 'text/plain; charset=utf-8';
} else {
    http_response_code(415);
    exit('This file type has no preview');
}

// Shown in an iframe of this same site, but unable to run anything
bv_security_headers('md', true);
header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'");
md_stream_file($abs, $mime, 'inline');
