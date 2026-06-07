<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApiKey extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $hidden = ['key_hash'];

    protected $fillable = [
        'workspace_id',
        'name',
        'prefix',
        'key_hash',
        'scopes',
        'ip_allow_list',
        'last_used_at',
        'expires_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'ip_allow_list' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function allowsScope(string $scope): bool
    {
        $scopes = $this->scopes ?: [];

        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }
}
