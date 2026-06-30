<?php

namespace Spatie\Health\Http\Controllers\Concerns;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Spatie\Health\Commands\RunHealthChecksCommand;
use Spatie\Health\Exceptions\InvalidSuite;
use Spatie\Health\Health;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResults;
use Spatie\Health\Support\SuiteNames;
use Spatie\Health\Support\TransientHealthResults;

trait RunsHealthChecks
{
    protected function validateRequestedHealthCheckSuites(Request $request): void
    {
        $suiteNames = SuiteNames::from($request->query(SuiteNames::PARAMETER));

        if ($suiteNames === []) {
            return;
        }

        try {
            app(Health::class)->registeredChecksForSuites($suiteNames);
        } catch (InvalidSuite $exception) {
            abort(404, $exception->getMessage());
        }
    }

    protected function runHealthChecks(Request $request): ?StoredCheckResults
    {
        $this->validateRequestedHealthCheckSuites($request);

        $suiteNames = SuiteNames::from($request->query(SuiteNames::PARAMETER));

        if ($suiteNames !== []) {
            return $this->runHealthChecksForSuites($suiteNames);
        }

        $result = Artisan::call(
            RunHealthChecksCommand::class,
        );

        if ($result !== Command::SUCCESS) {
            abort(404, trim(Artisan::output()));
        }

        return null;
    }

    /** @param  array<int, string>  $suiteNames */
    protected function runHealthChecksForSuites(array $suiteNames): ?StoredCheckResults
    {
        $result = Command::SUCCESS;

        $checkResults = TransientHealthResults::capture(function () use ($suiteNames, &$result) {
            $result = Artisan::call(
                RunHealthChecksCommand::class,
                SuiteNames::toArtisanOptions($suiteNames) + ['--store-suite-results' => true],
            );
        });

        if ($result !== Command::SUCCESS) {
            abort(404, trim(Artisan::output()));
        }

        return $checkResults;
    }
}
