<?php

use App\Http\Controllers\api\AnalyticsController;
use App\Http\Controllers\api\BookingController;
use App\Http\Controllers\api\EventController;
use App\Http\Controllers\api\JobsController;
use App\Http\Controllers\api\LoginController;
use App\Http\Controllers\api\MailController;
use App\Http\Controllers\api\NotificationController;
use App\Http\Controllers\api\PaymentController;
use App\Http\Controllers\api\SalesController;
use App\Http\Controllers\api\StatsController;
use App\Http\Controllers\api\TicketPackageController;
use App\Http\Controllers\api\UserController;
use App\Http\Controllers\api\VenueController;
use App\Http\Controllers\api\PromotionController;
use App\Models\Event;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

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

Route::get('/healthcheck', function () {
    return response()->json(['status' => 'OK']);
});

Route::post('login', [LoginController::class, 'login']);
Route::post('socialLogin', [LoginController::class, 'socialLogin']);
Route::post('/auth/register', [LoginController::class, 'register']);
Route::post('/auth/password/forgot', [LoginController::class, 'forgotPassword']);
Route::post('/auth/password/reset', [LoginController::class, 'resetPassword']);

// Manager app
Route::post('/auth/manager/login', [LoginController::class, 'managerLogin']);
Route::post('/auth/manager/socialLogin', [LoginController::class, 'managerSocialLogin']);

Route::get('/events', [EventController::class, 'index']);
Route::get('/event/{id}', [EventController::class, 'getEventByUID']);

Route::post('/bookings', [BookingController::class, 'store']);
Route::post('/bookings/update', [BookingController::class, 'updateBooking'])->name('updateBooking');
Route::post('/bookings/{id}', [BookingController::class, 'getBooking']);

//Route::post('/bookings/update/{id}', [BookingController::class, 'updateStatus']);

Route::post('/promotions/check', [PromotionController::class, 'checkPromo'])->name('checkPromo');

Route::get('/search', function (Request $request) {
    $events = Event::search($request->q)->whereIn(
        'status',
        ['ongoing', 'pending', 'soldout']
    )->get();
    $events->load('venue', 'ticket_packages');
    return $events;
});

Route::get('/event/invitation/{invitationId}', [EventController::class, 'getInvitation'])->name('getInvitation');
Route::post('/event/invitationRSVP', [EventController::class, 'invitationRSVP'])->name('invitationRSVP');

Route::post('/sms/sendBulk', [NotificationController::class, 'sendBulkSMS'])->name('sendBulkSMS');

Route::middleware([
    'auth:sanctum'
])->group(function () {
    Route::delete('/user/profile', [UserController::class, 'destroyUserAccount']);
    Route::get('user/orders', [UserController::class, 'getUserData'])->name('getUserData');
    Route::get('user', [UserController::class, 'getUserData'])->name('getUser');
    Route::post('/auth/logout', [LoginController::class, 'managerLogOut']);
    Route::post('user/profile', [UserController::class, 'updateProfile'])->name('updateProfile');
    Route::post('/subscribe', [NotificationController::class, 'subscribe'])->name('subscribe');
});

