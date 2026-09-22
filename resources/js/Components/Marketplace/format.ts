/** Human-readable byte size, shared by the catalogue pages. */
export function formatBytes(bytes: number | null | undefined): string {
    if (!bytes || bytes < 0) {
        return '—';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${value.toFixed(value >= 10 || unit === 0 ? 0 : 1)} ${units[unit]}`;
}

/** First 12 characters of a SHA-256, for display next to a copy button. */
export function shortChecksum(sha256: string | null | undefined): string {
    return sha256 ? sha256.slice(0, 12) : '—';
}

export function formatDateTime(value: string | null | undefined): string {
    return value ? new Date(value).toLocaleString() : '—';
}

/** Relative time ("3 minutes ago"), for last-seen columns. */
export function formatRelative(value: string | null | undefined): string {
    if (!value) {
        return 'Never';
    }

    const then = new Date(value).getTime();

    if (Number.isNaN(then)) {
        return '—';
    }

    const seconds = Math.round((then - Date.now()) / 1000);
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['second', 60],
        ['minute', 60],
        ['hour', 24],
        ['day', 30],
        ['month', 12],
        ['year', Number.POSITIVE_INFINITY],
    ];

    const formatter = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
    let value_ = seconds;

    for (const [unit, size] of units) {
        if (Math.abs(value_) < size) {
            return formatter.format(Math.round(value_), unit);
        }
        value_ /= size;
    }

    return formatter.format(Math.round(value_), 'year');
}
