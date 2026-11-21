<?php

declare(strict_types=1);

return [
    // Server-side Akvorado console base URL. This must be a trusted internal endpoint.
    'url' => rtrim((string) env('AKVORADO_FLOW_URL', ''), '/'),
    'bearer_token' => (string) env('AKVORADO_FLOW_TOKEN', ''),
    // HTTP is allowed ONLY when explicitly opted in for a lab; use HTTPS in production.
    'allow_http' => (bool) env('AKVORADO_FLOW_ALLOW_HTTP', false),
    'timeout_seconds' => 7,
    'cache_seconds' => 60,
    // JSON object: {"123":"192.0.2.10","sw01.example.com":"192.0.2.10"}
    // An explicit map is needed when the exporter uses a different address.
    'exporter_map' => (string) env('AKVORADO_FLOW_EXPORTER_MAP', '{}'),
    'auto_match_management_ip' => (bool) env('AKVORADO_FLOW_AUTO_MATCH_IP', false),
    'show_direct_link' => (bool) env('AKVORADO_FLOW_SHOW_LINK', false),
    // Native-looking device tab: internal LibreNMS tab registry, version-sensitive.
    'device_tab' => (bool) env('AKVORADO_FLOW_DEVICE_TAB', true),
    // Previous v0.1 inline overview card is disabled when using the top tab.
    'overview_panel' => (bool) env('AKVORADO_FLOW_OVERVIEW_PANEL', false),
    // 60 minutes by default to cap expensive historical queries.
    'window_minutes' => 60,
    'max_conversations' => 10,
    'inventory_enabled' => (bool) env('AKVORADO_FLOW_INVENTORY_ENABLED', true),
    'inventory_cache_seconds' => 300,
    // Window for the single cached ExporterAddress probe that answers "is this exporter
    // sending flows now". Clamped in code to 5–1440 minutes; one probe, not per device.
    'inventory_probe_minutes' => (int) env('AKVORADO_FLOW_INVENTORY_PROBE_MINUTES', 60),
    // Opt-in LibreNMS -> Akvorado metadata feed (Akvorado `static` provider `exporter-sources`).
    // Off by default: it exposes device/interface identity to whatever token you point at it.
    'metadata_bridge_enabled' => (bool) env('AKVORADO_FLOW_METADATA_BRIDGE', false),
    'metadata_bridge_page_size' => (int) env('AKVORADO_FLOW_METADATA_BRIDGE_PAGE_SIZE', 2000),
    'max_ports_in_selector' => 1500,
    // Per-tab query budget. Optional datasets degrade gracefully if unsupported.
    'max_top_rows' => 12,
    // LAG membership comes from LibreNMS IF-MIB ports_stack only. No scheduled collection
    // exists in this plugin: a mapping written by an ad-hoc CLI run must never be presented
    // as current fact, so there is no persisted discovery file to expire or trust.
];
