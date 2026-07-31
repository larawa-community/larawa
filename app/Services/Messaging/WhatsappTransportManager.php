<?php

namespace App\Services\Messaging;

use App\Contracts\Messaging\WhatsappTransport;
use App\Models\WhatsappSession;

class WhatsappTransportManager
{
    public function __construct(
        private WrapperWhatsappTransport $wrapper,
        private CloudApiWhatsappTransport $cloud,
    ) {}

    public function for(WhatsappSession $session): WhatsappTransport
    {
        return $session->isCloudApi() ? $this->cloud : $this->wrapper;
    }
}
