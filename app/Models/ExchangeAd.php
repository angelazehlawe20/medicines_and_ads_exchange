<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExchangeAd extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'specialist_id',
        'governorate',
        'medicine_name',
        'image',
        'price',
        'ad_type',
        'security_check_status',
        'is_showing',
        'notes'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function specialist()
    {
        return $this->belongsTo(Specialist::class);
    }

    public function notifications()
    {
        return $this->morphMany(Notification::class, 'related');
    }
}
