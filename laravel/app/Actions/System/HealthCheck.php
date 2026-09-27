<?php

namespace App\Actions\System;

use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Concerns\AsController;

final class HealthCheck
{
    use AsAction;
    use AsController;

    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
