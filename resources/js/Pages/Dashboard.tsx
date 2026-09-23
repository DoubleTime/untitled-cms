import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/Components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import RevisionStatusBadge from '@/Components/Marketplace/RevisionStatusBadge';
import UnysisBoxStatusBadge from '@/Components/Marketplace/UnysisBoxStatusBadge';
import { formatDateTime, formatRelative } from '@/Components/Marketplace/format';
import type { RevisionStatus, UnysisBoxStatus } from '@/types';
import {
    Area,
    AreaChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

type EntryType = 'script' | 'ai_model';

interface EntryCard {
    released: number;
    total: number;
}

interface Props {
    cards: {
        scripts: EntryCard | null;
        aiModels: EntryCard | null;
        customers: { active: number; total: number } | null;
        unysisBoxes: { active: number; pending: number; blocked: number } | null;
        downloads: {
            last_seven_days: number;
            previous_seven_days: number;
            delta: number;
        } | null;
    };
    downloadsPerDay: { date: string; downloads: number }[] | null;
    latestRevisions:
        | {
              id: string;
              entry_type: EntryType;
              entry_id: string;
              entry_name: string | null;
              machine_model: string | null;
              number: number;
              status: RevisionStatus;
              uploaded_at: string | null;
              uploaded_by: string | null;
          }[]
        | null;
    recentBoxes:
        | {
              id: string;
              name: string | null;
              motherboard_uuid: string;
              status: UnysisBoxStatus;
              last_seen_at: string | null;
              customer_code: string | null;
              customer_company: string | null;
          }[]
        | null;
}

const ENTRY_LABEL: Record<EntryType, string> = {
    script: 'Script',
    ai_model: 'AI Model',
};

function StatCard({
    title,
    description,
    value,
    footer,
}: {
    title: string;
    description: string;
    value: string;
    footer?: React.ReactNode;
}) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardDescription>{description}</CardDescription>
                <CardTitle className="text-2xl tabular-nums">{value}</CardTitle>
            </CardHeader>
            <CardContent className="pt-0 text-xs text-muted-foreground">
                <span className="sr-only">{title}</span>
                {footer}
            </CardContent>
        </Card>
    );
}

