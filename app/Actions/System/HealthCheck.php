<?php

namespace App\Actions\System;

use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\Concerns\AsAction;

final class HealthCheck
{
    use AsAction;

    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
