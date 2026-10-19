<?php

declare(strict_types=1);

namespace Shopper\Payment;

use Shopper\Core\Contracts\PaymentSessionGateway;
use Shopper\Core\Exceptions\PaymentProviderUnavailableException;
use Shopper\Core\Models\Contracts\Order;
use Shopper\Payment\Contracts\CompensationStore;
use Shopper\Payment\Contracts\PaymentDriver;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Events\PaymentRefundRequested;
use Shopper\Payment\Models\PaymentTransaction;
use Shopper\Payment\Services\PaymentProcessingService;
use Throwable;

final readonly class ProviderPaymentSessionGateway implements PaymentSessionGateway
{
    public function __construct(
        private PaymentManager $paymentManager,
        private PaymentProcessingService $payments,
    ) {}

    public function collected(array $session): ?bool
    {
        if ($this->unmanaged($session)) {
            return false;
        }

        $driver = $this->driver($session);

        if (! $driver->supportsRetrieval()) {
            return null;
        }

        return $this->retrieveCollected($driver, $session['reference']);
    }

    public function release(array $session): bool
    {
        if ($this->unmanaged($session)) {
            return true;
        }

        $driver = $this->driver($session);

        if (! $driver->supportsRetrieval() || $this->retrieveCollected($driver, $session['reference'])) {
            return false;
        }

        try {
            if ($driver->cancelPayment($session['reference'])->success) {
                return true;
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        try {
            $result = $driver->retrievePayment($session['reference']);
        } catch (Throwable $exception) {
            throw new PaymentProviderUnavailableException($exception);
        }

        return match (true) {
            $result->isCollected() => false,
            in_array($result->status, ['canceled', 'failed', 'refunded'], true) => true,
            default => throw new PaymentProviderUnavailableException,
        };
    }

    /**
     * The refund runs under the lock of the payment reference, shared with
     * every completion path: a payment whose order exists is never given
     * back, and the decision to give it back is recorded before the provider
     * is called, so no completion turns it into an order afterwards. The
     * amount is the one the caller asks for, the payment amount otherwise; a
     * repeated refund of the same amount sends the same idempotency key. The
     * refund counts as given back once the provider accepted it.
     */
    public function refund(array $session): bool
    {
        if ($this->unmanaged($session)) {
            return true;
        }

        $driver = $this->driver($session);

        if (! $driver->supportsRetrieval()) {
            return false;
        }

        return PaymentReferenceLock::run($session['reference'], fn (): bool => $this->refundUnderLock($driver, $session));
    }

    public function releaseOrder(Order $order): bool
    {
        $transaction = PaymentTransaction::query()
            ->where('order_id', $order->getKey())
            ->where('type', TransactionType::Initiate)
            ->latest('id')
            ->first();

        if ($transaction?->reference === null) {
            return true;
        }

        $session = ['driver' => $transaction->driver, 'reference' => $transaction->reference];

        if ($this->unmanaged($session) || $this->driver($session)->supportsRetrieval()) {
            return $this->release($session);
        }

        try {
            return $this->driver($session)->cancelPayment($transaction->reference)->success;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function unmanaged(array $session): bool
    {
        return ! isset($session['reference']) || ($session['driver'] ?? 'manual') === 'manual';
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function driver(array $session): PaymentDriver
    {
        try {
            return $this->paymentManager->driver($session['driver']);
        } catch (Throwable $exception) {
            throw new PaymentProviderUnavailableException($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function refundUnderLock(PaymentDriver $driver, array $session): bool
    {
        $reference = (string) $session['reference'];

        if ($this->payments->findOrderByReference($reference) !== null) {
            return false;
        }

        try {
            $payment = $driver->retrievePayment($reference);
        } catch (Throwable $exception) {
            throw new PaymentProviderUnavailableException($exception);
        }

        if (! in_array($payment->status, ['captured', 'authorized'], true)) {
            return in_array($payment->status, ['pending', 'requires_action', 'canceled', 'failed', 'refunded'], true);
        }

        $amount = isset($session['amount']) ? (int) $session['amount'] : (int) $payment->amount;

        $this->compensations()?->begin((string) $session['driver'], $reference, $amount, (string) ($session['cause'] ?? 'refund'));

        try {
            if ($payment->status === 'authorized') {
                return $driver->cancelPayment($reference)->success;
            }

            $result = $driver->refundPayment($reference, $amount, context: ['idempotency_key' => 'refund-'.$reference.'-'.$amount]);
        } catch (Throwable $exception) {
            throw new PaymentProviderUnavailableException($exception);
        }

        event(new PaymentRefundRequested((string) $session['driver'], $reference, $result->reference, $amount, $result->status));

        return in_array($result->status, ['refund_pending', 'refund_requires_action', 'refunded'], true);
    }

    private function compensations(): ?CompensationStore
    {
        $store = config('shopper.payment.compensation_store');

        return is_string($store) && $store !== '' ? app($store) : null;
    }

    private function retrieveCollected(PaymentDriver $driver, string $reference): bool
    {
        try {
            return $driver->retrievePayment($reference)->isCollected();
        } catch (Throwable $exception) {
            throw new PaymentProviderUnavailableException($exception);
        }
    }
}
