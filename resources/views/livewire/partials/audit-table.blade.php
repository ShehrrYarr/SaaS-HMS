<div class="table-responsive">
    <table class="table table-hms table-hover mb-0">
        <thead class="table-light">
            <tr>
                <x-th field="created_at" :sort="$sortField" :dir="$sortDirection">When</x-th>
                @if ($showHospital ?? false)<th>Hospital</th>@endif
                <th>User</th><th>Event</th><th>Description</th><th>Changes</th><th>IP</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($logs as $log)
                <tr wire:key="log-{{ $log->id }}">
                    <td class="text-nowrap fs-12">{{ fmt_datetime($log->created_at) }}</td>
                    @if ($showHospital ?? false)<td class="fs-12">{{ $log->hospital?->name ?? 'Platform' }}</td>@endif
                    <td class="fs-12">{{ $log->user?->name ?? 'System' }}</td>
                    <td><x-status :value="$log->event" /></td>
                    <td class="fs-12">{{ $log->description }}</td>
                    <td class="fs-12" style="max-width: 360px;">
                        @if ($log->new_values || $log->old_values)
                            <details>
                                <summary class="text-primary">{{ count($log->new_values ?: $log->old_values) }} field(s)</summary>
                                <table class="table table-sm mb-0 mt-1">
                                    @foreach (array_unique(array_merge(array_keys($log->old_values ?? []), array_keys($log->new_values ?? []))) as $field)
                                        <tr>
                                            <td class="text-muted">{{ $field }}</td>
                                            <td class="text-danger text-break">{{ is_scalar($log->old_values[$field] ?? null) ? \Illuminate\Support\Str::limit((string) $log->old_values[$field], 40) : '' }}</td>
                                            <td class="text-success text-break">{{ is_scalar($log->new_values[$field] ?? null) ? \Illuminate\Support\Str::limit((string) $log->new_values[$field], 40) : json_encode($log->new_values[$field] ?? null) }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            </details>
                        @endif
                    </td>
                    <td class="fs-12 text-muted">{{ $log->ip_address }}</td>
                </tr>
            @empty
                <x-empty-row :colspan="($showHospital ?? false) ? 7 : 6" message="No activity recorded." />
            @endforelse
        </tbody>
    </table>
</div>
<div class="card-footer">{{ $logs->links() }}</div>
