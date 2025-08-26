<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Doctor extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'specialization',
        'experience',
        'bio',
        'hospital_id',
        'profile_photo',
        'is_verified',
        'password',
    ];

    protected $hidden = ['password'];
}
