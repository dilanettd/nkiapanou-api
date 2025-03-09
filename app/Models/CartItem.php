<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'product_id',
        'quantity',
    ];

    // Relations
    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Get price for this item
    public function getPriceAttribute()
    {
        return $this->product->current_price;
    }

    // Get total for this item
    public function getTotalAttribute()
    {
        return $this->price * $this->quantity;
    }
}
