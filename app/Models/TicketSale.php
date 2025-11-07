<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class TicketSale extends Model
{

    protected $table = 'ticket_sales';
    public $timestamps = true;

    use SoftDeletes, LogsActivity;

    protected $dates = ['deleted_at'];
    protected $fillable = array(
        'user_id',
        'event_id',
        'payment_status',
        'booking_status',
        'payment_ref_no',
        'order_id',
        'tot_amount',
        'transaction_date_time',
        'comment',
        'tot_ticket_count',
        'tot_verified_ticket_count',
        'e_ticket_url',
        'verified_by',
        'verified_at',
        'payment_method'
    );

    /*protected $casts = [
        'payment_status' => PaymentStatus::class,
        'booking_status' => BookingStatus::class
    ];*/

    public function user() {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function event()
    {
        return $this->hasOne(Event::class, 'id', 'event_id');
    }

    public function packages()
    {
        return $this->hasMany(TicketSalePackage::class, 'sale_id', 'id');
    }
    
    public function sub_bookings()
    {
        return $this->hasMany(SubTicket::class, 'sale_id', 'id');
    }

    public function addons()
    {
        return $this->hasMany(TicketAddon::class, 'sale_id', 'id');
    }

    public function history()
    {
        return $this->hasMany(BookingHistory::class, 'sale_id', 'id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
        ->logOnly(['*']);
        // Chain fluent methods for configuration options
    }
}
