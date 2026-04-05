<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'status'  => 'ok',
        'message' => 'Mabe3aty API is running',
        'version' => '1.0.0',
    ]);
});
