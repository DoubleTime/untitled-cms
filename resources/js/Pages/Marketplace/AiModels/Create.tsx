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

interface AiModelCreateProps extends PageProps {
    machineModels: MachineModelOption[];
    customers: CustomerOption[];
}

const NO_CUSTOMER = '__none__';

export default function Create({ machineModels, customers }: AiModelCreateProps) {
    const { data, setData, post, processing, errors, isDirty } = useForm({
        machine_model_id: '',
        customer_id: '',
        name: '',
        description: '',
        framework: '',
        input_size: '',
        labels: '',
        notes: '',
    });

    const submit = () => post(route('admin.marketplace.ai-models.store'));

    return (
        <AuthenticatedLayout header="Create AI Model">
            <Head title="Create AI Model" />

            <div className="flex flex-col gap-4 pb-20">
                <h1 className="text-2xl font-bold">Create AI Model</h1>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>AI Model</CardTitle>
                        <CardDescription>
                            A trained inference model published on its own. The name must be unique within its
                            Machine Model; the slug is generated from it. Upload the first Revision from the detail
                            page once this is saved.
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
                                rows={3}
                            />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="framework">Framework</Label>
                                <Input
                                    id="framework"
                                    type="text"
                                    value={data.framework}
                                    onChange={(e) => setData('framework', e.target.value)}
                                    className="mt-1 block w-full"
                                    placeholder="keras"
                                />
                            </div>
                            <div>
                                <Label htmlFor="input_size">Input size</Label>
                                <Input
                                    id="input_size"
                                    type="text"
                                    value={data.input_size}
                                    onChange={(e) => setData('input_size', e.target.value)}
                                    className="mt-1 block w-full"
                                    placeholder="224x224"
                                />
                            </div>
                        </div>

                        <div>
                            <Label htmlFor="labels">Labels</Label>
                            <Textarea
                                id="labels"
                                value={data.labels}
                                onChange={(e) => setData('labels', e.target.value)}
                                className="mt-1 block w-full"
                                rows={3}
                                placeholder="ok,ng"
                            />
                        </div>

                        <div>
                            <Label htmlFor="notes">Notes</Label>
                            <Textarea
                                id="notes"
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                                className="mt-1 block w-full"
                                rows={3}
                            />
                        </div>
                    </CardContent>
                </Card>
            </div>

            <StickyFormFooter isSaving={processing} isDirty={isDirty} onSave={submit} />
        </AuthenticatedLayout>
    );
}
