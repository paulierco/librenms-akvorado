@php
    $fmt = \LibreNMS\Plugins\AkvoradoFlow\Services\FlowFormatter::class;
    $isPort = ($panel['ifIndex'] ?? null) !== null;
    $metric = $panel['metric'] ?? 'l3bps';
    $portCombinedFallback = $isPort && !empty($panel['portCombinedFallback']);
    $mainCurves = $isPort
        ? ($portCombinedFallback
            ? ['Observed selected port (combined)' => ($panel['traffic'] ?? null)]
            : ['Observed InIf' => ($panel['trafficIn'] ?? null), 'Observed OutIf' => ($panel['trafficOut'] ?? null)])
        : ['Observed L3' => ($panel['traffic'] ?? null)];
    $mainCurves = array_filter($mainCurves, static fn ($curve) => is_array($curve) && count($curve['samples'] ?? []) > 1);
    $firstCurve = count($mainCurves) ? reset($mainCurves) : null;
    $mainSeries = [];
    foreach ($mainCurves as $name => $curve) {
        $mainSeries[] = ['label' => $name, 'values' => array_values($curve['plot'] ?? $curve['samples'] ?? [])];
    }
    $periodComparison = $panel['periodComparison'] ?? null;
    $chartPayload = [
        'main' => [
            'labels' => array_values($firstCurve['plotLabels'] ?? $firstCurve['labels'] ?? []),
            'series' => array_merge($mainSeries, $periodComparison['series'] ?? []),
            'unit' => $metric,
            'timestamps' => array_values($firstCurve['plotTimestamps'] ?? []),
            'partialTailExcluded' => !empty($firstCurve['partialTailExcluded']),
        ],
        'ports' => [
            'labels' => array_values($panel['portTimeline']['plotLabels'] ?? $panel['portTimeline']['labels'] ?? []),
            'series' => array_map(static fn ($series) => ['label' => (string) $series['label'], 'values' => array_values($series['plot'] ?? $series['samples'] ?? [])], $panel['portTimeline']['series'] ?? []),
            'unit' => 'l3bps',
            'partialTailExcluded' => !empty($panel['portTimeline']['partialTailExcluded']),
        ],
        'donut' => array_values($panel['portBreakdown'] ?? []),
        'member_in' => [
            'labels' => array_values($panel['lagMemberTraffic']['in']['labels'] ?? []),
            'series' => array_map(static fn ($row) => ['label' => (string) $row['label'], 'values' => array_values($row['samples'])], $panel['lagMemberTraffic']['in']['series'] ?? []),
            'unit' => 'l3bps',
        ],
        'member_out' => [
            'labels' => array_values($panel['lagMemberTraffic']['out']['labels'] ?? []),
            'series' => array_map(static fn ($row) => ['label' => (string) $row['label'], 'values' => array_values($row['samples'])], $panel['lagMemberTraffic']['out']['series'] ?? []),
            'unit' => 'l3bps',
        ],
    ];
    $curveTotal = $firstCurve ?: ['last' => 0, 'avg' => 0, 'max' => 0, 'samples' => []];
    $isValid = !empty($panel['ok']);
    $flowState = $panel['flowState'] ?? ($isValid ? 'incomplete' : 'query_error');
    $setupMessage = match ($flowState) {
        'exporter_unmapped' => ['No Akvorado exporter mapped', 'LibreNMS has no flow exporter mapping for this device.'],
        'exporter_not_observed' => ['Exporter not seen in recent results', 'Akvorado did not list this device’s management address among the exporters returned for the last 15 minutes.'],
        'exporter_verification_unavailable' => ['Exporter status unavailable', 'The exporter check could not complete. Check the collector connection, then reload this tab.'],
        default => null,
    };
    $exporterSetup = $setupMessage !== null;
    $baseParams = $panel['queryArgs'] ?? [];
    $selectedSection = in_array(request()->query('section'), ['traffic','talkers','flows','health','sankey'], true) ? request()->query('section') : 'traffic';
    if ($selectedSection !== 'traffic') { $baseParams['section'] = $selectedSection; }
    $sankeyClass = \LibreNMS\Plugins\AkvoradoFlow\Services\SankeyDiagram::class;
    $sankeyView = $panel['sankeyView'] ?? 'ports';
    $pathMode = $sankeyView === 'ports' ? $sankeyClass::pathMode(request()->query('sankey_paths')) : 'all';
    if (!empty($panel['sankeyView'])) { $baseParams['sankey_view'] = $sankeyView; }
    if ($pathMode !== 'all') { $baseParams['sankey_paths'] = $pathMode; }
    $drillUrl = static function (array $change) use ($baseParams, $panel): string {
        $query = array_merge($baseParams, $change);
        foreach ($query as $key => $value) { if ($value === null || $value === '') { unset($query[$key]); } }
        return route('device.akvorado', ['device' => $panel['deviceId']] + $query);
    };
    $canDrill = ($panel['deviceId'] ?? 0) > 0;
    $hasFocus = count($panel['focus'] ?? []) > 0;
    $focused = $panel['focus'] ?? [];
    $validIp = static fn ($value): bool => is_string($value) && $value !== '::' && (bool) filter_var($value, FILTER_VALIDATE_IP);
    $validPort = static fn ($value): bool => is_scalar($value) && preg_match('/\A[0-9]{1,5}\z/D', (string)$value) && (int)$value <= 65535;
    $sankey = $panel['sankey'] ?? null;
    $filteredPaths = $sankey === null ? null : $sankeyClass::filterPaths($sankey, $pathMode);
    $sankeyPayload = $filteredPaths === null ? null : [
        'diagram' => array_replace($sankey, ['rows' => $filteredPaths['rows']]),
        'ports' => $panel['sankeyPortMap'] ?? [],
    ];
    $rankField = ['SrcAddr'=>'src','DstAddr'=>'dst','DstPort'=>'dst_port'][$panel['dimension'] ?? 'SrcAddr'] ?? null;

