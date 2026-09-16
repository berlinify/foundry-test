<?php

declare(strict_types=1);

namespace Modules\Platform\Contracts;

/**
 * FIXTURE — not application code. Stands in for the shared platform service every
 * module is allowed to depend on (TAD §4.1 rule 5).
 */
interface TenantContext
{
    public function tenantId(): int;
}
