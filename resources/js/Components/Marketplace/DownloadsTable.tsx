import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import type { Download } from '@/types';
import { formatDateTime } from './format';

/**
 * The latest web Downloads of a catalogue entry — fetches made by a Team Member
 * from the admin. RPA-TOOL's own fetches are recorded with source `api` and get
 * their own log in Phase 5.
 */
export default function DownloadsTable({ downloads }: { downloads: Download[] }) {
    if (downloads.length === 0) {
        return <p className="text-sm text-muted-foreground">No web Downloads recorded yet.</p>;
    }

    return (
        <div className="rounded-md border overflow-x-auto">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Who</TableHead>
                        <TableHead>When</TableHead>
                        <TableHead>Revision</TableHead>
                        <TableHead>IP</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {downloads.map((download) => (
                        <TableRow key={download.id}>
                            <TableCell className="text-sm">{download.user?.name ?? '—'}</TableCell>
                            <TableCell className="text-sm">{formatDateTime(download.created_at)}</TableCell>
                            <TableCell className="text-sm">{download.revision?.number ?? '—'}</TableCell>
                            <TableCell className="font-mono text-xs text-muted-foreground">
                                {download.ip ?? '—'}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
