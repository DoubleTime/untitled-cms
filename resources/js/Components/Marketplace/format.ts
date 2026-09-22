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
