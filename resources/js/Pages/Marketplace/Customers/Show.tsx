import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Customer, CustomerUser, PageProps } from '@/types';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { DataTable, type DataTableColumnDef } from '@/Components/Common/DataTable';
import { DataTableColumnHeader } from '@/Components/Common/DataTableColumnHeader';
import { DataTableToolbar } from '@/Components/Common/DataTableToolbar';
import { Edit, KeyRound, MoreHorizontal, Plus, Power, ShieldOff } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useFlashToast } from '@/hooks/use-flash-toast';

interface CustomerShowProps extends PageProps {
    customer: Customer;
    customerUsers: CustomerUser[];
}

export default function Show({ customer, customerUsers }: CustomerShowProps) {
    useFlashToast();
    const { canEdit } = usePage<PageProps>().props;
    const [createOpen, setCreateOpen] = useState(false);

    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submitCustomerUser = () => {
        post(route('admin.marketplace.customers.users.store', customer.id), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setCreateOpen(false);
            },
        });
    };

    const columns = useMemo<DataTableColumnDef<CustomerUser>[]>(
        () => [
            {
                accessorKey: 'name',
                header: ({ column }) => <DataTableColumnHeader column={column} title="Name" />,
                cell: ({ row }) => <div className="font-medium">{row.getValue('name')}</div>,
            },
            {
                accessorKey: 'email',
                header: ({ column }) => <DataTableColumnHeader column={column} title="Email" />,
            },
            {
                id: 'status',
                accessorKey: 'is_active',
                header: ({ column }) => <DataTableColumnHeader column={column} title="Active" />,
                cell: ({ row }) =>
                    row.original.is_active ? (
                        <Badge variant="secondary">Active</Badge>
                    ) : (
                        <Badge variant="outline">Inactive</Badge>
                    ),
            },
            {
                accessorKey: 'created_at',
                header: ({ column }) => <DataTableColumnHeader column={column} title="Created" />,
                cell: ({ row }) => (
                    <span className="text-sm text-muted-foreground">
                        {row.original.created_at ? new Date(row.original.created_at).toLocaleDateString() : '—'}
                    </span>
                ),
            },
            {
                accessorKey: 'tokens_count',
                header: 'Sessions',
                cell: ({ row }) => <Badge variant="outline">{row.original.tokens_count ?? 0}</Badge>,
            },
            {
                id: 'actions',
                header: 'Actions',
                enableHiding: false,
                cell: ({ row }) => {
                    const user = row.original;

                    if (!canEdit) {
                        return null;
                    }

                    return (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" className="h-8 w-8 p-0">
                                    <span className="sr-only">Open menu</span>
                                    <MoreHorizontal className="h-4 w-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                <DropdownMenuItem
                                    onClick={() => {
                                        const verb = user.is_active ? 'Deactivate' : 'Reactivate';
                                        if (confirm(`${verb} the Customer User "${user.email}"?`)) {
                                            router.post(
                                                route('admin.marketplace.customers.users.toggle-active', [
                                                    customer.id,
                                                    user.id,
                                                ]),
                                                {},
                                                { preserveScroll: true }
                                            );
                                        }
                                    }}
                                >
                                    <Power className="mr-2 h-4 w-4" />
                                    {user.is_active ? 'Deactivate' : 'Reactivate'}
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    onClick={() => {
                                        if (confirm(`Revoke all RPA-TOOL sessions for "${user.email}"?`)) {
                                            router.post(
                                                route('admin.marketplace.customers.users.revoke-tokens', [
                                                    customer.id,
                                                    user.id,
                                                ]),
                                                {},
                                                { preserveScroll: true }
                                            );
                                        }
                                    }}
                                >
                                    <ShieldOff className="mr-2 h-4 w-4" /> Revoke all sessions
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    onClick={() => {
                                        if (confirm(`Send a password reset email to "${user.email}"?`)) {
                                            router.post(
                                                route('admin.marketplace.customers.users.send-password-reset', [
                                                    customer.id,
                                                    user.id,
                                                ]),
                                                {},
                                                { preserveScroll: true }
                                            );
                                        }
                                    }}
                                >
                                    <KeyRound className="mr-2 h-4 w-4" /> Send password reset
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    );
                },
            },
        ],
        [canEdit, customer.id]
    );

    return (
        <AuthenticatedLayout header={customer.company}>
            <Head title={customer.company} />

            <div className="flex flex-col gap-6">
                <div className="flex justify-between items-start">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">{customer.company}</h1>
                        <p className="text-muted-foreground">
                            {customer.name}
                            {customer.is_active ? '' : ' — inactive'}
                        </p>
                    </div>
                    {canEdit && (
                        <Link href={route('admin.marketplace.customers.edit', customer.id)}>
                            <Button variant="outline" size="sm">
                                <Edit className="mr-2 h-4 w-4" /> Edit Customer
                            </Button>
                        </Link>
                    )}
                </div>

                <Tabs defaultValue="users">
                    <TabsList>
                        <TabsTrigger value="users">Customer Users</TabsTrigger>
                        <TabsTrigger value="details">Details</TabsTrigger>
                    </TabsList>

                    <TabsContent value="users" className="mt-4 space-y-4">
                        <div className="flex justify-between items-center">
                            <p className="text-sm text-muted-foreground">
                                Customer Users sign in through RPA-TOOL only — they never reach the Marketplace
                                admin. Each live session is one AI Box token.
                            </p>
                            {canEdit && (
                                <Button
                                    size="sm"
                                    className="h-8 shrink-0"
                                    onClick={() => {
                                        clearErrors();
                                        setCreateOpen(true);
                                    }}
                                >
                                    <Plus className="mr-2 h-4 w-4" /> Create Customer User
                                </Button>
                            )}
                        </div>

                        <DataTable data={customerUsers} columns={columns}>
                            {({ table }) => <DataTableToolbar table={table} searchKey="name" />}
                        </DataTable>
                    </TabsContent>

                    <TabsContent value="details" className="mt-4">
                        <Card className="max-w-2xl">
                            <CardHeader>
                                <CardTitle>Details</CardTitle>
                                <CardDescription>Contact information and notes.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">Contact Name</span>
                                    <span>{customer.contact_name || '—'}</span>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">Contact Email</span>
                                    <span>{customer.contact_email || '—'}</span>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">Contact Phone</span>
                                    <span>{customer.contact_phone || '—'}</span>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">AI Boxes</span>
                                    <span>{customer.ai_boxes_count ?? 0}</span>
                                </div>
                                {customer.notes && (
                                    <div className="pt-2">
                                        <p className="text-muted-foreground mb-1">Notes</p>
                                        <p className="whitespace-pre-wrap">{customer.notes}</p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>

            <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Create Customer User</DialogTitle>
                        <DialogDescription>
                            Leave the password blank to send an invite email instead — the Customer User then sets
                            their own password.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4">
                        <div>
                            <Label htmlFor="cu-name">Name</Label>
                            <Input
                                id="cu-name"
                                type="text"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                className="mt-1 block w-full"
                                required
                            />
                            {errors.name && <p className="text-sm text-destructive mt-1">{errors.name}</p>}
                        </div>

                        <div>
                            <Label htmlFor="cu-email">Email</Label>
                            <Input
                                id="cu-email"
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                className="mt-1 block w-full"
                                required
                            />
                            {errors.email && <p className="text-sm text-destructive mt-1">{errors.email}</p>}
                        </div>

                        <div>
                            <Label htmlFor="cu-password">Password (optional)</Label>
                            <Input
                                id="cu-password"
                                type="password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                className="mt-1 block w-full"
                                autoComplete="new-password"
                            />
                            {errors.password && <p className="text-sm text-destructive mt-1">{errors.password}</p>}
                        </div>

                        {data.password !== '' && (
                            <div>
                                <Label htmlFor="cu-password-confirmation">Confirm Password</Label>
                                <Input
                                    id="cu-password-confirmation"
                                    type="password"
                                    value={data.password_confirmation}
                                    onChange={(e) => setData('password_confirmation', e.target.value)}
                                    className="mt-1 block w-full"
                                    autoComplete="new-password"
                                />
                            </div>
                        )}
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCreateOpen(false)} disabled={processing}>
                            Cancel
                        </Button>
                        <Button onClick={submitCustomerUser} disabled={processing}>
                            {processing ? 'Creating...' : 'Create Customer User'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
