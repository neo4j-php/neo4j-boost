<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Controllers;

use Illuminate\Http\JsonResponse;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Http\Requests\StorePostRequest;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\PodcastParser;

final class PostController extends Controller
{
    public function store(StorePostRequest $request, PodcastParser $parser): JsonResponse
    {
        return response()->json(['parsed' => $parser !== null]);
    }

    public function index(PodcastParser $parser): JsonResponse
    {
        return response()->json([]);
    }
}
