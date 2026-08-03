<?php

namespace App\Services\Messaging;

use App\Models\WhatsappConversation;
use App\Models\WhatsappSession;
use Illuminate\Validation\ValidationException;

class CloudServiceWindowGuard
{
    public function ensureCanSend(WhatsappSession $session, string $customerWaId): WhatsappConversation
    {
        $conversation = WhatsappConversation::query()
            ->where('whatsapp_session_id', $session->id)
            ->where('customer_wa_id', $customerWaId)
            ->first();

        if ($conversation?->serviceWindowIsOpen()) {
            return $conversation;
        }

        throw ValidationException::withMessages([
            'code' => 'customer_service_window_closed',
            'service_window_expires_at' => $conversation?->service_window_expires_at?->toISOString() ?? 'unknown',
            'message' => 'The customer service window is closed. Send an approved template message to reopen the conversation.',
        ]);
    }
}
