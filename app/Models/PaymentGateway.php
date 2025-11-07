<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaymentGateway extends Model
{
    use HasFactory, HasUuids, SoftDeletes, LogsActivity;

    protected $table = 'payment_gateways';
    public $timestamps = true;

    protected $casts = [
        'apply_handling_fee' => 'boolean',
        'active' => 'boolean'
    ];

    protected $dates = ['deleted_at'];
    protected $fillable = array('id', 'name', 'logo', 'commission_rate', 'apply_handling_fee', 'active');

    public function events()
    {
        return $this->belongsToMany(PaymentGateway::class, 'event_payment_gateway');
    }

    public function serialize()
    {
        $obj = [
            'id' => $this->id,
            'name' => $this->name,
            'logo' => $this->logo,
            'commission_rate' => $this->commission_rate,
            'apply_handling_fee' => $this->apply_handling_fee,
            'active' => $this->active
        ];

        return $obj;

    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['*']);
    }
}
