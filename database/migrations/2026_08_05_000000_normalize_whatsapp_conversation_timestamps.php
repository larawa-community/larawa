<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $timezone = config('app.timezone', 'UTC');

        DB::table('whatsapp_conversations')
            ->orderBy('id')
            ->chunkById(200, function ($conversations) use ($timezone): void {
                foreach ($conversations as $conversation) {
                    $latestMessageAt = null;
                    $latestInboundAt = null;

                    $messages = DB::table('messages')
                        ->where('conversation_id', $conversation->id)
                        ->orderBy('id')
                        ->get(['direction', 'created_at', 'payload']);

                    foreach ($messages as $message) {
                        $messageAt = $message->created_at
                            ? Carbon::parse($message->created_at, $timezone)
                            : null;

                        if ($message->direction === 'incoming') {
                            $payload = is_string($message->payload)
                                ? json_decode($message->payload, true)
                                : (array) $message->payload;
                            $providerTimestamp = data_get($payload, 'timestamp');

                            if (is_numeric($providerTimestamp)) {
                                $messageAt = Carbon::createFromTimestampUTC((int) $providerTimestamp)
                                    ->setTimezone($timezone);
                                if (! $latestInboundAt || $messageAt->isAfter($latestInboundAt)) {
                                    $latestInboundAt = $messageAt->copy();
                                }
                            }
                        }

                        if ($messageAt && (! $latestMessageAt || $messageAt->isAfter($latestMessageAt))) {
                            $latestMessageAt = $messageAt->copy();
                        }
                    }

                    $updates = [];
                    if ($latestMessageAt) {
                        $updates['latest_message_at'] = $latestMessageAt->format('Y-m-d H:i:s');
                    }
                    if ($latestInboundAt) {
                        $updates['latest_inbound_at'] = $latestInboundAt->format('Y-m-d H:i:s');
                        $updates['service_window_expires_at'] = $latestInboundAt->copy()->addHours(24)->format('Y-m-d H:i:s');
                    }

                    if ($updates !== []) {
                        DB::table('whatsapp_conversations')->where('id', $conversation->id)->update($updates);
                    }
                }
            });
    }

    public function down(): void
    {
        // Corrected timezone data cannot be safely converted back to mixed values.
    }
};
