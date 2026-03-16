<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pharmacy extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'pharmacy_name',
        'governorate'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class, 'pharmacy_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'pharmacy_id');
    }

    public function medicines()
    {
        return $this->hasMany(Medicine::class, 'pharmacy_id');
    }

    public function coupons()
    {
        return $this->hasMany(Coupon::class, 'pharmacy_id');
    }
}
