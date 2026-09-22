import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { StickyFormFooter } from '@/Components/Common/FormLayouts';
import type { UnysisBox, PageProps } from '@/types';
import type { MachineModelOption } from '@/Components/Marketplace/CatalogueFilters';

const NO_MACHINE_MODEL = '__none__';

interface UnysisBoxEditProps extends PageProps {
    unysisBox: UnysisBox;
    machineModels: MachineModelOption[];
}

/**
 * Only the label is editable. The motherboard UUID is what the box reports and
 * what its token is named after, and the status moves through block / unblock /
 * activate on the index and detail pages.
 */
export default function Edit({ unysisBox, machineModels }: UnysisBoxEditProps) {
    const { data, setData, put, processing, errors, isDirty } = useForm({
        name: unysisBox.name ?? '',
        location: unysisBox.location ?? '',
        machine_model_id: unysisBox.machine_model_id ?? '',
    });

    const submit = () => put(route('admin.marketplace.unysis-boxes.update', unysisBox.id));

    const sorted = [...machineModels].sort((a, b) => {
        const brand = (a.machine_brand?.name ?? '').localeCompare(b.machine_brand?.name ?? '');
        return brand !== 0 ? brand : a.name.localeCompare(b.name);
    });

    return (
        <AuthenticatedLayout header="Edit UNYSIS Box">
            <Head title={`Edit ${unysisBox.name || unysisBox.motherboard_uuid}`} />

            <div className="flex flex-col gap-4 pb-20">
                <h1 className="text-2xl font-bold">Edit UNYSIS Box</h1>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>{unysisBox.customer?.company ?? 'UNYSIS Box'}</CardTitle>
                        <CardDescription>
                            Motherboard UUID:{' '}
                            <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                                {unysisBox.motherboard_uuid}
                            </code>{' '}
                            — reported by the box itself and never editable. Blocking and unblocking live on the
                            UNYSIS Box detail page.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div>
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                type="text"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                className="mt-1 block w-full"
                                placeholder="Line 3 inspection box"
                            />
                            {errors.name && <p className="mt-1 text-sm text-destructive">{errors.name}</p>}
                        </div>

                        <div>
                            <Label htmlFor="location">Location</Label>
                            <Input
                                id="location"
                                type="text"
                                value={data.location}
                                onChange={(e) => setData('location', e.target.value)}
                                className="mt-1 block w-full"
                                placeholder="Penang — Plant 2"
                            />
                            {errors.location && (
                                <p className="mt-1 text-sm text-destructive">{errors.location}</p>
                            )}
                        </div>

                        <div>
                            <Label htmlFor="machine_model_id">Machine Model (optional)</Label>
                            <Select
                                value={data.machine_model_id === '' ? NO_MACHINE_MODEL : data.machine_model_id}
                                onValueChange={(value) =>
                                    setData('machine_model_id', value === NO_MACHINE_MODEL ? '' : value)
                                }
                            >
                                <SelectTrigger id="machine_model_id" className="mt-1 w-full">
                                    <SelectValue placeholder="No Machine Model" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NO_MACHINE_MODEL}>No Machine Model</SelectItem>
                                    {sorted.map((model) => (
                                        <SelectItem key={model.id} value={model.id}>
                                            {model.machine_brand?.name
                                                ? `${model.machine_brand.name} — ${model.name}`
                                                : model.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors.machine_model_id && (
                                <p className="mt-1 text-sm text-destructive">{errors.machine_model_id}</p>
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>

            <StickyFormFooter isSaving={processing} isDirty={isDirty} onSave={submit} />
        </AuthenticatedLayout>
    );
}
