<?php

namespace App\Exceptions;

use App\Models\Showcase;
use RuntimeException;

/**
 * O tenant já tem `Showcase::MAX_SHOWCASES_PER_TENANT` vitrines ativas (VAL-004).
 *
 * Lançada por `Showcase::createWithinLimit()` dentro da transação que serializa
 * as criações do tenant: quem captura sabe que nada foi gravado.
 */
class ShowcaseLimitReachedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Limite de '.Showcase::MAX_SHOWCASES_PER_TENANT.' vitrines atingido.');
    }
}
