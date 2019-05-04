<?php

namespace Tapp\Airtable\Api;

use GuzzleHttp\Client;
use Illuminate\Support\Str;

class AirtableApiClient implements ApiClient
{
    private $client;

    private $base;
    private $table;

    private $filters = [];
    private $pageSize = 100;
    private $maxRecords = 100;

    public function __construct($base, $table, $access_token, Client $client = null)
    {
        $this->base = $base;
        $this->table = $table;
        $this->client = $client ?? $this->buildClient($access_token) ;
    }

    private function buildClient($access_token)
    {
        $stack = \GuzzleHttp\HandlerStack::create();
        $stack->push(
            \GuzzleHttp\Middleware::log(
                new \Monolog\Logger('Logger'),
                new \GuzzleHttp\MessageFormatter('{request} >>> {res_body}')
            )
        );

        return new Client([
            'base_uri' => 'https://api.airtable.com',
            'headers' => [
                'Authorization' => "Bearer {$access_token}",
                'content-type' => 'application/json',
            ],
            // uncomment to log requests
            'handler' => $stack,
        ]);
    }

    public function where($column, $value)
    {
        $this->filters [] = "{{$column}}=\"{$value}\"";

        return $this;
    }

    public function table($table)
    {
        $this->table = $table;

        return $this;
    }

    public function firstOrCreate(array $idData, array $createData = [])
    {
        foreach ($idData as $key => $value) {
            $this->where($key, $value);
        }

        $results = $this->get();

        // first
        if ($results->isNotEmpty()) {
            return $results->first();
        }

        // create
        $data = array_merge($idData, $createData);

        return $this->post($data);
    }

    public function createOrUpdate(array $idData, array $updateData = [])
    {
        foreach ($idData as $key => $value) {
            $this->where($key, $value);
        }

        $results = $this->get();

        // first
        if ($results->isNotEmpty()) {
            return $results->first();
        }

        // create
        $data = array_merge($idData, $createData);

        return $this->post($data);
    }

    public function get(?string $id = null)
    {
        $url = $this->getEndpointUrl($id);

        return $this->jsonToArray($this->client->get($url));
    }

    public function getAllPages()
    {
        $url = $this->getEndpointUrl();

        $response = $this->client->get($url, [
            'query' => [
                'pageSize' => $this->pageSize,
                'maxRecords' => $this->maxRecords,
            ],
        ]);

        //TODO: loop through offset to get more than one page when more than 100 records exist

        return $this->jsonToObject($response);
    }

    public function post($contents = null)
    {
        $url = $this->getEndpointUrl();

        $params = ['json' => ['fields' => (object) $contents]];

        return $this->jsonToArray($this->client->post($url, $params));
    }

    public function put(string $id, $contents = null)
    {
        $url = $this->getEndpointUrl($id);

        $params = ['json' => ['fields' => (object) $contents]];

        return $this->jsonToObject($this->client->put($url, $params));
    }

    public function patch(string $id, $contents = null)
    {
        $url = $this->getEndpointUrl($id);

        $params = ['json' => ['fields' => (object) $contents]];

        return $this->jsonToObject($this->client->patch($url, $params));
    }

    public function delete(string $id)
    {
        $url = $this->getEndpointUrl($id);

        return $this->jsonToObject($this->client->delete($url));
    }

    public function responseToJson($response)
    {
        $body = (string) $response->getBody();

        return $body;
    }

    public function jsonToObject($response)
    {
        $body = (string) $response->getBody();

        if ($body === '') {
            return collect([]);
        }

        $object = json_decode($body);

        return isset($object->records) ? collect($object->records) : $object;
    }

    public function jsonToArray($response)
    {
        $body = (string) $response->getBody();

        if ($body === '') {
            return [];
        }

        return json_decode($body, true);
    }

    protected function getEndpointUrl(?string $id = null): string
    {
        if ($id) {
            $url = '/v0/~/~/~';

            return Str::replaceArray('~', [
                $this->base,
                $this->table,
                $id,
            ], $url);
        }

        $url = '/v0/~/~';

        $url = Str::replaceArray('~', [
            $this->base,
            $this->table
        ], $url);

        if ($this->filters) {
            $url .= '?' . http_build_query([
                'filterByFormula' => implode('&', $this->filters),
            ]);
        }

        return $url;
    }
}