export default function Dashboard({
    cards,
    downloadsPerDay,
    latestRevisions,
    recentBoxes,
}: Props) {
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        if (params.get('verified') === '1') {
            toast.success('Email verified! Welcome aboard.');
            router.replace({ url: route('admin.dashboard'), preserveScroll: true });
        }
    }, []);

    const delta = cards.downloads?.delta ?? 0;

    return (
        <AuthenticatedLayout header="Dashboard">
            <Head title="Dashboard" />

            <div className="flex flex-1 flex-col gap-4">
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    {cards.scripts && (
                        <StatCard
                            title="Scripts"
                            description="Scripts released"
                            value={`${cards.scripts.released} / ${cards.scripts.total}`}
                            footer="Scripts with at least one released Revision"
                        />
                    )}
                    {cards.aiModels && (
                        <StatCard
                            title="AI Models"
                            description="AI Models released"
                            value={`${cards.aiModels.released} / ${cards.aiModels.total}`}
                            footer="AI Models with at least one released Revision"
                        />
                    )}
                    {cards.customers && (
                        <StatCard
                            title="Customers"
                            description="Active Customers"
                            value={String(cards.customers.active)}
                            footer={`${cards.customers.total} in total`}
                        />
                    )}
                    {cards.unysisBoxes && (
                        <StatCard
                            title="UNYSIS Boxes"
                            description="Active UNYSIS Boxes"
                            value={String(cards.unysisBoxes.active)}
                            footer={`${cards.unysisBoxes.pending} pending · ${cards.unysisBoxes.blocked} blocked`}
                        />
                    )}
                    {cards.downloads && (
                        <StatCard
                            title="Downloads"
                            description="Downloads, last 7 days"
                            value={String(cards.downloads.last_seven_days)}
                            footer={
                                <span>
                                    {delta === 0
                                        ? 'Level with the previous 7 days'
                                        : `${delta > 0 ? '+' : ''}${delta} vs the previous 7 days`}
                                </span>
                            }
                        />
                    )}
                </div>

                {downloadsPerDay && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Downloads per day</CardTitle>
                            <CardDescription>
                                Every recorded fetch of a Revision file over the last 30 days,
                                from RPA-TOOL and from the admin pages.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="h-[280px]">
                            <ResponsiveContainer width="100%" height="100%">
                                <AreaChart data={downloadsPerDay}>
                                    <CartesianGrid strokeDasharray="3 3" vertical={false} />
                                    <XAxis
                                        dataKey="date"
                                        fontSize={11}
                                        tickLine={false}
                                        axisLine={false}
                                        tickFormatter={(value: string) => value.slice(5)}
                                    />
                                    <YAxis
                                        fontSize={11}
                                        tickLine={false}
                                        axisLine={false}
                                        allowDecimals={false}
                                    />
                                    <Tooltip />
                                    <Area
                                        type="monotone"
                                        dataKey="downloads"
                                        name="Downloads"
                                        stroke="var(--primary)"
                                        fill="var(--primary)"
                                        fillOpacity={0.15}
                                        strokeWidth={2}
                                    />
                                </AreaChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    {latestRevisions && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Latest Revisions</CardTitle>
                                <CardDescription>
                                    The last {latestRevisions.length || 10} uploads across Scripts and AI Models.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Entry</TableHead>
                                                <TableHead>Rev</TableHead>
                                                <TableHead>Status</TableHead>
                                                <TableHead>Uploaded</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {latestRevisions.length === 0 && (
                                                <TableRow>
                                                    <TableCell colSpan={4} className="text-muted-foreground">
                                                        No Revisions uploaded yet.
                                                    </TableCell>
                                                </TableRow>
                                            )}
                                            {latestRevisions.map((revision) => (
                                                <TableRow key={revision.id}>
                                                    <TableCell>
                                                        <div className="font-medium">
                                                            {revision.entry_name ?? '—'}
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            {ENTRY_LABEL[revision.entry_type]}
                                                            {revision.machine_model
                                                                ? ` · ${revision.machine_model}`
                                                                : ''}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell className="tabular-nums">
                                                        #{revision.number}
                                                    </TableCell>
                                                    <TableCell>
                                                        <RevisionStatusBadge status={revision.status} />
                                                    </TableCell>
                                                    <TableCell className="text-xs text-muted-foreground">
                                                        {formatDateTime(revision.uploaded_at)}
                                                        {revision.uploaded_by ? ` · ${revision.uploaded_by}` : ''}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {recentBoxes && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Recently seen UNYSIS Boxes</CardTitle>
                                <CardDescription>
                                    The boxes that last checked in through RPA-TOOL.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Box</TableHead>
                                                <TableHead>Customer</TableHead>
                                                <TableHead>Status</TableHead>
                                                <TableHead>Last seen</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {recentBoxes.length === 0 && (
                                                <TableRow>
                                                    <TableCell colSpan={4} className="text-muted-foreground">
                                                        No UNYSIS Box has checked in yet.
                                                    </TableCell>
                                                </TableRow>
                                            )}
                                            {recentBoxes.map((box) => (
                                                <TableRow key={box.id}>
                                                    <TableCell>
                                                        <Link
                                                            href={route('admin.marketplace.unysis-boxes.show', box.id)}
                                                            className="font-medium hover:underline"
                                                        >
                                                            {box.name ?? box.motherboard_uuid}
                                                        </Link>
                                                        <div className="font-mono text-xs text-muted-foreground">
                                                            {box.motherboard_uuid}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell className="text-xs">
                                                        {box.customer_code ?? '—'}
                                                    </TableCell>
                                                    <TableCell>
                                                        <UnysisBoxStatusBadge status={box.status} />
                                                    </TableCell>
                                                    <TableCell className="text-xs text-muted-foreground">
                                                        {formatRelative(box.last_seen_at)}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
