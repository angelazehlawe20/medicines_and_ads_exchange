<?php

use App\Http\Controllers\AdminController;
use Illuminate\Http\Request;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\MedicineController;
use App\Http\Controllers\CitizenController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\ExchangeAdController;
use App\Http\Controllers\PharmacyController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SpecialistController;
use App\Http\Controllers\StripeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});


Route::controller(AuthController::class)->group(function () {
    Route::post('/register', 'register');
    Route::post('/login', 'login');
    Route::get('/get-my-profile', 'getMyProfile')->middleware('auth:sanctum');
    Route::post('/update-my-profile', 'updateMyProfile')->middleware('auth:sanctum');
});

Route::controller(AdminController::class)->middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/get-dashboard-stats', 'getDashboardStats');
    Route::post('/get-users-by-role', 'getUsersByRole');
    Route::post('/toggle-user-status', 'toggleUserStatus');
    Route::get('/manage-exchange-ads', 'manageExchangeAds');
    Route::post('/search-user', 'searchUser');
    Route::post('/search-medicine-in-ads', 'searchMedicineInAds');
    Route::post('/add-user', 'addUser');
    Route::post('/delete-user', 'deleteUser');
});

Route::controller(MedicineController::class)->middleware(['auth:sanctum', 'role:pharmacy'])->group(function () {
    Route::post('/add-medicine', 'addMedicine');
    Route::post('/update-medicine', 'updateMedicine');
    Route::post('/delete-medicine', 'deleteMedicine');
});

Route::controller(CitizenController::class)->group(function () {
    Route::post('/get-all-medicines', 'getAllMedicines');  // ممكن بدون توكن
    Route::post('/create-order-for-pharmacist', 'createOrderForPharmacist')->middleware(['auth:sanctum', 'role:citizen']);
    Route::post('/cancel-order', 'cancelOrder')->middleware(['auth:sanctum', 'role:citizen']);
    Route::post('/cancel-order', 'cancelOrder')->middleware(['auth:sanctum', 'role:citizen']);
    Route::post('/get-my-order-history', 'getMyOrderHistory')->middleware(['auth:sanctum', 'role:citizen']);
});

Route::controller(PharmacyController::class)->middleware(['auth:sanctum', 'role:pharmacy'])->group(function () {
    Route::get('/get-my-orders', 'getMyOrders');
    Route::post('/accept-order', 'acceptOrder');
    Route::post('/reject-order', 'rejectOrder');
    Route::get('/get-pharmacy-reviews', 'getPharmacyReviews');
    Route::get('/get-my-inventory', 'getMyInventory');
});

Route::controller(ReviewController::class)->middleware(['auth:sanctum', 'role:citizen'])->group(function () {
    Route::post('/add-review', 'addReview');
    Route::post('/delete-review', 'deleteReview');
});

Route::controller(DeliveryController::class)->middleware(['auth:sanctum', 'role:delivery'])->group(function () {
    Route::post('/accept-delivery', 'acceptDelivery');
    Route::post('/reject-delivery', 'rejectDelivery');
    Route::post('/pick-up-order', 'pickUpOrder');
    Route::post('/deliver-order', 'deliverOrder');
    Route::post('/get-order-details', 'getOrderDetails');
    Route::post('/update-availability-status', 'updateAvailabilityStatus');
    Route::get('/get-my-assigned-orders', 'getMyAssignedOrders');
});

Route::controller(ExchangeAdController::class)->group(function () {
    Route::post('/create-ad', 'createAd')->middleware(['auth:sanctum', 'role:citizen']);
    Route::post('/delete-pending-ad', 'deletePendingAd')->middleware(['auth:sanctum', 'role:citizen']);
    Route::get('/get-all-my-ads', 'getAllMyAds')->middleware(['auth:sanctum', 'role:citizen']);
    Route::post('/get-all-confirm-ads', 'getAllConfirmAds'); // ممكن بدون توكن
});

Route::controller(SpecialistController::class)->middleware(['auth:sanctum', 'role:specialist'])->group(function () {
    Route::get('/get-my-pending-ads', 'getMyPendingAds');
    Route::post('/verify-ad', 'verifyAd');
    Route::post('/mark-ad-as-taken', 'markAdAsTaken');
    Route::get('/get-my-action-history', 'getMyActionHistory')->middleware('auth:sanctum');
});

Route::controller(StripeController::class)->group(function () {
    Route::post('/payment/create-intent', 'createPaymentIntent')->middleware('auth:sanctum');
    Route::post('payment/webhook','handleWebhook');
});
