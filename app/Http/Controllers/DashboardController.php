<?php

namespace App\Http\Controllers;

use App\Services\Marketplace\DashboardStatsService;
use App\Support\CatalogueEntryType;
use Inertia\Inertia;

/**
 * The Marketplace dashboard.
 *
 * Every panel is gated on the `<resource>.view` permission that governs the page
 * it summarises, and a panel the Team Member cannot see is left out of the props
 * entirely rather than sent and hidden in the browser. Deciding that is all this
 * controller does — the figures come from
 * App\Services\Marketplace\DashboardStatsService.
 */
class DashboardController extends Controller
{
    public function __construct(private DashboardStatsService $stats) {}

    public function index()
    {
        $user = request()->user();

        $can = fn (string $permission) => (bool) $user?->hasPermission($permission);

        $seesCatalogue = $can('scripts.view') || $can('ai_models.view');

        return Inertia::render('Dashboard', [
            'cards' => [
                'scripts' => $can('scripts.view')
                    ? $this->stats->entryCard(CatalogueEntryType::SCRIPT)
                    : null,
                'aiModels' => $can('ai_models.view')
                    ? $this->stats->entryCard(CatalogueEntryType::AI_MODEL)
                    : null,
                'customers' => $can('customers.view') ? $this->stats->customerCard() : null,
                'unysisBoxes' => $can('unysis_boxes.view') ? $this->stats->boxCard() : null,
                'downloads' => $can('downloads.view') ? $this->stats->downloadCard() : null,
            ],
            'downloadsPerDay' => $can('downloads.view') ? $this->stats->downloadsPerDay() : null,
            'latestRevisions' => $seesCatalogue ? $this->stats->latestRevisions() : null,
            'recentBoxes' => $can('unysis_boxes.view') ? $this->stats->recentBoxes() : null,
        ]);
    }
}
