<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaAppSetting extends Model
{
    public $incrementing = false;

    protected $fillable = ['id', 'verify_token'];

    protected $hidden = ['verify_token'];

    protected $casts = ['verify_token' => 'encrypted'];
}
