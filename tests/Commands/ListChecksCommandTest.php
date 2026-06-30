<?php

use Spatie\Health\Commands\ListHealthChecksCommand;
use Spatie\Health\Facades\Health;
use Spatie\Health\Models\HealthCheckResultHistoryItem;
use Spatie\Health\ResultStores\InMemoryHealthResultStore;
use Spatie\Health\Tests\TestClasses\FakeUsedDiskSpaceCheck;

use function Pest\Laravel\artisan;

it('thrown no exceptions with no checks registered', function () {
    artisan(ListHealthChecksCommand::class)->assertSuccessful();
});

it('thrown no exceptions with a check registered', function () {
    Health::checks([
        FakeUsedDiskSpaceCheck::new(),
    ]);

    artisan(ListHealthChecksCommand::class, ['--fresh' => true])->assertSuccessful();
});

it('has an option that will let the command fail when a check fails', function () {
    $fakeDiskSpaceCheck = FakeUsedDiskSpaceCheck::new();

    Health::checks([
        $fakeDiskSpaceCheck,
    ]);

    $fakeDiskSpaceCheck->fakeDiskUsagePercentage(0);
    artisan('health:list')->assertSuccessful();
    artisan('health:list --fail-command-on-failing-check')->assertSuccessful();

    $fakeDiskSpaceCheck->fakeDiskUsagePercentage(100);

    artisan('health:check')->assertSuccessful();
    artisan('health:list')->assertSuccessful();
    artisan('health:list --fail-command-on-failing-check')->assertFailed();
});

it('can use multiple options at once', function () {
    $fakeDiskSpaceCheck = FakeUsedDiskSpaceCheck::new();

    Health::checks([
        $fakeDiskSpaceCheck,
    ]);

    artisan('health:list --fresh --do-not-store-results --no-notification')->assertSuccessful();
});

it('can freshen results for a named suite', function () {
    Health::checks([
        FakeUsedDiskSpaceCheck::new()->name('Default'),
    ]);

    Health::suite('readiness', [
        FakeUsedDiskSpaceCheck::new()->name('Readiness'),
    ]);

    artisan('health:list --fresh --suites=readiness')->assertSuccessful();

    expect(app(InMemoryHealthResultStore::class)->latestResults()?->storedCheckResults->first()->name)->toBe('Readiness')
        ->and(HealthCheckResultHistoryItem::pluck('check_name')->all())->toBe([]);

    artisan('health:list --fresh --suites=readiness --do-not-store-results')->assertSuccessful();

    expect(app(InMemoryHealthResultStore::class)->latestResults()?->storedCheckResults->first()->name)->toBe('Readiness')
        ->and(HealthCheckResultHistoryItem::pluck('check_name')->all())->toBe([]);
});
