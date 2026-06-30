<?php

namespace Spatie\Health\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\Health\Enums\Status;
use Spatie\Health\Events\CheckEndedEvent;
use Spatie\Health\Events\CheckStartingEvent;
use Spatie\Health\Exceptions\CheckDidNotComplete;
use Spatie\Health\Exceptions\InvalidSuite;
use Spatie\Health\Health;
use Spatie\Health\Notifications\Notifiable;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\Support\SuiteNames;

class RunHealthChecksCommand extends Command
{
    protected $signature = 'health:check {--do-not-store-results} {--no-notification}
                         {--fail-command-on-failing-check}
                         {--store-suite-results : Store suite-filtered results in the configured result store}
                         {--suites=* : Only run checks from the given suite(s)}';

    protected $description = 'Run health checks';

    /** @var array<int, Exception> */
    protected array $thrownExceptions = [];

    public function handle(): int
    {
        try {
            $this->selectedChecks();
        } catch (InvalidSuite $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (Cache::get(PauseHealthChecksCommand::CACHE_KEY)) {
            $this->info('Checks paused');

            return self::SUCCESS;
        }

        $this->info('Running checks...');

        $results = $this->runChecks();

        if (! $this->option('no-notification') && config('health.notifications.enabled', false)) {
            $this->sendNotification($results);
        }

        if ($this->shouldStoreResults()) {
            $this->storeResults($results);
        }

        $this->line('');
        $this->info('All done!');

        return $this->determineCommandResult($results);
    }

    public function runCheck(Check $check): Result
    {
        event(new CheckStartingEvent($check));

        try {
            $this->line('');
            $this->line("Running check: {$check->getLabel()}...");
            $result = $check->run();
        } catch (Exception $exception) {
            $exception = CheckDidNotComplete::make($check, $exception);
            report($exception);

            $this->thrownExceptions[] = $exception;

            $result = $check->markAsCrashed();
        }

        $result
            ->check($check)
            ->endedAt(now());

        $this->outputResult($result, $exception ?? null);

        event(new CheckEndedEvent($check, $result));

        return $result;
    }

    /** @return Collection<int, Result> */
    protected function runChecks(): Collection
    {
        [$shouldRun, $skipped] = $this->splitChecksByShouldRun(
            $this->selectedChecks()
        )->all();

        // retain original order
        return $skipped->mapWithKeys(fn (Check $check, $i) => [
            $i => (new Result(Status::skipped()))->check($check)->endedAt(now()),
        ])->union(
            $shouldRun->mapWithKeys(fn (Check $check, $i) => [
                $i => $this->runCheck($check),
            ])
        )->sortKeys();
    }

    /**
     * @param  Collection<int, Check>  $checks
     * @return Collection<int<0, 1>, Collection<int, Check>>
     */
    protected function splitChecksByShouldRun(Collection $checks): Collection
    {
        return $checks->partition(fn (Check $check) => $check->shouldRun());
    }

    /** @param  Collection<int, Result>  $results */
    protected function storeResults(Collection $results): self
    {
        app(Health::class)
            ->resultStores()
            ->each(fn (ResultStore $store) => $store->save($results));

        return $this;
    }

    /** @param  Collection<int, Result>  $results */
    protected function sendNotification(Collection $results): self
    {
        $resultsWithMessages = $results->filter(fn (Result $result) => ! empty($result->getNotificationMessage()));

        if (config('health.notifications.only_on_failure', false)) {
            $resultsWithMessages = $resultsWithMessages->filter(
                fn (Result $result) => $result->status === Status::failed()
            );
        }

        if ($resultsWithMessages->count() === 0) {
            return $this;
        }

        $notifiableClass = config('health.notifications.notifiable');

        /** @var Notifiable $notifiable */
        $notifiable = app($notifiableClass);

        /** @var array<int, Result> $results */
        $results = $resultsWithMessages->toArray();

        $failedNotificationClass = $this->getFailedNotificationClass();

        $notification = (new $failedNotificationClass($results));

        $notifiable->notify($notification);

        return $this;
    }

    protected function outputResult(Result $result, ?Exception $exception = null): void
    {
        $status = ucfirst((string) $result->status->value);

        $okMessage = $status;

        if (! empty($result->shortSummary)) {
            $okMessage .= ": {$result->shortSummary}";
        }

        match ($result->status) {
            Status::ok() => $this->info($okMessage),
            Status::warning() => $this->comment("{$status}: {$result->getNotificationMessage()}"),
            Status::failed() => $this->error("{$status}: {$result->getNotificationMessage()}"),
            Status::crashed() => $this->error("{$status}: `{$exception?->getMessage()}`"),
            default => null,
        };
    }

    /** @param  Collection<int, Result>  $results */
    protected function determineCommandResult(Collection $results): int
    {
        if (! $this->option('fail-command-on-failing-check')) {
            return self::SUCCESS;
        }

        if (count($this->thrownExceptions)) {
            return self::FAILURE;
        }

        $containsFailingCheck = $results->contains(function (Result $result) {
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

    /**
     * @return class-string
     */
    protected function getFailedNotificationClass(): string
    {
        return array_key_first(config('health.notifications.notifications'));
    }

    protected function shouldStoreResults(): bool
    {
        if ($this->option('do-not-store-results')) {
            return false;
        }

        if (! $this->hasSelectedSuites()) {
            return true;
        }

        return (bool) $this->option('store-suite-results');
    }

    /** @return Collection<int, Check> */
    protected function selectedChecks(): Collection
    {
        return app(Health::class)->registeredChecksForSuites($this->selectedSuiteNames());
    }

    protected function hasSelectedSuites(): bool
    {
        return SuiteNames::from($this->option('suites')) !== [];
    }

    /** @return array<int, string> */
    protected function selectedSuiteNames(): array
    {
        return SuiteNames::requestedOrDefault($this->option('suites'));
    }
}
