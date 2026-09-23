<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\ScriptDetailResource;
use App\Http\Resources\Api\V1\ScriptResource;
use App\Models\Script;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Scripts for RPA-TOOL. The route parameter is `{entry}`; the concrete
 * type hints here are what makes implicit route model binding resolve it (a
 * soft-deleted Script is not bound, so it 404s).
 */
class ScriptController extends CatalogueController
{
    protected function modelClass(): string
    {
        return Script::class;
    }

    protected function listResourceClass(): string
    {
        return ScriptResource::class;
    }

    protected function detailResourceClass(): string
    {
        return ScriptDetailResource::class;
    }

    /** Preview Images come along so the list can carry a Preview Image URL. */
    protected function listRelations(): array
    {
        return ['machineModel.machineBrand', 'customer', 'images.vaultFile'];
    }

    public function show(Script $entry): JsonResponse
    {
        return $this->showEntry($entry);
    }

    public function revisions(Script $entry): JsonResponse
    {
        return $this->revisionsFor($entry);
    }

    public function download(Request $request, Script $entry): StreamedResponse
    {
        return $this->downloadFrom($request, $entry);
    }

    public function checkUpdate(Request $request, Script $entry): JsonResponse
    {
        return $this->checkUpdateFor($request, $entry);
    }
}
