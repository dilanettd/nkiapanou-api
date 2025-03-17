<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\WishlistController;
use App\Http\Controllers\UserAddressController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\ProductReviewController;
use App\Http\Controllers\InventoryMovementController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NewsletterSubscriberController;
use App\Http\Controllers\ShippingFormulaController;
use App\Http\Controllers\TransactionController;


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

Route::middleware('auth:api')->group(function () {
    Route::put('/change-password', [AuthController::class, 'changePassword']);
});

/* |-------------------------------------------------------------------------- 
  | API Routes for User Profile 
  |-------------------------------------------------------------------------- */

// Toutes ces routes sont protégées et nécessitent une authentification
Route::middleware('auth:api')->group(function () {
    // Profil utilisateur
    Route::get('/profile', [UserController::class, 'getProfile']);
    Route::put('/profile', [UserController::class, 'updateProfile']);
    Route::post('/profile/image', [UserController::class, 'updateProfileImage']);

    // Changement de mot de passe
    Route::put('/change-password', [UserController::class, 'changePassword']);

    // Préférences utilisateur
    Route::put('/preferences', [UserController::class, 'updatePreferences']);

    // Adresses utilisateur
    Route::get('/addresses', [UserController::class, 'getAddresses']);
    Route::post('/addresses', [UserController::class, 'addAddress']);
    Route::put('/addresses/{id}', [UserController::class, 'updateAddress']);
    Route::delete('/addresses/{id}', [UserController::class, 'deleteAddress']);
    Route::put('/addresses/{id}/default', [UserController::class, 'setDefaultAddress']);

    // Routes d'administration (protégées par vérification d'admin dans les contrôleurs)
    Route::get('/users', [UserController::class, 'getUsers']);
    Route::get('/users/{id}', [UserController::class, 'getUserById']);
    Route::delete('/users/{id}', [UserController::class, 'deleteUser']);
    Route::post('/users/{id}/convert-social', [UserController::class, 'convertSocialAccount']);
    Route::patch('/users/{id}/admin-status', [UserController::class, 'toggleAdminStatus']);
});


/* |--------------------------------------------------------------------------
   | API Routes for Newsletter Subscription
   |-------------------------------------------------------------------------- */

Route::post('/newsletter/subscribe', [NewsletterSubscriberController::class, 'subscribe']);
Route::post('/newsletter/unsubscribe', [NewsletterSubscriberController::class, 'unsubscribe']);

