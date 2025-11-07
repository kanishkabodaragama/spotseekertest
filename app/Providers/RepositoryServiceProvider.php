<?php

namespace App\Providers;

use App\Repositories\BookingRepository;
use App\Repositories\EventRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\PromotionRepository;
use App\Repositories\SalesRepository;
use App\Repositories\StatsRepository;
use App\Repositories\TicketPackageRepository;
use App\Repositories\UserRepository;
use App\Repositories\VenueRepository;
use App\Services\BookingService;
use App\Services\EventService;
use App\Services\PaymentService;
use App\Services\PromotionService;
use App\Services\SalesService;
use App\Services\StatsService;
use App\Services\TicketPackageService;
use App\Services\UserService;
use App\Services\VenueService;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(EventService::class, EventRepository::class);
        $this->app->bind(VenueService::class, VenueRepository::class);
        $this->app->bind(TicketPackageService::class, TicketPackageRepository::class);
        $this->app->bind(BookingService::class, BookingRepository::class);
        $this->app->bind(StatsService::class, StatsRepository::class);
        $this->app->bind(UserService::class, UserRepository::class);
        $this->app->bind(SalesService::class, SalesRepository::class);
        $this->app->bind(PromotionService::class, PromotionRepository::class);
        $this->app->bind(PaymentService::class, PaymentRepository::class);
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
