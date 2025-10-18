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
            'debug' => ['sometimes', 'boolean'],
        ]);

        $debug = array_key_exists('debug', $validated)
            ? (bool) $validated['debug']
            : (bool) config('laragrep.debug', false);

        $answer = $this->service->answerQuestion($validated['question'], $debug);

        return response()->json($answer);
    }
}
