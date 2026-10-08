{{-- Audit rows from AuditPresenter::row(): when / what / where / how / outcome (plan 1.7). --}}
@if ($rows->isEmpty())
    <p class="dsh-meta">Nothing recorded yet.</p>
@else
    <div style="overflow-x: auto;">
        <table class="dsh-table">
            <thead><tr><th>When</th><th>What</th><th>Where</th><th>How</th><th>Outcome</th></tr></thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr wire:key="dsh-audit-{{ $row['id'] }}">
                        <td style="white-space: nowrap;">{{ $row['when']?->format('M j, Y H:i') }}</td>
                        <td>{{ $row['what'] }}</td>
                        <td>{{ $row['where'] ?: '—' }}</td>
                        <td>{{ $row['how'] ?: '—' }}</td>
                        <td>{{ $row['outcome'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
