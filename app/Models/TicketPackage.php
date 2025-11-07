<?php

namespace App\Models;

use App\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
class TicketPackage extends Model 
{
    use SoftDeletes, Searchable, LogsActivity;

    protected $table = 'ticket_packages';
    public $timestamps = true;

    protected $dates = ['deleted_at'];
    protected $casts = [
        'seating_range' => 'array',
        'reserved_seats' => 'array',
        'available_seats' => 'array',
        'free_seating' => 'boolean',
        'active' => 'boolean',
        'sold_out' => 'boolean',
        'private' => 'boolean'
    ];
    protected $fillable = array('event_id', 'name', 'price', 'seating_range', 'desc', 'tot_tickets', 'aval_tickets', 'res_tickets', 'reserved_seats', 'available_seats', 'free_seating', 'active', 'sold_out', 'private', 'max_tickets_can_buy');

    public function ticket_sales()
    {
        return $this->hasMany(TicketSalePackage::class, 'package_id', 'id');
    }

    public function events()
    {
        return $this->belongsTo(Event::class, 'event_id', 'id');
    }

    public function promotions()
    {
        return $this->hasMany(Promotion::class, 'package_id', 'id');
    }

    public function serialize()
    {
        $obj['id'] = $this->id;
        $obj['name'] = $this->name;
        $obj['price'] = $this->price;
        $obj['desc'] = $this->desc;
        $obj['sold_out'] = $this->aval_tickets == 0 || $this->sold_out;
        $obj['active'] = $this->active;
        $obj['seating_range'] = $this->seating_range;
        $obj['reserved_seats'] = $this->reserved_seats;
        $obj['available_seats'] = $this->available_seats;
        $obj['free_seating'] = $this->free_seating;
        $obj['promo'] = sizeof($this->promotions) > 0 ? true : false;
        $obj['max_tickets_can_buy'] = $this->max_tickets_can_buy;
        
        foreach($this->promotions as $promo) {
            $obj['promo_auto_apply'] = $promo->auto_apply;

            if($promo->auto_apply) {
                $obj['promo_code'] = $promo->coupon_code;
            }
        }

        return $obj;
    }

    public function toSearchableArray()
    {
        return [
            'name' => $this->name,
            'price' => $this->price
        ];

    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
        ->logOnly(['*']);
        // Chain fluent methods for configuration options
    }
}