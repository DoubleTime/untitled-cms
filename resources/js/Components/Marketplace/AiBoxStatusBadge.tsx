import { Badge } from '@/Components/ui/badge';
import type { AiBoxStatus } from '@/types';

const LABELS: Record<AiBoxStatus, string> = {
    pending: 'Pending',
    active: 'Active',
    blocked: 'Blocked',
};

const VARIANTS: Record<AiBoxStatus, 'default' | 'secondary' | 'outline' | 'destructive'> = {
    pending: 'outline',
    active: 'secondary',
    blocked: 'destructive',
};

/**
 * An AI Box is `pending` until a Team Member acknowledges it — RPA-TOOL registers
 * it by itself on first login, so a pending box is one nobody has looked at yet.
 */
export default function AiBoxStatusBadge({ status }: { status: AiBoxStatus }) {
    return <Badge variant={VARIANTS[status] ?? 'outline'}>{LABELS[status] ?? status}</Badge>;
}
