<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Admin extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'role',
        'status'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status' => 'boolean',
    ];

    /**
     * Get the user that owns the admin record.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if the admin is a superadmin
     *
     * @return bool
     */
    public function isSuperAdmin()
    {
        return $this->role === 'superadmin';
    }

    /**
     * Check if the admin is an editor
     *
     * @return bool
     */
    public function isEditor()
    {
        return $this->role === 'editor';
    }

    /**
     * Check if the admin account is active
     *
     * @return bool
     */
    public function isActive()
    {
        return $this->status;
    }
}