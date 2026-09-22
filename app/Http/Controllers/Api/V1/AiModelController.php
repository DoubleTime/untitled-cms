<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\AiModelDetailResource;
use App\Http\Resources\Api\V1\AiModelResource;
use App\Models\AiModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AI Models for RPA-TOOL — the same five endpoints as FlowChart Scripts, minus
 * Preview Images and plus the inference metadata.
 */
class AiModelController extends CatalogueController
{
    protected function modelClass(): string
    {
        return AiModel::class;
    }

    protected function listResourceClass(): string
    {
        return AiModelResource::class;
    }

    protected function detailResourceClass(): string
    {
        return AiModelDetailResource::class;
    }

    public function show(AiModel $entry): JsonResponse
    {
        return $this->showEntry($entry);
    }

    public function revisions(AiModel $entry): JsonResponse
    {
        return $this->revisionsFor($entry);
    }

    public function download(Request $request, AiModel $entry): StreamedResponse
    {
        return $this->downloadFrom($request, $entry);
    }

    public function checkUpdate(Request $request, AiModel $entry): JsonResponse
    {
        return $this->checkUpdateFor($request, $entry);
    }
}
