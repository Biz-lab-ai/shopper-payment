<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Shopper\Core\Events\Payments\PaymentOrphaned;
use Shopper\Core\Models\Order;
use Shopper\Payment\DataTransferObjects\WebhookResult;
use Shopper\Payment\Enum\WebhookAction;
use Shopper\Payment\Events\OrphanLeftUnresolved;
use Shopper\Payment\Events\OrphanSettled;
use Shopper\Payment\Exceptions\PaymentException;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Models\PaymentTransaction;
use Shopper\Payment\Models\PaymentWebhookEvent;
use Shopper\Payment\Tests\Stubs\AccountOrphanFilter;
use Shopper\Payment\Tests\Stubs\FakePaymentDriver;

beforeEach(function (): void {
    $driver = $this->driver = new FakePaymentDriver;
    Payment::extend('fake', fn (): FakePaymentDriver => $driver);
    config([
        'shopper.payment.reconciliation.orphan_filter' => AccountOrphanFilter::class,
        'shopper.payment.reconciliation.orphans' => 'refund',
    ]);
    Exceptions::fake();
    Event::fake([OrphanLeftUnresolved::class, OrphanSettled::class]);
});

/**
 * One webhook of the payment, journaled an hour ago so the reconciliation
 * looks at it.
 */
function journalPayment(WebhookAction $action, string $reference, int $amount, string $account, string $eventId): void
{
    $event = PaymentWebhookEvent::journal('fake', new WebhookResult(
        action: $action,
        reference: $reference,
        amount: $amount,
        data: ['cart_id' => 'cart_1', 'account' => $account],
        eventId: $eventId,
    ));

    $event?->forceFill(['created_at' => now()->subHour()])->save();
}

function reconcile(): void
{
    test()->artisan('shopper:payments:reconcile')->assertSuccessful();
}

function orphanedAt(string $reference): bool
{
    return PaymentWebhookEvent::query()->forReference($reference)->whereNotNull('orphaned_at')->exists();
}

function assertUnresolved(string $reason, int $refunded = 0): void
{
    Event::assertDispatched(
        OrphanLeftUnresolved::class,
        fn (OrphanLeftUnresolved $event): bool => $event->reason === $reason && $event->refunded === $refunded && $event->reference === 'pi_1',
    );
}

describe('OrphanFilterTest', function (): void {
    it('passes over a candidate of another account before any branch, a partial refund included', function (): void {
        journalPayment(WebhookAction::Captured, 'pi_1', 5000, 'acct_other', 'evt_1');
        journalPayment(WebhookAction::Refunded, 'pi_1', 1000, 'acct_other', 'evt_2');

        reconcile();

        expect(orphanedAt('pi_1'))->toBeFalse();
        Exceptions::assertNothingReported();
        Event::assertNotDispatched(OrphanLeftUnresolved::class);
    });

    it('tells a partial refund of the owner left unresolved', function (): void {
        journalPayment(WebhookAction::Captured, 'pi_1', 5000, 'acct_owner', 'evt_1');
        journalPayment(WebhookAction::Refunded, 'pi_1', 1000, 'acct_owner', 'evt_2');

        reconcile();

        assertUnresolved(OrphanLeftUnresolved::PARTIAL_REFUND, 1000);
        Exceptions::assertReported(PaymentException::class);
        expect(orphanedAt('pi_1'))->toBeTrue();
    });

    it('tells an orphan whose cart is not found left unresolved', function (): void {
        journalPayment(WebhookAction::Captured, 'pi_1', 5000, 'acct_owner', 'evt_1');

        reconcile();

        assertUnresolved(OrphanLeftUnresolved::UNRECOGNIZED);
    });

    it('tells a deferred orphan left unresolved and keeps it for the next run', function (): void {
        Event::listen(PaymentOrphaned::class, fn (PaymentOrphaned $orphan) => $orphan->defer());
        journalPayment(WebhookAction::Captured, 'pi_1', 5000, 'acct_owner', 'evt_1');

        reconcile();

        assertUnresolved(OrphanLeftUnresolved::DEFERRED);
        expect(orphanedAt('pi_1'))->toBeFalse();
    });

    it('tells an orphan the refund did not give back left unresolved', function (): void {
        Event::listen(PaymentOrphaned::class, fn (PaymentOrphaned $orphan) => $orphan->recognize());
        $this->driver->retrievedStatus = 'processing';
        journalPayment(WebhookAction::Captured, 'pi_1', 5000, 'acct_owner', 'evt_1');

        reconcile();

        assertUnresolved(OrphanLeftUnresolved::NOT_RESOLVED);
        Event::assertNotDispatched(OrphanSettled::class);
        expect($this->driver->refunds)->toBe([])
            ->and(orphanedAt('pi_1'))->toBeFalse();
    });

    it('tells an orphan whose handler failed left unresolved', function (): void {
        Event::listen(PaymentOrphaned::class, function (): void {
            throw new RuntimeException('Handler failed.');
        });
        journalPayment(WebhookAction::Captured, 'pi_1', 5000, 'acct_owner', 'evt_1');

        reconcile();

        assertUnresolved(OrphanLeftUnresolved::HANDLER_FAILED);
        Exceptions::assertReported(RuntimeException::class);
    });

    it('settles an orphan whose order its handler placed', function (): void {
        Event::listen(PaymentOrphaned::class, function (PaymentOrphaned $orphan): void {
            $orphan->recognize();
            $order = Order::factory()->create();
            PaymentTransaction::factory()->create(['order_id' => $order->id, 'reference' => $orphan->reference, 'driver' => 'fake']);
        });
        journalPayment(WebhookAction::Captured, 'pi_1', 5000, 'acct_owner', 'evt_1');

        reconcile();

        Event::assertDispatched(OrphanSettled::class, fn (OrphanSettled $event): bool => $event->outcome === OrphanSettled::ORDER);
        Event::assertNotDispatched(OrphanLeftUnresolved::class);
        expect($this->driver->refunds)->toBe([]);
    });

    it('settles an orphan the refund gave back', function (): void {
        Event::listen(PaymentOrphaned::class, fn (PaymentOrphaned $orphan) => $orphan->recognize());
        journalPayment(WebhookAction::Captured, 'pi_1', 5000, 'acct_owner', 'evt_1');

        reconcile();

        Event::assertDispatched(OrphanSettled::class, fn (OrphanSettled $event): bool => $event->outcome === OrphanSettled::REFUNDED);
        expect($this->driver->refunds)->toBe([['reference' => 'pi_1', 'amount' => 5000]]);
    });
});
