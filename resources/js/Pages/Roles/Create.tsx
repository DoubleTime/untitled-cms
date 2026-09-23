import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Checkbox } from '@/Components/ui/checkbox';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { PageProps } from '@/types';
import { FormSplitLayout, StickyFormFooter } from '@/Components/Common/FormLayouts';

interface RoleCreateProps extends PageProps {
    availablePermissions: string[];
}

export default function Create({ auth, availablePermissions }: RoleCreateProps) {
    const { data, setData, post, processing, errors, isDirty } = useForm({
        name: '',
        slug: '',
        permissions: [] as string[],
    });

    const submit = () => {
        post(route('admin.roles.store'));
    };

    const handlePermissionChange = (permission: string, checked: boolean) => {
        if (checked) {
            setData('permissions', [...data.permissions, permission]);
        } else {
            setData('permissions', data.permissions.filter((p) => p !== permission));
        }
    };

    // Auto-generate slug from name
    const handleNameChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const name = e.target.value;
        setData((prev) => ({
            ...prev,
            name: name,
            slug: name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)+/g, '')
        }));
    };

    return (
        <AuthenticatedLayout header="Create Role">
            <Head title="Create Role" />

            <div className="flex flex-col gap-4">
                <h1 className="text-2xl font-bold">Create Role</h1>

                <FormSplitLayout
                    sidebar={
                        <Card>
                            <CardHeader>
                                <CardTitle>Role Details</CardTitle>
                                <CardDescription>Basic information</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div>
                                    <Label htmlFor="name">Role Name</Label>
                                    <Input
                                        id="name"
                                        type="text"
                                        value={data.name}
                                        onChange={handleNameChange}
                                        className="mt-1 block w-full"
                                        required
                                        autoFocus
                                    />
                                    {errors.name && <p className="text-sm text-destructive mt-1">{errors.name}</p>}
                                </div>

                                <div>
                                    <Label htmlFor="slug">Slug</Label>
                                    <Input
                                        id="slug"
                                        type="text"
                                        value={data.slug}
                                        onChange={(e) => setData('slug', e.target.value)}
                                        className="mt-1 block w-full bg-muted text-muted-foreground"
                                        required
                                    />
                                    {errors.slug && <p className="text-sm text-destructive mt-1">{errors.slug}</p>}
                                </div>
                            </CardContent>
                        </Card>
                    }
                >
                    <Card>
                        <CardHeader>
                            <CardTitle>Permissions</CardTitle>
                            <CardDescription>Select permissions for this role</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                {availablePermissions.map((permission) => (
                                    <div key={permission} className="flex items-center space-x-2">
                                        <Checkbox
                                            id={`perm-${permission}`}
                                            checked={data.permissions.includes(permission)}
                                            onCheckedChange={(checked) => handlePermissionChange(permission, checked as boolean)}
                                        />
                                        <label
                                            htmlFor={`perm-${permission}`}
                                            className="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                                        >
                                            {permission}
                                        </label>
                                    </div>
                                ))}
                            </div>
                            {errors.permissions && <p className="text-sm text-destructive mt-1">{errors.permissions}</p>}
                        </CardContent>
                    </Card>
                </FormSplitLayout>
            </div>

            <StickyFormFooter
                isSaving={processing}
                isDirty={isDirty}
                onSave={submit}
            />
        </AuthenticatedLayout>
    );
}
