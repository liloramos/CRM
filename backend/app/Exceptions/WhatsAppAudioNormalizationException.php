<?php

namespace App\Exceptions;

use DomainException;

class WhatsAppAudioNormalizationException extends DomainException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
