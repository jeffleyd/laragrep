<?php

namespace LaraGrep\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaraGrep\Services\LaraGrepQueryService;

class QueryController extends Controller
{
    public function __construct(protected LaraGrepQueryService $service)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string'],
        ]);

        $answer = $this->service->answerQuestion($validated['question']);

        return response()->json($answer);
    }
}
