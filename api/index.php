<?php
declare(strict_types=1);

/** Vercel entry point: dispatch app PHP pages and safely serve public assets. */
$projectRoot = dirname(__DIR__);
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$requestPath = rawurldecode($requestPath);

if (str_contains($requestPath, "\0") || str_contains($requestPath, '\\') || preg_match('#(?:^|/)\.\.(?:/|$)#', $requestPath)) {
    http_response_code(400);
    exit('Bad request.');
}

if (preg_match('#^/assets/([A-Za-z0-9_./-]+\.(?:css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf))$#i', $requestPath, $match)) {
    $assetPath = realpath($projectRoot . '/assets/' . $match[1]);
    $assetRoot = realpath($projectRoot . '/assets');
    if ($assetPath === false || $assetRoot === false || !str_starts_with($assetPath, $assetRoot . DIRECTORY_SEPARATOR) || !is_file($assetPath)) {
        http_response_code(404);
        exit('Not found.');
    }

    $mimeTypes = [
        'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
    ];
    $extension = strtolower(pathinfo($assetPath, PATHINFO_EXTENSION));
    header('Content-Type: ' . $mimeTypes[$extension]);
    header('Content-Length: ' . (string) filesize($assetPath));
    header('Cache-Control: public, max-age=3600');
    readfile($assetPath);
    exit;
}

$allowedRootPages = [
    'index.php', 'login.php', 'logout.php', 'register.php', 'resend-verification.php', 'verify-email.php', 'verify.php',
];
$normalizedPath = ltrim($requestPath, '/');
if ($requestPath === '/') $normalizedPath = 'index.php';
$allowed = in_array($normalizedPath, $allowedRootPages, true)
    || preg_match('#^(admin|staff|user)/[A-Za-z0-9_-]+\.php$#', $normalizedPath) === 1;

if (!$allowed) {
    http_response_code(404);
    exit('Not found.');
}

$pagePath = realpath($projectRoot . '/' . $normalizedPath);
if ($pagePath === false || !str_starts_with($pagePath, $projectRoot . DIRECTORY_SEPARATOR) || !is_file($pagePath)) {
    http_response_code(404);
    exit('Not found.');
}

$_SERVER['SCRIPT_NAME'] = '/' . $normalizedPath;
$_SERVER['PHP_SELF'] = '/' . $normalizedPath;
$_SERVER['SCRIPT_FILENAME'] = $pagePath;
chdir($projectRoot);
require $pagePath;
