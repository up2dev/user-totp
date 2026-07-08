<?php

namespace Up2Dev\UserTotp\Models;

use Illuminate\Database\Eloquent\Model;

class TwoFactorMethod extends Model
{
    protected $table = 'user_two_factor_methods';

    protected $fillable = [
        'user_id',
        'method',
        'secret',
        'enabled',
        'confirmed_at',
    ];

    protected $hidden = [
        'secret',
    ];

    protected $casts = [
        'secret'       => 'encrypted',
        'enabled'      => 'boolean',
        'confirmed_at' => 'datetime',
    ];
}
