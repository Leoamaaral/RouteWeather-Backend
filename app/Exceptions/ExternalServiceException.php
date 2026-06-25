<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Erro estruturado para falhas em integrações externas (Google, Tomorrow.io).
 *
 * Carrega um código de erro estável (para o cliente tratar), o status HTTP que
 * deve ser devolvido e detalhes opcionais que ajudam no diagnóstico.
 */
final class ExternalServiceException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 502,
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function routeNotFound(string $message, array $details = []): self
    {
        return new self('ROUTE_NOT_FOUND', $message, 404, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function directionsFailed(string $message, array $details = []): self
    {
        return new self('GOOGLE_DIRECTIONS_FAILED', $message, 502, $details);
    }
}
