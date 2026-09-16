<?php

declare(strict_types=1);

namespace Modules\Merchant\Contracts;

/**
 * FIXTURE — not application code. A module's public API: interfaces and DTOs only.
 */
interface MerchantLookup
{
    public function summaryFor(int $merchantId): MerchantSummary;
}
