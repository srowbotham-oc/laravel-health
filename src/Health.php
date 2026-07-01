<?php

namespace Spatie\Health;

use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use LogicException;
use Spatie\Health\Checks\Check;
use Spatie\Health\Exceptions\DuplicateCheckNamesFound;
use Spatie\Health\Exceptions\InvalidCheck;
use Spatie\Health\Exceptions\InvalidSuite;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\ResultStores;
use Spatie\Health\Support\SuiteNames;

class Health
{
    public const DEFAULT_SUITE = SuiteNames::DEFAULT;

    /** @var array<int, Check> */
    protected array $checks = [];

    /** @var array<string, array<int, string>> */
    protected array $checkNamesBySuite = [
        self::DEFAULT_SUITE => [],
    ];

    /** @var array<int, string> */
    protected array $inlineStylesheets = [];

    /**
     * @param  array<int, Check>  $checks
     */
    public function checks(array $checks): self
    {
        return $this->registerChecks($checks, self::DEFAULT_SUITE, allowAlreadyRegisteredChecks: false);
    }

    /** @param  array<int, Check>  $checks */
    public function suite(string $name, array $checks): self
    {
        if (SuiteNames::from($name) === []) {
            throw InvalidSuite::emptyName();
        }

        return $this->registerChecks($checks, $name, allowAlreadyRegisteredChecks: true);
    }

    public function clearChecks(): self
    {
        $this->checks = [];
        $this->checkNamesBySuite = [
            self::DEFAULT_SUITE => [],
        ];

        return $this;
    }

    /** @return Collection<int, Check> */
    public function registeredChecks(): Collection
    {
        return collect($this->checks);
    }

    /**
     * @param  string|array<int, string>  $suites
     * @return Collection<int, Check>
     */
    public function registeredChecksForSuites(string|array $suites): Collection
    {
        $suiteNames = SuiteNames::from($suites);

        if ($suiteNames === []) {
            return collect();
        }

        $this->guardAgainstMissingSuites($suiteNames);

        return collect($suiteNames)
            ->flatMap(fn (string $suiteName) => $this->checkNamesBySuite[$suiteName])
            ->unique()
            ->map(fn (string $checkName) => $this->getRegisteredCheck($checkName))
            ->values();
    }

    /** @return Collection<int, string> */
    public function registeredSuiteNames(): Collection
    {
        return collect(array_keys($this->checkNamesBySuite))->values();
    }

    /** @return Collection<int, ResultStore> */
    public function resultStores(): Collection
    {
        return ResultStores::createFromConfig();
    }

    public function inlineStylesheet(string $stylesheet): self
    {
        $this->inlineStylesheets[] = $stylesheet;

        return $this;
    }

    public function assets(): HtmlString
    {
        $assets = [];

        foreach ($this->inlineStylesheets as $inlineStylesheet) {
            $assets[] = "<style>{$inlineStylesheet}</style>";
        }

        return new HtmlString(implode('', $assets));
    }

    /** @param  array<int,mixed>  $checks */
    protected function ensureCheckInstances(array $checks): void
    {
        foreach ($checks as $check) {
            if (! $check instanceof Check) {
                throw InvalidCheck::doesNotExtendCheck($check);
            }
        }
    }

    /**
     * @param  array<int, Check>  $checks
     * @param  string|array<int, string>  $suites
     */
    protected function registerChecks(array $checks, string|array $suites, bool $allowAlreadyRegisteredChecks): self
    {
        $this->ensureCheckInstances($checks);

        $suiteNames = SuiteNames::from($suites);

        foreach ($checks as $check) {
            $checkName = $check->getName();

            if ($this->shouldStoreCheck($check, $checkName, $suiteNames, $allowAlreadyRegisteredChecks)) {
                $this->checks[] = $check;
            }

            foreach ($suiteNames as $suiteName) {
                $this->checkNamesBySuite[$suiteName] ??= [];

                if (! in_array($checkName, $this->checkNamesBySuite[$suiteName], true)) {
                    $this->checkNamesBySuite[$suiteName][] = $checkName;
                }
            }
        }

        $this->guardAgainstDuplicateCheckNames();

        return $this;
    }

    protected function guardAgainstDuplicateCheckNames(): void
    {
        $duplicateCheckNames = collect($this->checks)
            ->map(fn (Check $check) => $check->getName())
            ->duplicates();

        if ($duplicateCheckNames->isNotEmpty()) {
            throw DuplicateCheckNamesFound::make($duplicateCheckNames);
        }
    }

    protected function findRegisteredCheck(string $name): ?Check
    {
        return collect($this->checks)->first(fn (Check $check) => $check->getName() === $name);
    }

    /** @param  array<int, string>  $suiteNames */
    protected function shouldStoreCheck(Check $check, string $checkName, array $suiteNames, bool $allowAlreadyRegisteredChecks): bool
    {
        if ($this->findRegisteredCheck($checkName) !== $check) {
            return true;
        }

        if ($allowAlreadyRegisteredChecks) {
            return false;
        }

        return collect($suiteNames)
            ->contains(fn (string $suiteName) => in_array($checkName, $this->checkNamesBySuite[$suiteName] ?? [], true));
    }

    protected function getRegisteredCheck(string $name): Check
    {
        $check = $this->findRegisteredCheck($name);

        if (! $check) {
            throw new LogicException("The health check `{$name}` is not registered.");
        }

        return $check;
    }

    /** @param  array<int, string>  $suiteNames */
    protected function guardAgainstMissingSuites(array $suiteNames): void
    {
        $missingSuiteNames = collect($suiteNames)
            ->diff(array_keys($this->checkNamesBySuite))
            ->values();

        if ($missingSuiteNames->isEmpty()) {
            return;
        }

        throw InvalidSuite::doesNotExist(
            $missingSuiteNames->all(),
            $this->registeredSuiteNames()->all(),
        );
    }
}
