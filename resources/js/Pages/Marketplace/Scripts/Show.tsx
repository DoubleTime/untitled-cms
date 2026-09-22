import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { Download as DownloadRow, FlowchartScript, PageProps, Revision } from '@/types';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Edit, Upload } from 'lucide-react';
import { useState } from 'react';
import { useFlashToast } from '@/hooks/use-flash-toast';
import RevisionsTable from '@/Components/Marketplace/RevisionsTable';
import RevisionUploadDialog from '@/Components/Marketplace/RevisionUploadDialog';
import PreviewImagesManager from '@/Components/Marketplace/PreviewImagesManager';
import DownloadsTable from '@/Components/Marketplace/DownloadsTable';

interface ScriptShowProps extends PageProps {
    script: FlowchartScript;
    revisions: Revision[];
    downloads: DownloadRow[];
    allowedExtensions: string[];
    maxUploadKb: number;
}

export default function Show({
    script,
    revisions,
    downloads,
    allowedExtensions,
    maxUploadKb,
}: ScriptShowProps) {
    useFlashToast();
    const { canEdit, canUpload, canRelease, canHardDelete } = usePage<PageProps>().props;
    const [uploadOpen, setUploadOpen] = useState(false);

    const cover = script.images?.find((image) => image.vault_file)?.vault_file ?? null;
    const latestReleased = revisions.find((revision) => revision.status === 'released');

    return (
        <AuthenticatedLayout header={script.name}>
            <Head title={script.name} />

            <div className="flex flex-col gap-6">
                <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div className="flex gap-4">
                        {cover ? (
                            <img
                                src={cover.url}
                                alt={cover.original_name}
                                className="h-24 w-40 shrink-0 rounded-md border object-cover"
                            />
                        ) : (
                            <div className="flex h-24 w-40 shrink-0 items-center justify-center rounded-md border border-dashed text-xs text-muted-foreground">
                                No Preview Image
                            </div>
                        )}
                        <div>
                            <h1 className="text-3xl font-bold tracking-tight">{script.name}</h1>
                            <p className="text-muted-foreground">
                                {script.machine_model?.machine_brand?.name
                                    ? `${script.machine_model.machine_brand.name} — `
                                    : ''}
                                {script.machine_model?.name ?? 'No Machine Model'}
                                {script.customer ? ` · ${script.customer.company}` : ''}
                            </p>
                            <div className="mt-2 flex flex-wrap gap-2">
                                {latestReleased ? (
                                    <Badge variant="secondary">Latest released: Rev {latestReleased.number}</Badge>
                                ) : (
                                    <Badge variant="outline">No released Revision</Badge>
                                )}
                                <Badge variant="outline">{revisions.length} Revisions</Badge>
                            </div>
                        </div>
                    </div>
                    <div className="flex shrink-0 gap-2">
                        {canUpload && (
                            <Button size="sm" onClick={() => setUploadOpen(true)}>
                                <Upload className="mr-2 h-4 w-4" /> Upload Revision
                            </Button>
                        )}
                        {canEdit && (
                            <Link href={route('admin.marketplace.scripts.edit', script.id)}>
                                <Button variant="outline" size="sm">
                                    <Edit className="mr-2 h-4 w-4" /> Edit
                                </Button>
                            </Link>
                        )}
                    </div>
                </div>

                <Tabs defaultValue="revisions">
                    <TabsList>
                        <TabsTrigger value="revisions">Revisions</TabsTrigger>
                        <TabsTrigger value="images">Preview Images</TabsTrigger>
                        <TabsTrigger value="downloads">Downloads</TabsTrigger>
                        <TabsTrigger value="details">Details</TabsTrigger>
                    </TabsList>

                    <TabsContent value="revisions" className="mt-4 space-y-4">
                        <RevisionsTable
                            revisions={revisions}
                            routePrefix="admin.marketplace.scripts"
                            entityId={script.id}
                            canRelease={Boolean(canRelease)}
                            canHardDelete={Boolean(canHardDelete)}
                        />
                    </TabsContent>

                    <TabsContent value="images" className="mt-4">
                        <PreviewImagesManager
                            scriptId={script.id}
                            images={script.images ?? []}
                            canEdit={Boolean(canEdit)}
                        />
                    </TabsContent>

                    <TabsContent value="downloads" className="mt-4 space-y-4">
                        <p className="text-sm text-muted-foreground">
                            The latest 50 web Downloads — fetches made by a Team Member from this admin.
                        </p>
                        <DownloadsTable downloads={downloads} />
                    </TabsContent>

                    <TabsContent value="details" className="mt-4">
                        <Card className="max-w-2xl">
                            <CardHeader>
                                <CardTitle>Details</CardTitle>
                                <CardDescription>Where this FlowChart Script came from.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">Slug</span>
                                    <span className="font-mono text-xs">{script.slug}</span>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">Created by</span>
                                    <span>{script.creator?.name ?? '—'}</span>
                                </div>
                                {script.description && (
                                    <div className="pt-2">
                                        <p className="mb-1 text-muted-foreground">Description</p>
                                        <p className="whitespace-pre-wrap">{script.description}</p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>

            {canUpload && (
                <RevisionUploadDialog
                    open={uploadOpen}
                    onOpenChange={setUploadOpen}
                    uploadUrl={route('admin.marketplace.scripts.revisions.store', script.id)}
                    allowedExtensions={allowedExtensions}
                    maxUploadKb={maxUploadKb}
                />
            )}
        </AuthenticatedLayout>
    );
}
