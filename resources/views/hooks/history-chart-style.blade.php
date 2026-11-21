<style>
    .akv-history-chart { --akv-card-bg:#fff; --akv-ink:#1f2d42; --akv-rule:#e8edf4 }
    .dark .akv-history-chart { --akv-card-bg:#2e3338; --akv-ink:#c8c8c8; --akv-muted:#acb6bf; --akv-rule:#3e444c }
    .akv-history-chart .akv-chart-wrap { position:relative; margin-top:9px; height:252px }
    .akv-history-chart canvas { display:block; width:100%; height:252px }
    .akv-history-chart .akv-tooltip { display:none; position:absolute; pointer-events:none; white-space:pre; padding:7px 9px; background:#202632; color:#fff; border-radius:4px; font-size:12px }
    .akv-history-chart .akv-legend { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:18px }
    .akv-history-chart .akv-legend-item { border:1px solid var(--akv-rule); border-radius:4px; background:var(--akv-card-bg); color:var(--akv-ink); padding:4px 8px }
    .akv-history-chart .akv-legend-item[aria-pressed="false"] { opacity:.65; text-decoration:line-through }
    .akv-history-chart .akv-dot { display:inline-block; width:8px; height:8px; margin-right:6px; border-radius:50% }
    .akv-history-chart .akv-line-key { display:inline-block; width:17px; border-top-width:2px; margin:0 6px 3px 0 }
</style>
