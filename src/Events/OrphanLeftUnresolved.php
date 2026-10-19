<?php

declare(strict_types=1);

namespace Shopper\Payment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An accepted orphan candidate the reconciliation could not settle. The
 * reason is one of partial_refund, unrecognized, deferred, not_resolved or
 * handler_failed.
 */
final class OrphanLeftUnresolved
{
    use Dispatchable;

    public const string PARTIAL_REFUND = 'partial_refund';

    public const string UNRECOGNIZED = 'unrecognized';

    public const string DEFERRED = 'deferred';

    public const string NOT_RESOLVED = 'not_resolved';

    public const string HANDLER_FAILED = 'handler_failed';

    public function __construct(
        public string $driver,
        public string $reference,
        public ?int $amount,
        public int $refunded,
        public string $reason,
    ) {}
}
