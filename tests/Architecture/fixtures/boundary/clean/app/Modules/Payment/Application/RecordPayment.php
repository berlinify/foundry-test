<?php

declare(strict_types=1);

namespace Modules\Payment\Application;

use Modules\Merchant\Contracts\MerchantLookup;
use Modules\Merchant\Contracts\MerchantSummary;
use Modules\Platform\Contracts\TenantContext;

/**
 * FIXTURE — the legal shape. Payment reaches Platform, and reaches Merchant only
 * through Merchant\Contracts. Both edges must be allowed by deptrac.
 */
final class RecordPayment
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly MerchantLookup $merchants,
    ) {}

    public function describe(int $merchantId): string
    {
        $summary = $this->merchants->summaryFor($merchantId);

        return $this->tenant->tenantId().":".$this->name($summary);
    }

    private function name(MerchantSummary $summary): string
    {
        return $summary->displayName;
    }
}
