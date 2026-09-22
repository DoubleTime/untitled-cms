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
import type { InstalledRevision } from '@/types';
import RevisionStatusBadge from './RevisionStatusBadge';
import { entryRoute, entryTypeLabel } from './DownloadLogTable';
import { formatDateTime } from './format';

/**
 * What an AI Box is running: the latest Download of each catalogue entry by that
 * box. Nothing is stored — a box never reports back, so the Download log is the
 * only evidence of what it has.
 */
export default function InstalledRevisionsTable({ installed }: { installed: InstalledRevision[] }) {
    if (installed.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                This AI Box has not downloaded anything yet, so there is nothing installed to report.
            </p>
        );
    }

    return (
        <div className="rounded-md border overflow-x-auto">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Type</TableHead>
                        <TableHead>Entry</TableHead>
                        <TableHead>Machine Model</TableHead>
                        <TableHead>Installed</TableHead>
                        <TableHead>Downloaded</TableHead>
                        <TableHead>Latest released</TableHead>
                        <TableHead></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {installed.map((row) => (
                        <TableRow key={row.key}>
                            <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
                                {entryTypeLabel(row.entry_type)}
                            </TableCell>
                            <TableCell className="text-sm">
                                {row.entry_name == null ? (
                                    <span className="text-muted-foreground">(removed)</span>
                                ) : row.entry_deleted ? (
                                    <span className="text-muted-foreground line-through">{row.entry_name}</span>
                                ) : (
                                    <Link href={entryRoute(row)} className="font-medium hover:underline">
                                        {row.entry_name}
                                    </Link>
                                )}
                            </TableCell>
                            <TableCell className="text-sm">
                                {row.machine_brand ? `${row.machine_brand} — ` : ''}
                                {row.machine_model ?? '—'}
                            </TableCell>
                            <TableCell className="whitespace-nowrap text-sm">
                                <span className="mr-2 font-medium">
                                    {row.installed_number != null ? `Rev ${row.installed_number}` : '—'}
                                </span>
                                {row.installed_status && <RevisionStatusBadge status={row.installed_status} />}
                            </TableCell>
                            <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                                {formatDateTime(row.downloaded_at)}
                            </TableCell>
                            <TableCell className="text-sm">
                                {row.latest_released_number != null ? `Rev ${row.latest_released_number}` : '—'}
                            </TableCell>
                            <TableCell>
                                {row.outdated && <Badge variant="destructive">Outdated</Badge>}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
