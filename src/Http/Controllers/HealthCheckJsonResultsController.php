<?php

namespace Spatie\Health\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\Health\Http\Controllers\Concerns\RunsHealthChecks;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\Support\SuiteNames;

class HealthCheckJsonResultsController
{
    use RunsHealthChecks;

    public function __invoke(Request $request, ResultStore $resultStore): Response
    {
        $checkResults = null;

        if ($request->has('fresh') || $request->has(SuiteNames::PARAMETER) || config('health.oh_dear_endpoint.always_send_fresh_results')) {
            $checkResults = $this->runHealthChecks($request);
        }

        $checkResults ??= $resultStore->latestResults();

        $statusCode = $checkResults?->containsFailingCheck()
            ? config('health.json_results_failure_status', Response::HTTP_OK)
            : Response::HTTP_OK;

        return response($checkResults?->toJson() ?? '', $statusCode)
            ->header('Content-Type', 'application/json')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, post-check=0, pre-check=0');
    }
}
