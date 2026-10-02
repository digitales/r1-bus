<?php

namespace App\Services;

use App\Exceptions\TflUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class TflClient
{
    private const BUS_STOP_TYPE = 'NaptanPublicBusCoachTram';

    /**
     * @return list<array{id: string, name: string, towards: ?string}>
     */
    public function searchStops(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $body = $this->get('/StopPoint/Search/'.rawurlencode($query), ['modes' => 'bus', 'maxResults' => 15]);

        $places = [];

        foreach ($body['matches'] ?? [] as $match) {
            if (isset($match['id'], $match['name'])) {
                $places[] = [
                    'id' => $match['id'],
                    'name' => $match['name'],
                    'towards' => $match['towards'] ?? null,
                ];
            }
        }

        return $places;
    }

    /**
     * @return list<array{naptan_id: string, name: string, stop_letter: ?string, towards: ?string}>
     */
    public function stopsAt(string $id): array
    {
        $stops = [];
        $this->collectBusStops($this->get('/StopPoint/'.rawurlencode($id)), $stops);

        return $stops;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function arrivals(string $naptanId): array
    {
        return array_values($this->get('/StopPoint/'.rawurlencode($naptanId).'/Arrivals'));
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
