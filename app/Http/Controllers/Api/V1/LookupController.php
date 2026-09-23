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

    /**
     * Every Machine Model, active or not. This is a filter list, and a catalogue
     * entry may still be labelled with a Machine Model that has since been
     * deactivated — dropping it here would hide that entry's filter. The
     * `is_active` flag on the resource lets RPA-TOOL grey the row out instead.
     */
    public function machineModels(Request $request): AnonymousResourceCollection
    {
        $query = MachineModel::query()
            ->with('machineBrand')
            ->orderBy('name');

        $query->when($request->filled('brand'), fn ($q) => $q
            ->where('machine_brand_id', $request->string('brand')->toString()));

        return MachineModelResource::collection($query->get());
    }

    /**
     * Every Customer is visible to every Customer User — the label is a secondary
     * filter, never an access wall (docs/adr/0001).
     *
     * Inactive Customers are included for the same reason as inactive Machine
     * Models: an entry may still carry the label, and this is a filter list.
     * `is_active` on the resource is how RPA-TOOL greys one out.
     */
    public function customers(): AnonymousResourceCollection
    {
        return CustomerResource::collection(
            Customer::query()->orderBy('company')->get()
        );
    }
}
