<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class BookingHistory extends Model
{
    use LogsActivity;

    protected $table = 'booking_history';
    public $timestamps = true;
    protected $fillable = array(
        'sale_id',
        'data'
    );

    protected $casts = [
        'data' => 'json'
    ];

    public function sale() {
        return $this->hasOne(TicketSale::class, 'id', 'sale_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
        ->logOnly(['*']);
        // Chain fluent methods for configuration options
    }
}
