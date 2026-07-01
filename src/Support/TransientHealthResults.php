<?php

namespace Spatie\Health\Support;

use Closure;
use Spatie\Health\ResultStores\InMemoryHealthResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResults;

class TransientHealthResults
{
    public static function capture(Closure $callback): ?StoredCheckResults
    {
        $configuredResultStores = config('health.result_stores');

        InMemoryHealthResultStore::clear();

        config()->set('health.result_stores', [
            InMemoryHealthResultStore::class,
        ]);

        try {
            $callback();

            return app(InMemoryHealthResultStore::class)->latestResults();
        } finally {
            config()->set('health.result_stores', $configuredResultStores);
        }
    }
}
