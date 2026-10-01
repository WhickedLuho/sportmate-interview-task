export function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

export function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleDateString() : '—';
}

export function formatNumber(value: number): string {
    return new Intl.NumberFormat().format(value);
}
