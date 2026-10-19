<?php

declare(strict_types=1);

namespace Shopper\Payment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The provider was asked to give a payment back. The status is the one the
 * provider answered, a refund only being final once it is refunded.
 */
final class PaymentRefundRequested
{
    use Dispatchable;

    public function __construct(
        public string $driver,
        public string $reference,
        public ?string $refundId,
        public int $amount,
        public string $status,
    ) {}
}
