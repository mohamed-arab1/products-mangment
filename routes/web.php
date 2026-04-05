<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'status'  => 'ok',
        'message' => 'Mabe3aty API is running',
        'version' => '1.0.0',
    ]);
});

// Temporary: remove after debugging
Route::get('/debug-request', function () {
    return response()->json([
        'REQUEST_URI'  => $_SERVER['REQUEST_URI']  ?? null,
        'HTTP_HOST'    => $_SERVER['HTTP_HOST']    ?? null,
        'DB_HOST'      => config('database.connections.pgsql.host'),
        'DB_USERNAME'  => config('database.connections.pgsql.username'),
        'DB_PORT'      => config('database.connections.pgsql.port'),
    ]);
});

