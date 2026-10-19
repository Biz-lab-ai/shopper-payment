<?php

declare(strict_types=1);

namespace Shopper\Payment\Tests\Stubs;

use Shopper\Payment\Contracts\OrphanFilter;
use Shopper\Payment\Models\PaymentWebhookEvent;

final class AccountOrphanFilter implements OrphanFilter
{
    public function accepts(PaymentWebhookEvent $event): bool
    {
        return ($event->toWebhookResult()->data['account'] ?? null) === 'acct_owner';
    }
}
