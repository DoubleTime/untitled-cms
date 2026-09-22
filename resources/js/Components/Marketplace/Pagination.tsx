import { router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { Paginated } from '@/types';

/**
 * Server-side pagination for the Download logs, which are too long to hand to the
 * browser whole the way the DataTable indexes do.
 */
export default function Pagination<T>({
    page,
    only,
    preserveState = true,
}: {
    page: Paginated<T>;
    /** Inertia partial-reload keys, so paging a tab does not refetch the rest. */
    only?: string[];
    preserveState?: boolean;
}) {
    if (page.last_page <= 1) {
        return null;
    }

    const go = (url: string | null) => {
        if (!url) return;
        router.visit(url, { preserveScroll: true, preserveState, only });
    };

    return (
        <div className="flex items-center justify-between gap-4">
            <p className="text-sm text-muted-foreground">
                {page.total === 0
                    ? 'No rows'
                    : `Showing ${page.from ?? 0}–${page.to ?? 0} of ${page.total}`}
            </p>
            <div className="flex items-center gap-2">
                <span className="text-sm text-muted-foreground">
                    Page {page.current_page} of {page.last_page}
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    disabled={!page.prev_page_url}
                    onClick={() => go(page.prev_page_url)}
                >
                    <ChevronLeft className="h-4 w-4" />
                    <span className="sr-only">Previous page</span>
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    disabled={!page.next_page_url}
                    onClick={() => go(page.next_page_url)}
                >
                    <ChevronRight className="h-4 w-4" />
                    <span className="sr-only">Next page</span>
                </Button>
            </div>
        </div>
    );
}
