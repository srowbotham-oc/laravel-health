<?php

namespace Spatie\Health\Testing;

use Closure;
use Illuminate\Support\Collection;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\Health\Health;

class FakeHealth extends Health
{
    /**
     * @param  array<class-string<Check>, Result|FakeValues|(Closure(Check): Result|FakeValues)>  $fakeChecks
     */
    public function __construct(
        private Health $decoratedHealth,
        private array $fakeChecks
    ) {}

    /** @return Collection<int, Check> */
    public function registeredChecks(): Collection
    {
        return $this->fakeRegisteredChecks($this->decoratedHealth->registeredChecks());
    }

    /**
     * @param  string|array<int, string>  $suites
     * @return Collection<int, Check>
     */
    public function registeredChecksForSuites(string|array $suites): Collection
    {
        return $this->fakeRegisteredChecks($this->decoratedHealth->registeredChecksForSuites($suites));
    }

    /** @return Collection<int, string> */
    public function registeredSuiteNames(): Collection
    {
        return $this->decoratedHealth->registeredSuiteNames();
    }

    /**
     * @param  Collection<int, Check>  $checks
     * @return Collection<int, Check>
     */
    protected function fakeRegisteredChecks(Collection $checks): Collection
    {
        return $checks->map(
            fn (Check $check) => array_key_exists($check::class, $this->fakeChecks)
                ? $this->buildFakeCheck($check, $this->fakeChecks[$check::class])
                : $check
        );
    }

    /**
     * @param  Result|FakeValues|(Closure(Check): Result|FakeValues)  $result
     */
    protected function buildFakeCheck(Check $decoratedCheck, Result|FakeValues|Closure $result): FakeCheck
    {
        // @phpstan-ignore-next-line
        $result = FakeValues::from(value($result, $decoratedCheck));

        return FakeCheck::new()->fake($decoratedCheck, $result);
    }
}
