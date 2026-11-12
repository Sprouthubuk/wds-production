<?php

use App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\EngagementController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PublicController;
use Illuminate\Support\Facades\Route;

// Public
Route::get('/competitions', [PublicController::class, 'competitions']);
Route::get('/competitions/{slug}', [PublicController::class, 'competition']);
Route::get('/upcoming', [PublicController::class, 'upcoming']);
Route::get('/upcoming/{slug}', [PublicController::class, 'upcomingBySlug']);
Route::get('/preview/{token}', [PublicController::class, 'preview'])->middleware('throttle:30,1');
Route::get('/winners', [PublicController::class, 'winners']);
Route::get('/draws', [PublicController::class, 'draws']);
Route::get('/draws/{draw}/certificate', [PublicController::class, 'certificate'])->name('draws.certificate');
Route::get('/settings', [PublicController::class, 'settings']);

Route::middleware('throttle:10,1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
    Route::post('/newsletter', [EngagementController::class, 'newsletter']);
    Route::post('/contact', [EngagementController::class, 'contact']);
});

// Checkout (guest or signed in) and order confirmation
Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:20,1');
Route::get('/orders/{reference}', [CheckoutController::class, 'show']);

// Payments
Route::post('/payments/webhook', [PaymentController::class, 'webhook'])->name('payments.webhook');
Route::get('/payments/fake', [PaymentController::class, 'fake'])->name('payments.fake')->middleware('signed');

// Signed-in customer
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [MeController::class, 'show']);
    Route::put('/me', [MeController::class, 'update']);
    Route::get('/me/entries', [MeController::class, 'entries']);
    Route::get('/me/orders', [MeController::class, 'orders']);
    Route::post('/me/limits', [MeController::class, 'limits']);
    Route::post('/me/break', [MeController::class, 'takeBreak']);
    Route::post('/me/self-exclude', [MeController::class, 'selfExclude']);
    Route::post('/me/verification', [MeController::class, 'verification']);
});

// Staff (event sales + postal logging)
Route::prefix('admin')->middleware(['auth:sanctum', 'role:staff'])->group(function () {
    Route::get('/event-sales/competitions', [Admin\EventSaleController::class, 'competitions']);
    Route::post('/event-sales', [Admin\EventSaleController::class, 'store']);
    Route::get('/event-sales/summary', [Admin\EventSaleController::class, 'summary']);
    Route::get('/postal', [Admin\PostalController::class, 'index']);
    Route::post('/postal', [Admin\PostalController::class, 'store']);
});

// Admin
Route::prefix('admin')->middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/dashboard', Admin\DashboardController::class);

    Route::get('/competitions', [Admin\CompetitionController::class, 'index']);
    Route::post('/competitions', [Admin\CompetitionController::class, 'store']);
    Route::get('/competitions/{competition}', [Admin\CompetitionController::class, 'show']);
    Route::put('/competitions/{competition}', [Admin\CompetitionController::class, 'update']);
    Route::post('/competitions/{competition}/publish', [Admin\CompetitionController::class, 'publish']);
    Route::post('/competitions/{competition}/extend', [Admin\CompetitionController::class, 'extend']);
    Route::get('/competitions/{competition}/preview-link', [Admin\PreviewLinkController::class, 'show']);
    Route::post('/competitions/{competition}/preview-link', [Admin\PreviewLinkController::class, 'store']);
    Route::delete('/competitions/{competition}/preview-link', [Admin\PreviewLinkController::class, 'destroy']);
    Route::post('/uploads/competition-image', [Admin\CompetitionController::class, 'uploadImage']);

    Route::get('/orders', [Admin\OrderController::class, 'index']);
    Route::get('/orders/{order}', [Admin\OrderController::class, 'show']);
    Route::post('/orders/{order}/refunded', [Admin\OrderController::class, 'markRefunded']);

    Route::get('/customers', [Admin\CustomerController::class, 'index']);
    Route::get('/customers/{user}', [Admin\CustomerController::class, 'show']);
    Route::post('/customers/{user}/credit', [Admin\CustomerController::class, 'addCredit']);
    Route::post('/customers/{user}/suspend', [Admin\CustomerController::class, 'setSuspended']);
    Route::post('/customers/{user}/role', [Admin\CustomerController::class, 'setRole']);
    Route::get('/verifications', [Admin\CustomerController::class, 'verifications']);
    Route::get('/verifications/{verification}/{which}', [Admin\CustomerController::class, 'verificationFile']);
    Route::post('/verifications/{verification}', [Admin\CustomerController::class, 'reviewVerification']);

    // Draws run on Verity (verity:process-due). Admins see the state and publish the result; nothing here changes one.
    Route::get('/draws', [Admin\DrawController::class, 'index']);
    Route::get('/draws/{draw}/entries.csv', [Admin\DrawController::class, 'entries']);
    Route::post('/draws/{draw}/publish', [Admin\DrawController::class, 'publish']);

    Route::get('/winners', [Admin\WinnerController::class, 'index']);
    Route::post('/winners', [Admin\WinnerController::class, 'store']);
    Route::put('/winners/{winner}', [Admin\WinnerController::class, 'update']);
    // Permanent and public (Verity's audit chain). There is no separate redraw route: a redraw is chosen
    // at the forfeit and committed to a future drand round there and then (protocol R2).
    Route::post('/winners/{winner}/forfeit', [Admin\WinnerController::class, 'forfeit']);
    Route::post('/winners/{winner}/image', [Admin\WinnerController::class, 'uploadImage']);

    Route::get('/promotions', [Admin\PromotionController::class, 'index']);
    Route::post('/promotions', [Admin\PromotionController::class, 'store']);
    Route::put('/promotions/{code}', [Admin\PromotionController::class, 'update']);

    Route::get('/settings', [Admin\SettingsController::class, 'show']);
    Route::put('/settings', [Admin\SettingsController::class, 'update']);
});
