import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AiModel, PageProps } from '@/types';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Switch } from '@/Components/ui/switch';
import { Label } from '@/Components/ui/label';
import { Edit, Eye, MoreHorizontal, Plus, RotateCcw, Trash2 } from 'lucide-react';
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
import {
    buildCatalogueFilters,
    type CustomerOption,
    type MachineModelOption,
} from '@/Components/Marketplace/CatalogueFilters';
import { formatDateTime } from '@/Components/Marketplace/format';

interface AiModelsIndexProps extends PageProps {
    aiModels: AiModel[];
    machineModels: MachineModelOption[];
    customers: CustomerOption[];
    showDeleted: boolean;
}

export default function Index({ aiModels, machineModels, customers, showDeleted }: AiModelsIndexProps) {
    useFlashToast();
    const { canCreate, canEdit, canDelete, canHardDelete } = usePage<PageProps>().props;

    const columns = useMemo<DataTableColumnDef<AiModel>[]>(
        () => [
            {
                accessorKey: 'name',
                header: ({ column }) => <DataTableColumnHeader column={column} title="Name" />,
                cell: ({ row }) => (
                    <Link
                        href={route('admin.marketplace.ai-models.show', row.original.id)}
                        className="font-medium hover:underline"
                    >
                        {row.original.name}
                    </Link>
                ),
            },
            {
                id: 'machineModel',
                accessorFn: (row) => row.machine_model_id,
                header: ({ column }) => <DataTableColumnHeader column={column} title="Machine Model" />,
                cell: ({ row }) => (
                    <span className="text-sm">
                        {row.original.machine_model?.machine_brand?.name
                            ? `${row.original.machine_model.machine_brand.name} — `
                            : ''}
                        {row.original.machine_model?.name ?? '—'}
                    </span>
                ),
                filterFn: (row, id, value) => value.includes(row.original.machine_model_id),
            },
            {
                id: 'customer',
                accessorFn: (row) => row.customer_id ?? '',
                header: ({ column }) => <DataTableColumnHeader column={column} title="Customer" />,
                cell: ({ row }) => row.original.customer?.company ?? '—',
                filterFn: (row, id, value) => value.includes(row.original.customer_id ?? ''),
            },
            {
                id: 'latest_released',
                header: 'Latest released',
                cell: ({ row }) =>
                    row.original.latest_released_number ? (
                        <Badge variant="secondary">Rev {row.original.latest_released_number}</Badge>
                    ) : (
                        <Badge variant="outline">None</Badge>
                    ),
            },
            {
                id: 'revisions',
                header: 'Revisions',
                cell: ({ row }) => row.original.revisions_count ?? 0,
            },
            {
                id: 'downloads',
                header: 'Downloads',
                cell: ({ row }) => row.original.downloads_count ?? 0,
            },
            {
                accessorKey: 'updated_at',
                header: ({ column }) => <DataTableColumnHeader column={column} title="Updated" />,
                cell: ({ row }) => (
                    <span className="text-sm text-muted-foreground">
                        {formatDateTime(row.original.updated_at)}
                    </span>
                ),
            },
            {
                id: 'actions',
                header: 'Actions',
                enableHiding: false,
                cell: ({ row }) => {
                    const aiModel = row.original;

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
                                {!showDeleted && (
                                    <DropdownMenuItem
                                        onClick={() =>
                                            router.visit(route('admin.marketplace.ai-models.show', aiModel.id))
                                        }
                                    >
                                        <Eye className="mr-2 h-4 w-4" /> Open
                                    </DropdownMenuItem>
                                )}
                                {canEdit && !showDeleted && (
                                    <DropdownMenuItem
                                        onClick={() =>
                                            router.visit(route('admin.marketplace.ai-models.edit', aiModel.id))
                                        }
                                    >
                                        <Edit className="mr-2 h-4 w-4" /> Edit
                                    </DropdownMenuItem>
                                )}
                                {canDelete && showDeleted && (
                                    <DropdownMenuItem
                                        onClick={() =>
                                            router.post(route('admin.marketplace.ai-models.restore', aiModel.id))
                                        }
                                    >
                                        <RotateCcw className="mr-2 h-4 w-4" /> Restore
                                    </DropdownMenuItem>
                                )}
                                {canDelete && !showDeleted && (
                                    <DropdownMenuItem
                                        onClick={() => {
                                            if (confirm(`Delete the AI Model "${aiModel.name}"?`)) {
                                                router.delete(
                                                    route('admin.marketplace.ai-models.destroy', aiModel.id)
                                                );
                                            }
                                        }}
                                        className="text-destructive focus:text-destructive"
                                    >
                                        <Trash2 className="mr-2 h-4 w-4" /> Delete
                                    </DropdownMenuItem>
                                )}
                                {canHardDelete && showDeleted && (
                                    <DropdownMenuItem
                                        onClick={() => {
                                            if (
                                                confirm(
                                                    `Permanently delete "${aiModel.name}" and every Revision file? This cannot be undone.`
                                                )
                                            ) {
                                                router.delete(
                                                    route('admin.marketplace.ai-models.force-destroy', aiModel.id)
                                                );
                                            }
                                        }}
                                        className="text-destructive focus:text-destructive"
                                    >
                                        <Trash2 className="mr-2 h-4 w-4" /> Delete permanently
                                    </DropdownMenuItem>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    );
                },
            },
        ],
        [canEdit, canDelete, canHardDelete, showDeleted]
    );

    return (
        <AuthenticatedLayout header="AI Models">
            <Head title="AI Models" />

            <div className="flex flex-col gap-6">
                <div className="flex justify-between items-center">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">AI Models</h1>
                        <p className="text-muted-foreground">
                            Trained inference models published on their own, independent of any FlowChart Script.
                        </p>
                    </div>
                    {canCreate && !showDeleted && (
                        <Link href={route('admin.marketplace.ai-models.create')}>
                            <Button size="sm" className="h-8">
                                <Plus className="mr-2 h-4 w-4" /> Add AI Model
                            </Button>
                        </Link>
                    )}
                </div>

                <div className="flex items-center gap-2">
                    <Switch
                        id="show-deleted"
                        checked={showDeleted}
                        onCheckedChange={(checked) =>
                            router.get(
                                route('admin.marketplace.ai-models.index'),
                                checked ? { deleted: 1 } : {},
                                { preserveState: false }
                            )
                        }
                    />
                    <Label htmlFor="show-deleted">Show deleted</Label>
                </div>

                <DataTable data={aiModels} columns={columns}>
                    {({ table }) => (
                        <DataTableToolbar
                            table={table}
                            searchKey="name"
                            filters={buildCatalogueFilters(machineModels, customers)}
                        />
                    )}
                </DataTable>
            </div>
        </AuthenticatedLayout>
    );
}