// Routes protégées pour l'administration
Route::middleware('auth:api')->group(function () {
    // Gestion des abonnés à la newsletter (admin)
    Route::prefix('admin/newsletter')->group(function () {
        Route::get('/subscribers', [NewsletterSubscriberController::class, 'index']);
        Route::post('/subscribers', [NewsletterSubscriberController::class, 'store']);
        Route::get('/subscribers/{id}', [NewsletterSubscriberController::class, 'show']);
        Route::put('/subscribers/{id}', [NewsletterSubscriberController::class, 'update']);
        Route::delete('/subscribers/{id}', [NewsletterSubscriberController::class, 'destroy']);

        // Export CSV
        Route::post('/export', [NewsletterSubscriberController::class, 'exportSelected']);
        Route::get('/export-all', [NewsletterSubscriberController::class, 'exportAll']);
    });
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
// Route d'authentification admin
Route::post('admin/login', [AdminController::class, 'loginAdmin']);
Route::middleware('auth:api')->group(function () {
    // Routes nécessitant une authentification
    Route::middleware('admin')->group(function () {
        // Routes pour les admins
        Route::apiResource('admins', AdminController::class);
        Route::patch('admins/{id}/toggle-status', [AdminController::class, 'toggleStatus']);
        Route::post('admins/create-with-user', [AdminController::class, 'createWithUser']);
    });
});

/*
|--------------------------------------------------------------------------
| Routes Wishlist (à ajouter dans routes/api.php)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:api')->prefix('wishlist')->group(function () {
    Route::get('/', [WishlistController::class, 'index']);
    Route::post('/', [WishlistController::class, 'store']);
    Route::get('/check/{productId}', [WishlistController::class, 'check']);
    Route::delete('/{productId}', [WishlistController::class, 'destroy']);
    Route::delete('/', [WishlistController::class, 'clear']);
});

/*
|--------------------------------------------------------------------------
| User Addresses Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:api')->prefix('user/addresses')->group(function () {
    Route::get('/', [UserAddressController::class, 'index']);
    Route::post('/', [UserAddressController::class, 'store']);
    Route::get('/{id}', [UserAddressController::class, 'show']);
    Route::put('/{id}', [UserAddressController::class, 'update']);
    Route::delete('/{id}', [UserAddressController::class, 'destroy']);
    Route::put('/{id}/default', [UserAddressController::class, 'setDefault']);
    Route::get('/type/{type}', [UserAddressController::class, 'getByType']);
    Route::get('/type/{type}/default', [UserAddressController::class, 'getDefaultByType']);
});

/* |-------------------------------------------------------------------------- 
  | API Routes for Products 
  |-------------------------------------------------------------------------- */

// Routes publiques
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show']);
Route::get('/products/slug/{slug}', [ProductController::class, 'showBySlug']);

// Routes protégées pour l'administration
Route::middleware('auth:api')->group(function () {
    // Vérification du rôle admin à faire dans les contrôleurs
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);

    // Gestion du statut et des produits mis en avant
    Route::patch('/products/{id}/status', [ProductController::class, 'updateStatus']);
    Route::patch('/products/{id}/featured', [ProductController::class, 'toggleFeatured']);

    // Upload d'image
    Route::post('/upload/product', [ProductController::class, 'uploadImage']);

    // Gestion des images de produit
    Route::post('/products/{id}/images', [ProductController::class, 'addProductImage']);
    Route::patch('/products/{productId}/images/{imageId}', [ProductController::class, 'updateProductImage']);
    Route::patch('/products/{productId}/images/{imageId}/primary', [ProductController::class, 'setImageAsPrimary']);
    Route::delete('/products/{productId}/images/{imageId}', [ProductController::class, 'deleteProductImage']);
});

/* |-------------------------------------------------------------------------- 
  | API Routes for Categories 
  |-------------------------------------------------------------------------- */

// Routes publiques
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/parents', [CategoryController::class, 'getParentCategories']);
Route::get('/categories/all', [CategoryController::class, 'getAllCategories']);
Route::get('/categories/{id}', [CategoryController::class, 'show']);
Route::get('/categories/slug/{slug}', [CategoryController::class, 'showBySlug']);
Route::get('/categories/{id}/products', [CategoryController::class, 'getCategoryProducts']);
Route::get('/categories/{id}/all-products', [CategoryController::class, 'getAllCategoryProducts']);

// Routes protégées pour l'administration
Route::middleware('auth:api')->group(function () {
    // Vérification du rôle admin à faire dans les contrôleurs
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

    // Gestion du statut
    Route::patch('/categories/{id}/status', [CategoryController::class, 'updateStatus']);
    Route::patch('/categories/{id}/status/toggle', [CategoryController::class, 'toggleStatus']);

    // Upload d'image
    Route::post('/upload/category', [CategoryController::class, 'uploadImage']);
});

/* |-------------------------------------------------------------------------- 
  | API Routes for Cart 
  |-------------------------------------------------------------------------- */

// Toutes les routes du panier nécessitent une authentification
Route::middleware('auth:api')->group(function () {
    Route::get('/cart', [CartController::class, 'getCart']);
    Route::post('/cart', [CartController::class, 'addToCart']);
    Route::put('/cart/{id}', [CartController::class, 'updateCartItem']);
    Route::delete('/cart/{id}', [CartController::class, 'removeCartItem']);
    Route::delete('/cart/clear', [CartController::class, 'clearCart']);
});

/* |-------------------------------------------------------------------------- 
  | API Routes for Product Reviews
  |-------------------------------------------------------------------------- */

// Routes publiques
Route::get('/products/{id}/reviews', [ProductReviewController::class, 'getProductReviews']);
Route::get('/reviews/top', [ProductReviewController::class, 'getTopReviews']);

// Routes authentifiées
Route::middleware('auth:api')->group(function () {
    Route::post('/reviews', [ProductReviewController::class, 'store']);
    Route::delete('/reviews/{id}', [ProductReviewController::class, 'destroy']);
    Route::get('/user/reviews', [ProductReviewController::class, 'getUserReviews']);
    Route::get('/users/{id}/reviews', [ProductReviewController::class, 'getUserReviews']);
    Route::prefix('admin')->group(function () {
        Route::get('/reviews', [ProductReviewController::class, 'index']);
        Route::get('/reviews/{id}', [ProductReviewController::class, 'show']);
        Route::patch('/reviews/{id}/status', [ProductReviewController::class, 'updateStatus']);
    });
});


/* |-------------------------------------------------------------------------- 
  | API Routes for Inventory Movements
  |-------------------------------------------------------------------------- */

// Toutes les routes nécessitent une authentification et des droits d'admin
Route::middleware(['auth:api'])->group(function () {
    Route::get('/inventory/movements', [InventoryMovementController::class, 'index']);
    Route::post('/inventory/movements', [InventoryMovementController::class, 'store']);
    Route::get('/inventory/movements/{id}', [InventoryMovementController::class, 'show']);
    Route::get('/inventory/products/{productId}/history', [InventoryMovementController::class, 'getProductHistory']);
    Route::get('/inventory/summary', [InventoryMovementController::class, 'getSummary']);
});


/* |-------------------------------------------------------------------------- 
  | API Routes for Orders
  |-------------------------------------------------------------------------- */

// Routes pour les commandes de l'utilisateur connecté
Route::middleware('auth:api')->group(function () {
    Route::get('/orders', [OrderController::class, 'getUserOrders']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::patch('/orders/{id}/cancel', [OrderController::class, 'cancelOrder']);
    Route::get('/orders/history', [OrderController::class, 'getOrdersHistory']);
});

// Routes d'administration des commandes
Route::middleware(['auth:api'])->prefix('admin')->group(function () {
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::patch('/orders/{id}/status', [OrderController::class, 'updateStatus']);
    Route::patch('/orders/{id}/payment-status', [OrderController::class, 'updatePaymentStatus']);
    Route::patch('/orders/{id}/tracking', [OrderController::class, 'updateTrackingNumber']);
});


/* |-------------------------------------------------------------------------- 
  | API Routes for Dashboard
  |-------------------------------------------------------------------------- */

// Routes protégées pour l'administration (nécessitent une authentification)
Route::middleware(['auth:api'])->prefix('admin/dashboard')->group(function () {
    // Route principale qui renvoie toutes les données du tableau de bord
    Route::get('/', [DashboardController::class, 'index']);

    // Routes pour les différentes sections du tableau de bord
    Route::get('/summary', [DashboardController::class, 'getSummary']);
    Route::get('/sales/daily', [DashboardController::class, 'getDailySales']);
    Route::get('/sales/monthly', [DashboardController::class, 'getMonthlySales']);
    Route::get('/products/top', [DashboardController::class, 'getTopProducts']);
    Route::get('/categories/top', [DashboardController::class, 'getTopCategories']);

    // Vous pouvez également ajouter d'autres routes spécifiques au besoin
    // Par exemple pour les statistiques personnalisées ou des périodes différentes
});


/* |-------------------------------------------------------------------------- 
  | API Routes for Shippings Formula
  |-------------------------------------------------------------------------- */
// Routes publiques
Route::get('/shipping/countries/{countryCode}', [ShippingFormulaController::class, 'getByCountry']);
Route::post('/shipping/calculate', [ShippingFormulaController::class, 'calculateForCart']);

// Routes protégées pour l'administration
Route::middleware('auth:api')->group(function () {
    // Vérification du rôle admin à faire dans un middleware
    Route::get('/admin/shipping', [ShippingFormulaController::class, 'index']);
    Route::post('/admin/shipping', [ShippingFormulaController::class, 'store']);
    Route::get('/admin/shipping/{id}', [ShippingFormulaController::class, 'show']);
    Route::put('/admin/shipping/{id}', [ShippingFormulaController::class, 'update']);
    Route::delete('/admin/shipping/{id}', [ShippingFormulaController::class, 'destroy']);
});


/* |-------------------------------------------------------------------------- 
  | API Routes for Transactions
  |-------------------------------------------------------------------------- */
// Routes protégées par authentification
Route::middleware('auth:api')->group(function () {
    // Routes pour les utilisateurs normaux
    Route::get('/transactions/user', [TransactionController::class, 'getUserTransactions']);
    Route::get('/orders/{orderId}/transactions', [TransactionController::class, 'getOrderTransactions']);

    // Routes pour les administrateurs (protégées par vérification admin dans le contrôleur)
    Route::get('/admin/transactions', [TransactionController::class, 'index']);
    Route::get('/admin/transactions/{id}', [TransactionController::class, 'show']);
    Route::post('/admin/transactions', [TransactionController::class, 'store']);
    Route::put('/admin/transactions/{id}', [TransactionController::class, 'update']);
    Route::post('/admin/transactions/refund', [TransactionController::class, 'processRefund']);
    Route::get('/admin/transaction/statistics', [TransactionController::class, 'getStatistics']);
});