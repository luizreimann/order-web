<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Não existe gateway online para o método/provedor pedido (método manual, provedor
 * desconhecido ou não registrado).
 */
class UnsupportedPaymentMethodException extends RuntimeException {}
