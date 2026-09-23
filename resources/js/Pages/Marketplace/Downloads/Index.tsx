import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import type { UnysisBox, Customer, DownloadLogRow, Paginated, PageProps, User } from '@/types';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Download as DownloadIcon, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useFlashToast } from '@/hooks/use-flash-toast';
import DownloadLogTable, { entryRoute } from '@/Components/Marketplace/DownloadLogTable';
import Pagination from '@/Components/Marketplace/Pagination';

const ANY = '__any__';

interface DownloadFilters {
    source: string | null;
    entry_type: string | null;
    customer_id: string | null;
    unysis_box_id: string | null;
    user_id: string | null;
    q: string | null;
    from: string | null;
    to: string | null;
}

interface TopEntry {
    entry_type: DownloadLogRow['entry_type'];
    entry_id: string;
    entry_name: string | null;
    downloads: number;
}

interface DownloadsIndexProps extends PageProps {
    downloads: Paginated<DownloadLogRow>;
    filters: DownloadFilters;
    summary: {
        total: number;
        last_seven_days: number;
        unique_boxes: number;
        top_entries: TopEntry[];
    };
    customers: Pick<Customer, 'id' | 'company'>[];
    unysisBoxes: (Pick<UnysisBox, 'id' | 'name' | 'motherboard_uuid' | 'customer_id'> & {
        customer?: Pick<Customer, 'id' | 'company'> | null;
    })[];
    users: (Pick<User, 'id' | 'name' | 'email'> & { customer_id?: string | null })[];
}

