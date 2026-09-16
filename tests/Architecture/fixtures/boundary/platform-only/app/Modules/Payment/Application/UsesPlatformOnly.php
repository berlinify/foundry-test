<?php

declare(strict_types=1);

namespace Modules\Payment\Application;

use Modules\Platform\Contracts\TenantContext;

/**
 * FIXTURE — not application code. Proves the positive case of TAD §4.1 rule 5:
 * every module may depend on Platform, and that dependency must never be reported
 * as a violation.
 */
final class UsesPlatformOnly
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function currentTenant(): int
    {
        return $this->tenant->tenantId();
    }
}
