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

    public function __invoke(Request $request, ?string $context = null): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string'],
            'debug' => ['sometimes', 'boolean'],
            'context' => ['sometimes', 'nullable', 'string'],
        ]);

        $debug = array_key_exists('debug', $validated)
            ? (bool) $validated['debug']
            : (bool) config('laragrep.debug', false);

        $conversationId = array_key_exists('context', $validated)
            ? ($validated['context'] ?? null)
            : null;

        if (is_string($conversationId)) {
            $conversationId = trim($conversationId);

            if ($conversationId === '') {
                $conversationId = null;
            }
        }

        $context = $context === null || $context === '' ? 'default' : $context;

        $answer = $this->service->answerQuestion($validated['question'], $debug, $context, $conversationId);

        return response()->json($answer);
    }
}
