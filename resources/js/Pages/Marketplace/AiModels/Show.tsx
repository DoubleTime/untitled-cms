import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { AiModel, Download as DownloadRow, PageProps, Revision } from '@/types';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Edit, Upload } from 'lucide-react';
import { useState } from 'react';
import { useFlashToast } from '@/hooks/use-flash-toast';
import RevisionsTable from '@/Components/Marketplace/RevisionsTable';
import RevisionUploadDialog from '@/Components/Marketplace/RevisionUploadDialog';
import DownloadsTable from '@/Components/Marketplace/DownloadsTable';

interface AiModelShowProps extends PageProps {
    aiModel: AiModel;
    revisions: Revision[];
    downloads: DownloadRow[];
    allowedExtensions: string[];
    maxUploadKb: number;
}

export default function Show({
    aiModel,
    revisions,
    downloads,
    allowedExtensions,
    maxUploadKb,
}: AiModelShowProps) {
    useFlashToast();
    const { canEdit, canUpload, canRelease, canHardDelete } = usePage<PageProps>().props;
    const [uploadOpen, setUploadOpen] = useState(false);

    const latestReleased = revisions.find((revision) => revision.status === 'released');

    return (
        <AuthenticatedLayout header={aiModel.name}>
            <Head title={aiModel.name} />

            <div className="flex flex-col gap-6">
                <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">{aiModel.name}</h1>
                        <p className="text-muted-foreground">
                            {aiModel.machine_model?.machine_brand?.name
                                ? `${aiModel.machine_model.machine_brand.name} — `
                                : ''}
                            {aiModel.machine_model?.name ?? 'No Machine Model'}
                            {aiModel.customer ? ` · ${aiModel.customer.company}` : ''}
                        </p>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {latestReleased ? (
                                <Badge variant="secondary">Latest released: Rev {latestReleased.number}</Badge>
                            ) : (
                                <Badge variant="outline">No released Revision</Badge>
                            )}
                            <Badge variant="outline">{revisions.length} Revisions</Badge>
                            {aiModel.framework && <Badge variant="outline">{aiModel.framework}</Badge>}
                            {aiModel.input_size && <Badge variant="outline">{aiModel.input_size}</Badge>}
                        </div>
                    </div>
                    <div className="flex shrink-0 gap-2">
                        {canUpload && (
                            <Button size="sm" onClick={() => setUploadOpen(true)}>
                                <Upload className="mr-2 h-4 w-4" /> Upload Revision
                            </Button>
                        )}
                        {canEdit && (
                            <Link href={route('admin.marketplace.ai-models.edit', aiModel.id)}>
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
                        <TabsTrigger value="downloads">Downloads</TabsTrigger>
                        <TabsTrigger value="details">Details</TabsTrigger>
                    </TabsList>

                    <TabsContent value="revisions" className="mt-4 space-y-4">
                        <RevisionsTable
                            revisions={revisions}
                            routePrefix="admin.marketplace.ai-models"
                            entityId={aiModel.id}
                            canRelease={Boolean(canRelease)}
                            canHardDelete={Boolean(canHardDelete)}
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
                                <CardDescription>What this AI Model expects and produces.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">Slug</span>
                                    <span className="font-mono text-xs">{aiModel.slug}</span>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">Framework</span>
                                    <span>{aiModel.framework || '—'}</span>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">Input size</span>
                                    <span>{aiModel.input_size || '—'}</span>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">Created by</span>
                                    <span>{aiModel.creator?.name ?? '—'}</span>
                                </div>
                                {aiModel.labels && (
                                    <div className="pt-2">
                                        <p className="mb-1 text-muted-foreground">Labels</p>
                                        <p className="whitespace-pre-wrap font-mono text-xs">{aiModel.labels}</p>
                                    </div>
                                )}
                                {aiModel.description && (
                                    <div className="pt-2">
                                        <p className="mb-1 text-muted-foreground">Description</p>
                                        <p className="whitespace-pre-wrap">{aiModel.description}</p>
                                    </div>
                                )}
                                {aiModel.notes && (
                                    <div className="pt-2">
                                        <p className="mb-1 text-muted-foreground">Notes</p>
                                        <p className="whitespace-pre-wrap">{aiModel.notes}</p>
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
                    uploadUrl={route('admin.marketplace.ai-models.revisions.store', aiModel.id)}
                    allowedExtensions={allowedExtensions}
                    maxUploadKb={maxUploadKb}
                />
            )}
        </AuthenticatedLayout>
    );
}
