<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CustomerResource;
use App\Http\Resources\Api\V1\MachineBrandResource;
use App\Http\Resources\Api\V1\MachineModelResource;
use App\Models\Customer;
use App\Models\MachineBrand;
use App\Models\MachineModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The small reference lists RPA-TOOL uses to build its catalogue filters.
 *
 * All three are unpaginated: there are tens of rows, not thousands, and the tool
 * caches them for the session.
 */
class LookupController extends Controller
{
    public function machineBrands(): AnonymousResourceCollection
    {
        return MachineBrandResource::collection(
            MachineBrand::query()->orderBy('name')->get()
        );
    }

    public function machineModels(Request $request): AnonymousResourceCollection
    {
        $query = MachineModel::query()
            ->active()
            ->with('machineBrand')
            ->orderBy('name');

        $query->when($request->filled('brand'), fn ($q) => $q
            ->where('machine_brand_id', $request->string('brand')->toString()));

        return MachineModelResource::collection($query->get());
    }

    /**
     * Every Customer is visible to every Customer User — the label is a secondary
     * filter, never an access wall (docs/adr/0001). Only the id and company name
     * are exposed.
     */
    public function customers(): AnonymousResourceCollection
    {
        return CustomerResource::collection(
            Customer::query()->active()->orderBy('company')->get()
        );
    }
}
