<?php

declare(strict_types=1);

namespace NewTwitchApi\Webhooks;

use GuzzleHttp\Client;
use NewTwitchApi\HelixGuzzleClient;

class WebhooksSubscriptionApi
{
    public const SUBSCRIBE = 'subscribe';

    private $guzzleClient;

    public function __construct(HelixGuzzleClient $guzzleClient = null)
    {
        $this->guzzleClient = $guzzleClient ?? new HelixGuzzleClient($clientId);
    }

    public function subscribeToStream(int $twitchId, string $bearer, string $callback, int $leaseSeconds = 0): void
    {
        $this->subscribe(
            sprintf('https://api.twitch.tv/helix/streams?user_id=%s', $twitchId),
            $bearer,
            $callback,
            $leaseSeconds
        );
    }

    public function validateWebhookEventCallback(string $xHubSignature, string $content): bool
    {
        [$hashAlgorithm, $expectedHash] = explode('=', $xHubSignature);
        $generatedHash = hash_hmac($hashAlgorithm, $content, $this->secret);

        return $expectedHash === $generatedHash;
    }

    private function subscribe(string $topic, string $bearer, string $callback, int $leaseSeconds = 0): void
    {
        $headers = [
            'Authorization' => sprintf('Bearer %s', $bearer),
            'Client-ID' => $this->guzzleClient->getClientId(),
        ];

        $body = [
            'hub.callback' => $callback,
            'hub.mode' => self::SUBSCRIBE,
            'hub.topic' => $topic,
            'hub.lease_seconds' => $leaseSeconds,
            'hub.secret' => $this->guzzleClient->getClientSecret(),
        ];

        $this->guzzleClient->post('webhooks/hub', [
            'headers' => $headers,
            'body' => json_encode($body),
        ]);
    }
}
