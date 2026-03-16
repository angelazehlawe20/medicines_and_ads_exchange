<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Medicine extends Model
{
    use HasFactory;

    protected $fillable = [
        'pharmacy_id',
        'name',
        'price',
        'image',
        'category',
        'quantity_available',
        'expiration_date',
        'description',
        'last_notified_at'
    ];

    protected $casts = [
        'expiration_date' => 'date',
    ];

    public function pharmacy()
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'medicine_id');
    }
}
