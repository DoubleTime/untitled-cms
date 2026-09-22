import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import type { UnysisBox, DownloadLogRow, InstalledRevision, Paginated, PageProps } from '@/types';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Ban, CheckCircle2, Edit, ShieldCheck } from 'lucide-react';
import { useFlashToast } from '@/hooks/use-flash-toast';
import UnysisBoxStatusBadge from '@/Components/Marketplace/UnysisBoxStatusBadge';
import DownloadLogTable from '@/Components/Marketplace/DownloadLogTable';
import InstalledRevisionsTable from '@/Components/Marketplace/InstalledRevisionsTable';
import Pagination from '@/Components/Marketplace/Pagination';
import { formatDateTime, formatRelative } from '@/Components/Marketplace/format';

interface UnysisBoxShowProps extends PageProps {
    unysisBox: UnysisBox;
    installed: InstalledRevision[];
    downloads: Paginated<DownloadLogRow>;
}

export default function Show({ unysisBox, installed, downloads }: UnysisBoxShowProps) {
    useFlashToast();
    const { canEdit, canBlock } = usePage<PageProps>().props;

    const label = unysisBox.name || 'Unlabelled UNYSIS Box';
    const outdated = installed.filter((row) => row.outdated).length;

    return (
        <AuthenticatedLayout header={label}>
            <Head title={label} />

            <div className="flex flex-col gap-6">
                <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">{label}</h1>
                        <p className="font-mono text-sm text-muted-foreground">{unysisBox.motherboard_uuid}</p>
                        <p className="text-muted-foreground">
                            {unysisBox.customer ? (
                                <Link
                                    href={route('admin.marketplace.customers.show', unysisBox.customer.id)}
                                    className="hover:underline"
                                >
                                    {unysisBox.customer.company}
                                </Link>
                            ) : (
                                'No Customer'
                            )}
                            {unysisBox.location ? ` · ${unysisBox.location}` : ''}
                        </p>
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            <UnysisBoxStatusBadge status={unysisBox.status} />
                            <Badge variant="outline">{unysisBox.downloads_count ?? 0} Downloads</Badge>
                            <Badge variant="outline">{installed.length} installed</Badge>
                            {outdated > 0 && <Badge variant="destructive">{outdated} outdated</Badge>}
                            <span className="text-sm text-muted-foreground">
                                Last seen {formatRelative(unysisBox.last_seen_at)}
                            </span>
                        </div>
                    </div>

                    <div className="flex shrink-0 flex-wrap gap-2">
                        {canEdit && unysisBox.status === 'pending' && (
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        route('admin.marketplace.unysis-boxes.activate', unysisBox.id),
                                        {},
                                        { preserveScroll: true }
                                    )
                                }
                            >
                                <CheckCircle2 className="mr-2 h-4 w-4" /> Mark active
                            </Button>
                        )}
                        {canBlock &&
                            (unysisBox.status === 'blocked' ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            route('admin.marketplace.unysis-boxes.unblock', unysisBox.id),
                                            {},
                                            { preserveScroll: true }
                                        )
                                    }
                                >
                                    <ShieldCheck className="mr-2 h-4 w-4" /> Unblock
                                </Button>
                            ) : (
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    onClick={() => {
                                        if (
                                            confirm(
                                                `Block "${label}"? Its RPA-TOOL sessions are revoked immediately.`
                                            )
                                        ) {
                                            router.post(
                                                route('admin.marketplace.unysis-boxes.block', unysisBox.id),
                                                {},
                                                { preserveScroll: true }
                                            );
                                        }
                                    }}
                                >
                                    <Ban className="mr-2 h-4 w-4" /> Block
                                </Button>
                            ))}
                        {canEdit && (
                            <Link href={route('admin.marketplace.unysis-boxes.edit', unysisBox.id)}>
                                <Button variant="outline" size="sm">
                                    <Edit className="mr-2 h-4 w-4" /> Edit
                                </Button>
                            </Link>
                        )}
                    </div>
                </div>

                <Tabs defaultValue="installed">
                    <TabsList>
                        <TabsTrigger value="installed">Installed</TabsTrigger>
                        <TabsTrigger value="downloads">Downloads</TabsTrigger>
                        <TabsTrigger value="details">Details</TabsTrigger>
                    </TabsList>

                    <TabsContent value="installed" className="mt-4 space-y-4">
                        <p className="text-sm text-muted-foreground">
                            Derived from this box's Download log: the most recent Revision it fetched of each
                            catalogue entry. A box never reports back, so this is what it is believed to be
                            running.
                        </p>
                        <InstalledRevisionsTable installed={installed} />
                    </TabsContent>

                    <TabsContent value="downloads" className="mt-4 space-y-4">
                        <DownloadLogTable
                            downloads={downloads.data}
                            showBox={false}
                            emptyMessage="This UNYSIS Box has not downloaded anything yet."
                        />
                        <Pagination page={downloads} />
                    </TabsContent>

                    <TabsContent value="details" className="mt-4">
                        <Card className="max-w-2xl">
                            <CardHeader>
                                <CardTitle>Details</CardTitle>
                                <CardDescription>
                                    How this UNYSIS Box came to be registered, and when it was last heard from.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">Motherboard UUID</span>
                                    <span className="font-mono text-xs">{unysisBox.motherboard_uuid}</span>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">Machine Model</span>
                                    <span>
                                        {unysisBox.machine_model?.machine_brand?.name
                                            ? `${unysisBox.machine_model.machine_brand.name} — `
                                            : ''}
                                        {unysisBox.machine_model?.name ?? '—'}
                                    </span>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">First signed in by</span>
                                    <span>
                                        {unysisBox.first_user
                                            ? `${unysisBox.first_user.name} (${unysisBox.first_user.email})`
                                            : '—'}
                                    </span>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">Registered</span>
                                    <span>{formatDateTime(unysisBox.created_at)}</span>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">Last seen</span>
                                    <span>{formatDateTime(unysisBox.last_seen_at)}</span>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">Last IP</span>
                                    <span className="font-mono text-xs">{unysisBox.last_ip ?? '—'}</span>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">Location</span>
                                    <span>{unysisBox.location || '—'}</span>
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>
        </AuthenticatedLayout>
    );
}
