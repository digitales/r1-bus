<?php

namespace App\Services;

use App\Exceptions\TflUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class TflClient
{
    private const BUS_STOP_TYPE = 'NaptanPublicBusCoachTram';

    /**
     * Every bus stop at the places matching $query, each with its letter and
     * direction. TfL's search only returns places (stop pairs, hubs), so the
     * stops inside them come from one batched follow-up call.
     *
     * @return list<array{naptan_id: string, name: string, stop_letter: ?string, towards: ?string}>
     */
    public function searchStops(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $body = $this->get('/StopPoint/Search/'.rawurlencode($query), ['modes' => 'bus', 'maxResults' => 15]);

        $ids = [];

        foreach ($body['matches'] ?? [] as $match) {
            if (isset($match['id'], $match['name'])) {
                $ids[] = $match['id'];
            }
        }

        return $ids === [] ? [] : $this->stopsAt(...$ids);
    }

    /**
     * The individual bus stops inside one or more places, in one call.
     *
     * @return list<array{naptan_id: string, name: string, stop_letter: ?string, towards: ?string}>
     */
    public function stopsAt(string ...$ids): array
    {
        $body = $this->get('/StopPoint/'.implode(',', array_map(rawurlencode(...), $ids)));

        $stops = [];

        foreach (array_is_list($body) ? $body : [$body] as $node) {
            if (is_array($node)) {
                $this->collectBusStops($node, $stops);
            }
        }

        return $stops;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function arrivals(string $naptanId): array
    {
        $predictions = $this->get('/StopPoint/'.rawurlencode($naptanId).'/Arrivals');

        if (! array_is_list($predictions)) {
            throw new TflUnavailable('TfL sent arrivals that were not a list.');
        }

        return $predictions;
    }

    private function collectBusStops(array $node, array &$stops): void
    {
        if (($node['stopType'] ?? null) === self::BUS_STOP_TYPE && isset($node['naptanId'], $node['commonName'])) {
            $stops[] = [
                'naptan_id' => $node['naptanId'],
                'name' => $node['commonName'],
                'stop_letter' => $node['stopLetter'] ?? null,
                'towards' => $this->towards($node),
            ];
        }

        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $this->collectBusStops($child, $stops);
            }
        }
    }

    private function towards(array $node): ?string
    {
        foreach ($node['additionalProperties'] ?? [] as $property) {
            if (($property['key'] ?? null) === 'Towards') {
                return $property['value'] ?? null;
            }
        }

        return null;
    }

    private function get(string $path, array $query = []): array
    {
        $query = array_filter($query + ['app_key' => config('services.tfl.app_key')]);

        try {
            $response = Http::baseUrl(config('services.tfl.base_url'))
                ->acceptJson()
                ->timeout(5)
                ->retry(2, 200, throw: false)
                ->get($path, $query);
        } catch (ConnectionException $exception) {
            throw new TflUnavailable('Could not reach TfL.', previous: $exception);
        }

        if ($response->failed()) {
            throw new TflUnavailable("TfL answered {$response->status()}.");
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new TflUnavailable('TfL sent a response that was not JSON.');
        }

        return $body;
    }
}
