import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { Download as DownloadIcon } from 'lucide-react';
import { formatDateTime } from '@/Components/Marketplace/format';
import type { PageProps } from '@/types';

const ANY = '__any__';

type EntryType = 'script' | 'ai_model';

const ENTRY_LABEL: Record<EntryType, string> = {
    script: 'Script',
    ai_model: 'AI Model',
};

const RANGES: { value: string; label: string }[] = [
    { value: '7', label: 'Last 7 days' },
    { value: '30', label: 'Last 30 days' },
    { value: '90', label: 'Last 90 days' },
    { value: '365', label: 'Last 365 days' },
    { value: 'all', label: 'All time' },
];

interface UsageFilters {
    range: string;
    entry_type: string | null;
    from: string | null;
    to: string | null;
}

interface CustomerRow {
    customer_id: string | null;
    customer_code: string | null;
    customer_company: string | null;
    active_boxes: number;
    boxes_downloaded: number;
    downloads: number;
    distinct_entries: number;
    last_download_at: string | null;
}

interface EntryRow {
    entry_type: EntryType;
    entry_id: string;
    entry_name: string | null;
    entry_deleted: boolean;
    machine_model: string | null;
    machine_brand: string | null;
    downloads: number;
    distinct_customers: number;
    distinct_boxes: number;
    latest_released_revision: number | null;
    last_download_at: string | null;
}

interface UsageProps extends PageProps {
    filters: UsageFilters;
    totals: { downloads: number; customers: number; boxes: number; entries: number };
    customers: CustomerRow[];
    entries: EntryRow[];
}

export default function Usage({ filters, totals, customers, entries }: UsageProps) {
    const apply = (changes: Partial<Record<keyof UsageFilters, string | null>>) => {
        const merged = { ...filters, ...changes };
        const next: Record<string, string> = {};

        (Object.keys(merged) as (keyof UsageFilters)[]).forEach((key) => {
            const value = merged[key];
            if (value) {
                next[key] = value;
            }
        });

        router.get(route('admin.marketplace.reports.usage'), next, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    // A section export re-runs the same aggregation server-side; the query string
    // carries the range the page is showing.
    const exportUrl = (section: 'customers' | 'entries') => {
        const params = new URLSearchParams({ section });

        (Object.keys(filters) as (keyof UsageFilters)[]).forEach((key) => {
            const value = filters[key];
            if (value) {
                params.set(key, String(value));
            }
        });

        return `${route('admin.marketplace.reports.usage.export')}?${params.toString()}`;
    };

    return (
        <AuthenticatedLayout header="Usage Report">
            <Head title="Usage Report" />

            <div className="flex flex-col gap-6">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">Usage Report</h1>
                    <p className="text-muted-foreground">
                        Who downloaded what, over a date range — per Customer and per catalogue entry.
                    </p>
                </div>

                <Card>
                    <CardContent className="flex flex-wrap items-end gap-4 pt-6">
                        <div className="w-44 space-y-2">
                            <Label>Range</Label>
                            <Select
                                value={filters.range}
                                onValueChange={(value) => apply({ range: value, from: null, to: null })}
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {RANGES.map((range) => (
                                        <SelectItem key={range.value} value={range.value}>
                                            {range.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="w-44 space-y-2">
                            <Label htmlFor="from">From</Label>
                            <Input
                                id="from"
                                type="date"
                                value={filters.from ?? ''}
                                onChange={(event) => apply({ from: event.target.value || null })}
                            />
                        </div>

                        <div className="w-44 space-y-2">
                            <Label htmlFor="to">To</Label>
                            <Input
                                id="to"
                                type="date"
                                value={filters.to ?? ''}
                                onChange={(event) => apply({ to: event.target.value || null })}
                            />
                        </div>

                        <div className="w-44 space-y-2">
                            <Label>Entry type</Label>
                            <Select
                                value={filters.entry_type ?? ANY}
                                onValueChange={(value) => apply({ entry_type: value === ANY ? null : value })}
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>All entries</SelectItem>
                                    <SelectItem value="script">Scripts</SelectItem>
                                    <SelectItem value="ai_model">AI Models</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <div className="grid gap-4 md:grid-cols-4">
                    {[
                        { label: 'Downloads', value: totals.downloads },
                        { label: 'Customers', value: totals.customers },
                        { label: 'UNYSIS Boxes', value: totals.boxes },
                        { label: 'Entries downloaded', value: totals.entries },
                    ].map((total) => (
                        <Card key={total.label}>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    {total.label}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold tabular-nums">{total.value}</p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-start justify-between gap-4">
                        <div>
                            <CardTitle>By Customer</CardTitle>
                            <CardDescription>
                                Active boxes are counted as they stand today; everything else is inside the range.
                            </CardDescription>
                        </div>
                        <Button variant="outline" size="sm" asChild>
                            <a href={exportUrl('customers')}>
                                <DownloadIcon className="mr-2 h-4 w-4" />
                                Export CSV
                            </a>
                        </Button>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Code</TableHead>
                                        <TableHead>Company</TableHead>
                                        <TableHead className="text-right">Active boxes</TableHead>
                                        <TableHead className="text-right">Boxes that downloaded</TableHead>
                                        <TableHead className="text-right">Downloads</TableHead>
                                        <TableHead className="text-right">Distinct entries</TableHead>
                                        <TableHead>Last download</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {customers.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={7} className="text-muted-foreground">
                                                No Customers.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {customers.map((row) => (
                                        <TableRow key={row.customer_id ?? 'none'}>
                                            <TableCell className="font-mono text-xs">
                                                {row.customer_code ?? '—'}
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                {row.customer_company ?? '—'}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.active_boxes}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.boxes_downloaded}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.downloads}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.distinct_entries}
                                            </TableCell>
                                            <TableCell className="text-xs text-muted-foreground">
                                                {formatDateTime(row.last_download_at)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-start justify-between gap-4">
                        <div>
                            <CardTitle>By entry</CardTitle>
                            <CardDescription>
                                One row per catalogue entry downloaded in the range. Entries deleted since are
                                still listed — their Download rows outlive them.
                            </CardDescription>
                        </div>
                        <Button variant="outline" size="sm" asChild>
                            <a href={exportUrl('entries')}>
                                <DownloadIcon className="mr-2 h-4 w-4" />
                                Export CSV
                            </a>
                        </Button>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Machine Model</TableHead>
                                        <TableHead className="text-right">Downloads</TableHead>
                                        <TableHead className="text-right">Customers</TableHead>
                                        <TableHead className="text-right">Boxes</TableHead>
                                        <TableHead className="text-right">Latest released</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {entries.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={7} className="text-muted-foreground">
                                                Nothing was downloaded in this range.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {entries.map((row) => (
                                        <TableRow key={`${row.entry_type}:${row.entry_id}`}>
                                            <TableCell className="text-xs">
                                                {ENTRY_LABEL[row.entry_type]}
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                {row.entry_name ?? '—'}
                                                {row.entry_deleted && (
                                                    <span className="ml-2 text-xs text-muted-foreground">
                                                        (deleted)
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-xs text-muted-foreground">
                                                {row.machine_model ?? '—'}
                                                {row.machine_brand ? ` · ${row.machine_brand}` : ''}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.downloads}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.distinct_customers}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.distinct_boxes}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.latest_released_revision
                                                    ? `#${row.latest_released_revision}`
                                                    : '—'}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
