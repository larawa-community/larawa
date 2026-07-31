<?php

use App\Models\Message;
use App\Models\WhatsappSession;
use App\Services\ConfigurationDiagnostics;
use App\Services\WhatsappSessionSync;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('larawa:health', function () {
    DB::connection()->getPdo();
    $this->info('LaraWA is healthy.');

    return self::SUCCESS;
})->purpose('Check LaraWA application and database readiness');

Artisan::command('larawa:doctor {--strict : Return a failing exit code when warnings are present}', function (ConfigurationDiagnostics $diagnostics) {
    $summary = $diagnostics->summary();

    $this->table(
        ['Status', 'Check', 'Value', 'Message'],
        collect($summary['checks'])->map(fn (array $check) => [
            strtoupper($check['status']),
            $check['label'],
            $check['value'],
            $check['message'],
        ])->all()
    );

    $critical = $summary['critical'];
    $warnings = $summary['warnings'];

    if ($critical > 0) {
        $this->error("LaraWA diagnostics found {$critical} critical issue(s) and {$warnings} warning(s).");

        return self::FAILURE;
    }

    if ($this->option('strict') && $warnings > 0) {
        $this->warn("LaraWA diagnostics found {$warnings} warning(s).");

        return self::FAILURE;
    }

    $this->info("LaraWA diagnostics passed with {$warnings} warning(s).");

    return self::SUCCESS;
})->purpose('Inspect production-readiness configuration for LaraWA');

Artisan::command('larawa:sessions:sync {--limit= : Maximum number of sessions to sync}', function (WhatsappSessionSync $sync) {
    $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
    $query = WhatsappSession::query()->orderBy('id');

    if ($limit !== null) {
        $query->limit($limit);
    }

    $synced = 0;
    $missed = 0;

    foreach ($query->cursor() as $session) {
        if ($sync->sync($session) === null) {
            $missed++;

            continue;
        }

        $synced++;
    }

    $this->info("Synced {$synced} WhatsApp session(s); {$missed} unavailable.");

    return self::SUCCESS;
})->purpose('Sync stored WhatsApp session state from its configured provider');

Artisan::command('larawa:messages:reconcile-acks {--dry-run : Count affected messages without updating them}', function () {
    $statuses = ['queued', 'pending', 'ack', 'sent'];
    $query = Message::query()
        ->where('direction', 'outgoing')
        ->whereIn('status', $statuses)
        ->whereNotNull('payload')
        ->orderBy('id');

    $matched = 0;
    $updated = 0;

    foreach ($query->cursor() as $message) {
        if (($message->payload['worker_status']['status'] ?? null) !== 'error') {
            continue;
        }

        $matched++;

        if ($this->option('dry-run')) {
            continue;
        }

        $message->update(['status' => 'error']);
        $updated++;
    }

    if ($this->option('dry-run')) {
        $this->info("Found {$matched} outgoing message(s) with failed WhatsApp ack state.");
    } else {
        $this->info("Reconciled {$updated} outgoing message(s) to error status.");
    }

    return self::SUCCESS;
})->purpose('Mark outgoing messages as error when stored WhatsApp ack state failed');
