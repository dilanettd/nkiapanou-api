<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone_number',
        'address',
        'city',
        'postal_code',
        'country',
        'profile_image',
        'social_id',
        'social_type',
        'is_social',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_social' => 'boolean',
    ];

    // Relations
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function reviews()
    {
        return $this->hasMany(ProductReview::class);
    }

    public function cart()
    {
        return $this->hasOne(Cart::class);
    }

    public function testimonials()
    {
        return $this->hasMany(Testimonial::class);
    }

    public function wishlist()
    {
        return $this->hasMany(Wishlist::class);
    }

    public function preferences()
    {
        return $this->hasOne(UserPreference::class);
    }

    public function addresses()
    {
        return $this->hasMany(UserAddress::class);
    }

    /**
     * Get the admin record associated with the user.
     */
    public function admin()
    {
        return $this->hasOne(Admin::class);
    }

    /**
     * Check if the user is an admin
     *
     * @return bool
     */
    public function isAdmin()
    {
        return $this->admin()->exists();
    }

    /**
     * Check if the user is a superadmin
     *
     * @return bool
     */
    public function isSuperAdmin()
    {
        return $this->admin && $this->admin->role === 'superadmin';
    }

    /**
     * Check if the user is an active admin
     *
     * @return bool
     */
    public function isActiveAdmin()
    {
        return $this->admin && $this->admin->status;
    }

}