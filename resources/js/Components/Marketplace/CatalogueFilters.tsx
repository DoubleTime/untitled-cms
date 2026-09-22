import type { Customer, MachineBrand, MachineModel } from '@/types';

export type MachineModelOption = Pick<MachineModel, 'id' | 'name' | 'machine_brand_id'> & {
    machine_brand?: Pick<MachineBrand, 'id' | 'name'> | null;
};

export type CustomerOption = Pick<Customer, 'id' | 'company'>;

interface FilterOption {
    label: string;
    value: string;
}

/**
 * Faceted filters shared by the Scripts and AI Models indexes.
 *
 * The Machine Model options are grouped by Machine Brand — the faceted filter is
 * a flat list, so the grouping is expressed by sorting on Brand and prefixing the
 * label with it. Machine Model is the primary axis of the catalogue (docs/adr/0001);
 * Customer is only a secondary filter.
 */
export function buildCatalogueFilters(
    machineModels: MachineModelOption[],
    customers: CustomerOption[]
): { column: string; title: string; options: FilterOption[] }[] {
    const machineModelOptions: FilterOption[] = [...machineModels]
        .sort((a, b) => {
            const brand = (a.machine_brand?.name ?? '').localeCompare(b.machine_brand?.name ?? '');
            return brand !== 0 ? brand : a.name.localeCompare(b.name);
        })
        .map((model) => ({
            label: `${model.machine_brand?.name ?? 'No Machine Brand'} — ${model.name}`,
            value: model.id,
        }));

    return [
        { column: 'machineModel', title: 'Machine Model', options: machineModelOptions },
        {
            column: 'customer',
            title: 'Customer',
            options: customers.map((customer) => ({ label: customer.company, value: customer.id })),
        },
    ];
}
