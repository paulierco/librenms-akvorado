# LibreNMS Akvorado

A read-only LibreNMS plugin for investigating flow telemetry collected by Akvorado. Open a device’s **Akvorado** tab to see traffic, find conversations and top talkers, and compare what the collector observed with LibreNMS interface data.

## Features

- Device and port traffic charts, top talkers, conversations, and filters for host IP, source or destination IP, protocol, and destination service port.
- An optional previous-period overlay for the same device, port, and filters, plus the largest increases for conversations ranked in both windows.
- A communications map with interface, IP, and service views. The interface view can show only known paths or only paths with unknown ingress, with the excluded bitrate share shown alongside the diagram.
- Fixed-time incident links that preserve the investigation filters. An optional link opens Akvorado Visualize with the same time window and flow filter.
- Exporter diagnostics with query status, the latest nonzero flow bucket in the selected window, and the shares of observed L3 traffic with unknown ingress or egress.
- Selected-port SNMP counter history aligned to the investigation window, including traffic, errors, and discards. Flow-to-counter coverage estimates are available for comparable L2 traffic queries.
- A paginated exporter inventory and an optional API for supplying LibreNMS interface metadata to Akvorado.

## Install

Use LibreNMS 26.11 or a compatible release with Composer plugins and the device tab registry. The plugin package requires PHP 8.2 or later. The LibreNMS web server must be able to reach the Akvorado Console API.

From the LibreNMS install directory, as the user that runs LibreNMS updates:

~~~sh
composer config --global repositories.akvorado-flow vcs https://github.com/paulierco/librenms-akvorado.git
./lnms plugin:add community/librenms-akvorado 0.7.16
~~~

The global Composer repository entry lets LibreNMS resolve the package again when it synchronizes plugins. LibreNMS records the package and version constraint in its Composer plugin list. [LibreNMS Composer plugin configuration](https://docs.librenms.org/Support/Environment-Variables/#composer-packages)

Enable **akvorado-flow** in the LibreNMS plugin settings. Set the Console URL and map devices using the exporter addresses Akvorado receives. Example LibreNMS .env configuration:

~~~dotenv
AKVORADO_FLOW_URL=https://akvorado.example.net
AKVORADO_FLOW_EXPORTER_MAP='{"123":"192.0.2.10","switch.example.net":"192.0.2.20"}'
~~~

| Variable | Default | Purpose |
| --- | --- | --- |
| AKVORADO_FLOW_TOKEN | Empty | Optional bearer token for access to the configured Console endpoint |
| AKVORADO_FLOW_AUTO_MATCH_IP | false | Try the LibreNMS management IP when there is no explicit mapping |
| AKVORADO_FLOW_DEVICE_TAB | true | Register the Akvorado device tab |
| AKVORADO_FLOW_OVERVIEW_PANEL | false | Render the optional inline device overview panel |
| AKVORADO_FLOW_SHOW_LINK | false | Show the direct Console link |
| AKVORADO_FLOW_INVENTORY_ENABLED | true | Enable exporter inventory |
| AKVORADO_FLOW_INVENTORY_PROBE_MINUTES | 60 | Window used to look for recent exporter activity in inventory |
| AKVORADO_FLOW_METADATA_BRIDGE | false | Enable the authenticated interface metadata endpoint |
| AKVORADO_FLOW_METADATA_BRIDGE_PAGE_SIZE | 2000 | Maximum available metadata rows per page |
| AKVORADO_FLOW_ALLOW_HTTP | false | Permit plain HTTP for a trusted lab Console |

If LibreNMS uses cached configuration, rebuild or clear the configuration cache after editing .env. Visit a device’s **Akvorado** tab and open **Flow diagnostics** to check the mapping and query.

## Share an investigation

Use **Copy incident link** on the Akvorado tab to share the time window shown on the page, including when the time range was originally a rolling preset. The link keeps the selected port, filters, and view and follows the same LibreNMS permissions and eight-day fixed-window lookback as graph zoom.

Enable AKVORADO_FLOW_SHOW_LINK to open the scoped window directly in Akvorado Visualize. The configured Console URL must be reachable by the user's browser. The scoped link uses Akvorado's v1 visualization URL format, verified with v2026.10.1.

## Understand the results

- Akvorado reports sampled flow observations. An interface name can be empty because the exporter omitted the interface index or because enrichment could not resolve it. The diagnostic shares use all observed exporter traffic in the selected window. The map’s shares use the ranked paths displayed in that view.
- A flow can be sampled at multiple points. SNMP counters and flow estimates use different observation and aggregation methods. A coverage percentage is an estimate and may differ from the interface’s polled counters.
- Link-aggregate member links depend on the relationships LibreNMS has discovered in its port stack. Aggregate traffic may be attributed to physical members or to the logical interface by the exporter.
- The exporter inventory shows both known exporters and addresses observed in a recent, bounded query. A missing address in that result can have several causes, including quiet traffic in the selected window.

See the [architecture notes](ARCHITECTURE.md) for how the plugin queries Akvorado and exposes metadata. Consult [Akvorado’s exporter setup](https://demo.akvorado.net/docs/exporters) for collector configuration.

## Run tests

From this repository:

~~~sh
for suite in tests/*.php; do
    case "$suite" in *diagnose-port.php) continue ;; esac
    php -d zend.assertions=1 -d assert.exception=1 "$suite" || exit 1
done
node tests/sankey-render.js
node tests/incident-sharing.js
~~~

Set LIBRENMS_ROOT to run view tests against an installed LibreNMS environment. Set AKVORADO_TEST_DEVICE_ID to render the investigation view with a device that has flows. A read-only selected-port diagnostic is available separately:

~~~sh
php tests/diagnose-port.php 192.0.2.10 '1/1/1' 60
~~~

## License

[MIT](LICENSE)
