<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
    ];

    // Relations
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(CartItem::class);
    }

    // Get total items count
    public function getTotalItemsAttribute()
    {
        return $this->items->sum('quantity');
    }

    // Get subtotal
    public function getSubtotalAttribute()
    {
        $subtotal = 0;

        foreach ($this->items as $item) {
            $subtotal += $item->total;
        }

        return $subtotal;
    }

    // Add product to cart
    public function addProduct($productId, $quantity = 1)
    {
        $item = $this->items()->where('product_id', $productId)->first();

        if ($item) {
            $item->update([
                'quantity' => $item->quantity + $quantity
            ]);
        } else {
            $product = Product::find($productId);

            if (!$product) {
                return false;
            }

            $this->items()->create([
                'product_id' => $productId,
                'quantity' => $quantity
            ]);
        }

        return true;
    }

    // Remove product from cart
    public function removeProduct($productId)
    {
        return $this->items()->where('product_id', $productId)->delete();
    }

    // Update product quantity
    public function updateQuantity($productId, $quantity)
    {
        $item = $this->items()->where('product_id', $productId)->first();

        if (!$item) {
            return false;
        }

        if ($quantity <= 0) {
            return $this->removeProduct($productId);
        }

        return $item->update(['quantity' => $quantity]);
    }

    // Clear cart
    public function clear()
    {
        return $this->items()->delete();
    }
}
