<?php

namespace Tests\Isolated;

/**
 * Stands in for Stripe's curl client: records each request and answers from
 * a queue.
 */
class FakeStripeClient implements \Stripe\HttpClient\ClientInterface
{
    public $requests = [];
    private $responses = [];

    public function queue(int $code, array $body)
    {
        $this->responses[] = [$code, $body];
    }

    public function request($method, $absUrl, $headers, $params, $hasFile)
    {
        $named = [];
        foreach ($headers as $h) {
            [$k, $v] = array_map('trim', explode(':', $h, 2));
            $named[$k] = $v;
        }
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $named, 'params' => $params];

        [$code, $body] = array_shift($this->responses) ?: [500, ['error' => ['message' => 'no fake response queued']]];

        return [json_encode($body), $code, []];
    }
}
