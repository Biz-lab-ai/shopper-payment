<?php

declare(strict_types=1);

namespace Shopper\Payment\Contracts;

use Shopper\Payment\Models\PaymentWebhookEvent;

interface OrphanFilter
{
    /**
     * Whether the reconciliation may handle this orphan candidate. A refused
     * candidate is skipped entirely and left unmarked.
     */
    public function accepts(PaymentWebhookEvent $event): bool;
}
