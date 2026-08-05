<?php

namespace App\Champs\Exceptions;

use RuntimeException;

final class ChampsSearchException extends RuntimeException
{
    public static function processingFailed(): self
    {
        return new self('Não foi possível concluir a busca de leads.');
    }
}
