<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_session_id')->constrained()->cascadeOnDelete();
            $table->string('customer_wa_id', 32);
            $table->string('customer_name')->nullable();
            $table->timestamp('latest_inbound_at')->nullable()->index();
            $table->timestamp('latest_message_at')->nullable()->index();
            $table->timestamp('service_window_expires_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['whatsapp_session_id', 'customer_wa_id'], 'wa_conversations_session_customer_unique');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('conversation_id')
                ->nullable()
                ->after('transport_session_id')
                ->constrained('whatsapp_conversations')
                ->nullOnDelete();
        });

        // Preserve all message history while associating existing Cloud messages in
        // bounded batches. Only a genuine inbound provider timestamp may open the
        // customer-service window; created_at is not treated as customer activity.
        DB::table('messages')
            ->whereNull('conversation_id')
            ->whereNotNull('transport_session_id')
            ->orderBy('id')
            ->chunkById(500, function ($messages): void {
                foreach ($messages as $message) {
                    $session = DB::table('whatsapp_sessions')
                        ->where('id', $message->transport_session_id)
                        ->where('type', 'official_cloud_api')
                        ->first(['id', 'workspace_id']);
                    if (! $session) {
                        continue;
                    }

                    $customer = $message->direction === 'incoming' ? $message->from : $message->to;
                    $customer = preg_replace('/\D+/', '', (string) $customer) ?: '';
                    if ($customer === '') {
                        continue;
                    }

                    $now = now();
                    DB::table('whatsapp_conversations')->insertOrIgnore([
                        'workspace_id' => $session->workspace_id,
                        'whatsapp_session_id' => $session->id,
                        'customer_wa_id' => $customer,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $conversation = DB::table('whatsapp_conversations')
                        ->where('whatsapp_session_id', $session->id)
                        ->where('customer_wa_id', $customer)
                        ->first();

                    $updates = ['updated_at' => $now];
                    if (! $conversation->latest_message_at || Carbon::parse($message->created_at)->isAfter(Carbon::parse($conversation->latest_message_at))) {
                        $updates['latest_message_at'] = $message->created_at;
                    }

                    $payload = is_string($message->payload) ? json_decode($message->payload, true) : (array) $message->payload;
                    $providerTimestamp = data_get($payload, 'timestamp');
                    if ($message->direction === 'incoming' && is_numeric($providerTimestamp)) {
                        $inboundAt = Carbon::createFromTimestampUTC((int) $providerTimestamp);
                        if (! $conversation->latest_inbound_at || $inboundAt->isAfter(Carbon::parse($conversation->latest_inbound_at))) {
                            $updates['latest_inbound_at'] = $inboundAt;
                            $updates['service_window_expires_at'] = $inboundAt->copy()->addHours(24);
                        }
                    }

                    DB::table('whatsapp_conversations')->where('id', $conversation->id)->update($updates);
                    DB::table('messages')->where('id', $message->id)->update(['conversation_id' => $conversation->id]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conversation_id');
        });

        Schema::dropIfExists('whatsapp_conversations');
    }
};
