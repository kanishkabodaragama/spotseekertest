<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Promotion extends Model
{
    use SoftDeletes, Searchable, LogsActivity;

    protected $table = 'promotions';
    public $timestamps = true;
    protected $casts = [
        'percentage' => 'boolean',
        'per_ticket' => 'boolean',
        'auto_apply' => 'boolean'
    ];

    protected $dates = ['deleted_at'];
    protected $fillable = array(
        'event_id',
        'package_id',
        'coupon_code',
        'discount_amount',
        'percentage',
        'min_tickets',
        'min_amount',
        'max_tickets',
        'max_amount',
        'start_date',
        'end_date',
        'per_ticket',
        'auto_apply',
        'redeems'
    );

    public function ticket_package()
    {
        return $this->belongsTo(TicketPackage::class, 'package_id', 'id');
    }

    public function event()
    {
        return $this->hasOne(Event::class, 'event_id', 'id');
    }

    public function ticket_sale_packages()
    {
        return $this->hasMany(TicketSalePackage::class, 'promo_id', 'id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
        ->logOnly(['*']);
        // Chain fluent methods for configuration options
    }
}
