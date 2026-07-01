<?php

namespace Spatie\Health\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Spatie\Health\Enums\Status;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResults;
use Spatie\Health\Support\SuiteNames;
use Spatie\Health\Support\TransientHealthResults;

use function Termwind\render;
use function Termwind\renderUsing;

class ListHealthChecksCommand extends Command
{
    protected $signature = 'health:list {--fresh} {--do-not-store-results} {--no-notification}
                         {--fail-command-on-failing-check}
                         {--suites=* : Only run checks from the given suite(s) when using --fresh}';

    protected $description = 'List all health checks';

    public function handle(): int
    {
        $checkResults = null;

        if ($this->option('fresh')) {
            $checkResults = $this->runFreshChecks();

            if ($checkResults === false) {
                return self::FAILURE;
            }
        }

        $checkResults ??= app(ResultStore::class)->latestResults();

        renderUsing($this->output);
        render(view('health::list-cli', [
            'lastRanAt' => new Carbon($checkResults?->finishedAt),
            'checkResults' => $checkResults,
            'color' => fn (string $status) => $this->getBackgroundColor($status),
        ]));

        return $this->determineCommandResult($checkResults);
    }

    protected function getBackgroundColor(string $status): string
    {
        $status = Status::from($status);

        return match ($status) {
            Status::ok() => 'text-green-600',
            Status::warning() => 'text-yellow-600',
            Status::skipped() => 'text-blue-600',
            Status::failed(), Status::crashed() => 'text-red-600',
            default => ''
        };
    }

    protected function runFreshChecks(): StoredCheckResults|false|null
    {
        $parameters = $this->runHealthChecksCommandParameters();

        if (SuiteNames::from($this->option('suites')) === []) {
            return $this->runHealthChecksCommand($parameters)
                ? app(ResultStore::class)->latestResults()
                : false;
        }

        $succeeded = true;

        $checkResults = TransientHealthResults::capture(function () use ($parameters, &$succeeded) {
            unset($parameters['--do-not-store-results']);

            $parameters['--store-suite-results'] = true;

            $succeeded = $this->runHealthChecksCommand($parameters);
        });

        return $succeeded ? $checkResults : false;
    }

    /**
     * @return array<string, array<int, string>|bool>
     */
    protected function runHealthChecksCommandParameters(): array
    {
        $parameters = SuiteNames::toArtisanOptions($this->option('suites'));

        if ($this->option('do-not-store-results')) {
            $parameters['--do-not-store-results'] = true;
        }

        if ($this->option('no-notification')) {
            $parameters['--no-notification'] = true;
        }

        return $parameters;
    }

    /**
     * @param  array<string, array<int, string>|bool>  $parameters
     */
    protected function runHealthChecksCommand(array $parameters): bool
    {
        if (Artisan::call(RunHealthChecksCommand::class, $parameters) === self::SUCCESS) {
            return true;
        }

        $this->error(trim(Artisan::output()));

        return false;
    }

    protected function determineCommandResult(?StoredCheckResults $results): int
    {
        if (! $this->option('fail-command-on-failing-check') || is_null($results)) {
            return self::SUCCESS;
        }

        $containsFailingCheck = $results->storedCheckResults->contains(function (StoredCheckResult $result) {
            return in_array($result->status, [
                Status::crashed(),
                Status::failed(),
                Status::warning(),
            ]);
        });

        return $containsFailingCheck
            ? self::FAILURE
            : self::SUCCESS;
    }
}
