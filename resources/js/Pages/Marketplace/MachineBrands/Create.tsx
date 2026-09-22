import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { StickyFormFooter } from '@/Components/Common/FormLayouts';

export default function Create() {
    const { data, setData, post, processing, errors, isDirty } = useForm({
        name: '',
    });

    const submit = () => post(route('admin.marketplace.machine-brands.store'));

    return (
        <AuthenticatedLayout header="Create Machine Brand">
            <Head title="Create Machine Brand" />

            <div className="flex flex-col gap-4 pb-20">
                <h1 className="text-2xl font-bold">Create Machine Brand</h1>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Machine Brand</CardTitle>
                        <CardDescription>
                            The manufacturer of a Machine Model. The slug is generated from the name.
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

            <StickyFormFooter isSaving={processing} isDirty={isDirty} onSave={submit} />
        </AuthenticatedLayout>
    );
}
