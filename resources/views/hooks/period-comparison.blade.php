<div class="akv-card akv-full akv-pad akv-section" data-akv-section="traffic flows" hidden>
    <h4>Previous-period comparison</h4>
    <div class="akv-card-sub">{{ $periodComparison['start'] }} to {{ $periodComparison['end'] }} UTC</div>
    @if($periodComparison['series'] !== [])
        <p class="akv-card-sub">Dashed lines on the traffic chart show the previous period aligned to the current window.</p>
    @endif
    @if($periodComparison['unavailable'] !== [])
        <p class="akv-card-sub">No comparable traffic series for {{ implode(', ', $periodComparison['unavailable']) }}.</p>
    @elseif(!$periodComparison['observed'])
        <p class="akv-card-sub">Akvorado returned no nonzero bitrate in the previous window.</p>
    @endif
    <h5>Largest increases among conversations ranked in both periods</h5>
    @if($periodComparison['increases'] === null)
        <p class="akv-card-sub">Conversation comparison is unavailable for this selection.</p>
    @elseif($periodComparison['increases'] === [])
        <p class="akv-card-sub">No ranked conversation showed an increase in both results.</p>
    @else
        <div class="table-responsive">
            <table class="akv-table">
                <thead><tr><th>Source IP</th><th>Destination IP</th><th>Previous L3 rate</th><th>Current L3 rate</th><th>Increase</th></tr></thead>
                <tbody>
                    @foreach($periodComparison['increases'] as $row)
                        <tr>
                            <td><code>{{ $row['source'] }}</code></td>
                            <td><code>{{ $row['destination'] }}</code></td>
                            <td>{{ $fmt::bitrate($row['previousBps']) }}</td>
                            <td>{{ $fmt::bitrate($row['bps']) }}</td>
                            <td>+{{ $fmt::bitrate($row['increaseBps']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
