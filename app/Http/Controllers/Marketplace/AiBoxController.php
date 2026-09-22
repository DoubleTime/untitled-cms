<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\UpdateAiBoxRequest;
use App\Models\AiBox;
use App\Models\Customer;
use App\Models\Download;
use App\Models\MachineModel;
use App\Services\ActivityLogger;
use App\Services\Marketplace\AiBoxInstalledService;
use App\Support\DownloadPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * AI Boxes admin.
 *
 * Boxes are never created here — RPA-TOOL auto-registers them on first login
 * (docs/adr/0002). A Team Member labels one, marks a pending box active, blocks
 * a box that should no longer pull, and removes one that never downloaded
 * anything.
 */
class AiBoxController extends Controller
{
    public function __construct(private AiBoxInstalledService $installed) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', AiBox::class);

        $boxes = AiBox::query()
            ->with([
                'customer:id,company',
                'machineModel:id,name,machine_brand_id',
                'machineModel.machineBrand:id,name',
            ])
            ->withCount('downloads')
            ->orderByDesc('last_seen_at')
            ->get();

        return Inertia::render('Marketplace/AiBoxes/Index', [
            'aiBoxes' => $boxes,
            'customers' => Customer::orderBy('company')->get(['id', 'company']),
            'machineModels' => $this->machineModelOptions(),
            'statuses' => AiBox::STATUSES,
            'canEdit' => $request->user()->can('update', new AiBox),
            'canBlock' => $request->user()->can('block', new AiBox),
            'canDelete' => $request->user()->can('delete', new AiBox),
        ]);
    }

    public function show(Request $request, AiBox $aiBox)
    {
        Gate::authorize('view', $aiBox);

        $aiBox->load([
            'customer:id,company',
            'machineModel:id,name,machine_brand_id',
            'machineModel.machineBrand:id,name',
            'firstUser:id,name,email',
        ]);
        $aiBox->loadCount('downloads');

        return Inertia::render('Marketplace/AiBoxes/Show', [
            'aiBox' => $aiBox,
            'installed' => $this->installed->forBox($aiBox),
            'downloads' => $this->downloadPayload($aiBox),
            'canEdit' => $request->user()->can('update', $aiBox),
            'canBlock' => $request->user()->can('block', $aiBox),
            'canDelete' => $request->user()->can('delete', $aiBox),
        ]);
    }

    public function edit(AiBox $aiBox)
    {
        Gate::authorize('update', $aiBox);

        $aiBox->load('customer:id,company');

        return Inertia::render('Marketplace/AiBoxes/Edit', [
            'aiBox' => $aiBox,
            'machineModels' => $this->machineModelOptions(),
        ]);
    }

    /**
     * The label only. `status` never moves through here — see block/unblock/activate.
     */
    public function update(UpdateAiBoxRequest $request, AiBox $aiBox)
    {
        $validated = $request->validated();

        $aiBox->update([
            'name' => $validated['name'] ?? null,
            'location' => $validated['location'] ?? null,
            'machine_model_id' => $validated['machine_model_id'] ?? null,
        ]);

        ActivityLogger::log('update', "Updated AI Box: {$aiBox->motherboard_uuid}", $aiBox);

        return redirect()->route('admin.marketplace.ai-boxes.show', $aiBox->id)
            ->with('success', 'AI Box updated successfully.');
    }

    /**
     * Block the box and cut it off now.
     *
     * ResolveAiBox already refuses a blocked box on the next request, but the
     * token is what proves which box is calling, so it goes too: every Sanctum
     * token is named after the motherboard UUID it was issued for
     * (docs/adr/0002), which makes "every token for this box" an exact lookup.
     */
    public function block(AiBox $aiBox)
    {
        Gate::authorize('block', $aiBox);

        $aiBox->update(['status' => AiBox::STATUS_BLOCKED]);

        $revoked = PersonalAccessToken::query()
            ->where('name', $aiBox->motherboard_uuid)
            ->delete();

        ActivityLogger::log('block', "Blocked AI Box: {$aiBox->motherboard_uuid}", $aiBox);

        return back()->with('success', "AI Box blocked. {$revoked} RPA-TOOL session(s) revoked.");
    }

    /** Lift a block: the box may sign in and pull again. */
    public function unblock(AiBox $aiBox)
    {
        Gate::authorize('block', $aiBox);

        $aiBox->update(['status' => AiBox::STATUS_ACTIVE]);

        ActivityLogger::log('unblock', "Unblocked AI Box: {$aiBox->motherboard_uuid}", $aiBox);

        return back()->with('success', 'AI Box unblocked. It must sign in again to get a new session.');
    }

    /**
     * Acknowledge a newly auto-registered box: pending -> active. Deliberately a
     * different action from unblock, which is the security one; this is the
     * Team Member saying "yes, I know this box".
     */
    public function activate(AiBox $aiBox)
    {
        Gate::authorize('update', $aiBox);

        if ($aiBox->isBlocked()) {
            return back()->with('error', 'This AI Box is blocked. Unblock it instead.');
        }

        $aiBox->update(['status' => AiBox::STATUS_ACTIVE]);

        ActivityLogger::log('activate', "Acknowledged AI Box: {$aiBox->motherboard_uuid}", $aiBox);

        return back()->with('success', 'AI Box marked active.');
    }

    /**
     * Remove a box. Downloads are the record of what a box installed and are
     * never deleted, so a box that has any is kept.
     */
    public function destroy(AiBox $aiBox)
    {
        Gate::authorize('delete', $aiBox);

        if ($aiBox->downloads()->exists()) {
            return back()->with('error', 'This AI Box has recorded Downloads. Block it instead — the Download history is kept.');
        }

        $uuid = $aiBox->motherboard_uuid;

        PersonalAccessToken::query()->where('name', $uuid)->delete();
        $aiBox->delete();

        ActivityLogger::log('delete', "Deleted AI Box: {$uuid}");

        return redirect()->route('admin.marketplace.ai-boxes.index')
            ->with('success', 'AI Box deleted.');
    }

    /**
     * The full Download log for this box, newest first, paginated server-side —
     * unlike the entry pages, which show only the latest 50.
     */
    private function downloadPayload(AiBox $aiBox)
    {
        $page = Download::query()
            ->where('ai_box_id', $aiBox->getKey())
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
