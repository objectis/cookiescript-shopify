<?php

use App\Http\Controllers\GoogleConsentController;
use App\Http\Controllers\ScriptController;
use Illuminate\Support\Facades\Route;

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
Route::post('/google-consent-mode', [GoogleConsentController::class, 'store']);
Route::get('/store-script', [ScriptController::class, 'getScriptContent']);
Route::post('/add-script-tag', [ScriptController::class, 'addScriptTag']);
Route::get('/get-stored-settings', [GoogleConsentController::class, 'getStoredSettings']);
