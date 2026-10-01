export function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

export function formatTime(value: string | null): string {
    return value
        ? new Date(value).toLocaleTimeString([], {
              hour: '2-digit',
              minute: '2-digit',
          })
        : '—';
}

export function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleDateString() : '—';
}

export function formatNumber(value: number): string {
    return new Intl.NumberFormat().format(value);
}
