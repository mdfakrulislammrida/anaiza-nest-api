<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'Anaiza Nest API',
        'status' => 'ok',
    ]);
});
