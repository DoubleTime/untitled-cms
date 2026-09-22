import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { MachineBrand, MachineModel, PageProps } from '@/types';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { CheckCircle, Edit, MoreHorizontal, Plus, Trash2, XCircle } from 'lucide-react';
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

interface MachineModelsIndexProps extends PageProps {
    machineModels: MachineModel[];
    machineBrands: Pick<MachineBrand, 'id' | 'name'>[];
}

export default function Index({ machineModels, machineBrands }: MachineModelsIndexProps) {
    useFlashToast();
    const { canCreate, canEdit, canDelete } = usePage<PageProps>().props;

    const columns = useMemo<DataTableColumnDef<MachineModel>[]>(
        () => [
            {
                accessorKey: 'name',
                header: ({ column }) => <DataTableColumnHeader column={column} title="Name" />,
                cell: ({ row }) => <div className="font-medium">{row.getValue('name')}</div>,
            },
            {
                id: 'brand',
                accessorFn: (row) => row.machine_brand_id,
                header: ({ column }) => <DataTableColumnHeader column={column} title="Machine Brand" />,
                cell: ({ row }) => row.original.machine_brand?.name ?? '—',
                filterFn: (row, id, value) => value.includes(row.original.machine_brand_id),
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
                id: 'entries',
                header: 'Catalogue Entries',
                cell: ({ row }) => (
                    <span className="text-sm text-muted-foreground">
                        {row.original.flowchart_scripts_count ?? 0} Scripts · {row.original.ai_models_count ?? 0} AI
                        Models
                    </span>
                ),
            },
            {
                id: 'actions',
                header: 'Actions',
                enableHiding: false,
                cell: ({ row }) => {
                    const model = row.original;

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
                                            router.visit(route('admin.marketplace.machine-models.edit', model.id))
                                        }
                                    >
                                        <Edit className="mr-2 h-4 w-4" /> Edit
                                    </DropdownMenuItem>
                                )}
                                {canDelete && (
                                    <DropdownMenuItem
                                        onClick={() => {
                                            if (confirm(`Delete the Machine Model "${model.name}"?`)) {
                                                router.delete(
                                                    route('admin.marketplace.machine-models.destroy', model.id)
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

    const MobileCard = ({ row }: { row: MachineModel }) => (
        <div className="p-4 space-y-2">
            <div className="flex justify-between items-start">
                <div>
                    <h3 className="font-semibold text-lg">{row.name}</h3>
                    <p className="text-sm text-muted-foreground">{row.machine_brand?.name ?? '—'}</p>
                </div>
                {canEdit && (
                    <Link href={route('admin.marketplace.machine-models.edit', row.id)}>
                        <Button variant="ghost" size="icon" className="h-8 w-8">
                            <Edit className="h-4 w-4" />
                        </Button>
                    </Link>
                )}
            </div>
            {row.is_active ? <Badge variant="secondary">Active</Badge> : <Badge variant="outline">Inactive</Badge>}
        </div>
    );

    return (
        <AuthenticatedLayout header="Machine Models">
            <Head title="Machine Models" />

            <div className="flex flex-col gap-6">
                <div className="flex justify-between items-center">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Machine Models</h1>
                        <p className="text-muted-foreground">
                            The equipment FlowChart Scripts and AI Models target.
                        </p>
                    </div>
                    {canCreate && (
                        <Link href={route('admin.marketplace.machine-models.create')}>
                            <Button size="sm" className="h-8">
                                <Plus className="mr-2 h-4 w-4" /> Add Machine Model
                            </Button>
                        </Link>
                    )}
                </div>

                <DataTable
                    data={machineModels}
                    columns={columns}
                    mobileCardRenderer={(row) => <MobileCard row={row} />}
                >
                    {({ table }) => (
                        <DataTableToolbar
                            table={table}
                            searchKey="name"
                            filters={[
                                {
                                    column: 'brand',
                                    title: 'Machine Brand',
                                    options: machineBrands.map((brand) => ({
                                        label: brand.name,
                                        value: brand.id,
                                    })),
                                },
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
