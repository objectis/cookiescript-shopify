<?php

use App\Http\Controllers\ScriptController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Shopify\Rest\Admin2023_01\ScriptTag;
use Shopify\Utils;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
Route::post('/add-script', [ScriptController::class, 'addScript']);
Route::get('/get-script-tags', [ScriptController::class, 'getScripts']);
Route::delete('/remove-script-tag/{id}', [ScriptController::class, 'removeScript']);
