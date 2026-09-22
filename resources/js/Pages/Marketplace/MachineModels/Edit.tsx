import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Switch } from '@/Components/ui/switch';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { StickyFormFooter } from '@/Components/Common/FormLayouts';
import { MachineBrand, MachineModel, PageProps } from '@/types';

interface MachineModelEditProps extends PageProps {
    machineModel: MachineModel;
    machineBrands: Pick<MachineBrand, 'id' | 'name'>[];
}

export default function Edit({ machineModel, machineBrands }: MachineModelEditProps) {
    const { canDelete } = usePage<PageProps>().props;
    const { data, setData, put, processing, errors, isDirty } = useForm({
        machine_brand_id: machineModel.machine_brand_id,
        name: machineModel.name,
        description: machineModel.description ?? '',
        is_active: machineModel.is_active,
    });

    const submit = () => put(route('admin.marketplace.machine-models.update', machineModel.id));

    const destroy = () => {
        if (confirm(`Delete the Machine Model "${machineModel.name}"?`)) {
            router.delete(route('admin.marketplace.machine-models.destroy', machineModel.id));
        }
    };

    return (
        <AuthenticatedLayout header="Edit Machine Model">
            <Head title={`Edit ${machineModel.name}`} />

            <div className="flex flex-col gap-4 pb-20">
                <h1 className="text-2xl font-bold">Edit Machine Model</h1>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Machine Model</CardTitle>
                        <CardDescription>
                            Slug: <code className="bg-muted px-1.5 py-0.5 rounded text-xs">{machineModel.slug}</code>
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div>
                            <Label htmlFor="machine_brand_id">Machine Brand</Label>
                            <Select
                                value={data.machine_brand_id}
                                onValueChange={(value) => setData('machine_brand_id', value)}
                            >
                                <SelectTrigger id="machine_brand_id" className="mt-1 w-full">
                                    <SelectValue placeholder="Select a Machine Brand" />
                                </SelectTrigger>
                                <SelectContent>
                                    {machineBrands.map((brand) => (
                                        <SelectItem key={brand.id} value={brand.id}>
                                            {brand.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors.machine_brand_id && (
                                <p className="text-sm text-destructive mt-1">{errors.machine_brand_id}</p>
                            )}
                        </div>

                        <div>
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                type="text"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                className="mt-1 block w-full"
                                required
                            />
                            {errors.name && <p className="text-sm text-destructive mt-1">{errors.name}</p>}
                        </div>

                        <div>
                            <Label htmlFor="description">Description</Label>
                            <Textarea
                                id="description"
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                className="mt-1 block w-full"
                                rows={4}
                            />
                            {errors.description && (
                                <p className="text-sm text-destructive mt-1">{errors.description}</p>
                            )}
                        </div>

                        <div className="flex items-center justify-between">
                            <Label htmlFor="is_active">Active</Label>
                            <Switch
                                id="is_active"
                                checked={data.is_active}
                                onCheckedChange={(checked) => setData('is_active', checked)}
                            />
                        </div>
                    </CardContent>
                </Card>
            </div>

            <StickyFormFooter
                isSaving={processing}
                isDirty={isDirty}
                onSave={submit}
                canDelete={!!canDelete}
                onDelete={destroy}
            />
        </AuthenticatedLayout>
    );
}
