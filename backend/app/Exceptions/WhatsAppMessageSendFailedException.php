<?php

namespace App\Exceptions;

use DomainException;

class WhatsAppMessageSendFailedException extends DomainException
{
    public function __construct(
        string $message,
        public readonly int $conversationId,
        public readonly ?int $messageId = null,
        public readonly string $errorCode = 'whatsapp_provider_rejected',
    ) {
        parent::__construct($message);
    }
}
