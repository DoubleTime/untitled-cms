<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\FlowchartScriptDetailResource;
use App\Http\Resources\Api\V1\FlowchartScriptResource;
use App\Models\FlowchartScript;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FlowChart Scripts for RPA-TOOL. The route parameter is `{entry}`; the concrete
 * type hints here are what makes implicit route model binding resolve it (a
 * soft-deleted Script is not bound, so it 404s).
 */
class FlowchartScriptController extends CatalogueController
{
    protected function modelClass(): string
    {
        return FlowchartScript::class;
    }

    protected function listResourceClass(): string
    {
        return FlowchartScriptResource::class;
    }

    protected function detailResourceClass(): string
    {
        return FlowchartScriptDetailResource::class;
    }

    /** Preview Images come along so the list can carry a cover image URL. */
    protected function listRelations(): array
    {
        return ['machineModel.machineBrand', 'customer', 'images.vaultFile'];
    }

    public function show(FlowchartScript $entry): JsonResponse
    {
        return $this->showEntry($entry);
    }

    public function revisions(FlowchartScript $entry): JsonResponse
    {
        return $this->revisionsFor($entry);
    }

    public function download(Request $request, FlowchartScript $entry): StreamedResponse
    {
        return $this->downloadFrom($request, $entry);
    }

    public function checkUpdate(Request $request, FlowchartScript $entry): JsonResponse
    {
        return $this->checkUpdateFor($request, $entry);
    }
}
