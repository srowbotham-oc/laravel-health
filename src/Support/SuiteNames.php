<?php

namespace Spatie\Health\Support;

class SuiteNames
{
    public const DEFAULT = 'default';

    public const PARAMETER = 'suites';

    /**
     * @return array<int, string>
     */
    public static function from(mixed $suiteNames): array
    {
        if ($suiteNames === null || $suiteNames === false) {
            return [];
        }

        if (is_array($suiteNames)) {
            return collect($suiteNames)
                ->flatMap(fn (mixed $suiteName) => self::from($suiteName))
                ->unique()
                ->values()
                ->all();
        }

        if (is_scalar($suiteNames)) {
            return self::fromString((string) $suiteNames);
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    public static function requestedOrDefault(mixed $suiteNames): array
    {
        return self::from($suiteNames) ?: [self::DEFAULT];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function toArtisanOptions(mixed $suiteNames): array
    {
        $suiteNames = self::from($suiteNames);

        if ($suiteNames === []) {
            return [];
        }

        return ['--'.self::PARAMETER => $suiteNames];
    }

    /**
     * @return array<int, string>
     */
    protected static function fromString(string $suiteNames): array
    {
        return collect(explode(',', $suiteNames))
            ->map(fn (string $suiteName) => trim($suiteName))
            ->filter(fn (string $suiteName) => $suiteName !== '')
            ->unique()
            ->values()
            ->all();
    }
}
