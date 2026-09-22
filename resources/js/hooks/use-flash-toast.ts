import { usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';

/**
 * Surfaces the `success` / `error` messages that controllers flash with
 * `redirect()->with(...)` as toasts. Shared from HandleInertiaRequests.
 */
export function useFlashToast() {
    const { flash } = usePage().props as unknown as {
        flash?: { success?: string | null; error?: string | null };
    };
    const lastShown = useRef<string | null>(null);

    useEffect(() => {
        const message = flash?.error ?? flash?.success;

        if (!message || message === lastShown.current) {
            return;
        }

        lastShown.current = message;

        if (flash?.error) {
            toast.error(flash.error);
        } else if (flash?.success) {
            toast.success(flash.success);
        }
    }, [flash?.success, flash?.error]);
}
