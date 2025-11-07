<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketAddon extends Model
{
    use HasFactory;

    protected $table = 'ticket_addons';
    public $timestamps = false;

    protected $fillable = array('sale_id', 'addon_id', 'quantity');

    public function ticket()
    {
        return $this->hasOne(TicketSale::class, 'id', 'sale_id');
    }

    public function addon()
    {
        return $this->hasOne(EventAddon::class, 'id', 'addon_id');
    }
}
