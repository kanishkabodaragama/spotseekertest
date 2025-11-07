<?php

use App\Http\Controllers\BookingController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StaterkitController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VenueController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/


//Route::get('/', [HomeController::class, 'home'])->name('home');

// Route Components
/*Route::get('layouts/collapsed-menu', [StaterkitController::class, 'collapsed_menu'])->name('collapsed-menu');
Route::get('layouts/full', [StaterkitController::class, 'layout_full'])->name('layout-full');
Route::get('layouts/without-menu', [StaterkitController::class, 'without_menu'])->name('without-menu');
Route::get('layouts/empty', [StaterkitController::class, 'layout_empty'])->name('layout-empty');
Route::get('layouts/blank', [StaterkitController::class, 'layout_blank'])->name('layout-blank');*/


// locale Route
//Route::get('lang/{locale}', [LanguageController::class, 'swap']);

/*Route::middleware([
    'auth:sanctum',
    'role:Admin',
])->group(function () {

    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    Route::get('home', [HomeController::class, 'home']);


    Route::get('event-list', [EventController::class, 'index'])->name('event-list.index');
    Route::get('event-create', [EventController::class, 'create'])->name('event-list.create');
    Route::get('event-edit/{id}', [EventController::class, 'edit'])->name('event-list.edit');

    Route::get('venue-list', [VenueController::class, 'index'])->name('venue-list.index');
    Route::get('venue-create', [VenueController::class, 'create'])->name('venue-list.create');

    Route::get('booking-list', [BookingController::class, 'index'])->name('booking-list.index');
    Route::get('booking-create', [BookingController::class, 'create'])->name('booking-list.create');

    Route::get('user-list', [UserController::class, 'index'])->name('user-list.index');
    Route::get('user-view/{id}', [UserController::class, 'show'])->name('user-list.show');
    Route::get('user-create', [UserController::class, 'create'])->name('user-list.create');
    Route::post('user-create', [UserController::class, 'store'])->name('user.store');

    Route::get('sales-list', [SalesController::class, 'index'])->name('sales-list.index');

    Route::post('updateEvent/{id}', [EventController::class, 'updateEvent'])->name('updateEvent');
    
    Route::apiResource('/users', App\Http\Controllers\api\UserController::class);
    Route::get('user/orders', [UserController::class, 'getOrders'])->name('getOrders');
        
    Route::apiResource('/sales', App\Http\Controllers\api\SalesController::class);

});*/