export default function Index({
    downloads,
    filters,
    summary,
    customers,
    unysisBoxes,
    users,
}: DownloadsIndexProps) {
    useFlashToast();

    const [search, setSearch] = useState(filters.q ?? '');

    // Keep the box in step when the server sends a different filter set back.
    useEffect(() => setSearch(filters.q ?? ''), [filters.q]);

    const apply = (changes: Partial<Record<keyof DownloadFilters, string | null>>) => {
        const next: Record<string, string> = {};

        const merged = { ...filters, ...changes };

        (Object.keys(merged) as (keyof DownloadFilters)[]).forEach((key) => {
            const value = merged[key];
            if (value) {
                next[key] = value;
            }
        });

        router.get(route('admin.marketplace.downloads.index'), next, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const isFiltered = Object.values(filters).some((value) => !!value);

    // The export re-runs the same filter builder server-side, so it only needs the
    // active filters echoed back into its query string.
    const exportQuery = new URLSearchParams(
        Object.entries(filters).filter(([, value]) => !!value) as [string, string][],
    ).toString();
    const exportUrl = route('admin.marketplace.downloads.export') + (exportQuery ? `?${exportQuery}` : '');

    const visibleBoxes = filters.customer_id
        ? unysisBoxes.filter((box) => box.customer_id === filters.customer_id)
        : unysisBoxes;

    return (
        <AuthenticatedLayout header="Downloads">
            <Head title="Downloads" />

            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Downloads</h1>
                        <p className="text-muted-foreground">
                            Every recorded fetch of a Revision file — from RPA-TOOL on an UNYSIS Box, and from Team
                            Members in the admin. Download rows are never deleted.
                        </p>
                    </div>
                    <Button variant="outline" asChild>
                        {/* A full page load, not an Inertia visit: the response is a streamed CSV.
                            The current filters ride along so the file matches what is on screen. */}
                        <a href={exportUrl}>
                            <DownloadIcon className="mr-2 h-4 w-4" />
                            Export CSV
                        </a>
                    </Button>
                </div>

                <div className="grid gap-4 md:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Total Downloads
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">{summary.total}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Last 7 days
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">{summary.last_seven_days}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Unique UNYSIS Boxes
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">{summary.unique_boxes}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Top entries
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-1">
                            {summary.top_entries.length === 0 && (
                                <p className="text-sm text-muted-foreground">Nothing downloaded yet.</p>
                            )}
                            {summary.top_entries.map((entry) => (
                                <div
                                    key={`${entry.entry_type}:${entry.entry_id}`}
                                    className="flex justify-between gap-2 text-sm"
                                >
                                    {entry.entry_name ? (
                                        <Link href={entryRoute(entry)} className="truncate hover:underline">
                                            {entry.entry_name}
                                        </Link>
                                    ) : (
                                        <span className="truncate text-muted-foreground">(removed)</span>
                                    )}
                                    <span className="shrink-0 tabular-nums text-muted-foreground">
                                        {entry.downloads}
                                    </span>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <div>
                        <Label htmlFor="q" className="text-xs">
                            Entry name
                        </Label>
                        <Input
                            id="q"
                            value={search}
                            placeholder="Search entries…"
                            className="mt-1 h-8 w-[200px]"
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    apply({ q: search });
                                }
                            }}
                            onBlur={() => {
                                if ((filters.q ?? '') !== search) {
                                    apply({ q: search });
                                }
                            }}
                        />
                    </div>

                    <div>
                        <Label className="text-xs">Source</Label>
                        <Select
                            value={filters.source ?? ANY}
                            onValueChange={(value) => apply({ source: value === ANY ? null : value })}
                        >
                            <SelectTrigger className="mt-1 h-8 w-[140px]">
                                <SelectValue placeholder="Any source" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ANY}>Any source</SelectItem>
                                <SelectItem value="api">RPA-TOOL</SelectItem>
                                <SelectItem value="web">Admin</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div>
                        <Label className="text-xs">Entry type</Label>
                        <Select
                            value={filters.entry_type ?? ANY}
                            onValueChange={(value) => apply({ entry_type: value === ANY ? null : value })}
                        >
                            <SelectTrigger className="mt-1 h-8 w-[170px]">
                                <SelectValue placeholder="Any type" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ANY}>Any type</SelectItem>
                                <SelectItem value="script">Script</SelectItem>
                                <SelectItem value="ai_model">AI Model</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div>
                        <Label className="text-xs">Customer</Label>
                        <Select
                            value={filters.customer_id ?? ANY}
                            onValueChange={(value) =>
                                apply({ customer_id: value === ANY ? null : value, unysis_box_id: null })
                            }
                        >
                            <SelectTrigger className="mt-1 h-8 w-[180px]">
                                <SelectValue placeholder="Any Customer" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ANY}>Any Customer</SelectItem>
                                {customers.map((customer) => (
                                    <SelectItem key={customer.id} value={customer.id}>
                                        {customer.company}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div>
                        <Label className="text-xs">UNYSIS Box</Label>
                        <Select
                            value={filters.unysis_box_id ?? ANY}
                            onValueChange={(value) => apply({ unysis_box_id: value === ANY ? null : value })}
                        >
                            <SelectTrigger className="mt-1 h-8 w-[200px]">
                                <SelectValue placeholder="Any UNYSIS Box" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ANY}>Any UNYSIS Box</SelectItem>
                                {visibleBoxes.map((box) => (
                                    <SelectItem key={box.id} value={box.id}>
                                        {box.name || box.motherboard_uuid}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div>
                        <Label className="text-xs">User</Label>
                        <Select
                            value={filters.user_id ?? ANY}
                            onValueChange={(value) => apply({ user_id: value === ANY ? null : value })}
                        >
                            <SelectTrigger className="mt-1 h-8 w-[180px]">
                                <SelectValue placeholder="Anyone" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ANY}>Anyone</SelectItem>
                                {users.map((user) => (
                                    <SelectItem key={user.id} value={user.id}>
                                        {user.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div>
                        <Label htmlFor="from" className="text-xs">
                            From
                        </Label>
                        <Input
                            id="from"
                            type="date"
                            value={filters.from ?? ''}
                            className="mt-1 h-8 w-[150px]"
                            onChange={(e) => apply({ from: e.target.value || null })}
                        />
                    </div>

                    <div>
                        <Label htmlFor="to" className="text-xs">
                            To
                        </Label>
                        <Input
                            id="to"
                            type="date"
                            value={filters.to ?? ''}
                            className="mt-1 h-8 w-[150px]"
                            onChange={(e) => apply({ to: e.target.value || null })}
                        />
                    </div>

                    {isFiltered && (
                        <Button
                            variant="ghost"
                            className="h-8 px-2 lg:px-3"
                            onClick={() =>
                                router.get(
                                    route('admin.marketplace.downloads.index'),
                                    {},
                                    { preserveScroll: true, replace: true }
                                )
                            }
                        >
                            Reset
                            <X className="ml-2 h-4 w-4" />
                        </Button>
                    )}
                </div>

                <DownloadLogTable
                    downloads={downloads.data}
                    emptyMessage="No Downloads match these filters."
                />
                <Pagination page={downloads} />
            </div>
        </AuthenticatedLayout>
    );
}
