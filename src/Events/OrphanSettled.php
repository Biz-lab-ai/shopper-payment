<?php

declare(strict_types=1);

namespace Shopper\Payment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An orphan payment that ended: its order was found, or its refund was
 * accepted. The outcome is order or refunded.
 */
final class OrphanSettled
{
    use Dispatchable;

    public const string ORDER = 'order';

    public const string REFUNDED = 'refunded';

    public function __construct(
        public string $driver,
        public string $reference,
        public string $outcome,
    ) {}
}
