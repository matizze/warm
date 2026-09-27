<?php

namespace App\Actions\Search;

use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Concerns\AsController;

final class AdminReindex
{
    use AsAction;
    use AsController;

    public function __invoke(): JsonResponse
    {
        $summary = ReindexMessages::run([]);

        return response()->json($summary);
    }
}
