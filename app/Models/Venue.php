<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Venue extends Model
{

    protected $table = 'venues';
    public $timestamps = true;

    use SoftDeletes, Searchable, LogsActivity;

    protected $dates = ['deleted_at'];
    protected $fillable = array('name', 'desc', 'seating_capacity', 'seat_map', 'location_url');

    public function events()
    {
        return $this->hasMany(Event::class, 'venue_id', 'id');
    }

    public function serialize()
    {

        if (Auth::user() && Auth::user()->hasRole('Admin')) {
            return $this;
        } else {
            $obj['id'] = $this->id;
            $obj['name'] = $this->name;
            $obj['desc'] = $this->desc;
            $obj['seating_capacity'] = $this->seating_capacity;
            $obj['seat_map'] = $this->seat_map;
            $obj['location_url'] = $this->location_url;
            
            return $obj;
        }
    }

    public function toSearchableArray()
    {
        return [
            'name' => $this->name,
            'desc' => $this->desc
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
        ->logOnly(['*']);
        // Chain fluent methods for configuration options
    }
}
