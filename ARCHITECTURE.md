# Architecture

The package registers an Akvorado device tab, port hooks, an exporter inventory page, and an optional metadata API. The device tab delegates to the LibreNMS device controller and checks device and port permissions. Tab registration uses LibreNMS’s internal device-tab registry, so check the tab and port entry points when upgrading LibreNMS.

## Querying flow data

The web server sends read-only requests to the configured Akvorado Console. The plugin builds filters from the resolved exporter address and validated investigation inputs. Port queries use the enriched InIfName and OutIfName dimensions. Dashboard, map, and exporter diagnostics queries have separate caches. The map and exporter diagnostics load on demand.

The communications map summarizes ranked Akvorado Sankey paths. Unknown ingress and egress are directional placeholders, and Other is a ranking remainder. Filters change the displayed set of paths while retaining the original set as the denominator for the excluded bitrate share.

Exporter diagnostics runs three exporter-scoped series for the same window when traffic is present. The series measure total observed layer-3 traffic, traffic with an empty ingress name, and traffic with an empty egress name. The latest nonzero bucket indicates recent activity during the selected window. An empty name by itself cannot distinguish a missing exporter index from an enrichment failure.

## LibreNMS port data

The plugin uses LibreNMS’s polled interface rates, error/discard counters, and native history graphs. Coverage is computed for eligible L2 queries, with prior polling and sample scope taken into account. Aggregate membership comes from the LibreNMS port stack.

## Optional metadata feed

When AKVORADO_FLOW_METADATA_BRIDGE is enabled, GET /plugin/akvorado/metadata/exporters exposes a paginated interface metadata feed for use with Akvorado’s static exporter source. The endpoint uses LibreNMS API authentication and checks visibility of each device. Configure Akvorado with a LibreNMS API identity that has access to the devices it needs.

Responses contain an exporters array plus page, per_page, and count values. Exporter metadata includes the single-address exporter subnet and an interfaces list with interface index, name, description, and speed in Mbit/s. The feed uses the device addresses in LibreNMS. Compare those addresses with the source addresses in Akvorado before relying on the feed for name resolution. [Akvorado exporter metadata configuration](https://demo.akvorado.net/docs/configuration)
