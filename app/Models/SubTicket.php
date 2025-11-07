<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class SubTicket extends Model
{

    protected $table = 'sub_tickets';
    public $timestamps = true;

    use SoftDeletes, LogsActivity;

    protected $dates = ['deleted_at', 'verified_at'];
    protected $fillable = array(
        'sale_id',
        'sub_order_id',
        'e_ticket_url',
        'package_id',
        'booking_status',
        'verified_by',
        'verified_at'
    );

    public function ticket_sale()
    {
        return $this->hasOne(TicketSale::class, 'id', 'sale_id');
    }

    public function sale_package()
    {
        return $this->hasOne(TicketSalePackage::class, 'id', 'package_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
        ->logOnly(['*']);
        // Chain fluent methods for configuration options
    }
}
