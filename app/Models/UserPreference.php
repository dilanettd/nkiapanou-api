<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'newsletter_subscription',
        'preferred_categories',
        'preferred_payment_method',
        'language',
    ];

    protected $casts = [
        'newsletter_subscription' => 'boolean',
        'preferred_categories' => 'array',
    ];

    // Relations
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Get preferred categories as Category models
    public function getPreferredCategoryModelsAttribute()
    {
        if (!$this->preferred_categories) {
            return collect();
        }

        return Category::whereIn('id', $this->preferred_categories)->get();
    }
}
