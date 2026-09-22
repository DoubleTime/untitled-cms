import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Customer, PageProps } from '@/types';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { CheckCircle, Edit, Eye, MoreHorizontal, Plus, Trash2, XCircle } from 'lucide-react';
import { DataTable, type DataTableColumnDef } from '@/Components/Common/DataTable';
import { DataTableColumnHeader } from '@/Components/Common/DataTableColumnHeader';
import { DataTableToolbar } from '@/Components/Common/DataTableToolbar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { useMemo } from 'react';
import { useFlashToast } from '@/hooks/use-flash-toast';

interface CustomersIndexProps extends PageProps {
    customers: Customer[];
}

export default function Index({ customers }: CustomersIndexProps) {
    useFlashToast();
    const { canCreate, canEdit, canDelete } = usePage<PageProps>().props;

    const columns = useMemo<DataTableColumnDef<Customer>[]>(
        () => [
            {
                accessorKey: 'company',
                header: ({ column }) => <DataTableColumnHeader column={column} title="Company" />,
                cell: ({ row }) => (
                    <Link
                        href={route('admin.marketplace.customers.show', row.original.id)}
                        className="font-medium hover:underline"
                    >
                        {row.getValue('company')}
                    </Link>
                ),
            },
            {
                accessorKey: 'code',
                header: ({ column }) => <DataTableColumnHeader column={column} title="Code" />,
            },
            {
                accessorKey: 'contact_email',
                header: 'Contact',
                cell: ({ row }) => (
                    <span className="text-sm text-muted-foreground">
                        {row.original.contact_name ?? '—'}
                        {row.original.contact_email ? ` · ${row.original.contact_email}` : ''}
                    </span>
                ),
            },
            {
                id: 'status',
                accessorKey: 'is_active',
                header: ({ column }) => <DataTableColumnHeader column={column} title="Status" />,
                cell: ({ row }) =>
                    row.original.is_active ? (
                        <Badge variant="secondary">Active</Badge>
                    ) : (
                        <Badge variant="outline">Inactive</Badge>
                    ),
                filterFn: (row, id, value) => value.includes(String(row.original.is_active)),
            },
            {
                id: 'counts',
                header: 'Users / UNYSIS Boxes',
                cell: ({ row }) => (
                    <span className="text-sm text-muted-foreground">
                        {row.original.users_count ?? 0} / {row.original.unysis_boxes_count ?? 0}
                    </span>
                ),
            },
            {
                id: 'actions',
                header: 'Actions',
                enableHiding: false,
                cell: ({ row }) => {
                    const customer = row.original;

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
                                    onClick={() =>
                                        router.visit(route('admin.marketplace.customers.show', customer.id))
                                    }
                                >
                                    <Eye className="mr-2 h-4 w-4" /> View
                                </DropdownMenuItem>
                                {canEdit && (
                                    <DropdownMenuItem
                                        onClick={() =>
                                            router.visit(route('admin.marketplace.customers.edit', customer.id))
                                        }
                                    >
                                        <Edit className="mr-2 h-4 w-4" /> Edit
                                    </DropdownMenuItem>
                                )}
                                {canDelete && (
                                    <DropdownMenuItem
                                        onClick={() => {
                                            if (confirm(`Delete the Customer "${customer.company}"?`)) {
                                                router.delete(
                                                    route('admin.marketplace.customers.destroy', customer.id)
                                                );
                                            }
                                        }}
                                        className="text-destructive focus:text-destructive"
                                    >
                                        <Trash2 className="mr-2 h-4 w-4" /> Delete
                                    </DropdownMenuItem>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    );
                },
            },
        ],
        [canEdit, canDelete]
    );

    const MobileCard = ({ row }: { row: Customer }) => (
        <div className="p-4 space-y-2">
            <div className="flex justify-between items-start">
                <div>
                    <h3 className="font-semibold text-lg">{row.company}</h3>
                    <p className="text-sm text-muted-foreground">{row.code}</p>
                </div>
                <Link href={route('admin.marketplace.customers.show', row.id)}>
                    <Button variant="ghost" size="icon" className="h-8 w-8">
                        <Eye className="h-4 w-4" />
                    </Button>
                </Link>
            </div>
            <div className="text-sm text-muted-foreground">
                {row.users_count ?? 0} Customer Users · {row.unysis_boxes_count ?? 0} UNYSIS Boxes
            </div>
        </div>
    );

    return (
        <AuthenticatedLayout header="Customers">
            <Head title="Customers" />

            <div className="flex flex-col gap-6">
                <div className="flex justify-between items-center">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Customers</h1>
                        <p className="text-muted-foreground">
                            Companies that own UNYSIS Boxes and have their own Customer Users.
                        </p>
                    </div>
                    {canCreate && (
                        <Link href={route('admin.marketplace.customers.create')}>
                            <Button size="sm" className="h-8">
                                <Plus className="mr-2 h-4 w-4" /> Add Customer
                            </Button>
                        </Link>
                    )}
                </div>

                <DataTable data={customers} columns={columns} mobileCardRenderer={(row) => <MobileCard row={row} />}>
                    {({ table }) => (
                        <DataTableToolbar
                            table={table}
                            searchKey="company"
                            filters={[
                                {
                                    column: 'status',
                                    title: 'Status',
                                    options: [
                                        { label: 'Active', value: 'true', icon: CheckCircle },
                                        { label: 'Inactive', value: 'false', icon: XCircle },
                                    ],
                                },
                            ]}
                        />
                    )}
                </DataTable>
            </div>
        </AuthenticatedLayout>
    );
}
