import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import type { UnysisBox, UnysisBoxStatus, Customer, PageProps } from '@/types';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Ban, CheckCircle2, Copy, Edit, Eye, MoreHorizontal, ShieldCheck, Trash2 } from 'lucide-react';
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
import { toast } from 'sonner';
import { useFlashToast } from '@/hooks/use-flash-toast';
import UnysisBoxStatusBadge from '@/Components/Marketplace/UnysisBoxStatusBadge';
import type { MachineModelOption } from '@/Components/Marketplace/CatalogueFilters';
import { formatRelative } from '@/Components/Marketplace/format';

interface UnysisBoxesIndexProps extends PageProps {
    unysisBoxes: UnysisBox[];
    customers: Pick<Customer, 'id' | 'company'>[];
    machineModels: MachineModelOption[];
    statuses: UnysisBoxStatus[];
}

const STATUS_LABELS: Record<string, string> = {
    pending: 'Pending',
    active: 'Active',
    blocked: 'Blocked',
};

export default function Index({ unysisBoxes, customers, machineModels, statuses }: UnysisBoxesIndexProps) {
    useFlashToast();
    const { canEdit, canBlock, canDelete } = usePage<PageProps>().props;

    const filters = useMemo(
        () => [
            {
                column: 'customer',
                title: 'Customer',
                options: customers.map((customer) => ({ label: customer.company, value: customer.id })),
            },
            {
                column: 'status',
                title: 'Status',
                options: statuses.map((status) => ({
                    label: STATUS_LABELS[status] ?? status,
                    value: status,
                })),
            },
            {
                column: 'machineModel',
                title: 'Machine Model',
                options: [...machineModels]
                    .sort((a, b) => {
                        const brand = (a.machine_brand?.name ?? '').localeCompare(b.machine_brand?.name ?? '');
                        return brand !== 0 ? brand : a.name.localeCompare(b.name);
                    })
                    .map((model) => ({
                        label: `${model.machine_brand?.name ?? 'No Machine Brand'} — ${model.name}`,
                        value: model.id,
                    })),
            },
        ],
        [customers, machineModels, statuses]
    );

    const columns = useMemo<DataTableColumnDef<UnysisBox>[]>(
        () => [
            {
                id: 'customer',
                accessorFn: (row) => row.customer_id,
                header: ({ column }) => <DataTableColumnHeader column={column} title="Customer" />,
                cell: ({ row }) => <span className="text-sm">{row.original.customer?.company ?? '—'}</span>,
                filterFn: (row, id, value) => value.includes(row.original.customer_id),
            },
            {
                // The search column: the motherboard UUID, the label and the
                // location all match one box, so they are searched together.
                id: 'search',
                accessorFn: (row) =>
                    [row.motherboard_uuid, row.name ?? '', row.location ?? ''].join(' '),
                header: ({ column }) => <DataTableColumnHeader column={column} title="Motherboard UUID" />,
                cell: ({ row }) => (
                    <button
                        type="button"
                        className="inline-flex items-center gap-1 font-mono text-xs hover:text-foreground"
                        title={row.original.motherboard_uuid}
                        onClick={() => {
                            navigator.clipboard
                                ?.writeText(row.original.motherboard_uuid)
                                .then(() => toast.success('Motherboard UUID copied to the clipboard.'))
                                .catch(() => toast.error('Could not copy the UUID.'));
                        }}
                    >
                        {row.original.motherboard_uuid}
                        <Copy className="h-3 w-3" />
                    </button>
                ),
            },
            {
                accessorKey: 'name',
                header: ({ column }) => <DataTableColumnHeader column={column} title="Name" />,
                cell: ({ row }) => (
                    <Link
                        href={route('admin.marketplace.unysis-boxes.show', row.original.id)}
                        className="font-medium hover:underline"
                    >
                        {row.original.name || 'Unlabelled'}
                    </Link>
                ),
            },
            {
                accessorKey: 'location',
                header: ({ column }) => <DataTableColumnHeader column={column} title="Location" />,
                cell: ({ row }) => <span className="text-sm">{row.original.location || '—'}</span>,
            },
            {
                id: 'machineModel',
                accessorFn: (row) => row.machine_model_id ?? '',
                header: 'Machine Model',
                cell: ({ row }) => (
                    <span className="text-sm">
                        {row.original.machine_model?.machine_brand?.name
                            ? `${row.original.machine_model.machine_brand.name} — `
                            : ''}
                        {row.original.machine_model?.name ?? '—'}
                    </span>
                ),
                filterFn: (row, id, value) => value.includes(row.original.machine_model_id ?? ''),
            },
            {
                id: 'status',
                accessorFn: (row) => row.status,
                header: ({ column }) => <DataTableColumnHeader column={column} title="Status" />,
                cell: ({ row }) => <UnysisBoxStatusBadge status={row.original.status} />,
                filterFn: (row, id, value) => value.includes(row.original.status),
            },
            {
                accessorKey: 'last_seen_at',
                header: ({ column }) => <DataTableColumnHeader column={column} title="Last seen" />,
                cell: ({ row }) => (
                    <span
                        className="text-sm text-muted-foreground"
                        title={row.original.last_seen_at ?? undefined}
                    >
                        {formatRelative(row.original.last_seen_at)}
                    </span>
                ),
            },
            {
                accessorKey: 'last_ip',
                header: 'Last IP',
                cell: ({ row }) => (
                    <span className="font-mono text-xs text-muted-foreground">
                        {row.original.last_ip ?? '—'}
                    </span>
                ),
            },
            {
                id: 'downloads',
                header: 'Downloads',
                cell: ({ row }) => <Badge variant="outline">{row.original.downloads_count ?? 0}</Badge>,
            },
            {
                id: 'actions',
                header: 'Actions',
                enableHiding: false,
                cell: ({ row }) => {
                    const box = row.original;
                    const label = box.name || box.motherboard_uuid;

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
                                <DropdownMenuItem asChild>
                                    <Link href={route('admin.marketplace.unysis-boxes.show', box.id)}>
                                        <Eye className="mr-2 h-4 w-4" /> View
                                    </Link>
                                </DropdownMenuItem>
                                {canEdit && (
                                    <DropdownMenuItem asChild>
                                        <Link href={route('admin.marketplace.unysis-boxes.edit', box.id)}>
                                            <Edit className="mr-2 h-4 w-4" /> Edit
                                        </Link>
                                    </DropdownMenuItem>
                                )}
                                {canEdit && box.status === 'pending' && (
                                    <DropdownMenuItem
                                        onClick={() =>
                                            router.post(
                                                route('admin.marketplace.unysis-boxes.activate', box.id),
                                                {},
                                                { preserveScroll: true }
                                            )
                                        }
                                    >
                                        <CheckCircle2 className="mr-2 h-4 w-4" /> Mark active
                                    </DropdownMenuItem>
                                )}
                                {canBlock && box.status !== 'blocked' && (
                                    <DropdownMenuItem
                                        onClick={() => {
                                            if (
                                                confirm(
                                                    `Block "${label}"? Its RPA-TOOL sessions are revoked immediately.`
                                                )
                                            ) {
                                                router.post(
                                                    route('admin.marketplace.unysis-boxes.block', box.id),
                                                    {},
                                                    { preserveScroll: true }
                                                );
                                            }
                                        }}
                                    >
                                        <Ban className="mr-2 h-4 w-4" /> Block
                                    </DropdownMenuItem>
                                )}
                                {canBlock && box.status === 'blocked' && (
                                    <DropdownMenuItem
                                        onClick={() =>
                                            router.post(
                                                route('admin.marketplace.unysis-boxes.unblock', box.id),
                                                {},
                                                { preserveScroll: true }
                                            )
                                        }
                                    >
                                        <ShieldCheck className="mr-2 h-4 w-4" /> Unblock
                                    </DropdownMenuItem>
                                )}
                                {canDelete && (
                                    <DropdownMenuItem
                                        className="text-destructive"
                                        onClick={() => {
                                            if (confirm(`Delete the UNYSIS Box "${label}"?`)) {
                                                router.delete(
                                                    route('admin.marketplace.unysis-boxes.destroy', box.id),
                                                    { preserveScroll: true }
                                                );
                                            }
                                        }}
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
        [canBlock, canDelete, canEdit]
    );

    return (
        <AuthenticatedLayout header="UNYSIS Boxes">
            <Head title="UNYSIS Boxes" />

            <div className="flex flex-col gap-4">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">UNYSIS Boxes</h1>
                    <p className="text-muted-foreground">
                        Deployed UNYSIS edge devices running RPA-TOOL. A box registers itself on its first
                        sign-in, arriving as <strong>Pending</strong> until a Team Member acknowledges it.
                    </p>
                </div>

                <DataTable data={unysisBoxes} columns={columns}>
                    {({ table }) => (
                        <DataTableToolbar table={table} searchKey="search" filters={filters} />
                    )}
                </DataTable>
            </div>
        </AuthenticatedLayout>
    );
}
