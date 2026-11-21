'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const views = path.join(__dirname, '..', 'resources', 'views');
const view = fs.readFileSync(path.join(views, 'hooks', 'incident-sharing.blade.php'), 'utf8');
const library = fs.readFileSync(path.join(views, 'vendor', 'lz-string-compress.blade.php'), 'utf8');
const script = view.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace("@include('akvorado-flow::vendor.lz-string-compress')", library)
    .replace(/@if\(\$consoleUrl\)|@endif/g, '');

const fixed = 'https://nms.example.net/device/12/akvorado?start=1795258800&end=1795262400&port=42&host=192.0.2.10&section=sankey&sankey_paths=known';
const consoleState = JSON.stringify({filter: 'InIfName = "Étage 1"', start: '2026-11-21T11:00:00.000Z'});
const consoleExpected = 'N4IgZglgNgLgpgJxALhASQHZrAOQIYC2cABALzEA6IAkzHgOYkCMVIANCAM50IwogAmAAwCAbAFomTcQKYAVKciFClQgHTKhALRABfIA';

async function main() {
    const externalLink = {href: 'https://akvorado.example.net/console/', dataset: {akvConsoleState: consoleState}};
    const status = {};
    const fallback = {hidden: true, focus() { this.focused = true; }, select() { this.selected = true; }};
    let click;
    let section = 'flows';
    let copied;
    const sharing = {
        dataset: {akvIncidentUrl: fixed},
        closest: () => ({querySelector: () => ({dataset: {akvTab: section}})}),
        querySelector: selector => ({
            '[data-akv-incident-copy]': {addEventListener: (event, handle) => { assert.equal(event, 'click'); click = handle; }},
            '[data-akv-incident-status]': status,
            '[data-akv-incident-fallback]': fallback,
            '[data-akv-console-link]': externalLink
        })[selector]
    };
    const navigator = {clipboard: {writeText: async value => { copied = value; }}};
    vm.runInNewContext(script, {
        URL, navigator, document: {currentScript: {closest: () => sharing}}, window: {location: {href: fixed}}
    });

    await click();
    const url = new URL(copied);
    assert.equal(url.pathname, '/device/12/akvorado');
    assert.equal(url.searchParams.get('start'), '1795258800');
    assert.equal(url.searchParams.get('end'), '1795262400');
    assert.equal(url.searchParams.get('port'), '42');
    assert.equal(url.searchParams.get('host'), '192.0.2.10');
    assert.equal(url.searchParams.get('section'), 'flows');
    assert.equal(url.searchParams.get('sankey_paths'), 'known');
    assert.equal(status.textContent, 'Link copied');

    delete navigator.clipboard;
    section = 'traffic';
    await click();
    assert.equal(fallback.hidden, false);
    assert.equal(fallback.focused, true);
    assert.equal(fallback.selected, true);
    assert.equal(new URL(fallback.value).searchParams.has('section'), false);

    const consoleUrl = new URL(externalLink.href);
    assert.equal(consoleUrl.origin, 'https://akvorado.example.net');
    assert.equal(decodeURIComponent(consoleUrl.pathname), '/console/visualize/v1-' + consoleExpected);
    console.log('PASS incident links preserve fixed windows, active views and a versioned Akvorado URL');
}

main().catch(error => { console.error(error); process.exitCode = 1; });
