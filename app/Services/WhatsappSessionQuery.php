<?php

namespace App\Services;

use App\Models\WhatsappSession;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappSessionQuery
{
    public const STATUSES = [
        'created',
        'initializing',
        'qr',
        'authenticated',
        'ready',
        'disconnected',
        'auth_failure',
        'failed',
    ];

    public function forWorkspace(Workspace $workspace, array $filters = []): HasMany
    {
        $query = $workspace->whatsappSessions()->whereIn('type', $workspace->allowedSessionTypes())->latest();

        $this->applyFilters($query, $filters);

        return $query;
    }

    public function all(array $filters = []): Builder
    {
        $query = WhatsappSession::query()->allowedByWorkspace()->with('workspace')->latest();

        $this->applyFilters($query, $filters);

        return $query;
    }

    public function statusCounts(?Workspace $workspace = null): array
    {
        $query = $workspace
            ? $workspace->whatsappSessions()->whereIn('type', $workspace->allowedSessionTypes())
            : WhatsappSession::query()->allowedByWorkspace();

        return $query
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();
    }

    private function applyFilters(Builder|HasMany $query, array $filters): void
    {
        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }

        if (($filters['q'] ?? null) !== null) {
            $needle = '%'.mb_strtolower(trim($filters['q'])).'%';

            $query->where(function (Builder $query) use ($needle): void {
                foreach (['name', 'uuid', 'phone_number', 'status'] as $column) {
                    $query->orWhereRaw($this->lowerLikeExpression($query, $column), [$needle]);
                }
            });
        }
    }

    private function lowerLikeExpression(Builder $query, string $column): string
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);

        if ($column === 'uuid' && $query->getConnection()->getDriverName() === 'pgsql') {
            return 'LOWER(CAST('.$wrapped.' AS TEXT)) LIKE ?';
        }

        return 'LOWER('.$wrapped.') LIKE ?';
    }
}
