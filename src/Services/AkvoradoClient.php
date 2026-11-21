<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class AkvoradoClient
{
    /** Fetch allowlisted read-only Console metadata. */
    public function get(string $endpoint): array
    {
        if (! in_array($endpoint, ['widget/exporters', 'configuration'], true)) {
            throw new RuntimeException('Unsupported Akvorado endpoint');
        }
        $response = $this->httpRequest()->get($this->baseUrl().'/api/v0/console/'.$endpoint);
        return $this->responseJson($response);
    }

    public function investigationDimensions(): array
    {
        $key = 'akvorado-investigation-dimensions-v1:'.hash('sha256', (string) config('akvorado-flow.url'));
        return Cache::remember($key, 300, function (): array {
            try {
                $dimensions = $this->get('configuration')['dimensions'] ?? null;
                return is_array($dimensions) && array_is_list($dimensions)
                    ? array_values(array_filter($dimensions, 'is_string')) : [];
            } catch (RuntimeException|ConnectionException) {
                return [];
            }
        });
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
