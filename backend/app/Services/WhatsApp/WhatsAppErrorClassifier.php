<?php

namespace App\Services\WhatsApp;

use Throwable;

class WhatsAppErrorClassifier
{
    public const TOKEN_INVALID = 'whatsapp_token_invalid';

    public const TOKEN_EXPIRED = 'whatsapp_token_expired';

    public const PHONE_NUMBER_MISMATCH = 'whatsapp_phone_number_mismatch';

    public const RECIPIENT_NOT_ALLOWED = 'whatsapp_recipient_not_allowed';

    public const TEMPLATE_REQUIRED = 'whatsapp_template_required';

    public const CUSTOMER_WINDOW_CLOSED = 'whatsapp_customer_window_closed';

    public const NETWORK_FAILURE = 'whatsapp_network_failure';

    public const PROVIDER_REJECTED = 'whatsapp_provider_rejected';

    public const CONFIGURATION_MISSING = 'whatsapp_configuration_missing';

    /**
     * @return array{code: string, message: string, safe_details: array<string, mixed>}
     */
    public function configurationMissing(): array
    {
        return [
            'code' => self::CONFIGURATION_MISSING,
            'message' => 'A configuração do WhatsApp está incompleta. Revise as credenciais do provider.',
            'safe_details' => [],
        ];
    }

    /**
     * @return array{code: string, message: string, safe_details: array<string, mixed>}
     */
    public function networkFailure(Throwable $exception): array
    {
        $reason = $this->networkReason($exception);
        preg_match('/curl error\s+(\d+)/i', $exception->getMessage(), $matches);

        return [
            'code' => self::NETWORK_FAILURE,
            'message' => match ($reason) {
                'dns' => 'Não foi possível localizar o servidor da Meta. Verifique DNS e acesso à internet do backend.',
                'tls' => 'A conexão segura com a Meta falhou. Verifique os certificados TLS do servidor.',
                'timeout' => 'A Meta não respondeu dentro do tempo esperado. Tente novamente em instantes.',
                default => 'Não foi possível conectar o backend à Meta WhatsApp. Verifique a rede do servidor.',
            },
            'safe_details' => [
                'network_error_reason' => $reason,
                'network_error_code' => $matches[1] ?? null,
                'network_exception' => class_basename($exception),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $error
     * @return array{code: string, message: string, safe_details: array<string, mixed>}
     */
    public function providerRejection(int $httpStatus, array $error): array
    {
        $metaCode = isset($error['code']) ? (int) $error['code'] : null;
        $metaSubcode = isset($error['error_subcode']) ? (int) $error['error_subcode'] : null;
        $code = $this->providerCode($httpStatus, $metaCode, $metaSubcode);

        return [
            'code' => $code,
            'message' => $this->messageFor($code),
            'safe_details' => [
                'http_status' => $httpStatus,
                'meta_error_code' => $metaCode !== null ? (string) $metaCode : null,
                'meta_error_subcode' => $metaSubcode !== null ? (string) $metaSubcode : null,
                'meta_error_type' => isset($error['type']) ? (string) $error['type'] : null,
            ],
        ];
    }

    public function messageFor(string $code): string
    {
        return match ($code) {
            self::TOKEN_INVALID => 'O token da Meta é inválido. Atualize a credencial antes de tentar novamente.',
            self::TOKEN_EXPIRED => 'O token da Meta expirou. Gere uma nova credencial antes de tentar novamente.',
            self::PHONE_NUMBER_MISMATCH => 'O identificador do número de teste não corresponde à configuração da Meta.',
            self::RECIPIENT_NOT_ALLOWED => 'O número destinatário não está autorizado no ambiente de teste da Meta.',
            self::TEMPLATE_REQUIRED => 'A Meta exige um template aprovado para iniciar esta conversa.',
            self::CUSTOMER_WINDOW_CLOSED => 'A janela de atendimento do WhatsApp encerrou. Use um template aprovado para retomar.',
            self::NETWORK_FAILURE => 'Não foi possível conectar o backend à Meta WhatsApp. Verifique a rede do servidor.',
            self::CONFIGURATION_MISSING => 'A configuração do WhatsApp está incompleta. Revise as credenciais do provider.',
            default => 'A Meta recusou o envio. Consulte o diagnóstico seguro para identificar o motivo.',
        };
    }

    private function providerCode(int $httpStatus, ?int $metaCode, ?int $metaSubcode): string
    {
        if ($metaCode === 190) {
            return in_array($metaSubcode, [460, 463, 467], true)
                ? self::TOKEN_EXPIRED
                : self::TOKEN_INVALID;
        }

        if (in_array($httpStatus, [401, 403], true)) {
            return self::TOKEN_INVALID;
        }

        if ($metaCode === 100 && $metaSubcode === 33) {
            return self::PHONE_NUMBER_MISMATCH;
        }

        return match ($metaCode) {
            131030 => self::RECIPIENT_NOT_ALLOWED,
            131047 => self::CUSTOMER_WINDOW_CLOSED,
            132000, 132001, 132012 => self::TEMPLATE_REQUIRED,
            default => self::PROVIDER_REJECTED,
        };
    }

    private function networkReason(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'resolve host') || str_contains($message, 'getaddrinfo') || str_contains($message, 'dns')) {
            return 'dns';
        }

        if (str_contains($message, 'certificate') || str_contains($message, 'ssl') || str_contains($message, 'tls')) {
            return 'tls';
        }

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return 'timeout';
        }

        return 'connection';
    }
}
