<?php

declare(strict_types=1);

namespace Modules\Merchant\Infrastructure;

/**
 * FIXTURE — not application code. Stands in for an Eloquent model: internal to the
 * Merchant module and, per TAD §4.1 rule 4, never allowed to cross a boundary.
 * Deliberately does not extend Illuminate\Database\Eloquent\Model so the fixture
 * needs no framework to be parsed.
 */
final class MerchantModel
{
    public string $displayName = "";

    public static function find(int $id): self
    {
        return new self();
    }
}
