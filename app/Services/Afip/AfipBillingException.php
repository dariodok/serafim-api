<?php

namespace App\Services\Afip;

use App\Models\ComprobanteFacturacion;
use RuntimeException;

class AfipBillingException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?ComprobanteFacturacion $comprobante = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function comprobante(): ?ComprobanteFacturacion
    {
        return $this->comprobante;
    }
}
