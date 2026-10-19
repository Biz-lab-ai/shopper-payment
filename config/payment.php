<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Reconciliation
    |--------------------------------------------------------------------------
    |
    | Here you may control how an order catches up with the provider events
    | that arrived before it existed. When a cart is completed the stored
    | events are replayed and a provider check is queued for a payment still
    | pending. Every fifteen minutes the scheduled command replays what is
    | still unprocessed and queues a check for every pending payment. The
    | checks run on the queue configured here, and the event ledger is
    | pruned daily past the retention below.
    |
    | A payment collected without an order past the grace below is an orphan:
    | the cart it paid for is completed when it can be, otherwise the orphan
    | is reported, and given back to the customer when `orphans` is "refund".
    |
    | `orphan_filter` names a class implementing
    | Shopper\Payment\Contracts\OrphanFilter: a candidate it refuses is
    | skipped before any branch of the reconciliation and left unmarked.
    | Left null, every candidate is handled.
    |
    */

    'reconciliation' => [
        'pull_on_completion' => env('PAYMENT_PULL_ON_COMPLETION', true),
        'schedule' => env('PAYMENT_RECONCILE_SCHEDULE', true),
        'queue' => env('PAYMENT_RECONCILE_QUEUE'),
        'backoff' => [60, 300, 900],
        'prune_after_days' => env('PAYMENT_WEBHOOK_EVENTS_PRUNE_AFTER_DAYS', 90),
        'orphan_after_minutes' => env('PAYMENT_ORPHAN_AFTER_MINUTES', 30),
        'orphans' => env('PAYMENT_ORPHANS', 'alert'),
        'orphan_filter' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Compensation Store
    |--------------------------------------------------------------------------
    |
    | A class implementing Shopper\Payment\Contracts\CompensationStore. The
    | decision to give a payment back is recorded there before the provider
    | is called, and a cart whose payment is being given back is never
    | completed into an order. Left null, nothing is recorded.
    |
    */

    'compensation_store' => null,

];
