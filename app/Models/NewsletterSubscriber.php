<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NewsletterSubscriber extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'name',
        'status',
    ];

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeUnsubscribed($query)
    {
        return $query->where('status', 'unsubscribed');
    }

    // Subscribe
    public function subscribe()
    {
        $this->status = 'active';
        $this->save();

        return $this;
    }

    // Unsubscribe
    public function unsubscribe()
    {
        $this->status = 'unsubscribed';
        $this->save();

        return $this;
    }
}
