<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\UpdateUnysisBoxRequest;
use App\Models\Customer;
use App\Models\Download;
use App\Models\MachineModel;
use App\Models\UnysisBox;
use App\Services\ActivityLogger;
use App\Services\Marketplace\UnysisBoxInstalledService;
use App\Support\DownloadPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * UNYSIS Boxes admin.
 *
 * Boxes are never created here — RPA-TOOL auto-registers them on first login
 * (docs/adr/0002). A Team Member labels one, marks a pending box active, blocks
 * a box that should no longer pull, and removes one that never downloaded
 * anything.
 */
class UnysisBoxController extends Controller
{
    public function __construct(private UnysisBoxInstalledService $installed) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', UnysisBox::class);

        $boxes = UnysisBox::query()
            ->with([
                'customer:id,company',
                'machineModel:id,name,machine_brand_id',
                'machineModel.machineBrand:id,name',
            ])
            ->withCount('downloads')
            ->orderByDesc('last_seen_at')
            ->get();

        return Inertia::render('Marketplace/UnysisBoxes/Index', [
            'unysisBoxes' => $boxes,
            'customers' => Customer::orderBy('company')->get(['id', 'company']),
            'machineModels' => $this->machineModelOptions(),
            'statuses' => UnysisBox::STATUSES,
            'canEdit' => $request->user()->can('update', new UnysisBox),
            'canBlock' => $request->user()->can('block', new UnysisBox),
            'canDelete' => $request->user()->can('delete', new UnysisBox),
        ]);
    }

    public function show(Request $request, UnysisBox $unysisBox)
    {
        Gate::authorize('view', $unysisBox);

        $unysisBox->load([
            'customer:id,company',
            'machineModel:id,name,machine_brand_id',
            'machineModel.machineBrand:id,name',
            'firstUser:id,name,email',
        ]);
        $unysisBox->loadCount('downloads');

        return Inertia::render('Marketplace/UnysisBoxes/Show', [
            'unysisBox' => $unysisBox,
            'installed' => $this->installed->forBox($unysisBox),
            'downloads' => $this->downloadPayload($unysisBox),
            'canEdit' => $request->user()->can('update', $unysisBox),
            'canBlock' => $request->user()->can('block', $unysisBox),
            'canDelete' => $request->user()->can('delete', $unysisBox),
        ]);
    }

    public function edit(UnysisBox $unysisBox)
    {
        Gate::authorize('update', $unysisBox);

        $unysisBox->load('customer:id,company');

        return Inertia::render('Marketplace/UnysisBoxes/Edit', [
            'unysisBox' => $unysisBox,
            'machineModels' => $this->machineModelOptions(),
        ]);
    }

    /**
     * The label only. `status` never moves through here — see block/unblock/activate.
     */
    public function update(UpdateUnysisBoxRequest $request, UnysisBox $unysisBox)
    {
        $validated = $request->validated();

        $unysisBox->update([
            'name' => $validated['name'] ?? null,
            'location' => $validated['location'] ?? null,
            'machine_model_id' => $validated['machine_model_id'] ?? null,
        ]);

        ActivityLogger::log('update', "Updated UNYSIS Box: {$unysisBox->motherboard_uuid}", $unysisBox);

        return redirect()->route('admin.marketplace.unysis-boxes.show', $unysisBox->id)
            ->with('success', 'UNYSIS Box updated successfully.');
    }

    /**
     * Block the box and cut it off now.
     *
     * ResolveUnysisBox already refuses a blocked box on the next request, but the
     * token is what proves which box is calling, so it goes too: every Sanctum
     * token is named after the motherboard UUID it was issued for
     * (docs/adr/0002), which makes "every token for this box" an exact lookup.
     */
    public function block(UnysisBox $unysisBox)
    {
        Gate::authorize('block', $unysisBox);

        $unysisBox->update(['status' => UnysisBox::STATUS_BLOCKED]);

        $revoked = PersonalAccessToken::query()
            ->where('name', $unysisBox->motherboard_uuid)
            ->delete();

        ActivityLogger::log('block', "Blocked UNYSIS Box: {$unysisBox->motherboard_uuid}", $unysisBox);

        return back()->with('success', "UNYSIS Box blocked. {$revoked} RPA-TOOL session(s) revoked.");
    }

    /** Lift a block: the box may sign in and pull again. */
    public function unblock(UnysisBox $unysisBox)
    {
        Gate::authorize('block', $unysisBox);

        $unysisBox->update(['status' => UnysisBox::STATUS_ACTIVE]);

        ActivityLogger::log('unblock', "Unblocked UNYSIS Box: {$unysisBox->motherboard_uuid}", $unysisBox);

        return back()->with('success', 'UNYSIS Box unblocked. It must sign in again to get a new session.');
    }

    /**
     * Acknowledge a newly auto-registered box: pending -> active. Deliberately a
     * different action from unblock, which is the security one; this is the
     * Team Member saying "yes, I know this box".
     */
    public function activate(UnysisBox $unysisBox)
    {
        Gate::authorize('update', $unysisBox);

        if ($unysisBox->isBlocked()) {
            return back()->with('error', 'This UNYSIS Box is blocked. Unblock it instead.');
        }

        $unysisBox->update(['status' => UnysisBox::STATUS_ACTIVE]);

        ActivityLogger::log('activate', "Acknowledged UNYSIS Box: {$unysisBox->motherboard_uuid}", $unysisBox);

        return back()->with('success', 'UNYSIS Box marked active.');
    }

    /**
     * Remove a box. Downloads are the record of what a box installed and are
     * never deleted, so a box that has any is kept.
     */
    public function destroy(UnysisBox $unysisBox)
    {
        Gate::authorize('delete', $unysisBox);

        if ($unysisBox->downloads()->exists()) {
            return back()->with('error', 'This UNYSIS Box has recorded Downloads. Block it instead — the Download history is kept.');
        }

        $uuid = $unysisBox->motherboard_uuid;

        PersonalAccessToken::query()->where('name', $uuid)->delete();
        $unysisBox->delete();

        ActivityLogger::log('delete', "Deleted UNYSIS Box: {$uuid}");

        return redirect()->route('admin.marketplace.unysis-boxes.index')
            ->with('success', 'UNYSIS Box deleted.');
    }

    /**
     * The full Download log for this box, newest first, paginated server-side —
     * unlike the entry pages, which show only the latest 50.
     */
    private function downloadPayload(UnysisBox $unysisBox)
    {
        $page = Download::query()
            ->where('unysis_box_id', $unysisBox->getKey())
            ->with(['user:id,name', 'revision:id,number,status'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        // Two extra queries for the whole page rather than a morphTo eager load,
        // which would not reach soft-deleted entries.
        $names = DownloadPresenter::entryNames(collect($page->items()));

        return $page->through(fn (Download $download) => DownloadPresenter::present($download, $names));
    }

    private function machineModelOptions()
    {
        return MachineModel::with('machineBrand:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'machine_brand_id']);
    }
}
