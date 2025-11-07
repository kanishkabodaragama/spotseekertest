<?php

namespace App\Models;

use App\Models\TicketPackage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Laravel\Scout\Searchable;
use Laravel\Scout\Attributes\SearchUsingPrefix;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Event extends Model
{

    use SoftDeletes, Searchable, LogsActivity;

    protected $table = 'events';
    public $timestamps = true;
    protected $casts = [
        'featured' => 'boolean',
        'free_seating' => 'boolean',
        'json_desc' => 'json',
        'handling_cost_perc' => 'boolean',
        'invitation_feature' => 'boolean',
        'analytics_ids' => 'array'
    ];

    protected $dates = ['deleted_at'];
    protected $fillable = array('uid', 'name', 'json_desc', 'type', 'sub_type', 'organizer', 'manager', 'venue_id', 'start_date', 'end_date', 'status', 'thumbnail_img', 'banner_img', 'event', 'featured', 'message', 'free_seating', 'handling_cost', 'handling_cost_perc', 'currency', 'invitation_feature', 'invitation_count', 'trailer_url', 'addons_feature', 'analytics_ids');

    public function venue()
    {
        return $this->hasOne(Venue::class, 'id', 'venue_id');
    }

    public function sale()
    {
        return $this->hasMany('EventSaleSummary');
    }

    public function ticket_sales()
    {
        return $this->hasMany(TicketSale::class, 'event_id', 'id');
    }

    public function ticket_packages()
    {
        return $this->hasMany(TicketPackage::class, 'event_id', 'id');
    }

    public function managerr()
    {
        return $this->hasOne(User::class, 'id', 'manager');
    }

    public function promotion()
    {
        return $this->hasOne(Promotion::class, 'event_id');
    }

    public function invitations()
    {
        return $this->hasMany(EventInvitation::class, 'event_id', 'id');
    }

    public function getPromotions()
    {
        return optional($this->promotion)->coupon_code;
    }

    public function coordinators()
    {
        return $this->belongsToMany(User::class, 'event_coordinators', 'event_id', 'user_id');
    }

    public function addons()
    {
        return $this->hasMany(EventAddon::class, 'event_id', 'id');
    }

    public function payment_gateways()
    {
        return $this->belongsToMany(PaymentGateway::class, 'event_payment_gateway', 'event_id', 'payment_gateway_id');
    }

    public function serialize()
    {

        $obj['id'] = $this->id;
        $obj['uid'] = $this->uid;
        $obj['name'] = $this->name;
        $obj['description'] = $this->json_desc;
        $obj['type'] = $this->type;
        $obj['sub_type'] = $this->sub_type;
        $obj['organizer'] = $this->organizer;
        $obj['manager'] = $this->managerr ? $this->managerr->serialize() : null;
        $obj['start_date'] = Carbon::parse(preg_replace('/\(.*$/', '', $this->start_date))->toDateTimeString();
        $obj['end_date'] = Carbon::parse(preg_replace('/\(.*$/', '', $this->end_date))->toDateTimeString();
        $obj['status'] = $this->status;
        $obj['thumbnail_img'] = $this->thumbnail_img;
        $obj['banner_img'] = $this->banner_img;
        $obj['featured'] = $this->featured;
        $obj['venue'] = $this->venue ? $this->venue->serialize() : null;
        $obj['message'] = $this->message;
        $obj['free_seating'] = $this->free_seating;
        $obj['handling_cost'] = $this->handling_cost;
        $obj['handling_cost_perc'] = $this->handling_cost_perc;
        $obj['currency'] = $this->currency;

        if ($this->getPromotions() != null) {
            $obj['promo'] = true;

            if ($this->promotion->package_id == null) {
                $obj['promo_auto_apply'] = $this->promotion->auto_apply;
                $obj['promo_code'] = $this->promotion->coupon_code;
            }
        } else {
            $obj['promo'] = false;
        }

        $pack_arr = [];

        if (Auth::check() && (Auth::user()->hasRole('Manager') || Auth::user()->hasRole('Admin'))) {
            $obj['invitation_count'] = $this->invitation_count;
            $obj['invitation_feature'] = $this->invitation_feature;
        }

        foreach ($this->ticket_packages as $pack) {
            if ($pack->private === false) {
                array_push($pack_arr, $pack->serialize());
            }
        }

        usort($pack_arr, function ($a, $b) {
            return $a['price'] <=> $b['price'];
        });

        $obj['ticket_packages'] = $pack_arr;
        $obj['addons_feature'] = $this->addons_feature;
        $obj['addons'] = $this->addons;
        $obj['trailer_url'] = $this->trailer_url;
        $obj['analytics'] = $this->analytics_ids;

        $pg_arr = [];

        foreach ($this->payment_gateways as $pg) {
            if ($pg->active === true) {
                array_push($pg_arr, $pg->serialize());
            }
        }

        $obj['payment_gateways'] = $pg_arr;

        return $obj;
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array
     */
    #[SearchUsingPrefix(['name', 'type', 'organizer', 'description'])]
    public function toSearchableArray()
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'organizer' => $this->organizer
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['*']);
        // Chain fluent methods for configuration options
    }
}
