import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { StickyFormFooter } from '@/Components/Common/FormLayouts';
import { PageProps } from '@/types';
import type { CustomerOption, MachineModelOption } from '@/Components/Marketplace/CatalogueFilters';

interface ScriptCreateProps extends PageProps {
    machineModels: MachineModelOption[];
    customers: CustomerOption[];
}

const NO_CUSTOMER = '__none__';

export default function Create({ machineModels, customers }: ScriptCreateProps) {
    const { data, setData, post, processing, errors, isDirty } = useForm({
        machine_model_id: '',
        customer_id: '',
        name: '',
        description: '',
    });

    const submit = () => post(route('admin.marketplace.scripts.store'));

    return (
        <AuthenticatedLayout header="Create FlowChart Script">
            <Head title="Create FlowChart Script" />

            <div className="flex flex-col gap-4 pb-20">
                <h1 className="text-2xl font-bold">Create FlowChart Script</h1>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>FlowChart Script</CardTitle>
                        <CardDescription>
                            A packaged automation sequence for one Machine Model. The name must be unique within
                            that Machine Model; the slug is generated from it. Upload the first Revision from the
                            detail page once this is saved.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div>
                            <Label htmlFor="machine_model_id">Machine Model</Label>
                            <Select
                                value={data.machine_model_id}
                                onValueChange={(value) => setData('machine_model_id', value)}
                            >
                                <SelectTrigger id="machine_model_id" className="mt-1 w-full">
                                    <SelectValue placeholder="Select a Machine Model" />
                                </SelectTrigger>
                                <SelectContent>
                                    {machineModels.map((model) => (
                                        <SelectItem key={model.id} value={model.id}>
                                            {model.machine_brand?.name
                                                ? `${model.machine_brand.name} — ${model.name}`
                                                : model.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors.machine_model_id && (
                                <p className="text-sm text-destructive mt-1">{errors.machine_model_id}</p>
                            )}
                        </div>

                        <div>
                            <Label htmlFor="customer_id">Customer (optional)</Label>
                            <Select
                                value={data.customer_id === '' ? NO_CUSTOMER : data.customer_id}
                                onValueChange={(value) =>
                                    setData('customer_id', value === NO_CUSTOMER ? '' : value)
                                }
                            >
                                <SelectTrigger id="customer_id" className="mt-1 w-full">
                                    <SelectValue placeholder="No Customer" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NO_CUSTOMER}>No Customer</SelectItem>
                                    {customers.map((customer) => (
                                        <SelectItem key={customer.id} value={customer.id}>
                                            {customer.company}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-xs text-muted-foreground mt-1">
                                The Customer label is a secondary filter only — every Customer User sees the whole
                                catalogue.
                            </p>
                            {errors.customer_id && (
                                <p className="text-sm text-destructive mt-1">{errors.customer_id}</p>
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
                    </CardContent>
                </Card>
            </div>

            <StickyFormFooter isSaving={processing} isDirty={isDirty} onSave={submit} />
        </AuthenticatedLayout>
    );
}
