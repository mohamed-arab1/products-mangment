<?php

/**
 * Laravel Vercel Entry Point
 *
 * This file acts as the serverless function entry point for Vercel.
 * It bootstraps the Laravel application and handles all incoming requests.
 */

define('LARAVEL_START', microtime(true));

// On Vercel, ensure HTTP_HOST is set so Laravel can build correct URLs.
if (empty($_SERVER['HTTP_HOST'])) {
    $appUrl = $_ENV['APP_URL'] ?? $_SERVER['APP_URL'] ?? '';
    if ($appUrl) {
        $parsed = parse_url($appUrl);
        $_SERVER['HTTP_HOST']   = $parsed['host'] ?? 'localhost';
        $_SERVER['HTTPS']       = ($parsed['scheme'] ?? 'https') === 'https' ? 'on' : 'off';
        $_SERVER['SERVER_PORT'] = ($parsed['scheme'] ?? 'https') === 'https' ? 443 : 80;
    }
}

// Ensure REQUEST_URI is set (Vercel may provide PATH_INFO instead).
if (empty($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = $_SERVER['PATH_INFO'] ?? '/';
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__ . '/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
$app = require_once __DIR__ . '/bootstrap/app.php';

$app->handleRequest(Illuminate\Http\Request::capture());
