<?php

namespace App\Actions\Search;

use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\Concerns\AsAction;

final class AdminReindex
{
    use AsAction;

    public function __invoke(): JsonResponse
    {
        $summary = ReindexMessages::run([]);

        return response()->json($summary);
    }
}
