<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
class TicketSalePackage extends Model
{
    protected $table = 'ticket_sale_packages';
    public $timestamps = true;

    use SoftDeletes, LogsActivity;

    protected $dates = ['deleted_at'];
    protected $casts = [
        'seat_nos' => 'array',
    ];
    protected $fillable = array(
        'sale_id',
        'package_id',
        'ticket_count',
        'seat_nos',
        'promo_id'
    );

    public function sale() {
        return $this->belongsTo(Sale::class, 'id', 'sale_id');
    }

    public function package()
    {
        return $this->hasOne(TicketPackage::class, 'id', 'package_id');
    }

    public function promotion()
    {
        return $this->hasOne(Promotion::class, 'id', 'promo_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
        ->logOnly(['*']);
        // Chain fluent methods for configuration options
    }
}
