import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Switch } from '@/Components/ui/switch';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { FormSplitLayout, StickyFormFooter } from '@/Components/Common/FormLayouts';

export default function Create() {
    const { data, setData, post, processing, errors, isDirty } = useForm({
        code: '',
        company: '',
        contact_name: '',
        contact_email: '',
        contact_phone: '',
        notes: '',
        is_active: true,
    });

    const submit = () => post(route('admin.marketplace.customers.store'));

    return (
        <AuthenticatedLayout header="Create Customer">
            <Head title="Create Customer" />

            <div className="flex flex-col gap-4">
                <h1 className="text-2xl font-bold">Create Customer</h1>

                <FormSplitLayout
                    sidebar={
                        <Card>
                            <CardHeader>
                                <CardTitle>Status</CardTitle>
                                <CardDescription>
                                    Inactive Customers stay in the catalogue but are flagged in the admin.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
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
                    }
                >
                    <Card>
                        <CardHeader>
                            <CardTitle>Customer</CardTitle>
                            <CardDescription>
                                A company that owns UNYSIS Boxes, for example Inari.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <div>
                                <Label htmlFor="company">Company</Label>
                                <Input
                                    id="company"
                                    type="text"
                                    value={data.company}
                                    onChange={(e) => setData('company', e.target.value)}
                                    className="mt-1 block w-full"
                                    required
                                    autoFocus
                                />
                                {errors.company && <p className="text-sm text-destructive mt-1">{errors.company}</p>}
                            </div>

                            <div>
                                <Label htmlFor="code">Code</Label>
                                <Input
                                    id="code"
                                    type="text"
                                    value={data.code}
                                    onChange={(e) => setData('code', e.target.value)}
                                    className="mt-1 block w-full"
                                    required
                                />
                                {errors.code && <p className="text-sm text-destructive mt-1">{errors.code}</p>}
                            </div>

                            <div className="grid gap-6 md:grid-cols-2">
                                <div>
                                    <Label htmlFor="contact_name">Contact Name</Label>
                                    <Input
                                        id="contact_name"
                                        type="text"
                                        value={data.contact_name}
                                        onChange={(e) => setData('contact_name', e.target.value)}
                                        className="mt-1 block w-full"
                                    />
                                    {errors.contact_name && (
                                        <p className="text-sm text-destructive mt-1">{errors.contact_name}</p>
                                    )}
                                </div>

                                <div>
                                    <Label htmlFor="contact_email">Contact Email</Label>
                                    <Input
                                        id="contact_email"
                                        type="email"
                                        value={data.contact_email}
                                        onChange={(e) => setData('contact_email', e.target.value)}
                                        className="mt-1 block w-full"
                                    />
                                    {errors.contact_email && (
                                        <p className="text-sm text-destructive mt-1">{errors.contact_email}</p>
                                    )}
                                </div>
                            </div>

                            <div>
                                <Label htmlFor="contact_phone">Contact Phone</Label>
                                <Input
                                    id="contact_phone"
                                    type="text"
                                    value={data.contact_phone}
                                    onChange={(e) => setData('contact_phone', e.target.value)}
                                    className="mt-1 block w-full"
                                />
                                {errors.contact_phone && (
                                    <p className="text-sm text-destructive mt-1">{errors.contact_phone}</p>
                                )}
                            </div>

                            <div>
                                <Label htmlFor="notes">Notes</Label>
                                <Textarea
                                    id="notes"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    className="mt-1 block w-full"
                                    rows={4}
                                />
                                {errors.notes && <p className="text-sm text-destructive mt-1">{errors.notes}</p>}
                            </div>
                        </CardContent>
                    </Card>
                </FormSplitLayout>
            </div>

            <StickyFormFooter isSaving={processing} isDirty={isDirty} onSave={submit} />
        </AuthenticatedLayout>
    );
}
