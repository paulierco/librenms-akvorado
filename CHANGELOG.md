# Changelog

## v0.7.22

- Recheck leading IP conversations that rank in only one period to distinguish missed rankings, no sampled traffic, and unavailable rechecks.

## v0.7.21

- Add a historical SNMP and Akvorado L2 overlay for a selected port with aligned averages and gaps for missing data.

## v0.7.20

- Show all device ports on a single visibility page. Keep collector requests batched and preserve SNMP rows when flow checks are unavailable.

## v0.7.19

- Added a per-device port visibility table with latest SNMP rates and separate ingress and egress observations.

## v0.7.18

- Link from port alert templates to the affected LibreNMS port and a window around the alert with previous-period comparison enabled.

## v0.7.17

- Added previous-period comparison and fixed-time incident sharing.

## v0.7.16

- Added on-demand exporter diagnostics with query status, latest nonzero flow bucket, and unknown ingress and egress shares.
- Added known-path and unknown-ingress filters to the communications map, with the excluded share of ranked bitrate.
- Preserved link-aggregate member scope when updating the map.

## v0.7.15

- Made the device tab available on authorized devices whether or not their exporters appear in recent results.
- Added separate guidance for missing mappings, unavailable exporter verification, and empty traffic results.

## v0.7.14

- Added a host IP filter that matches either endpoint of a conversation.
- Restricted SNMP coverage comparisons to comparable L2 flow queries and rolling windows.
- Distinguished unknown ingress from unknown egress and corrected the map’s attribution denominator.

## v0.7.9–v0.7.13

- Added dark-mode support, refined unknown-interface display, and improved installation guidance for LibreNMS’s plugin synchronization.
- Added an authenticated, opt-in LibreNMS interface metadata feed and per-member coverage when membership is available.
- Used LibreNMS’s discovered port-stack relationships for live LAG membership.

## v0.7.0–v0.7.8

- Added the interactive communications map, link-aggregation views, and per-direction traffic and coverage summaries.
- Improved flow-window calculations, exporter visibility, and rendering checks.

## Earlier versions

- Introduced device and port investigation, traffic charts, conversations, exporter inventory, and synchronized LibreNMS SNMP history.
