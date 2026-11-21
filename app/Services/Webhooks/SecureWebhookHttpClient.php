<?php

namespace App\Services\Webhooks;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use LengthException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class SecureWebhookHttpClient
{
    private const MAX_RESPONSE_BYTES = 262144; // 256 KiB

    private readonly ClientInterface $client;

    public function __construct(
        private readonly WebhookUrlValidator $urlValidator,
        ?ClientInterface $client = null,
    ) {
        if (! extension_loaded('curl')) {
            throw new RuntimeException(
                'The PHP cURL extension is required.'
            );
        }

        $this->client = $client ?? new Client([
            'handler' => HandlerStack::create(new CurlHandler),
        ]);
    }

    /**
     * @throws GuzzleException
     * @throws LengthException
     */
    public function send(
        string $url,
        string $body,
        array $headers = [],
    ): ResponseInterface {
        $destination = $this->urlValidator->validateAndResolve($url);

        $address = $destination->selectedAddress();

        // Maximum response size: 256 KiB.
        $downloadLimit = self::MAX_RESPONSE_BYTES;

        // Abort downloads that exceed the configured limit.
        $progress = static function (
            int $downloadTotal,
            int $downloaded,
            int $uploadTotal,
            int $uploaded,
        ) use ($downloadLimit): void {
            if ($downloaded > $downloadLimit) {
                throw new LengthException(
                    'Webhook response exceeds the allowed size.'
                );
            }
        };

        // Disable proxies and force the selected IP address family.
        $forceIpResolve = str_contains($address, ':')
            ? 'v6'
            : 'v4';

        // Pin DNS resolution to the previously validated IP.
        // Literal IP addresses do not need DNS pinning.
        if (
            filter_var(
                $destination->hostname,
                FILTER_VALIDATE_IP
            ) === false
        ) {
            $curlAddress = str_contains($address, ':')
                ? '['.$address.']'
                : $address;

            $resolveEntry = sprintf(
                '%s:%d:%s',
                $destination->hostname,
                $destination->port,
                $curlAddress,
            );

        }

        try {
            return $this->client->request('POST', $url, [
                'headers' => array_merge([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'RelayGuard/1.0',
                ], $headers),

                'body' => $body,

                'allow_redirects' => false,
                'http_errors' => false,
                'verify' => true,

                'connect_timeout' => 3,
                'timeout' => 10,

                'proxy' => '',

                'force_ip_resolve' => $forceIpResolve,

                'on_headers' => static function (
                    ResponseInterface $response,
                ) use ($downloadLimit): void {
                    $contentLength = $response->getHeaderLine(
                        'Content-Length'
                    );

                    if (
                        $contentLength !== ''
                        && ctype_digit($contentLength)
                        && (int) $contentLength > $downloadLimit
                    ) {
                        throw new LengthException(
                            'Webhook response exceeds the allowed size.'
                        );
                    }
                },

                'progress' => $progress,

                'curl' => isset($resolveEntry)
                    ? [
                        CURLOPT_RESOLVE => [$resolveEntry],
                    ]
                    : [],
            ]);
        } catch (GuzzleException $exception) {
            $previous = $exception->getPrevious();

            if ($previous instanceof LengthException) {
                throw $previous;
            }

            throw $exception;
        }
    }
}
