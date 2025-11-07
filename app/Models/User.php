<?php

namespace App\Models;

use App\Notifications\CustomResetPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Fouladgar\MobileVerification\Contracts\MustVerifyMobile as IMustVerifyMobile;
use Fouladgar\MobileVerification\Concerns\MustVerifyMobile;

class User extends Authenticatable implements IMustVerifyMobile
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use HasRoles;
    use LogsActivity;
    use MustVerifyMobile;
    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'nic',
        'password',
        'phone_no',
        'address_line_one',
        'address_line_two',
        'city',
        'state',
        'postal_code',
        'country',
        'status',
        'profile_photo_path'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public static function boot()
    {
        parent::boot();

        static::deleting(function ($user) {
            $user->orders()->delete();
            $user->invitations()->delete();
            $user->coordinated_events()->delete();
        });
    }

    /**
     * Send the password reset notification.
     *
     * @param  string  $token
     * @return void
     */
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new CustomResetPassword($token));
    }

    public function provider()
    {
        return $this->hasMany(Provider::class, 'user_id', 'id');
    }

    public function orders()
    {
        return $this->hasMany(TicketSale::class, 'user_id', 'id');
    }

    public function hasTicketCountAboveForPackage($eventId, $userId, $packageId, $threshold, $ticketsSelected)
    {
        // Load orders with their related packages for the given event
        $totalTickets = DB::table('ticket_sales')
            ->join('ticket_sale_packages', 'ticket_sales.id', '=', 'ticket_sale_packages.sale_id')
            ->join('ticket_packages', 'ticket_sale_packages.package_id', '=', 'ticket_packages.id')
            ->join('users', 'ticket_sales.user_id', '=', 'users.id')
            ->select(DB::raw('sum(ticket_sale_packages.ticket_count) as ticket_count'))
            ->where([
                ['ticket_sales.event_id', '=', $eventId],
                ['ticket_sales.booking_status', '=', 'complete'],
                ['ticket_sale_packages.package_id', '=', $packageId],
                ['ticket_sales.user_id', '=', $userId]
            ])
            ->whereIn('ticket_sales.booking_status', ['verified', 'complete'])
            ->pluck('ticket_count')[0];

            Log::info($threshold .",". $totalTickets .",".$ticketsSelected);
        // Check if the total tickets for the specific package exceed the threshold
        return $threshold < ($totalTickets + $ticketsSelected);
    }


    public function events()
    {
        return $this->hasMany(Event::class, 'manager', 'id');
    }

    public function invitations()
    {
        return $this->hasMany(EventInvitation::class, 'user_id', 'id');
    }

    public function coordinated_events()
    {
        return $this->belongsToMany(Event::class, 'event_coordinators', 'user_id', 'event_id');
    }


    public function serialize()
    {

        if (Auth::user() && Auth::user()->hasRole('Admin')) {

            return $this;
        } else {
            $obj['name'] = $this->name;

            return $obj;
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['*']);
        // Chain fluent methods for configuration options
    }
}
