<?php

declare(strict_types=1);

namespace Modules\Payment\Application;

use Modules\Merchant\Infrastructure\MerchantModel;
use Modules\Platform\Contracts\TenantContext;

/**
 * FIXTURE — THE SYNTHETIC VIOLATION.
 *
 * Payment reaches straight into Merchant\Infrastructure instead of going through
 * Merchant\Contracts. This is the exact merge request the boundary check exists to
 * block, and BoundaryFixtureTest asserts that deptrac fails on it.
 */
final class RecordPaymentDirectly
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function describe(int $merchantId): string
    {
        return $this->tenant->tenantId().":".MerchantModel::find($merchantId)->displayName;
    }
}
