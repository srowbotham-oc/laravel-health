<?php

namespace Spatie\Health\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Health\Health;
use Spatie\Health\Http\Controllers\Concerns\RunsHealthChecks;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\Support\SuiteNames;

class HealthCheckResultsController
{
    use RunsHealthChecks;

    public function __invoke(Request $request, ResultStore $resultStore, Health $health): JsonResponse|View
    {
        $checkResults = null;

        if ($request->has('fresh') || $request->has(SuiteNames::PARAMETER)) {
            $checkResults = $this->runHealthChecks($request);
        }

        $checkResults ??= $resultStore->latestResults();

        return view('health::list', [
            'lastRanAt' => new Carbon($checkResults?->finishedAt),
            'checkResults' => $checkResults,
            'assets' => $health->assets(),
            'theme' => config('health.theme'),
        ]);
    }
}
