import { Link } from '@inertiajs/react';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { Badge } from '@/Components/ui/badge';
import type { DownloadLogRow } from '@/types';
import { formatDateTime } from './format';

/**
 * The Download log — one row per recorded fetch of a Revision file.
 *
 * The narrower `DownloadsTable` on the catalogue Show pages lists only that
 * entry's own web fetches; this one is the full log, so it carries the entry, the
 * source and the UNYSIS Box as well. Rows are never deleted, so an entry that has
 * since been hard deleted still appears, without a link.
 */
export function entryRoute(row: Pick<DownloadLogRow, 'entry_type' | 'entry_id'>): string {
    return row.entry_type === 'script'
        ? route('admin.marketplace.scripts.show', row.entry_id)
        : route('admin.marketplace.ai-models.show', row.entry_id);
}

export function entryTypeLabel(type: DownloadLogRow['entry_type']): string {
    return type === 'script' ? 'Script' : 'AI Model';
}

export default function DownloadLogTable({
    downloads,
    showEntry = true,
    showBox = true,
    emptyMessage = 'No Downloads recorded yet.',
}: {
    downloads: DownloadLogRow[];
    showEntry?: boolean;
    showBox?: boolean;
    emptyMessage?: string;
}) {
    if (downloads.length === 0) {
        return <p className="text-sm text-muted-foreground">{emptyMessage}</p>;
    }

    return (
        <div className="rounded-md border overflow-x-auto">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>When</TableHead>
                        <TableHead>Source</TableHead>
                        {showEntry && <TableHead>Entry</TableHead>}
                        <TableHead>Revision</TableHead>
                        <TableHead>Who</TableHead>
                        {showBox && <TableHead>UNYSIS Box</TableHead>}
                        <TableHead>IP</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {downloads.map((row) => (
                        <TableRow key={row.id}>
                            <TableCell className="whitespace-nowrap text-sm">
                                {formatDateTime(row.created_at)}
                            </TableCell>
                            <TableCell>
                                <Badge variant={row.source === 'api' ? 'secondary' : 'outline'}>
                                    {row.source === 'api' ? 'RPA-TOOL' : 'Admin'}
                                </Badge>
                            </TableCell>
                            {showEntry && (
                                <TableCell className="text-sm">
                                    <span className="mr-2 text-xs text-muted-foreground">
                                        {entryTypeLabel(row.entry_type)}
                                    </span>
                                    {row.entry_name == null ? (
                                        <span className="text-muted-foreground">(removed)</span>
                                    ) : row.entry_deleted ? (
                                        <span className="text-muted-foreground line-through">
                                            {row.entry_name}
                                        </span>
                                    ) : (
                                        <Link href={entryRoute(row)} className="font-medium hover:underline">
                                            {row.entry_name}
                                        </Link>
                                    )}
                                </TableCell>
                            )}
                            <TableCell className="text-sm">
                                {row.revision?.number != null ? `Rev ${row.revision.number}` : '—'}
                            </TableCell>
                            <TableCell className="text-sm">{row.user?.name ?? '—'}</TableCell>
                            {showBox && (
                                <TableCell className="text-sm">
                                    {row.unysis_box ? (
                                        <Link
                                            href={route('admin.marketplace.unysis-boxes.show', row.unysis_box.id)}
                                            className="hover:underline"
                                        >
                                            {row.unysis_box.name || row.unysis_box.motherboard_uuid}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </TableCell>
                            )}
                            <TableCell className="font-mono text-xs text-muted-foreground">
                                {row.ip ?? '—'}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
