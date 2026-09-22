import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import type { AiBox, DownloadLogRow, InstalledRevision, Paginated, PageProps } from '@/types';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Ban, CheckCircle2, Edit, ShieldCheck } from 'lucide-react';
import { useFlashToast } from '@/hooks/use-flash-toast';
import AiBoxStatusBadge from '@/Components/Marketplace/AiBoxStatusBadge';
import DownloadLogTable from '@/Components/Marketplace/DownloadLogTable';
import InstalledRevisionsTable from '@/Components/Marketplace/InstalledRevisionsTable';
import Pagination from '@/Components/Marketplace/Pagination';
import { formatDateTime, formatRelative } from '@/Components/Marketplace/format';

interface AiBoxShowProps extends PageProps {
    aiBox: AiBox;
    installed: InstalledRevision[];
    downloads: Paginated<DownloadLogRow>;
}

export default function Show({ aiBox, installed, downloads }: AiBoxShowProps) {
    useFlashToast();
    const { canEdit, canBlock } = usePage<PageProps>().props;

    const label = aiBox.name || 'Unlabelled AI Box';
    const outdated = installed.filter((row) => row.outdated).length;

    return (
        <AuthenticatedLayout header={label}>
            <Head title={label} />

            <div className="flex flex-col gap-6">
                <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">{label}</h1>
                        <p className="font-mono text-sm text-muted-foreground">{aiBox.motherboard_uuid}</p>
                        <p className="text-muted-foreground">
                            {aiBox.customer ? (
                                <Link
                                    href={route('admin.marketplace.customers.show', aiBox.customer.id)}
                                    className="hover:underline"
                                >
                                    {aiBox.customer.company}
                                </Link>
                            ) : (
                                'No Customer'
                            )}
                            {aiBox.location ? ` · ${aiBox.location}` : ''}
                        </p>
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            <AiBoxStatusBadge status={aiBox.status} />
                            <Badge variant="outline">{aiBox.downloads_count ?? 0} Downloads</Badge>
                            <Badge variant="outline">{installed.length} installed</Badge>
                            {outdated > 0 && <Badge variant="destructive">{outdated} outdated</Badge>}
                            <span className="text-sm text-muted-foreground">
                                Last seen {formatRelative(aiBox.last_seen_at)}
                            </span>
                        </div>
                    </div>

                    <div className="flex shrink-0 flex-wrap gap-2">
                        {canEdit && aiBox.status === 'pending' && (
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        route('admin.marketplace.ai-boxes.activate', aiBox.id),
                                        {},
                                        { preserveScroll: true }
                                    )
                                }
                            >
                                <CheckCircle2 className="mr-2 h-4 w-4" /> Mark active
                            </Button>
                        )}
                        {canBlock &&
                            (aiBox.status === 'blocked' ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            route('admin.marketplace.ai-boxes.unblock', aiBox.id),
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
                                                route('admin.marketplace.ai-boxes.block', aiBox.id),
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
                            <Link href={route('admin.marketplace.ai-boxes.edit', aiBox.id)}>
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
                            emptyMessage="This AI Box has not downloaded anything yet."
                        />
                        <Pagination page={downloads} />
                    </TabsContent>

                    <TabsContent value="details" className="mt-4">
                        <Card className="max-w-2xl">
                            <CardHeader>
                                <CardTitle>Details</CardTitle>
                                <CardDescription>
                                    How this AI Box came to be registered, and when it was last heard from.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">Motherboard UUID</span>
                                    <span className="font-mono text-xs">{aiBox.motherboard_uuid}</span>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">Machine Model</span>
                                    <span>
                                        {aiBox.machine_model?.machine_brand?.name
                                            ? `${aiBox.machine_model.machine_brand.name} — `
                                            : ''}
                                        {aiBox.machine_model?.name ?? '—'}
                                    </span>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">First signed in by</span>
                                    <span>
                                        {aiBox.first_user
                                            ? `${aiBox.first_user.name} (${aiBox.first_user.email})`
                                            : '—'}
                                    </span>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">Registered</span>
                                    <span>{formatDateTime(aiBox.created_at)}</span>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">Last seen</span>
                                    <span>{formatDateTime(aiBox.last_seen_at)}</span>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">Last IP</span>
                                    <span className="font-mono text-xs">{aiBox.last_ip ?? '—'}</span>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">Location</span>
                                    <span>{aiBox.location || '—'}</span>
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>
        </AuthenticatedLayout>
    );
}
