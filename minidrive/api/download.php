<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
md_require_member();
md_release_session(); // the site keeps answering during the download

$rel = trim($_GET['path'] ?? '', '/');
$abs = md_resolve_path($rel);

if ($abs === false || !is_file($abs)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('File not found');
}

header("Content-Security-Policy: sandbox; default-src 'none'");
md_stream_file($abs, 'application/octet-stream', 'attachment');
