<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'discount_price',
        'quantity',
        'category_id',
        'status',
        'featured',
        'weight',
        'dimensions',
        'origin_country',
        'sku',
        'packaging',
        'low_stock_threshold',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'discount_price' => 'decimal:2',
        'featured' => 'boolean',
    ];

    // Generate slug for product
    public function setNameAttribute($value)
    {
        $this->attributes['name'] = $value;
        $this->attributes['slug'] = \Str::slug($value);
    }

    // Relations
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    public function reviews()
    {
        return $this->hasMany(ProductReview::class);
    }

    public function inventoryMovements()
    {
        return $this->hasMany(InventoryMovement::class);
    }

    // Get primary image
    public function getPrimaryImageAttribute()
    {
        return $this->images()->where('is_primary', true)->first() ?? $this->images()->first();
    }

    // Check if product is in stock
    public function getInStockAttribute()
    {
        return $this->quantity > 0;
    }

    // Check if product is low in stock
    public function getLowStockAttribute()
    {
        return $this->quantity > 0 && $this->quantity <= $this->low_stock_threshold;
    }

    // Get current price (with discount if available)
    public function getCurrentPriceAttribute()
    {
        return $this->discount_price ?? $this->price;
    }

    // Get discount percentage
    public function getDiscountPercentageAttribute()
    {
        if (!$this->discount_price) {
            return 0;
        }

        return round((($this->price - $this->discount_price) / $this->price) * 100);
    }
}