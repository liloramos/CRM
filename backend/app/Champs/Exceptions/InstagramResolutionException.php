<?php

namespace App\Champs\Exceptions;

use RuntimeException;

final class InstagramResolutionException extends RuntimeException
{
    public static function invalidUrl(): self
    {
        return new self('A URL do website deve ser publica e usar HTTP ou HTTPS.');
    }

    public static function unsafeDestination(): self
    {
        return new self('O website aponta para um destino de rede nao permitido.');
    }

    public static function hostResolutionFailed(): self
    {
        return new self('Nao foi possivel validar o endereco publico do website.');
    }

    public static function requestFailed(): self
    {
        return new self('Nao foi possivel consultar o website da empresa.');
    }

    public static function invalidHtml(): self
    {
        return new self('O website nao retornou um documento HTML valido.');
    }

    public static function responseTooLarge(): self
    {
        return new self('A pagina do website excede o limite de tamanho permitido.');
    }

    public static function tooManyRedirects(): self
    {
        return new self('O website excedeu o limite seguro de redirecionamentos.');
    }

    public static function requestLimitReached(): self
    {
        return new self('O website excedeu o limite seguro de requisicoes.');
    }
}
