<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class EventCoordinator extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'event_coordinators';
    public $timestamps = true;
    protected $dates = ['deleted_at'];
    protected $fillable = array(
        'event_id',
        'user_id',
        'manager_id'
    );

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id', 'id');
    }

    public function events()
    {
        return $this->hasMany(Event::class, 'id', 'event_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
        ->logOnly(['*']);
        // Chain fluent methods for configuration options
    }
}
