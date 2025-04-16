<?php

// use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Api\GuideController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\MountainController;


Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/user', [AuthController::class, 'userInfo']);
    Route::post('/logout', [AuthController::class, 'logout']);
});
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Guide Registration
Route::post('/guide/register', [GuideController::class, 'register']);

// Guide profile routes (protected by auth)
Route::middleware(['auth:sanctum', 'role:guide'])->group(function () {
    Route::get('/guide/profile', [GuideController::class, 'getProfile']);
    Route::post('/guide/profile/update', [GuideController::class, 'updateProfile']);
});

// Admin routes for guide management (protected)
Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/guides/all', [GuideController::class, 'getAllGuides']);
    Route::get('/guides/pending', [GuideController::class, 'getPendingGuides']);
    Route::post('/guides/{id}/approve', [GuideController::class, 'approveGuide']);
    Route::post('/guides/{id}/reject', [GuideController::class, 'rejectGuide']);
    Route::get('/guides/{id}', [GuideController::class, 'getGuideById']);
    Route::delete('/guides/{id}/ban', [GuideController::class, 'banGuide']);
    // Inside your admin middleware group
    Route::post('/guides/{id}/suspend', [GuideController::class, 'suspendGuide']);
    Route::post('/guides/{id}/unsuspend', [GuideController::class, 'unsuspendGuide']);
});

// Rest of your routes remain unchanged
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user/profile', [UserProfileController::class, 'show']);
    Route::post('/user/profile', [UserProfileController::class, 'update']);
    // Add this route with your other profile routes
    Route::post('/profile/image', [UserProfileController::class, 'updateProfileImage'])->middleware('auth:sanctum');

    // Add this new route for creating default profiles
    // Make sure this route exists in your api.php file
    Route::middleware('auth:sanctum')->group(function () {
        // Existing routes...

        // Add this route for creating default profiles if it doesn't exist
        Route::post('/user/profile/create-default', [App\Http\Controllers\Api\UserProfileController::class, 'createDefaultProfile']);
    });
});


// Public routes
Route::get('mountains', [App\Http\Controllers\Api\MountainController::class, 'index']);
Route::get('mountains/{id}', [MountainController::class, 'show']);

// Protected routes (admin only)
Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::post('mountains', [MountainController::class, 'store']);
    Route::put('mountains/{id}', [MountainController::class, 'update']);
    Route::delete('mountains/{id}', [MountainController::class, 'destroy']);
});
// Inside the guide middleware group
Route::middleware(['auth:sanctum', 'role:guide'])->group(function () {
    Route::post('/trips', [TripController::class, 'store']);
    Route::get('/guide/trips', [TripController::class, 'getGuideTrips']);
    Route::post('/guide/trips/{id}/finish', [TripController::class, 'finishTrip']);
});

// Public trip routes
Route::get('/trips/all', [TripController::class, 'getAllTrips']);
Route::get('/trips/{id}', [TripController::class, 'getTripDetail']);

// Booking routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/trips/{tripId}/book', [BookingController::class, 'bookTrip']);
    Route::get('/user/bookings', [BookingController::class, 'getUserBookings']);
    Route::get('/guide/bookings', [BookingController::class, 'getGuideBookings']);
    Route::get('/payments/{invoiceNumber}', [PaymentController::class, 'getPaymentStatus']);
});

// Payment callback (no auth required)
Route::post('/payments/callback', [PaymentController::class, 'callback']);

// Add these routes to your existing routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/payments/{orderId}/verify', [PaymentController::class, 'verifyPaymentStatus']);
    Route::post('/payments/return', [PaymentController::class, 'handlePaymentReturn']);
    // Add this route for payment completion
    Route::post('/bookings/{bookingId}/complete', [BookingController::class, 'handlePaymentCompletion'])
        ->middleware('auth:sanctum');

    // Guide earnings and withdrawal routes
    Route::middleware(['auth:sanctum'])->prefix('guide')->group(function () {
        Route::get('/earnings-summary', [PaymentController::class, 'getEarningsSummary']);
        Route::get('/earnings-list', [PaymentController::class, 'getGuideEarnings']);
        Route::get('/withdrawals-history', [PaymentController::class, 'getWithdrawalHistory']);
        Route::post('/withdrawals/request', [PaymentController::class, 'requestWithdrawal']);
    });

    // Invoice routes
    Route::get('/payments/{invoice}/invoice', [PaymentController::class, 'getInvoiceDetail']);
    Route::get('/payments/{invoice}/download', [PaymentController::class, 'downloadInvoice']);
});

// Admin routes for managing guide payments
Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/admin/guide-earnings', [PaymentController::class, 'getAllGuideEarnings']);
    Route::get('/admin/withdrawals', [PaymentController::class, 'getPendingWithdrawals']);
    Route::post('/admin/withdrawals/{id}/process', [PaymentController::class, 'processWithdrawal']);
    Route::post('/admin/withdrawals/{id}/reject', [PaymentController::class, 'rejectWithdrawal']);
    Route::get('/admin/withdrawals/{id}/check-balance', [PaymentController::class, 'checkGuideBalanceAfterWithdrawal']);

    // Tambahkan route baru ini untuk membuat data dummy
    Route::post('/admin/create-dummy-withdrawal', [PaymentController::class, 'createDummyWithdrawal']);
    Route::post('/admin/process-pending-earnings', [PaymentController::class, 'processAllPendingEarnings']);
       // Tambahkan route baru ini
       Route::post('/admin/create-dummy-earning', [PaymentController::class, 'createDummyEarning']);
       // Add this route for testing
       Route::post('/admin/earnings/reset-for-testing', [App\Http\Controllers\Api\PaymentController::class, 'resetGuideEarningsForTesting'])
       ->middleware(['auth:sanctum', 'admin']);
       // Add this temporary route for testing
       Route::post('/admin/process-payment/{paymentId}', function($paymentId) {
           $payment = \App\Models\Payment::find($paymentId);
           $controller = app(\App\Http\Controllers\Api\PaymentController::class);
           return $controller->handleSuccessfulPayment($payment);
       });
    // Add these routes for payment processing and checking
    Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
        // Existing admin routes...

        // Add these new routes for payment processing
        Route::get('/admin/payments/{paymentId}/check-earnings', [PaymentController::class, 'checkPaymentAndEarnings']);
        Route::post('/admin/payments/{paymentId}/process-guide-earnings', [PaymentController::class, 'processPaymentToGuideEarnings']);
    });
});


