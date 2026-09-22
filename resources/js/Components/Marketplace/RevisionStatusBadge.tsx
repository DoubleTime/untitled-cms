import { Badge } from '@/Components/ui/badge';
import type { RevisionStatus } from '@/types';

/**
 * Where a Revision sits in its life: draft, released or deprecated.
 * Only released Revisions are offered to RPA-TOOL by default.
 */
export default function RevisionStatusBadge({ status }: { status: RevisionStatus }) {
    if (status === 'released') {
        return <Badge variant="secondary">Released</Badge>;
    }

    if (status === 'deprecated') {
        return <Badge variant="destructive">Deprecated</Badge>;
    }

    return <Badge variant="outline">Draft</Badge>;
}
