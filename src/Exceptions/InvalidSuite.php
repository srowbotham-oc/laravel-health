<?php

namespace Spatie\Health\Exceptions;

use Exception;

class InvalidSuite extends Exception
{
    public static function emptyName(): self
    {
        return new self('A health check suite name cannot be empty.');
    }

    /**
     * @param  array<int, string>  $suiteNames
     * @param  array<int, string>  $availableSuiteNames
     */
    public static function doesNotExist(array $suiteNames, array $availableSuiteNames): self
    {
        $suiteNamesString = collect($suiteNames)
            ->map(fn (string $suiteName) => "`{$suiteName}`")
            ->join(', ', ' and ');

        $availableSuiteNamesString = collect($availableSuiteNames)
            ->map(fn (string $suiteName) => "`{$suiteName}`")
            ->join(', ', ' and ');

        $availableSuiteNamesMessage = $availableSuiteNamesString
            ? " Available suites are {$availableSuiteNamesString}."
            : '';

        return new self("No health check suite named {$suiteNamesString} has been registered.{$availableSuiteNamesMessage}");
    }
}
