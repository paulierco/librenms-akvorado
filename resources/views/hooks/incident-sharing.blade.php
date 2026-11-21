@php
    $incidentStart = gmdate('Y-m-d\TH:i:s.000\Z', $panel['windowStart']);
    $incidentEnd = gmdate('Y-m-d\TH:i:s.000\Z', $panel['windowEnd']);
    $incidentUrl = $drillUrl(['start' => $panel['windowStart'], 'end' => $panel['windowEnd']]);
    $consoleUrl = config('akvorado-flow.show_direct_link', false) ? config('akvorado-flow.url') : null;
    $consoleState = [
        'graphType' => 'lines', 'start' => $incidentStart, 'end' => $incidentEnd,
        'humanStart' => $incidentStart, 'humanEnd' => $incidentEnd,
        'filter' => $panel['filterExpression'], 'dimensions' => [],
        'units' => $metric === 'bytes' ? 'l3bps' : $metric, 'limit' => 10, 'limitType' => 'avg',
        'truncate-v4' => 32, 'truncate-v6' => 128, 'bidirectional' => false, 'previousPeriod' => false,
    ];
@endphp
<div data-akv-incident-sharing data-akv-incident-url="{{ $incidentUrl }}">
    <button type="button" class="btn btn-default btn-sm" data-akv-incident-copy>Copy incident link</button>
    @if($consoleUrl)
        <a href="{{ $consoleUrl }}" rel="noopener noreferrer" target="_blank" class="btn btn-default btn-sm"
           data-akv-console-link data-akv-console-state="{{ json_encode($consoleState, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) }}">Open in Akvorado</a>
    @endif
    <span class="text-muted" aria-live="polite" data-akv-incident-status></span>
    <input class="form-control input-sm" type="text" aria-label="Incident link" data-akv-incident-fallback readonly hidden>
    <script>
    (function () {
        'use strict';
        const sharing = document.currentScript.closest('[data-akv-incident-sharing]');
        if (!sharing) return;
        const copy = sharing.querySelector('[data-akv-incident-copy]');
        const status = sharing.querySelector('[data-akv-incident-status]');
        const fallback = sharing.querySelector('[data-akv-incident-fallback]');

        copy.addEventListener('click', async function () {
            const url = new URL(sharing.dataset.akvIncidentUrl, window.location.href);
            const active = sharing.closest('.akv-viz').querySelector('[data-akv-tab][aria-selected="true"]');
            const section = active?.dataset.akvTab;
            if (['talkers', 'flows', 'health', 'sankey'].includes(section)) {
                url.searchParams.set('section', section);
            } else if (section === 'traffic') {
                url.searchParams.delete('section');
            }
            try {
                await navigator.clipboard.writeText(url.href);
                fallback.hidden = true;
                status.textContent = 'Link copied';
            } catch (error) {
                fallback.value = url.href;
                fallback.hidden = false;
                fallback.focus();
                fallback.select();
                status.textContent = 'Select and copy the link';
            }
        });

        @if($consoleUrl)
            @include('akvorado-flow::vendor.lz-string-compress')
            const external = sharing.querySelector('[data-akv-console-link]');
            const consoleUrl = new URL(external.href);
            const uriAlphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+-$';
            const state = lzStringCompress(external.dataset.akvConsoleState, 6, value => uriAlphabet.charAt(value));
            consoleUrl.pathname = consoleUrl.pathname.replace(/\/$/, '') + '/visualize/v1-' + encodeURIComponent(state);
            consoleUrl.search = '';
            consoleUrl.hash = '';
            external.href = consoleUrl.href;
        @endif
    })();
    </script>
</div>
