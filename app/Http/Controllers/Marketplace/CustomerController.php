<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\StoreCustomerRequest;
use App\Http\Requests\Marketplace\UpdateCustomerRequest;
use App\Models\Customer;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Customer::class);

        $customers = Customer::withCount(['users', 'unysisBoxes'])
            ->orderBy('company')
            ->get();

        return Inertia::render('Marketplace/Customers/Index', [
            'customers' => $customers,
            'canCreate' => $request->user()->can('create', Customer::class),
            'canEdit' => $request->user()->can('update', new Customer),
            'canDelete' => $request->user()->can('delete', new Customer),
        ]);
    }

    public function create()
    {
        Gate::authorize('create', Customer::class);

        return Inertia::render('Marketplace/Customers/Create');
    }

    public function store(StoreCustomerRequest $request)
    {
        $validated = $request->validated();
        $validated['is_active'] = $validated['is_active'] ?? true;

        $customer = Customer::create($validated);

        ActivityLogger::log('create', "Created Customer: {$customer->company}", $customer);

        return redirect()->route('admin.marketplace.customers.index')
            ->with('success', 'Customer created successfully.');
    }

    /**
     * The Customer detail page, including its Customer Users.
     */
    public function show(Request $request, Customer $customer)
    {
        Gate::authorize('view', $customer);

        $customer->loadCount('unysisBoxes');

        return Inertia::render('Marketplace/Customers/Show', [
            'customer' => $customer,
            'customerUsers' => $customer->users()
                ->withCount('tokens')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'is_active', 'created_at', 'customer_id']),
            // The Customer's UNYSIS Boxes, for the UNYSIS Boxes tab (Phase 5).
            'unysisBoxes' => $customer->unysisBoxes()
                ->orderByDesc('last_seen_at')
                ->get(['id', 'motherboard_uuid', 'name', 'location', 'status', 'last_seen_at', 'customer_id']),
            'canViewUnysisBoxes' => $request->user()->hasPermission('unysis_boxes.view'),
            'canEdit' => $request->user()->can('update', $customer),
            'canDelete' => $request->user()->can('delete', $customer),
        ]);
    }

    public function edit(Customer $customer)
    {
        Gate::authorize('update', $customer);

        return Inertia::render('Marketplace/Customers/Edit', [
            'customer' => $customer,
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        $validated = $request->validated();
        $validated['is_active'] = $validated['is_active'] ?? $customer->is_active;

        $customer->update($validated);

        ActivityLogger::log('update', "Updated Customer: {$customer->company}", $customer);

        return redirect()->route('admin.marketplace.customers.index')
            ->with('success', 'Customer updated successfully.');
    }

    public function destroy(Customer $customer)
    {
        Gate::authorize('delete', $customer);

        // No cascade: a Customer that still owns Customer Users or UNYSIS Boxes is kept.
        if ($customer->users()->exists() || $customer->unysisBoxes()->exists()) {
            return redirect()->route('admin.marketplace.customers.index')
                ->with('error', 'This Customer still has Customer Users or UNYSIS Boxes. Remove them first.');
        }

        $company = $customer->company;
        $customer->delete();

        ActivityLogger::log('delete', "Deleted Customer: {$company}");

        return redirect()->route('admin.marketplace.customers.index')
            ->with('success', 'Customer deleted successfully.');
    }
}
