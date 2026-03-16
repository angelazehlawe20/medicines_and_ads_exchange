<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Specialist extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'pharmacy_name',
        'pharmacy_address',
        'governorate'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function exchangeAds()
    {
        return $this->hasMany(ExchangeAd::class, 'specialist_id');
    }
}
