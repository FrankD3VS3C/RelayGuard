<?php

namespace Tests\Unit\Services\Webhooks;

use App\Services\Webhooks\PublicIpAddressValidator;
use App\Services\Webhooks\SecureWebhookHttpClient;
use App\Services\Webhooks\WebhookUrlValidator;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LengthException;
use PHPUnit\Framework\TestCase;

class SecureWebhookHttpClientTest extends TestCase
{
    /**
     * @return array{SecureWebhookHttpClient, array}
     */
    private function makeClient(
        array $responses,
        ?\Closure $resolver = null,
    ): array {
        $history = [];

        $mock = new MockHandler($responses);

        $stack = HandlerStack::create($mock);

        $stack->push(Middleware::history($history));

        $guzzle = new Client([
            'handler' => $stack,
        ]);

        $validator = new WebhookUrlValidator(
            new PublicIpAddressValidator,
            $resolver,
        );

        $client = new SecureWebhookHttpClient(
            $validator,
            $guzzle,
        );

        return [
            'client' => $client,
            'history' => &$history,
        ];
    }

    public function test_it_pins_hostname_to_validated_ip(): void
    {
        $result = $this->makeClient(
            [new Response(200)],
            fn (string $host): array => ['93.184.216.34'],
        );

        $client = $result['client'];
        $client->send(
            'https://example.com/webhook',
            '{"event":"test"}',
        );

        $options = $result['history'][0]['options'];

        $this->assertSame(
            ['example.com:443:93.184.216.34'],
            $options['curl'][CURLOPT_RESOLVE],
        );
    }

    public function test_it_does_not_use_dns_pinning_for_ip_literals(): void
    {
        $result = $this->makeClient([
            new Response(200),
        ]);

        $client = $result['client'];
        $client->send(
            'https://8.8.8.8/webhook',
            '{"event":"test"}',
        );

        $options = $result['history'][0]['options'];

        $this->assertSame(
            [],
            $options['curl'],
        );

        $this->assertSame(
            'v4',
            $options['force_ip_resolve'],
        );
    }

    public function test_it_does_not_follow_redirects(): void
    {
        $result = $this->makeClient([
            new Response(302, [
                'Location' => 'http://127.0.0.1/private',
            ]),
            new Response(200),
        ]);
        $client = $result['client'];
        $response = $client->send(
            'https://example.com/webhook',
            '{"event":"test"}',
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertCount(1, $result['history']);
        $this->assertFalse($result['history'][0]['options']['allow_redirects']);
    }

    public function test_it_enforces_secure_transport_options(): void
    {
        $result = $this->makeClient([
            new Response(200),
        ]);
        $client = $result['client'];
        $client->send(
            'https://example.com/webhook',
            '{"event":"test"}',
        );

        $options = $result['history'][0]['options'];

        $this->assertTrue($options['verify']);
        $this->assertSame(3, $options['connect_timeout']);
        $this->assertSame(10, $options['timeout']);
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame('', $options['proxy']);
    }

    public function test_it_rejects_response_with_excessive_content_length(): void
    {
        $result = $this->makeClient([
            new Response(200),
        ]);
        $client = $result['client'];
        $client->send(
            'https://example.com/webhook',
            '{"event":"test"}',
        );

        $onHeaders = $result['history'][0]['options']['on_headers'];

        $this->expectException(LengthException::class);

        $onHeaders(new Response(200, [
            'Content-Length' => '300000',
        ]));
    }

    public function test_it_rejects_excessive_download_progress(): void
    {
        $result = $this->makeClient([
            new Response(200),
        ]);
        $client = $result['client'];
        $client->send(
            'https://example.com/webhook',
            '{"event":"test"}',
        );

        $progress = $result['history'][0]['options']['progress'];

        $this->expectException(LengthException::class);

        $progress(300000, 262145, 100, 100);
    }

    public function test_mock_handler_records_a_request(): void
    {
        $result = $this->makeClient(
            [
                new Response(200),
            ],
            fn (string $host): array => ['93.184.216.34'],
        );

        $result['client']->send(
            'https://example.com/webhook',
            '{"event":"test"}',
        );

        $this->assertCount(1, $result['history']);

        $this->assertSame(
            '{"event":"test"}',
            (string) $result['history'][0]['request']->getBody(),
        );
    }
}
