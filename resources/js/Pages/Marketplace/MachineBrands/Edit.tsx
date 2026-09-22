import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { StickyFormFooter } from '@/Components/Common/FormLayouts';
import { MachineBrand, PageProps } from '@/types';

interface MachineBrandEditProps extends PageProps {
    machineBrand: MachineBrand;
}

export default function Edit({ machineBrand }: MachineBrandEditProps) {
    const { canDelete } = usePage<PageProps>().props;
    const { data, setData, put, processing, errors, isDirty } = useForm({
        name: machineBrand.name,
    });

    const submit = () => put(route('admin.marketplace.machine-brands.update', machineBrand.id));

    const destroy = () => {
        if (confirm(`Delete the Machine Brand "${machineBrand.name}"?`)) {
            router.delete(route('admin.marketplace.machine-brands.destroy', machineBrand.id));
        }
    };

    return (
        <AuthenticatedLayout header="Edit Machine Brand">
            <Head title={`Edit ${machineBrand.name}`} />

            <div className="flex flex-col gap-4 pb-20">
                <h1 className="text-2xl font-bold">Edit Machine Brand</h1>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Machine Brand</CardTitle>
                        <CardDescription>
                            Slug: <code className="bg-muted px-1.5 py-0.5 rounded text-xs">{machineBrand.slug}</code>
                            {' '}— regenerated from the name when it changes.
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
                                required
                                autoFocus
                            />
                            {errors.name && <p className="text-sm text-destructive mt-1">{errors.name}</p>}
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
