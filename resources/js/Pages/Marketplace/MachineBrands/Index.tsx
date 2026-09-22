import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { MachineBrand, PageProps } from '@/types';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Edit, MoreHorizontal, Plus, Trash2 } from 'lucide-react';
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

interface MachineBrandsIndexProps extends PageProps {
    machineBrands: MachineBrand[];
}

export default function Index({ machineBrands }: MachineBrandsIndexProps) {
    useFlashToast();
    const { canCreate, canEdit, canDelete } = usePage<PageProps>().props;

    const columns = useMemo<DataTableColumnDef<MachineBrand>[]>(
        () => [
            {
                accessorKey: 'name',
                header: ({ column }) => <DataTableColumnHeader column={column} title="Name" />,
                cell: ({ row }) => <div className="font-medium">{row.getValue('name')}</div>,
            },
            {
                accessorKey: 'slug',
                header: ({ column }) => <DataTableColumnHeader column={column} title="Slug" />,
                cell: ({ row }) => (
                    <code className="bg-muted px-1.5 py-0.5 rounded text-xs">{row.getValue('slug')}</code>
                ),
            },
            {
                accessorKey: 'machine_models_count',
                header: 'Machine Models',
                cell: ({ row }) => <Badge variant="outline">{row.original.machine_models_count ?? 0}</Badge>,
            },
            {
                id: 'actions',
                header: 'Actions',
                enableHiding: false,
                cell: ({ row }) => {
                    const brand = row.original;

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
                                {canEdit && (
                                    <DropdownMenuItem
                                        onClick={() =>
                                            router.visit(route('admin.marketplace.machine-brands.edit', brand.id))
                                        }
                                    >
                                        <Edit className="mr-2 h-4 w-4" /> Edit
                                    </DropdownMenuItem>
                                )}
                                {canDelete && (
                                    <DropdownMenuItem
                                        onClick={() => {
                                            if (confirm(`Delete the Machine Brand "${brand.name}"?`)) {
                                                router.delete(
                                                    route('admin.marketplace.machine-brands.destroy', brand.id)
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

    const MobileCard = ({ row }: { row: MachineBrand }) => (
        <div className="p-4 space-y-2">
            <div className="flex justify-between items-start">
                <div>
                    <h3 className="font-semibold text-lg">{row.name}</h3>
                    <code className="text-xs bg-muted px-1.5 py-0.5 rounded">{row.slug}</code>
                </div>
                {canEdit && (
                    <Link href={route('admin.marketplace.machine-brands.edit', row.id)}>
                        <Button variant="ghost" size="icon" className="h-8 w-8">
                            <Edit className="h-4 w-4" />
                        </Button>
                    </Link>
                )}
            </div>
            <div className="text-sm text-muted-foreground">
                <span className="font-medium">{row.machine_models_count ?? 0}</span> Machine Models
            </div>
        </div>
    );

    return (
        <AuthenticatedLayout header="Machine Brands">
            <Head title="Machine Brands" />

            <div className="flex flex-col gap-6">
                <div className="flex justify-between items-center">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Machine Brands</h1>
                        <p className="text-muted-foreground">
                            Manufacturers naming the Machine Models the catalogue is organised by.
                        </p>
                    </div>
                    {canCreate && (
                        <Link href={route('admin.marketplace.machine-brands.create')}>
                            <Button size="sm" className="h-8">
                                <Plus className="mr-2 h-4 w-4" /> Add Machine Brand
                            </Button>
                        </Link>
                    )}
                </div>

                <DataTable
                    data={machineBrands}
                    columns={columns}
                    mobileCardRenderer={(row) => <MobileCard row={row} />}
                >
                    {({ table }) => <DataTableToolbar table={table} searchKey="name" />}
                </DataTable>
            </div>
        </AuthenticatedLayout>
    );
}
