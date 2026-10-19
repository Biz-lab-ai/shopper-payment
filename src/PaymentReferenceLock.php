<?php

declare(strict_types=1);

namespace Shopper\Payment;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * One lock per payment reference, shared by every path that may turn the
 * payment into an order or give it back: the cart completion, the orphan
 * listener and the reconciliation refund. The lock is re-entrant within
 * the process, so a path already holding it (a completion refunding on a
 * stock refusal) never waits on itself.
 */
final class PaymentReferenceLock
{
    /** @var array<string, true> */
    private static array $held = [];

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function run(string $reference, Closure $callback): mixed
    {
        $key = self::key($reference);

        if (isset(self::$held[$key])) {
            return $callback();
        }

        return Cache::lock($key, 180)->block(3, static function () use ($key, $callback): mixed {
            self::$held[$key] = true;

            try {
                return $callback();
            } finally {
                unset(self::$held[$key]);
            }
        });
    }

    public static function key(string $reference): string
    {
        return 'shopper:payment-reference:'.$reference;
    }
}
