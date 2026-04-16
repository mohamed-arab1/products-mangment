<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ForgotPasswordController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\TargetController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DashboardController;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink']);
Route::post('/reset-password', [ForgotPasswordController::class, 'reset']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/dashboard', [DashboardController::class, 'show']);

    // Invoices & sales (sellers + admin)
    Route::apiResource('invoices', InvoiceController::class);
    Route::post('/invoices/{invoice}/items', [InvoiceController::class, 'addItem']);
    Route::delete('/invoices/{invoice}/items/{item}', [InvoiceController::class, 'removeItem']);
    Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'addPayment']);

    // Products (المسار المحدد قبل apiResource حتى لا يُفسَّر «stats» كمعرّف)
    Route::get('/products/{product}/stats', [ProductController::class, 'stats']);
    Route::get('/products/{product}/batches', [ProductController::class, 'batches']);
    Route::post('/products/{product}/batches', [ProductController::class, 'storeBatch']);
    Route::apiResource('products', ProductController::class);
    Route::get('/products-low-stock', [ProductController::class, 'lowStock']);
    Route::get('/products-near-expiry', [ProductController::class, 'nearExpiry']);
    Route::get('/products-by-code/{code}', [ProductController::class, 'findByCode']);

    // Customers (buyers)
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::post('/conversations', [ConversationController::class, 'store']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
    Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'sendMessage']);
    Route::post('/conversations/{conversation}/read', [ConversationController::class, 'markRead']);

    // Reports
    Route::get('/reports/daily', [ReportController::class, 'daily']);
    Route::get('/reports/monthly', [ReportController::class, 'monthly']);
    Route::get('/reports/profits', [ReportController::class, 'profits']);
    Route::get('/reports/best-selling-product', [ReportController::class, 'bestSellingProduct']);
    Route::get('/reports/chart-daily', [ReportController::class, 'chartDaily']);
    Route::get('/reports/smart-insights', [ReportController::class, 'smartInsights']);
    Route::get('/reports/loyalty-summary', [ReportController::class, 'loyaltySummary']);
    Route::get('/reports/credit-dues', [ReportController::class, 'creditDues']);
    Route::get('/reports/invoice-status', [ReportController::class, 'invoiceStatus']);

    // Targets
    Route::apiResource('targets', TargetController::class)->only(['index', 'store', 'update', 'destroy']);

    // Notifications
    Route::get('/notifications/count', [NotificationController::class, 'count']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{notification}/mark', [NotificationController::class, 'mark']);
    Route::post('/notifications/mark-all', [NotificationController::class, 'markAll']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);

    // Admin only
    Route::middleware('admin')->group(function () {
        Route::get('/admin/dashboard', [AdminController::class, 'dashboard']);
        Route::get('/admin/sellers', [AdminController::class, 'sellers']);
        Route::post('/admin/sellers', [AdminController::class, 'storeSeller']);
        Route::get('/admin/sales-report', [AdminController::class, 'salesReport']);
    });
});
