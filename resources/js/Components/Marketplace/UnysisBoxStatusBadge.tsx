import { Badge } from '@/Components/ui/badge';
import type { UnysisBoxStatus } from '@/types';

const LABELS: Record<UnysisBoxStatus, string> = {
    pending: 'Pending',
    active: 'Active',
    blocked: 'Blocked',
};

const VARIANTS: Record<UnysisBoxStatus, 'default' | 'secondary' | 'outline' | 'destructive'> = {
    pending: 'outline',
    active: 'secondary',
    blocked: 'destructive',
};

/**
 * An UNYSIS Box is `pending` until a Team Member acknowledges it — RPA-TOOL registers
 * it by itself on first login, so a pending box is one nobody has looked at yet.
 */
export default function UnysisBoxStatusBadge({ status }: { status: UnysisBoxStatus }) {
    return <Badge variant={VARIANTS[status] ?? 'outline'}>{LABELS[status] ?? status}</Badge>;
}
