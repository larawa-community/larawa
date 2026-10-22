<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaAppSetting extends Model
{
    public $incrementing = false;

    protected $fillable = ['id', 'verify_token', 'app_id', 'app_secret', 'credentials_initialized', 'provider_access_token'];

    protected $hidden = ['verify_token', 'app_secret', 'provider_access_token'];

    protected $casts = ['provider_access_token' => 'encrypted', 'verify_token' => 'encrypted', 'app_secret' => 'encrypted', 'credentials_initialized' => 'boolean'];
}
