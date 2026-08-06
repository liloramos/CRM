<?php

namespace App\Champs\Exceptions;

use RuntimeException;

final class InstagramEnrichmentException extends RuntimeException
{
    public static function disabled(): self
    {
        return new self('O enriquecimento Meta esta desabilitado.');
    }

    public static function missingConfiguration(): self
    {
        return new self('A configuracao do Business Discovery esta incompleta.');
    }

    public static function invalidUsername(): self
    {
        return new self('O username do Instagram e invalido.');
    }

    public static function credentialsRejected(): self
    {
        return new self('A Meta rejeitou as credenciais configuradas.');
    }

    public static function forbidden(): self
    {
        return new self('A conta configurada nao tem permissao para Business Discovery.');
    }

    public static function rateLimited(): self
    {
        return new self('O limite de requisicoes da Meta foi atingido. Tente novamente mais tarde.');
    }

    public static function serverFailure(): self
    {
        return new self('A Meta esta temporariamente indisponivel.');
    }

    public static function requestFailed(): self
    {
        return new self('A consulta ao Business Discovery falhou.');
    }

    public static function connectionFailure(): self
    {
        return new self('A consulta a Meta expirou ou nao conseguiu conectar.');
    }

    public static function invalidResponse(): self
    {
        return new self('A Meta retornou uma resposta invalida.');
    }
}
