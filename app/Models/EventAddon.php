<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventAddon extends Model
{
    use HasFactory;

    protected $table = 'event_addons';
    public $timestamps = true;

    protected $fillable = array('event_id', 'name', 'image_url', 'category', 'price');

    public function event()
    {
        return $this->hasOne(Event::class, 'id', 'event_id');
    }
}
