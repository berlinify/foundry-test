<?php

declare(strict_types=1);

namespace Modules\Merchant\Contracts;

/**
 * FIXTURE — not application code. The DTO that crosses the boundary in place of an
 * Eloquent model (TAD §4.1 rule 4).
 */
final readonly class MerchantSummary
{
    public function __construct(
        public int $merchantId,
        public string $displayName,
    ) {}
}
