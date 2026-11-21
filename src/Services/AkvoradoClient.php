<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class AkvoradoClient
{
    /**
     * Fetch an allowlisted Akvorado Console read-only widget.
     * This is separate from flow queries: "widget/exporters" lists known
     * exporter identities and does NOT establish that they are sending now.
     * @return array<string,mixed>
     */
    public function get(string $endpoint): array
    {
        if ($endpoint !== 'widget/exporters') {
            throw new RuntimeException('Unsupported Akvorado endpoint');
        }
        $response = $this->httpRequest()->get($this->baseUrl().'/api/v0/console/'.$endpoint);
        return $this->responseJson($response);
    }

    /** @return array<string,mixed> */
    public function post(string $endpoint, array $payload): array
    {
        if (! in_array($endpoint, ['graph/line', 'graph/sankey', 'filter/validate'], true)) {
            throw new RuntimeException('Unsupported Akvorado endpoint');
        }
        $response = $this->httpRequest()->post($this->baseUrl().'/api/v0/console/'.$endpoint, $payload);
        return $this->responseJson($response);
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) config('akvorado-flow.url', ''), '/');
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! $url || ! in_array($scheme, ['https', 'http'], true)
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null
            || ($scheme !== 'https' && ! config('akvorado-flow.allow_http', false))) {
            throw new RuntimeException('Set a trusted HTTPS AKVORADO_FLOW_URL (or explicitly opt in to lab HTTP)');
        }
        return $url;
    }

    private function httpRequest(): \Illuminate\Http\Client\PendingRequest
    {
        $this->baseUrl();
        $request = Http::acceptJson()->asJson()
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(2)
            ->timeout((int) config('akvorado-flow.timeout_seconds', 7));
        $token = (string) config('akvorado-flow.bearer_token', '');
        return $token !== '' ? $request->withToken($token) : $request;
    }

    /** @return array<string,mixed> */
    private function responseJson(\Illuminate\Http\Client\Response $response): array
    {
        if (! $response->successful()) {
            throw new RuntimeException('Akvorado API request failed (HTTP '.$response->status().')');
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Invalid Akvorado JSON response');
        }
        return $json;
    }
}