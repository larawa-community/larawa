<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

class MessageLogQuery
{
    public function forWorkspace(Workspace $workspace, array $filters = [])
    {
        $query = $workspace->messages()->with(['workspace', 'whatsappSession']);

        return $this->applyFilters($query, $filters);
    }

    public function all(array $filters = [])
    {
        return $this->applyFilters(Message::query()->with(['workspace', 'whatsappSession']), $filters);
    }

    private function applyFilters($query, array $filters)
    {
        return $query
            ->when($filters['direction'] ?? null, fn (Builder $query, string $direction) => $query->where('direction', $direction))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when(array_key_exists('has_media', $filters) && $filters['has_media'] !== null, function (Builder $query) use ($filters) {
                return filter_var($filters['has_media'], FILTER_VALIDATE_BOOL)
                    ? $query->whereNotNull('media_path')
                    : $query->whereNull('media_path');
            })
            ->when($filters['session'] ?? null, function (Builder $query, string $session) {
                return $query->whereHas('whatsappSession', function (Builder $query) use ($session) {
                    $query->where('uuid', $session);

                    if (ctype_digit($session)) {
                        $query->orWhere('id', (int) $session);
                    }
                });
            })
            ->when($filters['q'] ?? null, function (Builder $query, string $term) {
                $like = '%'.mb_strtolower($term).'%';
                $columns = ['wa_message_id', 'from', 'to', 'body', 'mime_type'];

                return $query->where(function (Builder $query) use ($columns, $like) {
                    foreach ($columns as $index => $column) {
                        $wrapped = $query->getQuery()->getGrammar()->wrap($column);
                        $sql = "LOWER({$wrapped}) LIKE ?";

                        if ($index === 0) {
                            $query->whereRaw($sql, [$like]);

                            continue;
                        }

                        $query->orWhereRaw($sql, [$like]);
                    }
                });
            })
            ->latest();
    }
}
