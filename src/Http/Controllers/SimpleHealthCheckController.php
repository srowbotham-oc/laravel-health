<?php

namespace Spatie\Health\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Spatie\Health\Commands\PauseHealthChecksCommand;
use Spatie\Health\Http\Controllers\Concerns\RunsHealthChecks;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\Support\SuiteNames;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class SimpleHealthCheckController
{
    use RunsHealthChecks;

    public function __invoke(Request $request, ResultStore $resultStore): Response
    {
        $this->validateRequestedHealthCheckSuites($request);

        $checkResults = null;

        if (
            ($request->has('fresh') || $request->has(SuiteNames::PARAMETER) || config('health.oh_dear_endpoint.always_send_fresh_results'))
            && Cache::missing(PauseHealthChecksCommand::CACHE_KEY)
        ) {
            $checkResults = $this->runHealthChecks($request);
        }

        $checkResults ??= $resultStore->latestResults();

        if (! ($checkResults?->allChecksOk())) {
            throw new ServiceUnavailableHttpException(message: 'Application not healthy');
        }

        return response([
            'healthy' => true,
        ])
            ->header('Content-Type', 'application/json')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, post-check=0, pre-check=0');
    }
}
