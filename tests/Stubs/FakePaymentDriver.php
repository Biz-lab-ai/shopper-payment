<?php

declare(strict_types=1);

namespace Shopper\Payment\Tests\Stubs;

use Shopper\Payment\DataTransferObjects\PaymentResult;
use Shopper\Payment\DataTransferObjects\WebhookResult;
use Shopper\Payment\Drivers\Driver;
use Shopper\Payment\Enum\WebhookAction;

final class FakePaymentDriver extends Driver
{
    public string $retrievedStatus = 'captured';

    /** @var list<array{reference: string, amount: int}> */
    public array $refunds = [];

    public function code(): string
    {
        return 'fake';
    }

    public function name(): string
    {
        return 'Fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function initiatePayment(int $amount, string $currency, array $context = []): PaymentResult
    {
        return new PaymentResult(success: true, status: 'pending', reference: 'fake_intent', amount: $amount);
    }

    public function cancelPayment(string $reference): PaymentResult
    {
        return new PaymentResult(success: true, status: 'cancelled', reference: $reference);
    }

    public function refundPayment(string $reference, int $amount, ?string $reason = null, array $context = []): PaymentResult
    {
        $this->refunds[] = ['reference' => $reference, 'amount' => $amount];

        return new PaymentResult(success: true, status: 'refunded', reference: 're_'.count($this->refunds), amount: $amount);
    }

    public function handleWebhook(array $payload, array $headers = []): WebhookResult
    {
        return new WebhookResult(action: WebhookAction::Ignored);
    }

    public function supportsRetrieval(): bool
    {
        return true;
    }

    public function retrievePayment(string $reference): PaymentResult
    {
        return new PaymentResult(success: true, status: $this->retrievedStatus, reference: $reference, amount: 5000);
    }
}
