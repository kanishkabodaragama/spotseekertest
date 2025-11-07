<?php

namespace App\Models;

use App\Enums\SubscriptionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory;

    protected $table = 'subscriptions';
    public $timestamps = true;

    protected $fillable = array('user_id', 'mobile_no', 'event_id', 'type');

    protected $casts = [
        'type' => SubscriptionType::class
    ];
}