@endphp
<div class="akv-viz" data-akv-initialized="false" data-akv-initial-section="{{ $selectedSection }}" data-akv-sankey-loaded="{{ !empty($panel['sankeyView']) ? 'true' : 'false' }}" data-akv-sankey-view="{{ $sankeyView }}" data-akv-base-url="{{ $canDrill ? route('device.akvorado', ['device'=>$panel['deviceId']] + $baseParams) : '' }}">
    <style>
        /* Palette is driven by variables so LibreNMS dark mode can override it wholesale.
           LibreNMS sets class "dark" on <html> (applySiteStyle in librenms.js); its tw_dark.css
           uses #272b30 body, #2e3338 panels/tables, #3e444c headers, #353a41 stripes. */
        .akv-viz { --akv-border:#e5eaf2; --akv-muted:#68758a; --akv-ink:#1f2d42; --akv-surface:#f7f9fc;
            --akv-card-bg:#fff; --akv-info-bg:#f5f8fc; --akv-info-border:#e5eaf3; --akv-info-ink:#58667a;
            --akv-meter-bg:#eef1f7; --akv-rule:#edf0f5; --akv-nav-bg:#fff; --akv-chip-bg:#ecf3ff;
            --akv-chip-ink:#205a92; --akv-pill-bg:#eaf6ed; --akv-pill-ink:#18773b;
            --akv-legend-ink:#45546a; --akv-hint-ink:#4e6585; --akv-link-ink:#286fbc;
            --akv-graph-bg:#fff;
            font-family:inherit; color:var(--akv-ink); margin-top:13px }
        /* LibreNMS dark mode: <html class="dark">, palette from tw_dark.css
           (#272b30 body, #2e3338 panels/tables, #3e444c headers, #353a41 stripes,
            #c8c8c8 text, #7a8288 muted). Override the plugin's variables to match. */
        .dark .akv-viz {
            --akv-border:#3e444c; --akv-muted:#7a8288; --akv-ink:#c8c8c8; --akv-surface:#272b30;
            --akv-card-bg:#2e3338; --akv-info-bg:#272b30; --akv-info-border:#3e444c; --akv-info-ink:#c8c8c8;
            --akv-meter-bg:#3e444c; --akv-rule:#3e444c; --akv-nav-bg:#353a41; --akv-chip-bg:#3e444c;
            --akv-chip-ink:#c8c8c8; --akv-pill-bg:#353a41; --akv-pill-ink:#62c462;
            --akv-legend-ink:#bebfbf; --akv-hint-ink:#acb6bf; --akv-link-ink:#94b8de;
            --akv-graph-bg:#2e3338;
        }
        /* Bootstrap components rendered inside the panel follow LibreNMS dark rules,
           but the inline light backgrounds above need the explicit dark mapping too. */
        .dark .akv-viz .akv-card, .dark .akv-viz .akv-info, .dark .akv-viz .akv-native-graph,
        .dark .akv-viz .akv-sankey-frame, .dark .akv-viz .akv-nav button { color:#c8c8c8 }
        .dark .akv-viz .akv-nav button[aria-selected="true"] { background:#3e444c; border-color:#4f565d; color:#f9fafb }
        .dark .akv-viz .akv-table th { color:#7a8288 }
        .dark .akv-viz .akv-table code { color:#c8c8c8 }
        .dark .akv-viz .akv-tooltip { background:#1e2023; color:#f9fafb }
        .dark .akv-viz .akv-empty { color:#7a8288 }
        .dark .akv-viz .akv-query-preview { background:#2e3338; color:#c8c8c8; border-color:#3e444c }
        /* LibreNMS graphs are PNGs that honour its own ?style=dark parameter; keep the
           container dark so any missing image does not flash white. */
        .dark .akv-viz .akv-native-graph img { background:#2e3338 }
        .akv-viz * { box-sizing:border-box }
        .akv-viz .akv-head { display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:13px }
        .akv-viz .akv-head h3 { margin:0;font-size:18px;font-weight:650 }
        .akv-viz .akv-subtitle { color:var(--akv-muted);font-size:12px;margin-top:3px }
        .akv-viz .akv-pill { border-radius:18px;padding:5px 10px;background:var(--akv-pill-bg);color:var(--akv-pill-ink);font-size:12px;font-weight:650 }
        .akv-viz .akv-grid { display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:13px }
        .akv-viz .akv-card { border:1px solid var(--akv-border);background:var(--akv-card-bg);border-radius:9px;overflow:hidden;box-shadow:0 1px 3px rgba(30,45,70,.04);min-width:0 }
        .akv-viz .akv-pad { padding:15px 17px }
        .akv-viz .akv-card h4 { margin:0;font-size:14px;font-weight:650 }
        .akv-viz .akv-card .akv-card-sub { color:var(--akv-muted);font-size:11px;margin-top:4px }
        .akv-viz .akv-stat { grid-column:span 3;padding:15px 17px;border-top:3px solid #3897f0 }
        .akv-viz .akv-stat:nth-child(2) { border-top-color:#16a66a }
        .akv-viz .akv-stat:nth-child(3) { border-top-color:#8057da }
        .akv-viz .akv-stat:nth-child(4) { border-top-color:#e9a735 }
        .akv-viz .akv-stat-label { font-size:12px;color:var(--akv-muted) }
        .akv-viz .akv-stat-value { font-size:23px;font-weight:730;margin-top:4px;letter-spacing:-.5px }
        .akv-viz .akv-big { grid-column:span 8 }
        .akv-viz .akv-small { grid-column:span 4 }
        .akv-viz .akv-half { grid-column:span 6 }
        .akv-viz .akv-full { grid-column:span 12 }
        .akv-viz .akv-chart-wrap { position:relative;height:252px;min-height:252px;margin-top:8px }
        .akv-viz .akv-chart-wrap canvas { display:block;width:100%;height:100% }
        .akv-viz .akv-donut-wrap { width:185px;height:185px;position:relative;margin:auto }
        .akv-viz .akv-donut-wrap canvas {width:100%;height:100%}
        .akv-viz .akv-legend { display:flex;gap:8px 14px;flex-wrap:wrap;margin-top:8px;min-height:23px }
        .akv-viz button.akv-legend-item { appearance:none;background:none;border:0;padding:1px 0;color:var(--akv-legend-ink);font-size:11px;cursor:pointer }
        .akv-viz button.akv-legend-item[aria-pressed="false"] {opacity:.36;text-decoration:line-through}
        .akv-viz .akv-dot { display:inline-block;width:9px;height:9px;border-radius:2px;margin-right:5px;vertical-align:middle }
        .akv-viz .akv-tooltip { position:absolute;z-index:5;pointer-events:none;background:#1c293a;color:#fff;padding:8px 10px;border-radius:6px;font-size:11px;line-height:1.5;white-space:pre-line;display:none;box-shadow:0 4px 16px #0002;max-width:260px }
        .akv-viz .akv-meta { display:grid;grid-template-columns:auto 1fr;gap:5px 12px;margin:10px 0 0;font-size:12px }
        .akv-viz .akv-meta dt {color:var(--akv-muted);font-weight:400}
        .akv-viz .akv-meta dd {margin:0;min-width:0;word-break:break-word}
        .akv-viz .akv-meter { background:var(--akv-meter-bg);border-radius:4px;height:6px;overflow:hidden;min-width:45px }
        .akv-viz .akv-meter > span {display:block;height:100%;background:#3897f0;border-radius:4px}
        .akv-viz .akv-table {width:100%;font-size:12px;margin-top:10px;border-collapse:collapse}
        .akv-viz .akv-table td,.akv-viz .akv-table th {padding:8px 5px;border-bottom:1px solid var(--akv-rule);text-align:left;word-break:break-word}
        .akv-viz .akv-table th {color:var(--akv-muted);font-weight:600;font-size:11px}
        .akv-viz .akv-table td:last-child,.akv-viz .akv-table th:last-child {text-align:right;white-space:nowrap}
        .akv-viz .akv-table code {background:transparent;color:var(--akv-info-ink);font-size:11px;white-space:normal}
        .akv-viz .akv-row {display:flex;justify-content:space-between;gap:10px;margin:11px 0;align-items:center;font-size:12px}
        .akv-viz .akv-row-label {display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:65%}
        .akv-viz .akv-row small {color:var(--akv-muted)}
        .akv-viz .akv-empty {padding:26px 10px;text-align:center;color:var(--akv-muted);font-size:12px}
        .akv-viz .akv-info {background:var(--akv-info-bg);border:1px solid var(--akv-info-border);border-radius:7px;padding:11px 14px;color:var(--akv-info-ink);font-size:11px;line-height:1.6;margin-top:14px}
        .akv-viz .akv-list {margin:9px 0;padding-left:18px;font-size:12px}
        .akv-viz .akv-list li {margin-bottom:6px}
        .akv-viz .akv-nav {display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 14px;border-bottom:1px solid var(--akv-border);padding-bottom:10px}
        .akv-viz .akv-nav button {padding:7px 13px;border:1px solid var(--akv-border);background:var(--akv-nav-bg);color:var(--akv-ink);border-radius:7px;font-weight:600;cursor:pointer}
        .akv-viz .akv-nav button[aria-selected="true"] {background:#eaf4ff;border-color:#84baf2;color:#146bad}
        .akv-viz .akv-section[hidden] {display:none!important}
        .akv-viz .akv-filters {display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 12px}
        .akv-viz .akv-chip {font-size:12px;display:inline-block;border-radius:6px;background:var(--akv-chip-bg);color:var(--akv-chip-ink);padding:5px 9px;border:1px solid #d9e8fc}
        .akv-viz .akv-link {font-size:11px;border:0;background:transparent;color:var(--akv-link-ink);text-decoration:underline;cursor:pointer;padding:0}
        .akv-viz .akv-chart-wrap[data-akv-chart="main"] canvas {cursor:crosshair;touch-action:pan-y}
        .akv-viz .akv-snmp-graphs { display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px;margin-top:12px }
        .akv-viz .akv-native-graph {min-width:0;border:1px solid var(--akv-border);padding:9px;border-radius:7px;background:var(--akv-graph-bg)}
        .akv-viz .akv-native-graph .akv-row {margin:0 0 4px}
        .akv-viz .akv-native-graph img {display:block;width:100%;height:auto;max-height:350px;object-fit:contain;background:var(--akv-graph-bg)}
        .akv-viz .akv-sankey-frame {overflow-x:auto; background:var(--akv-surface); border:1px solid var(--akv-info-border);border-radius:9px; margin-top:10px; padding:10px}
        .akv-viz .akv-sankey-frame svg {width:100%;height:auto;min-width:690px;display:block}
        .akv-viz .akv-sankey-legend {font-size:12px;color:var(--akv-muted);padding-top:7px}
        .akv-viz .akv-sankey-controls {display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}
        .akv-viz .akv-sankey-controls label {font-size:12px;margin:0}
        .akv-viz .akv-sankey-controls select {min-width:170px}
        .akv-viz .akv-snmp-util {display:flex;flex-wrap:wrap;gap:10px 22px;font-size:12px;margin-top:12px;color:var(--akv-muted)}
        .akv-viz .akv-sankey-frame {overflow-x:auto; background:var(--akv-surface); border:1px solid var(--akv-info-border);border-radius:9px; margin-top:10px; padding:10px}
        .akv-viz .akv-sankey-frame svg {width:100%;height:auto;min-width:690px;display:block}
        .akv-viz .akv-sankey-legend {font-size:12px;color:var(--akv-muted);padding-top:7px}
        .akv-viz .akv-sankey-controls {display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}
        .akv-viz .akv-sankey-controls label {font-size:12px;margin:0}
        .akv-viz .akv-sankey-controls select {min-width:170px}
        .akv-viz .akv-snmp-util strong {color:var(--akv-ink);margin-left:4px}
        @media (max-width:990px) { .akv-viz .akv-snmp-graphs {grid-template-columns:1fr} }
        .akv-viz .akv-zoom-hint {font-size:11px;color:var(--akv-hint-ink);margin-top:9px}
        .akv-viz .akv-section-title {margin:0 0 6px;font-size:13px;color:var(--akv-info-ink)}
        .akv-viz .akv-table a {color:#176db3;text-decoration:underline}
        @media (max-width:1100px) { .akv-viz .akv-big,.akv-viz .akv-small {grid-column:span 12} .akv-viz .akv-stat {grid-column:span 6} }
        @media (max-width:650px) { .akv-viz .akv-half {grid-column:span 12} .akv-viz .akv-stat {grid-column:span 6}.akv-viz .akv-stat-value{font-size:18px} }
    </style>
    <div class="akv-head">
        <div>
            <h3>{{ $heading }}</h3>
            <div class="akv-subtitle">
                {{ $context }}
                @unless($exporterSetup)
                    · {{ $panel['minutes'] ?? 60 }}-minute window · Flow-derived analytics
                @endunless
            </div>
        </div>
        @if($isValid)
            @if($canDrill && isset($panel['windowStart'], $panel['windowEnd']))
                @include('akvorado-flow::hooks.incident-sharing')
            @endif
            <span class="akv-pill">● Akvorado API reachable</span>
            @if($flowState === 'has_flows')<span class="akv-pill">● Flow data observed</span>@endif
        @endif
    </div>
    @if(!$isValid && !$exporterSetup)
        <div class="alert alert-warning">{{ $panel['message'] ?? 'No flow data available.' }}</div>
        <div class="akv-grid">
            @include('akvorado-flow::hooks.snmp-history', ['panel' => $panel])
        </div>
    @elseif($flowState !== 'has_flows')
        <div class="akv-card akv-pad" style="border-left:4px solid #7ba2ca;max-width:1100px;margin:12px 0 18px">
            @if($exporterSetup)
                <h4>{{ $setupMessage[0] }}</h4>
                <p>{{ $setupMessage[1] }}</p>
                <ol class="akv-list">
                    <li>Check which flow protocol the device supports and configure it to send flows to the Akvorado collector.</li>
                    <li>Check the exporter source address in Akvorado. If it differs from the management address, ask a LibreNMS administrator to map the actual exporter address to this device.</li>
                    <li>Use the exporter inventory to see which devices Akvorado knows about and which were recently observed.</li>
                </ol>
            @else
            <h4>{{ $flowState === 'no_matching_flows' ? 'No matching Akvorado flow data found' : 'Flow visibility incomplete' }}</h4>
            <p style="margin-top:10px">Akvorado accepted the traffic query, but {{ $flowState === 'no_matching_flows' ? 'no nonzero flow samples were returned' : 'complete flow availability could not be established' }} for {{ $isPort ? 'this interface' : 'this device' }} in the selected {{ $panel['minutes'] ?? 60 }}-minute window.</p>
            <dl class="akv-meta">
                <dt>Expected exporter</dt><dd><code>{{ $panel['exporter'] ?? 'Unknown' }}</code></dd>
                <dt>Mapping</dt><dd>{{ $panel['mapping'] ?? 'Unknown' }}</dd>
                @if($isPort)<dt>Interface</dt><dd>{{ $panel['ifName'] ?? '' }} (ifIndex {{ $panel['ifIndex'] ?? '?' }})</dd>@endif
                <dt>Query status</dt><dd>HTTP request succeeded; flow presence not confirmed</dd>
            </dl>
            <p style="margin-top:15px;margin-bottom:6px"><strong>Possible explanations</strong></p>
            <ul class="akv-list">
                <li>No observed traffic in this window, or flow export has not been configured.</li>
                <li>The device may export with a different source address than LibreNMS's management IP.</li>
                @if($isPort)<li>Akvorado's enriched <code>InIfName</code>/<code>OutIfName</code> may differ from LibreNMS <code>ifName</code>.</li>@endif
                <li>Collector ingestion, enrichment, or sampling may be incomplete.</li>
            </ul>
            @endif
            <p style="margin:12px 0 0">
                <a href="https://demo.akvorado.net/docs/exporters" target="_blank" rel="noopener noreferrer">Akvorado exporter setup documentation</a> ·
                <a href="https://demo.akvorado.net/docs/troubleshooting" target="_blank" rel="noopener noreferrer">Akvorado troubleshooting guide</a>
                @if(!empty($panel['deviceId']) && config('akvorado-flow.inventory_enabled', true))
                  · <a href="{{ route('akvorado.exporters') }}">Exporter inventory</a>
                @endif
            </p>
            @unless($exporterSetup)
                <p class="akv-card-sub">An empty result can occur even when flow export is enabled.</p>
            @endunless
        </div>
        @if($periodComparison !== null)
            <div class="akv-info">
                @if($periodComparison['observed'])
                    Akvorado returned flows from the previous period.
                @elseif($periodComparison['unavailable'] !== [])
                    Previous-period traffic was unavailable for {{ implode(', ', $periodComparison['unavailable']) }}.
                @else
                    Akvorado returned no observed traffic for the previous period.
                @endif
                {{ $periodComparison['start'] }} to {{ $periodComparison['end'] }} UTC.
            </div>
        @endif
        @if(!empty($panel['partial']))<div class="alert alert-info">Some directions or optional queries were unavailable: {{ implode('; ', $panel['partial']) }}.</div>@endif
        <div class="akv-grid">@include('akvorado-flow::hooks.snmp-history', ['panel' => $panel])</div>
    @else
        @if(!empty($panel['partial']))
            <div class="alert alert-info">Some optional charts could not be loaded: {{ implode('; ', $panel['partial']) }}.</div>
        @endif
        <div class="akv-nav" role="tablist" aria-label="Akvorado investigation areas">
            <button type="button" role="tab" aria-selected="true" data-akv-tab="traffic">Traffic &amp; charts</button>
            <button type="button" role="tab" aria-selected="false" data-akv-tab="talkers">Top talkers</button>
            <button type="button" role="tab" aria-selected="false" data-akv-tab="flows">Conversations</button>
            <button type="button" role="tab" aria-selected="false" data-akv-tab="sankey">Communications map</button>
            <button type="button" role="tab" aria-selected="false" data-akv-tab="health">Port health</button>
        </div>
        @if($hasFocus || !empty($panel['zoomed']))
            <div class="akv-filters"><strong style="font-size:12px">Active investigation:</strong>
                @foreach($focused as $field=>$value)
                    <a class="akv-chip" data-akv-filter-link href="{{ $drillUrl([$field=>null]) }}" title="Remove this filter">{{ ['host'=>'Host','src'=>'Source','dst'=>'Destination','dst_port'=>'Dst port','proto'=>'Protocol'][$field] ?? $field }}: {{ $value }} ×</a>
                @endforeach
                @if(!empty($panel['zoomed']))
                    <a class="akv-chip" data-akv-filter-link href="{{ $drillUrl(['start'=>null, 'end'=>null]) }}" title="Clear zoom">Time zoom ×</a>
                @endif
                <a class="akv-link" data-akv-filter-link href="{{ $drillUrl(['host'=>null,'src'=>null,'dst'=>null,'dst_port'=>null,'proto'=>null,'start'=>null,'end'=>null]) }}">Clear filters and zoom</a>
            </div>
        @endif
        <div class="akv-grid">
            @if($isPort && !$portCombinedFallback)
                @foreach(['InIf' => ($panel['trafficIn'] ?? null), 'OutIf' => ($panel['trafficOut'] ?? null)] as $directionName => $curve)
                    @foreach(['last'=>'Latest completed', 'avg'=>'Average completed', 'max'=>'Peak incl. partial'] as $statKey => $statLabel)
                        @php
                            $dirKey = $directionName === 'InIf' ? 'in' : 'out';
                            $cov = $panel['coverage']['directions'][$dirKey] ?? null;
                            $covState = $cov['state'] ?? null;
                            $notObserved = $covState === 'not_observed';
                            // On a link aggregate, inbound is never attributable to the aggregate
                            // itself; label it structurally rather than as missing data.
                            $aggIngress = in_array($covState, ['aggregate_member_ingress', 'aggregate_unattributable'], true);
                        @endphp
                        <div class="akv-card akv-stat akv-section" data-akv-section="traffic">
                            <div class="akv-stat-label">{{ $statLabel }} · Observed {{ $directionName }}</div>
                            <div class="akv-stat-value">
                                @if($aggIngress && $statKey === 'last')
                                    @if($covState === 'aggregate_member_ingress')
                                        On members, not aggregate
                                    @else
                                        Aggregate ingress unattributable
                                    @endif
                                @elseif($notObserved && $statKey === 'last')
                                    Flow not observed
                                @else
                                    {{ $curve !== null ? $fmt::readable((float)($curve[$statKey] ?? 0), $metric) : 'Unavailable' }}
                                @endif
                            </div>
                            @if($aggIngress && $statKey === 'last')
                                <div class="akv-card-sub">{{ $cov['label'] ?? '' }} — see “Collection quality”</div>
                            @elseif($notObserved)
                                <div class="akv-card-sub">SNMP still reports traffic on this direction</div>
                            @endif
                        </div>
                    @endforeach
                @endforeach
            @else
                @php $bucketNote = !empty($curveTotal['partialTailExcluded']) ? 'completed buckets only; the still-filling current bucket is excluded' : 'all returned buckets'; @endphp
                <div class="akv-card akv-stat akv-section" data-akv-section="traffic"><div class="akv-stat-label">{{ $isPort ? 'Latest completed combined port bucket' : 'Latest completed bucket' }}</div><div class="akv-stat-value">{{ $fmt::readable((float)$curveTotal['last'],$metric) }}</div><div class="akv-card-sub">{{ $bucketNote }}</div></div>
                <div class="akv-card akv-stat akv-section" data-akv-section="traffic"><div class="akv-stat-label">{{ $isPort ? 'Average combined port bucket' : 'Average per completed bucket' }}</div><div class="akv-stat-value">{{ $fmt::readable((float)$curveTotal['avg'],$metric) }}</div><div class="akv-card-sub">{{ $bucketNote }}</div></div>
                <div class="akv-card akv-stat akv-section" data-akv-section="traffic"><div class="akv-stat-label">{{ $isPort ? 'Peak combined port bucket' : 'Peak observed bucket' }}</div><div class="akv-stat-value">{{ $fmt::readable((float)$curveTotal['max'],$metric) }}</div><div class="akv-card-sub">highest returned bucket, including the current partial one</div></div>
            @endif
            <div class="akv-card akv-stat akv-section" data-akv-section="traffic"><div class="akv-stat-label">Packet rate · latest completed</div><div class="akv-stat-value">{{ isset($panel['packets']['samples']) && count($panel['packets']['samples']) ? $fmt::packetRate((float)$panel['packets']['last']) : 'Unavailable' }}</div><div class="akv-card-sub">{{ !empty($panel['packets']['partialTailExcluded']) ? 'completed bucket only' : 'all returned buckets' }}</div></div>

            <div class="akv-card akv-big akv-pad akv-section" data-akv-section="traffic">
                <h4>Flow traffic over time</h4>
                <div class="akv-card-sub">{{ $metric === 'pps' ? 'Packets per second' : ($metric === 'bytes' ? 'Estimated bytes per time bucket' : 'Flow-derived '.strtoupper($metric).' rate') }} · {{ !empty($panel['lagIncluded']) ? 'Logical LAG plus discovered members; estimates may double-count' : ($isPort ? ($portCombinedFallback ? 'InIf OR OutIf combined; not directional' : 'InIf / OutIf observation paths') : 'Entire exporter') }}</div>
                @if(count($mainSeries))
                    <div class="akv-chart-wrap" data-akv-chart="main"><canvas aria-label="Flow throughput time series" role="img"></canvas><div class="akv-tooltip"></div></div>
                    <div class="akv-legend" data-akv-legend="main"></div>
                    @if($canDrill)<p class="akv-zoom-hint">Click a point for a ~10-minute incident window, or drag across the graph to zoom. The same time filter applies to conversations and top talkers.</p>@endif
                @else
                    <div class="akv-empty">No time-series data for this selection. The exporter may not report this interface.</div>
                @endif
            </div>
            @if($periodComparison !== null)
                @include('akvorado-flow::hooks.period-comparison')
            @endif
            <div class="akv-card akv-small akv-pad akv-section" data-akv-section="traffic health">
                <h4>Exporter &amp; coverage</h4>
                <div class="akv-card-sub">Details for the current query scope</div>
                <dl class="akv-meta">
                    <dt>Exporter IP</dt><dd><code>{{ $panel['exporter'] }}</code></dd>
                    <dt>Mapping method</dt><dd>{{ $panel['mapping'] }}</dd>
                    <dt>Interface</dt><dd>{{ $isPort ? 'ifIndex '.$panel['ifIndex'] : 'All observed interfaces' }}</dd>
                    <dt>Updated</dt><dd>{{ $panel['generatedAt'] ?? '—' }}</dd>
                    @php $coverage = $panel['coverage'] ?? null; @endphp
                    <dt>Collection quality</dt>
                    <dd>
                        @if($isPort && !empty($coverage['available']))
                            @foreach(['in' => 'InIf', 'out' => 'OutIf'] as $dir => $dirLabel)
                                @php $entry = $coverage['directions'][$dir] ?? null; @endphp
                                @if($entry)
                                    <div class="akv-row" style="margin:4px 0">
                                        <span>{{ $dirLabel }}</span>
                                        <strong>
                                            {{ $entry['label'] }}
                                            @if($entry['coverage'] !== null)
                                                ({{ number_format((float) $entry['coverage'], 1) }}% of SNMP)
                                            @endif
                                        </strong>
                                    </div>
                                    <div class="akv-card-sub" style="margin:0 0 4px">
                                        flow {{ $entry['flowBps'] === null ? 'n/a' : $fmt::readable((float) $entry['flowBps'], 'l3bps') }}
                                        · SNMP {{ $entry['snmpBps'] === null ? 'n/a' : $fmt::readable((float) $entry['snmpBps'], 'l3bps') }}
                                        @if(!empty($coverage['aggregate']) && ($entry['coverage'] ?? null) === null)
                                            · not comparable to the aggregate counter
                                        @endif
                                    </div>
                                @endif
                            @endforeach
                            @if(empty($coverage['aggregate']))
                                <div class="akv-card-sub">Approximate comparison of the last completed flow bucket and the latest SNMP poll. Their time intervals may differ.</div>
                            @endif
                            @if(!empty($coverage['aggregate']) && !empty($coverage['members']))
                                <div class="akv-card-sub" style="margin:4px 0 0">Members: {{ implode(', ', $coverage['members']) }}</div>
                            @endif
                            @if(!empty($coverage['memberCoverage']))
                                <div class="akv-card-sub" style="margin:6px 0 2px">
                                    Per-member coverage: each member has its own SNMP counter, so member flow
                                    is compared with that member, never with the aggregate.
                                </div>
                                @foreach($coverage['memberCoverage'] as $memberRow)
                                    <div class="akv-row" style="margin:2px 0">
                                        <span>{{ $memberRow['ifName'] }}</span>
                                        <strong>
                                            @foreach(['in' => 'InIf', 'out' => 'OutIf'] as $dirKey => $dirLabel)
                                                @php $mc = $memberRow['directions'][$dirKey] ?? null; @endphp
                                                {{ $dirLabel }}
                                                @if($mc && $mc['coverage'] !== null)
                                                    {{ number_format((float) $mc['coverage'], 1) }}%
                                                @else
                                                    n/a
                                                @endif
                                                @if(!$loop->last) · @endif
                                            @endforeach
                                        </strong>
                                    </div>
                                @endforeach
                            @endif
                        @elseif($isPort && !empty($coverage['reason']))
                            Comparison unavailable
                        @else
                            Per-interface only
                        @endif
                    </dd>
                </dl>
                @if($isPort && !empty($coverage['note']))
                    <div class="akv-info" style="margin-top:13px;border-left:3px solid #d08a2a">
                        <strong>Coverage interpretation:</strong> {{ $coverage['note'] }}
                    </div>
                @endif
                @if($isPort && !empty($panel['snmp']))
                    <div class="akv-info" style="margin-top:13px">
                        <strong>LibreNMS interface health</strong>
                        <div class="akv-row"><span>In discards</span><strong>{{ $panel['snmp']['inDiscardsRate'] === null ? 'n/a' : number_format($panel['snmp']['inDiscardsRate'],2).' /s' }}</strong></div>
                        <div class="akv-row"><span>Out discards</span><strong>{{ $panel['snmp']['outDiscardsRate'] === null ? 'n/a' : number_format($panel['snmp']['outDiscardsRate'],2).' /s' }}</strong></div>
                        <div class="akv-row"><span>In errors</span><strong>{{ $panel['snmp']['inErrorsRate'] === null ? 'n/a' : number_format($panel['snmp']['inErrorsRate'],2).' /s' }}</strong></div>
                        <div class="akv-row"><span>Out errors</span><strong>{{ $panel['snmp']['outErrorsRate'] === null ? 'n/a' : number_format($panel['snmp']['outErrorsRate'],2).' /s' }}</strong></div>
                    </div>
                @endif
                @if(!empty($panel['filterExpression']))
                    <div style="margin-top:12px">
                        <label style="font-size:11px;color:var(--akv-muted)">Akvorado filter</label>
                        <div style="display:flex;gap:5px;flex-wrap:wrap">
                            <input type="text" class="form-control input-sm akv-query-preview" readonly value="{{ $panel['filterExpression'] }}" style="flex:1;min-width:150px" aria-label="Read-only Akvorado filter" />
                            <button type="button" class="btn btn-default btn-sm" data-akv-copy>Copy</button>
                        </div>
                    </div>
                @endif
            </div>

            @include('akvorado-flow::hooks.snmp-history', ['panel' => $panel])

            @if(!empty($panel['lagIncluded']) && count($panel['lagMembers'] ?? []) > 0)
                <div class="akv-card akv-full akv-pad akv-section" data-akv-section="traffic health">
                    <h4>LAG physical-member flow observations</h4>
                    <div class="akv-card-sub">Separate Akvorado L3 estimates grouped by discovered physical interface names. These are not SNMP counters, do not prove link utilization, and can contain duplicated observations.</div>
                    @foreach(['in' => 'Observed InIf on physical members', 'out' => 'Observed OutIf on physical members'] as $direction=>$memberTitle)
                        @php $chartKey = $direction === 'in' ? 'member_in' : 'member_out'; @endphp
                        <h5 style="margin:18px 0 0">{{ $memberTitle }}</h5>
                        @if(count($chartPayload[$chartKey]['series']) && count($chartPayload[$chartKey]['labels']) > 1)
                            <div class="akv-chart-wrap" data-akv-chart="{{ $chartKey }}"><canvas role="img" aria-label="{{ $memberTitle }}"></canvas><div class="akv-tooltip"></div></div>
                            <div class="akv-legend" data-akv-legend="{{ $chartKey }}"></div>
                        @elseif(($panel['lagMemberTraffic'][$direction] ?? null) === null)
                            <div class="akv-empty">Member-specific {{ strtoupper($direction) }} breakdown unavailable from Akvorado for this query.</div>
                        @else
                            <div class="akv-empty">No nonzero matching {{ strtoupper($direction) }} member data returned in the selected window.</div>
                        @endif
                    @endforeach
                </div>
            @endif

            <div class="akv-card akv-big akv-pad akv-section" data-akv-section="traffic">
                <h4>Top destination ports over time</h4>
                <div class="akv-card-sub">Stacked L3 bits/sec, limited to the leading destination-port groups reported by Akvorado</div>
                @php $attribution = $panel['portAttribution'] ?? null; @endphp
                @if(!empty($attribution['hasUnattributed']))
                    <div class="akv-info" style="margin-top:9px">
                        <strong>Destination-port attribution:</strong>
                        {{ number_format((float) $attribution['unattributedShare'], 1) }}% of the returned traffic in this window falls into the <em>Other</em> remainder or the unattributed port&nbsp;0 bucket instead of a specific destination port. The named rows above therefore describe only {{ number_format(100 - (float) $attribution['unattributedShare'], 1) }}% of the total, while the stack still sums to the full returned traffic. This is an Akvorado enrichment gap, not missing traffic: the exporter is sending those records, but no service port could be resolved for them.
                    </div>
                @endif
                @if(count($chartPayload['ports']['series']))
                    <div class="akv-chart-wrap" data-akv-chart="ports"><canvas aria-label="Stacked traffic by destination port" role="img"></canvas><div class="akv-tooltip"></div></div>
                    <div class="akv-legend" data-akv-legend="ports"></div>
                @else
                    <div class="akv-empty">Destination-port time series unavailable for the selected range.</div>
                @endif
            </div>
            <div class="akv-card akv-small akv-pad akv-section" data-akv-section="traffic">
                <h4>Protocol / port mix</h4>
                <div class="akv-card-sub">Share of ranked port flows with an identifiable service port (not all traffic; no DPI). The <em>Other</em> remainder and the unattributed port 0 bucket are excluded from the slices.</div>
                @if(count($chartPayload['donut']))
                    <div class="akv-donut-wrap" data-akv-donut><canvas aria-label="Ranked protocol and port split" role="img"></canvas></div>
                    <div data-akv-donut-legend></div>
                @else
                    <div class="akv-empty">Protocol and destination-port breakdown unavailable.</div>
                @endif
            </div>

            <div class="akv-card akv-half akv-pad akv-section" data-akv-section="talkers" hidden>
                <h4>Top {{ ['SrcAddr'=>'source IPs','DstAddr'=>'destination IPs','DstPort'=>'destination ports','SrcAS'=>'source ASNs'][$panel['dimension'] ?? 'SrcAddr'] ?? 'groups' }}</h4>
                <div class="akv-card-sub">Akvorado-ranked results for current time and interface filter</div>
                @php $rankings = $panel['rankings'] ?? []; $rankPlaceholder = $fmt::rankingsArePlaceholder($rankings); @endphp
                @if($rankPlaceholder)
                    <div class="akv-info" style="margin-top:9px">
                        <strong>No usable AS ranking:</strong>
                        Akvorado returned only placeholder rows for this grouping. Try ranking by source IP, destination IP, or destination port. For AS rankings, check the collector’s ASN metadata.
                    </div>
                @endif
                @forelse(($panel['rankings'] ?? []) as $entry)
                    @php $maxRank = max(1.0, (float)($panel['rankings'][0]['value'] ?? 1)); @endphp
                    <div class="akv-row">
                        <span class="akv-row-label" title="{{ $entry['label'] }}">
                            @if($canDrill && $rankField && empty($entry['isOther']) && (($rankField === 'dst_port' && $validPort($entry['label'])) || ($rankField !== 'dst_port' && $validIp($entry['label']))))
                                <a data-akv-filter-link href="{{ $drillUrl([$rankField => (string)$entry['label']]) }}">{{ $entry['label'] }}</a>
                            @else {{ $entry['label'] }}{{ !empty($entry['isOther']) ? ' (remainder)' : '' }} @endif
                        </span>
                        <div style="width:32%;min-width:60px"><div class="akv-meter"><span style="width:{{ min(100, 100 * ((float)$entry['value']) / $maxRank) }}%"></span></div></div>
                        <strong>{{ $fmt::readable((float)$entry['value'],$metric) }}</strong>
                    </div>
                @empty <div class="akv-empty">No ranking data returned</div> @endforelse
            </div>
            <div class="akv-card akv-half akv-pad akv-section" data-akv-section="flows" hidden>
                <h4>Top conversations</h4><div class="akv-card-sub">Average L3 bitrate over selected window</div>
                @if(count($panel['conversations'] ?? []))
                    <div class="table-responsive"><table class="akv-table">
                        <thead><tr><th>Source</th><th>Destination</th><th>Avg L3 bitrate</th></tr></thead>
                        <tbody>
                            @foreach($panel['conversations'] as $flow)
                                <tr>
                                    <td>@if($canDrill && $validIp($flow['source']))<a data-akv-filter-link href="{{ $drillUrl(['src'=>$flow['source']]) }}">{{ $flow['source'] }}</a>@else <code>{{ $flow['source'] }}</code> @endif</td>
                                    <td>@if($canDrill && $validIp($flow['destination']))<a data-akv-filter-link href="{{ $drillUrl(['dst'=>$flow['destination']]) }}">{{ $flow['destination'] }}</a>@else <code>{{ $flow['destination'] }}</code> @endif</td>
                                    <td>{{ $fmt::bitrate((float)$flow['bps']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                @else <div class="akv-empty">No conversations returned</div> @endif
            </div>
            <div class="akv-card akv-full akv-pad akv-section" data-akv-section="talkers" hidden>
                <h4>Most active peers from top returned conversations</h4>
                <div class="akv-card-sub">These summaries reflect only the leading conversation rows and may omit lower-volume peers.</div>
                <div class="row">
                    @foreach(['Sources' => ['entries'=>$panel['topSources'] ?? [], 'param'=>'src'], 'Destinations' => ['entries'=>$panel['topDestinations'] ?? [], 'param'=>'dst']] as $group=>$details)
                        <div class="col-md-6"><h5>{{ $group }}</h5>
                            @forelse(array_slice($details['entries'],0,12) as $talker)
                                <div class="akv-row"><span class="akv-row-label">
                                    @if($canDrill && $validIp($talker['address']))
                                        <a data-akv-filter-link href="{{ $drillUrl([$details['param']=>$talker['address']]) }}">{{ $talker['address'] }}</a>
                                    @else {{ $talker['address'] }} @endif
                                </span><strong>{{ $fmt::bitrate((float)$talker['bps']) }}</strong></div>
                            @empty <div class="akv-empty">No peers available.</div> @endforelse
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="akv-card akv-full akv-pad akv-section" data-akv-section="flows" hidden>
                <h4>Conversation explorer — protocol and destination port</h4>
                <div class="akv-card-sub">Top returned rows only; click an address or port to narrow the whole dashboard.</div>
                @if(count($panel['detailedFlows'] ?? []))
                    <div class="table-responsive"><table class="akv-table">
                        <thead><tr><th>Source IP</th><th>Destination IP</th><th>Protocol</th><th>Destination port</th><th>Avg L3 bitrate</th></tr></thead><tbody>
                        @foreach($panel['detailedFlows'] as $flow)
                            <tr>
                                <td>@if($canDrill && $validIp($flow['source']))<a data-akv-filter-link href="{{ $drillUrl(['src'=>$flow['source']]) }}">{{ $flow['source'] }}</a>@else {{ $flow['source'] }} @endif</td>
                                <td>@if($canDrill && $validIp($flow['destination']))<a data-akv-filter-link href="{{ $drillUrl(['dst'=>$flow['destination']]) }}">{{ $flow['destination'] }}</a>@else {{ $flow['destination'] }} @endif</td>
                                <td>{{ ['6'=>'TCP','17'=>'UDP','1'=>'ICMP','58'=>'ICMPv6'][$flow['protocol']] ?? $flow['protocol'] }}</td>
                                <td>@if($canDrill && $validPort($flow['port']))<a data-akv-filter-link href="{{ $drillUrl(['dst_port'=>$flow['port']]) }}">{{ $flow['port'] }}</a>@else {{ $flow['port'] }} @endif</td>
                                <td>{{ $fmt::bitrate((float)$flow['bps']) }}</td>
                            </tr>
                        @endforeach
                        </tbody></table></div>
                @else <div class="akv-empty">Detailed flow grouping is unavailable. Top conversations are still shown above.</div> @endif
            </div>
            <div class="akv-card akv-full akv-pad akv-section" data-akv-section="sankey" hidden>
                <h4>Communication flows — Sankey diagram</h4>
                <div class="akv-card-sub">Ribbon width represents average observed L3 bits/sec for the leading ranked paths. Not a verified physical forwarding path.</div>
                <form class="akv-sankey-controls" method="get" action="{{ $canDrill ? route('device.akvorado', ['device' => $panel['deviceId']]) : '' }}" style="margin:14px 0 12px">
                    @foreach(($panel['queryArgs'] ?? []) as $field=>$value)
                        @if(in_array($field, ['port','period','metric','dimension','host','src','dst','dst_port','proto','start','end','lag_members','compare'], true) && is_scalar($value))
                            <input type="hidden" name="{{ $field }}" value="{{ $value }}" />
                        @endif
                    @endforeach
                    <input type="hidden" name="section" value="sankey" />
                    <div><label for="akv-sankey-view-{{ $panel['deviceId'] }}">Network perspective</label>
                        <select class="form-control" name="sankey_view" id="akv-sankey-view-{{ $panel['deviceId'] }}">
                            <option value="ports" @selected($sankeyView === 'ports')>Input port → Output port</option>
                            <option value="ips" @selected($sankeyView === 'ips')>Source IP → Destination IP</option>
                            <option value="services" @selected($sankeyView === 'services')>Source IP → Destination IP → Service port</option>
                        </select>
                    </div>
                    @if($sankeyView === 'ports')
                        <div><label for="akv-sankey-paths-{{ $panel['deviceId'] }}">Port paths</label>
                            <select class="form-control" name="sankey_paths" id="akv-sankey-paths-{{ $panel['deviceId'] }}">
                                <option value="all" @selected($pathMode === 'all')>All paths</option>
                                <option value="known" @selected($pathMode === 'known')>Known paths only</option>
                                <option value="unknown_ingress" @selected($pathMode === 'unknown_ingress')>Unknown ingress only</option>
                            </select>
                        </div>
                    @endif
                    <button type="submit" class="btn btn-primary btn-sm" @disabled(!$canDrill)>Update diagram</button>
                </form>
                @if($sankey === null && !empty($panel['sankeyFailure']))
                    <div class="alert alert-warning">Akvorado could not generate this diagram ({{ $panel['sankeyFailure'] }}). The selected dimensions may be unavailable in the Akvorado schema, or the query may have timed out. The other traffic views remain available.</div>
                @elseif($sankey === null)
                    <div class="akv-empty">Select this tab to request a scoped Sankey diagram from Akvorado.</div>
                @elseif(empty($sankey['rows']))
                    <div class="akv-empty">No ranked paths were returned for this perspective and investigation window. Try IP conversations, a longer time range or another interface.</div>
                @else
                    @if($pathMode !== 'all')
                        <p class="akv-info" style="margin-top:9px">{{ number_format($filteredPaths['excludedShare'], 1) }}% of the ranked-path bitrate is hidden by this filter.</p>
                    @endif
                    @if(!empty($sankey['hasUnresolved']))
                        <p class="akv-info" style="margin-top:9px">
                            {{ number_format((float) $sankey['unresolvedShare'], 1) }}% of the unfiltered ranked-path bitrate has an unknown ingress interface.
                            <a href="{{ $drillUrl(['section' => 'diagnostics', 'sankey_view' => null, 'sankey_paths' => null]) }}">Check exporter-wide flow diagnostics</a>.
                        </p>
                    @endif
                    @if($filteredPaths['rows'] === [])
                        <div class="akv-empty">None of the ranked paths match this filter.</div>
                    @else
                    <div class="akv-sankey-frame" data-akv-sankey><svg role="img" aria-label="Sankey diagram of observed network traffic" viewBox="0 0 1100 400"></svg></div>
                    <div class="akv-sankey-legend">
                        Hover over a ribbon for the average bitrate. Click a labeled IP (or matched interface) to narrow the investigation.
                        <strong>Source port unknown</strong> and <strong>destination port unknown</strong> mark missing interface identities.
                        <strong>Other</strong> groups results outside the leading entries for a dimension.
                    </div>
                    <details style="margin-top:15px"><summary style="cursor:pointer;font-size:12px">View ranked paths as a table</summary>
                        <div class="table-responsive"><table class="akv-table"><thead><tr>
                            @foreach($sankey['dimensions'] as $column)<th>{{ $column }}</th>@endforeach
                            <th>Avg L3 rate</th>
                        </tr></thead><tbody>
                            @foreach($filteredPaths['rows'] as $path)
                                <tr>
                                    @foreach($path['values'] as $i=>$value)
                                        @php
                                            $field = ['SrcAddr'=>'src','DstAddr'=>'dst','DstPort'=>'dst_port'][$sankey['dimensions'][$i] ?? ''] ?? null;
                                            $linkable = $field && $canDrill && $value !== 'Other'
                                                && $value !== \LibreNMS\Plugins\AkvoradoFlow\Services\SankeyDiagram::PLACEHOLDER_UNRESOLVED
                                                && ($field==='dst_port' ? $validPort($value) : $validIp($value));
                                        @endphp
                                        <td>@if($linkable)<a data-akv-filter-link href="{{ $drillUrl([$field=>$value,'section'=>'sankey']) }}">{{ $value }}</a>@else {{ $value }} @endif</td>
                                    @endforeach
                                    <td>{{ $fmt::bitrate((float)$path['bps']) }}</td>
                                </tr>
                            @endforeach
                        </tbody></table></div>
                    </details>
                    <script type="application/json" class="akv-sankey-payload">@json($sankeyPayload)</script>
                    @endif
                @endif
            </div>
            <div class="akv-card akv-full akv-pad akv-section" data-akv-section="health" hidden>
                <h4>Port health &amp; interpretation</h4>
                @if($isPort && !empty($panel['snmp']))
                    <div class="row">
                        @foreach(['inDiscardsRate'=>'Inbound discards/s','outDiscardsRate'=>'Outbound discards/s','inErrorsRate'=>'Inbound errors/s','outErrorsRate'=>'Outbound errors/s'] as $field=>$title)
                            <div class="col-xs-6 col-md-3" style="margin:12px 0">
                                <div class="akv-stat-label">{{ $title }}</div>
                                <div class="akv-stat-value">{{ $panel['snmp'][$field] === null ? 'n/a' : number_format($panel['snmp'][$field],2) }}</div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="akv-empty">Select an individual LibreNMS port to see polled discard/error rates.</div>
                @endif
                <div class="akv-info">The rates above are the latest polled snapshots. The synchronized historical LibreNMS SNMP graphs appear in the traffic and port-health sections. Standard IF-MIB discards do not independently establish physical buffer drops.</div>
                <div class="akv-info">Flow traffic may be sampled or observed more than once. Do not interpret flow estimates as wire-rate, packet loss, latency, or TCP retransmission measurements.</div>
            </div>
        </div>
        <div class="akv-info"><strong>Interpretation:</strong> Charts are based on collected NetFlow/IPFIX/sFlow records; sampling and observation points can affect totals. InIf and OutIf identify exported flow directions, not guaranteed physical ingress/egress counters. Protocol/port mix is based on ranked data only, not deep packet inspection. SNMP discards and errors come from LibreNMS, not Akvorado; they cannot establish end-to-end packet loss or TCP retransmissions.</div>
        <script type="application/json" class="akv-chart-payload">@json($chartPayload)</script>
        @include('akvorado-flow::hooks.chart-script')
        @include('akvorado-flow::hooks.sankey-script')
    @endif
</div>
