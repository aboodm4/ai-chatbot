<?php

use App\Http\Controllers\API\AiController;
use App\Services\AiSupportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Route::get('/', function () {
//     return view('welcome');
// });


Route::post('/ask-ai', [AiController::class, 'ask']);
