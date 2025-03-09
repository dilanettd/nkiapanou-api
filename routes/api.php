<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AdminController;

/*
|--------------------------------------------------------------------------
| API Routes for Authentication
|--------------------------------------------------------------------------
*/

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/social-login', [AuthController::class, 'socialLogin']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::post('/resend-verification', [AuthController::class, 'resendVerification']);
Route::post('/email/verify', [AuthController::class, 'verify']);


/*
|--------------------------------------------------------------------------
| API Routes for User Profile
|--------------------------------------------------------------------------
*/

// Toutes ces routes sont protégées et nécessitent une authentification
Route::middleware('auth:api')->group(function () {
    // Profil utilisateur
    Route::get('/profile', [UserController::class, 'getProfile']);
    Route::put('/profile', [UserController::class, 'updateProfile']);
    Route::post('/profile/image', [UserController::class, 'updateProfileImage']);

    // Préférences utilisateur
    Route::put('/preferences', [UserController::class, 'updatePreferences']);

    // Adresses utilisateur
    Route::get('/addresses', [UserController::class, 'getAddresses']);
    Route::post('/addresses', [UserController::class, 'addAddress']);
    Route::put('/addresses/{id}', [UserController::class, 'updateAddress']);
    Route::delete('/addresses/{id}', [UserController::class, 'deleteAddress']);
    Route::put('/addresses/{id}/default', [UserController::class, 'setDefaultAddress']);
});


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

Route::middleware('auth:api')->group(function () {
    // Routes nécessitant une authentification
    Route::middleware('admin')->group(function () {
        // Routes pour les admins
        Route::apiResource('admins', AdminController::class);
        Route::patch('admins/{id}/toggle-status', [AdminController::class, 'toggleStatus']);
        Route::post('admins/create-with-user', [AdminController::class, 'createWithUser']);
    });
});