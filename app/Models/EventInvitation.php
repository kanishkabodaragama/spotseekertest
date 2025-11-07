<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class EventInvitation extends Model
{
    use Searchable, LogsActivity;

    protected $table = 'event_invitations';
    public $timestamps = true;
    protected $fillable = array(
        'event_id',
        'user_id',
        'status',
        'invitation_id',
        'tickets_count',
        'package_id'
    );


    public function event()
    {
        return $this->hasOne(Event::class, 'id', 'event_id');
    }

    public function user()
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function package()
    {
        return $this->hasOne(TicketPackage::class, 'id', 'package_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
        ->logOnly(['*']);
        // Chain fluent methods for configuration options
    }
}
