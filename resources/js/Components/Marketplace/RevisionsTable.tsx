import { useState } from 'react';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/Components/ui/alert-dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Copy, Download, MoreHorizontal, Rocket, Trash2, Archive } from 'lucide-react';
import type { Revision } from '@/types';
import RevisionStatusBadge from './RevisionStatusBadge';
import { formatBytes, formatDateTime, shortChecksum } from './format';

interface RevisionsTableProps {
    revisions: Revision[];
    /** Route-name prefix of the owning entry, e.g. 'admin.marketplace.scripts'. */
    routePrefix: string;
    /** The owning catalogue entry's id. */
    entityId: string;
    canRelease: boolean;
    canHardDelete: boolean;
}

/**
 * Revision history for one catalogue entry. Shared by FlowChart Scripts and
 * AI Models — they use the same Revision implementation.
 */
export default function RevisionsTable({
    revisions,
    routePrefix,
    entityId,
    canRelease,
    canHardDelete,
}: RevisionsTableProps) {
    const [pendingDelete, setPendingDelete] = useState<Revision | null>(null);

    const copyChecksum = (sha256: string) => {
        navigator.clipboard
            ?.writeText(sha256)
            .then(() => toast.success('SHA-256 copied to the clipboard.'))
            .catch(() => toast.error('Could not copy the checksum.'));
    };

    if (revisions.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No Revisions yet. Upload one to give RPA-TOOL something to fetch.
            </p>
        );
    }

    return (
        <>
            <div className="rounded-md border overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>#</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Change note</TableHead>
                            <TableHead>File</TableHead>
                            <TableHead>Size</TableHead>
                            <TableHead>SHA-256</TableHead>
                            <TableHead>Uploaded by</TableHead>
                            <TableHead>Released</TableHead>
                            <TableHead>Downloads</TableHead>
                            <TableHead>AI Boxes</TableHead>
                            <TableHead className="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {revisions.map((revision) => (
                            <TableRow key={revision.id}>
                                <TableCell className="font-medium">{revision.number}</TableCell>
                                <TableCell>
                                    <RevisionStatusBadge status={revision.status} />
                                </TableCell>
                                <TableCell className="max-w-[18rem] whitespace-pre-wrap text-sm">
                                    {revision.change_note}
                                </TableCell>
                                <TableCell className="text-sm">{revision.original_filename}</TableCell>
                                <TableCell className="text-sm">{formatBytes(revision.size_bytes)}</TableCell>
                                <TableCell>
                                    <button
                                        type="button"
                                        onClick={() => copyChecksum(revision.sha256)}
                                        className="inline-flex items-center gap-1 font-mono text-xs text-muted-foreground hover:text-foreground"
                                        title={revision.sha256}
                                    >
                                        {shortChecksum(revision.sha256)}
                                        <Copy className="h-3 w-3" />
                                    </button>
                                </TableCell>
                                <TableCell className="text-sm">{revision.uploader?.name ?? '—'}</TableCell>
                                <TableCell className="text-sm">
                                    {revision.released_at ? (
                                        <span>
                                            {revision.releaser?.name ?? '—'}
                                            <br />
                                            <span className="text-xs text-muted-foreground">
                                                {formatDateTime(revision.released_at)}
                                            </span>
                                        </span>
                                    ) : (
                                        '—'
                                    )}
                                </TableCell>
                                <TableCell>
                                    <Badge variant="outline">{revision.downloads_count ?? 0}</Badge>
                                </TableCell>
                                <TableCell>
                                    <Badge variant="outline" title="Distinct AI Boxes that pulled this Revision">
                                        {revision.unique_boxes_count ?? 0}
                                    </Badge>
                                </TableCell>
                                <TableCell className="text-right">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button variant="ghost" className="h-8 w-8 p-0">
                                                <span className="sr-only">Open menu</span>
                                                <MoreHorizontal className="h-4 w-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuLabel>Revision {revision.number}</DropdownMenuLabel>
                                            <DropdownMenuItem
                                                onClick={() => {
                                                    window.location.href = route(
                                                        `${routePrefix}.revisions.download`,
                                                        [entityId, revision.id]
                                                    );
                                                }}
                                            >
                                                <Download className="mr-2 h-4 w-4" /> Download
                                            </DropdownMenuItem>
                                            {canRelease && revision.status === 'draft' && (
                                                <DropdownMenuItem
                                                    onClick={() =>
                                                        router.post(
                                                            route(`${routePrefix}.revisions.release`, [
                                                                entityId,
                                                                revision.id,
                                                            ]),
                                                            {},
                                                            { preserveScroll: true }
                                                        )
                                                    }
                                                >
                                                    <Rocket className="mr-2 h-4 w-4" /> Release
                                                </DropdownMenuItem>
                                            )}
                                            {canRelease && revision.status === 'released' && (
                                                <DropdownMenuItem
                                                    onClick={() =>
                                                        router.post(
                                                            route(`${routePrefix}.revisions.deprecate`, [
                                                                entityId,
                                                                revision.id,
                                                            ]),
                                                            {},
                                                            { preserveScroll: true }
                                                        )
                                                    }
                                                >
                                                    <Archive className="mr-2 h-4 w-4" /> Deprecate
                                                </DropdownMenuItem>
                                            )}
                                            {canHardDelete && (
                                                <DropdownMenuItem
                                                    onClick={() => setPendingDelete(revision)}
                                                    className="text-destructive focus:text-destructive"
                                                >
                                                    <Trash2 className="mr-2 h-4 w-4" /> Hard delete
                                                </DropdownMenuItem>
                                            )}
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>

            <AlertDialog open={pendingDelete !== null} onOpenChange={(open) => !open && setPendingDelete(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Permanently delete Revision {pendingDelete?.number}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            The stored file and the Revision row are removed for good. Recorded Downloads that
                            referenced it are kept — {pendingDelete?.downloads_count ?? 0} so far. This cannot be
                            undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => {
                                if (pendingDelete) {
                                    router.delete(
                                        route(`${routePrefix}.revisions.destroy`, [entityId, pendingDelete.id]),
                                        { preserveScroll: true }
                                    );
                                }
                                setPendingDelete(null);
                            }}
                        >
                            Delete permanently
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
