<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'address_type',
        'is_default',
        'recipient_name',
        'address_line1',
        'address_line2',
        'city',
        'state_province',
        'postal_code',
        'country',
        'phone_number',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    // Relations
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopeShipping($query)
    {
        return $query->where('address_type', 'shipping');
    }

    public function scopeBilling($query)
    {
        return $query->where('address_type', 'billing');
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    // Set as default address
    public function setAsDefault()
    {
        // Remove default flag from other addresses of same type
        UserAddress::where('user_id', $this->user_id)
            ->where('address_type', $this->address_type)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->is_default = true;
        $this->save();

        return $this;
    }

    // Get formatted address
    public function getFormattedAddressAttribute()
    {
        $parts = [
            $this->address_line1,
            $this->address_line2,
            $this->city,
            $this->state_province,
            $this->postal_code,
            $this->country,
        ];

        return implode(', ', array_filter($parts));
    }
}