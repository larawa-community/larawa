<?php

namespace App\Services;

use App\Models\WebhookDelivery;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookDeliveryQuery
{
    public const STATUSES = [
        'pending',
        'delivered',
        'failed',
        'exhausted',
        'skipped',
    ];

    public function forWorkspace(Workspace $workspace, array $filters = []): HasMany
    {
        $query = $workspace->webhookDeliveries()
            ->with('webhook:id,name,url')
            ->latest();

        $this->applyFilters($query, $filters);

        return $query;
    }

    public function all(array $filters = []): Builder
    {
        $query = WebhookDelivery::query()
            ->with('webhook:id,name,url')
            ->latest();

        $this->applyFilters($query, $filters);

        return $query;
    }

    public function statusCounts(?Workspace $workspace = null): array
    {
        $query = $workspace?->webhookDeliveries() ?: WebhookDelivery::query();

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

        if (($filters['event'] ?? null) !== null) {
            $query->where('event', $filters['event']);
        }

        if (($filters['webhook_id'] ?? null) !== null) {
            $query->where('webhook_id', $filters['webhook_id']);
        }

        if (($filters['q'] ?? null) !== null) {
            $needle = '%'.mb_strtolower(trim($filters['q'])).'%';

            $query->where(function (Builder $query) use ($needle): void {
                foreach (['event', 'status', 'response_body'] as $column) {
                    $wrapped = $query->getQuery()->getGrammar()->wrap($column);
                    $query->orWhereRaw('LOWER('.$wrapped.') LIKE ?', [$needle]);
                }

                $query->orWhereHas('webhook', function (Builder $query) use ($needle): void {
                    foreach (['name', 'url'] as $column) {
                        $wrapped = $query->getQuery()->getGrammar()->wrap($column);
                        $query->orWhereRaw('LOWER('.$wrapped.') LIKE ?', [$needle]);
                    }
                });
            });
        }
    }

    public function filterableEvents(): array
    {
        return array_values(array_filter(
            config('larawa.webhook_events'),
            fn (string $event): bool => $event !== '*'
        ));
    }
}
