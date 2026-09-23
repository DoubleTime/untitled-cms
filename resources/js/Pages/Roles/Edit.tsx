import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { Checkbox } from '@/Components/ui/checkbox';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { PageProps } from '@/types';
import { FormSplitLayout, StickyFormFooter } from '@/Components/Common/FormLayouts';
import { useState, useEffect } from 'react';
import { Maximize2, Minimize2 } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/Components/ui/alert-dialog";
import { router } from '@inertiajs/react';

interface Role {
    id: string;
    name: string;
    slug: string;
    permissions: string[];
    is_active: boolean;
}

interface RoleEditProps extends PageProps {
    role: Role;
    availablePermissions: string[];
}

export default function Edit({ auth, role, availablePermissions }: RoleEditProps) {
    const [showDeleteDialog, setShowDeleteDialog] = useState(false);
    const [isExpanded, setIsExpanded] = useState(() => {
        const saved = localStorage.getItem('role_edit_expand_sidebar');
        return saved ? JSON.parse(saved) : false;
    });

    useEffect(() => {
        localStorage.setItem('role_edit_expand_sidebar', JSON.stringify(isExpanded));
    }, [isExpanded]);

    const { data, setData, put, processing, errors, isDirty } = useForm({
        name: role.name,
        slug: role.slug,
        permissions: role.permissions || [],
        is_active: role.is_active ?? true,
    });

    useEffect(() => {
        if (!data.slug && data.name) {
            setData('slug', data.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)+/g, ''));
        }
    }, []);

    const handleNameChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const name = e.target.value;
        setData((prev) => ({
            ...prev,
            name: name,
            slug: name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)+/g, '')
        }));
    };

    const submit = () => {
        put(route('admin.roles.update', role.id));
    };

    const handleDelete = () => {
        router.delete(route('admin.roles.destroy', role.id));
    };

    // Group permissions
    const permissionGroups = availablePermissions.reduce((groups, permission) => {
        const [resource] = permission.split('.');
        if (!groups[resource]) {
            groups[resource] = [];
        }
        groups[resource].push(permission);
        return groups;
    }, {} as Record<string, string[]>);

    const toggleGroup = (group: string, checked: boolean) => {
        const groupPermissions = permissionGroups[group];
        let newPermissions = [...data.permissions];

        if (checked) {
            // Add all permissions from group that aren't already selected
            const toAdd = groupPermissions.filter(p => !newPermissions.includes(p));
            newPermissions = [...newPermissions, ...toAdd];
        } else {
            // Remove all permissions from group
            newPermissions = newPermissions.filter(p => !groupPermissions.includes(p));
        }
        setData('permissions', newPermissions);
    };

    const handlePermissionChange = (permission: string, checked: boolean) => {
        if (checked) {
            setData('permissions', [...data.permissions, permission]);
        } else {
            setData('permissions', data.permissions.filter((p) => p !== permission));
        }
    };

    return (
        <AuthenticatedLayout header="Edit Role">
            <Head title="Edit Role" />

            <div className="flex flex-col gap-4">
                <div className="flex items-center justify-end">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setIsExpanded(!isExpanded)}
                        className="text-muted-foreground hover:text-foreground"
                    >
                        {isExpanded ? (
                            <>
                                <Minimize2 className="h-4 w-4 mr-2" />
                                Collapse Sidebar
                            </>
                        ) : (
                            <>
                                <Maximize2 className="h-4 w-4 mr-2" />
                                Expand Content
                            </>
                        )}
                    </Button>
                </div>

                <FormSplitLayout
                    isExpanded={isExpanded}
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

                                <div className="flex items-center space-x-2 border p-3 rounded-md">
                                    <Switch
                                        id="is_active"
                                        checked={data.is_active}
                                        onCheckedChange={(checked) => {
                                            setData('is_active', checked);
                                            router.put(route('admin.roles.update', { id: role.id, stay: 1 }), {
                                                ...data,
                                                is_active: checked,
                                                stay: 1
                                            }, { preserveScroll: true });
                                        }}
                                        disabled={role.slug === 'admin'}
                                        className="scale-75 origin-left"
                                    />
                                    <Label htmlFor="is_active" className="cursor-pointer flex-1">
                                        {data.is_active ? 'Active' : 'Inactive'}
                                    </Label>
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
                                        disabled
                                    />
                                    <p className="text-[0.8rem] text-muted-foreground">
                                        Auto-generated from name
                                    </p>
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
                            <div className="space-y-6">
                                {Object.entries(permissionGroups).map(([group, permissions]) => {
                                    const allSelected = permissions.every(p => data.permissions.includes(p));
                                    const someSelected = permissions.some(p => data.permissions.includes(p));
                                    const isIndeterminate = someSelected && !allSelected;

                                    return (
                                        <div key={group} className="space-y-3">
                                            <div className="flex items-center space-x-2 pb-2 border-b">
                                                <Checkbox
                                                    id={`group-${group}`}
                                                    checked={allSelected || (isIndeterminate ? "indeterminate" : false)}
                                                    onCheckedChange={(checked) => toggleGroup(group, checked as boolean)}
                                                />
                                                <label
                                                    htmlFor={`group-${group}`}
                                                    className="text-base font-semibold capitalize cursor-pointer"
                                                >
                                                    {group}
                                                </label>
                                            </div>
                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-2 pl-2">
                                                {permissions.map((permission) => (
                                                    <div key={permission} className="flex items-center space-x-2">
                                                        <Checkbox
                                                            id={`perm-${permission}`}
                                                            checked={data.permissions.includes(permission)}
                                                            onCheckedChange={(checked) => handlePermissionChange(permission, checked as boolean)}
                                                        />
                                                        <label
                                                            htmlFor={`perm-${permission}`}
                                                            className="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70 cursor-pointer"
                                                        >
                                                            {permission}
                                                        </label>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    );
                                })}
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
                canDelete={true}
                onDelete={() => setShowDeleteDialog(true)}
            />

            <AlertDialog open={showDeleteDialog} onOpenChange={setShowDeleteDialog}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Are you sure?</AlertDialogTitle>
                        <AlertDialogDescription>
                            This action cannot be undone. This will permanently delete the role.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={handleDelete} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
