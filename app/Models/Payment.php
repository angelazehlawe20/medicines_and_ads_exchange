<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'payment_method',
        'payment_status',
        'stripe_payment_id',
        'transaction_time',
        'amount',
        'currency'
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
