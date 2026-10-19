<?php

declare(strict_types=1);

namespace Shopper\Payment\Contracts;

interface CompensationStore
{
    /**
     * Record the decision to give the payment back, before the provider is
     * called: false when the decision was already recorded, in which case
     * the caller repeats the same provider call with the same idempotency
     * key.
     */
    public function begin(string $driver, string $reference, int $amount, string $cause): bool;

    /**
     * Whether a decision to give the payment back was recorded: such a
     * payment never becomes an order.
     */
    public function compensated(string $reference): bool;
}