Route::middleware([
    'auth:sanctum',
    'role:Admin'
])->group(function () {
    Route::get('/events/search', function (Request $request) {
        $events = Event::search($request->q)->get();
        $events->load('venue', 'ticket_packages');
        return $events;
    });
    Route::apiResource('/users', UserController::class);
    Route::post('/admin/verify', [UserController::class, 'verifyAdminUser'])->name('verifyAdminUser');
    
    Route::post('user/create', [UserController::class, 'store'])->name('store');
    Route::post('users/ban/{id}', [UserController::class, 'banUser'])->name('banUser');
    Route::post('users/activate/{id}', [UserController::class, 'activateUser'])->name('activateUser');

    Route::get('/stats', [StatsController::class, 'getBasicStats'])->name('getBasicStats');

    Route::post('/events', [EventController::class, 'store']);
    Route::post('updateEvent/{id}', [EventController::class, 'updateEvent'])->name('event.updateEvent');
    Route::delete('/events/{id}', [EventController::class, 'destroy'])->name('event.destroy');
    Route::get('/events/{id}', [EventController::class, 'show']);

    Route::apiResource('/venues', VenueController::class);
    Route::post('updateVenue/{id}', [VenueController::class, 'update'])->name('venue.updateVenue');

    Route::apiResource('/ticket-packages', TicketPackageController::class);

    Route::get('/bookings', [BookingController::class, 'index']);
    Route::put('/bookings/{id}', [BookingController::class, 'update']);
    Route::post('/bookings/generateticket/{id}', [BookingController::class, 'generateETicket'])->name('generateETicket');
    Route::post('/bookings/generateSubBookings/{id}', [BookingController::class, 'generateSubBookings'])->name('generateSubBookings');
    
    Route::apiResource('/sales', SalesController::class);

    Route::post('send/orderMail', [MailController::class, 'sendOrderMail'])->name('sendOrderMail');

    Route::get('jobs', [JobsController::class, 'getJobs'])->name('getJobs');
    Route::post('jobs', [JobsController::class, 'startJobsQueue'])->name('startJobsQueue');
    Route::post('jobs/failed', [JobsController::class, 'retryFailedJobs'])->name('retryFailedJobs');

    Route::post('/events/sendInvitations', [EventController::class, 'sendInvitations']);
    Route::get('/events/getInvitations/{id}', [EventController::class, 'getInvitations']);

    Route::post('/notifications/schedule', [NotificationController::class, 'schedule']);
    Route::get('/notifications', [NotificationController::class, 'getNotifications']);
    Route::get('/notification/{id}', [NotificationController::class, 'getNotification']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'removeNotification']);
    Route::put('/notifications/{id}', [NotificationController::class, 'updateNotification']);

    Route::get('payments/gateways', [PaymentController::class, 'getPaymentGateways'])->name('getPaymentGateways');
    Route::get('payments/gateways/{id}', [PaymentController::class, 'getPaymentGateway'])->name('getPaymentGateway');
    Route::post('payments/gateways', [PaymentController::class, 'addPaymentGateway'])->name('addPaymentGateway');
    Route::post('payments/gateways/{id}', [PaymentController::class, 'updatePaymentGateway'])->name('updatePaymentGateway');
    Route::delete('payments/gateways/{id}', [PaymentController::class, 'removePaymentGateway'])->name('removePaymentGateway');

    Route::post('/analytics/add-pixel', [AnalyticsController::class, 'addPixelCode']);
    Route::post('/analytics/create-marketing-link', [AnalyticsController::class, 'addEventMarketingLink']);
    Route::get('/analytics/analytic-codes/{id}', [AnalyticsController::class, 'getPixelAndMarketingLinksByEvent']);

});

Route::middleware([
    'auth:sanctum',
    'role:Manager|Admin',
])->group(function () {
    Route::get('/manager/sales', [SalesController::class, 'managerSales']);
    Route::get('/manager/events', [EventController::class, 'getManagerEvents']);
    Route::get('/manager/events/{id}', [EventController::class, 'getManagerEventById']);
    Route::post('/manager/events/{id}', [EventController::class, 'updateManagerEvent']);
    Route::post('/manager/sendInvitations', [EventController::class, 'sendInvitations']);
    Route::get('/manager/getInvitations/{id}', [EventController::class, 'getInvitations']);
    Route::get('event/stats/{id}', [EventController::class, 'getEventStats'])->name('event.getEventStats');
    Route::get('/manager/coordinators', [UserController::class, 'getManagerCoordinators']);
    Route::post('/manager/coordinators', [UserController::class, 'createManagerCoordinator']);
    Route::put('/manager/coordinators/{id}', [UserController::class, 'updateManagerCoordinator']);
    Route::delete('/manager/coordinators/{id}', [UserController::class, 'deleteManagerCoordinator']);
    Route::put('/manager/coordinators/activate/{id}', [UserController::class, 'activateManagerCoordinator']);
    Route::put('/manager/coordinators/deactivate/{id}', [UserController::class, 'deactivateManagerCoordinator']);
    Route::get('/users?type=coordinator', [UserController::class, 'getManagerCoordinators']);
});

Route::middleware([
    'auth:sanctum',
    'role:Manager|Admin|Coordinator',
])->group(function () {
    Route::post('/auth/manager/logout', [LoginController::class, 'managerLogOut']);
    Route::post('/booking/verify', [BookingController::class, 'verifyBooking'])->name('verifyBooking');
});
