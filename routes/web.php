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
        'PATH_INFO'    => $_SERVER['PATH_INFO']    ?? null,
        'SCRIPT_NAME'  => $_SERVER['SCRIPT_NAME']  ?? null,
        'request_url'  => request()->url(),
        'request_path' => request()->path(),
    ]);
});

