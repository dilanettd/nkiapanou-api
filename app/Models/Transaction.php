<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'payment_method',
        'payment_id',
        'amount',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    // Relations
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    // Scopes
    public function scopeSuccessful($query)
    {
        return $query->whereIn('status', ['succeeded', 'completed', 'paid']);
    }

    public function scopeFailed($query)
    {
        return $query->whereIn('status', ['failed', 'declined', 'cancelled']);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    // Check if transaction was successful
    public function isSuccessful()
    {
        return in_array($this->status, ['succeeded', 'completed', 'paid']);
    }
}
