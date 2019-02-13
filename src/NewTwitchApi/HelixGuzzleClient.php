<?php

declare(strict_types=1);

namespace NewTwitchApi;

use GuzzleHttp\Client;
use NewTwitchApi\Auth\OauthApi;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class HelixGuzzleClient extends Client
{
    private const BASE_URI = 'https://api.twitch.tv/helix/';

    /** @var OauthApi */
    private $oauthApi;
    /** @var string */
    private $clientId;
    /** @var string */
    private $clientSecret;
    /** @var int */
    private $refreshBufferSeconds = 30;

    /**
     * [
     *   //NOTE: Twitch returned auth data
     *   "access_token" => "<user access token>",
     *   "refresh_token" => "<refresh token>",
     *   "expires_in" => <number of seconds until the token expires>,
     *   "scope" => ["<your previously listed scope(s)>"],
     *   "token_type" => "bearer"
     *
     *   //NOTE: Following values are attached and do NOT come from twitch
     *   "token_type" => "<user-access|app-access>";
     *   "request_time" => "<timestamp of when the request was made>";
     * ]
     * @var null|array Holds the auth data returned from twitch
     */
    private $authData = null;

    /**
     * HelixGuzzleClient constructor.
     *
     * @param string $clientId The Twitch Client Id
     * @param string $clientSecret The Twitch Secret
     * @param array $config Guzzle Config Array
     */
    public function __construct(string $clientId, string $clientSecret, array $config = [])
    {
        parent::__construct($config + [
                'base_uri' => self::BASE_URI,
                'timeout' => 30,
                'headers' => ['Content-Type' => 'application/json'],
            ]);
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->oauthApi = new OauthApi($clientId, $clientSecret);
    }

    /**
     * Returns the OauthApi object
     *
     * @return OauthApi
     */
    public function getOauthApi()
    {
        return $this->oauthApi;
    }

    /**
     * Returns the Twitch Client Id
     *
     * @return string
     */
    public function getClientId()
    {
        return $this->clientId;
    }

    /**
     * Returns the Twitch Client Secret
     *
     * @return string
     */
    public function getClientSecret()
    {
        return $this->clientSecret;
    }

    /**
     * Get's a Twitch User Access token for authenticating on future requests
     *
     * @param $code
     * @param string $redirectUri
     * @param null $state
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getUserAccessToken($code, string $redirectUri, $state = null)
    {
        $requestTime = time();
        $response = $this->oauthApi->getUserAccessToken($code, $redirectUri, $state);
        $this->authData = json_decode($response->getBody()->getContents());
        $this->authData['token_type'] = 'user-access';
        $this->authData['request_time'] = $requestTime;
    }

    /**
     * Get's a Twitch Application Access token for authentication on future requests
     *
     * @param string $scope
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getAppAccessToken(string $scope = '')
    {
        $requestTime = time();
        $response = $this->oauthApi->getAppAccessToken($scope);
        $this->authData = json_decode($response->getBody()->getContents(), true);
        $this->authData['token_type'] = 'app-access';
        $this->authData['request_time'] = $requestTime;
    }

    private function refreshToken()
    {
        switch ($this->authData) {
            case 'app-access':
                $this->getAppAccessToken($this->authData['scope']);
                break;
            case 'user-access';
                $response = $this->oauthApi->refreshToken($this->authData['refresh_token'], $this->authData['scope']);
                $data = json_decode($response->getBody()->getContents(), true);
                $this->authData['access_token'] = $data['access_token'];
                $this->authData['refresh_token'] = $data['refresh_token'];
                $this->authData['scope'] = $data['scope'];
                break;
            default:
                //TODO: What if an unknown token type?
        }
    }

    /**
     * Checks if the token has expired and refresh if needed
     */
    private function checkTokenExpired()
    {
        if (
            $this->authData !== null &&
            $this->authData['expires_in'] + $this->authData['request_time'] > time() - $this->refreshBufferSeconds
        ) {
            $this->refreshToken();
        }
    }

    /**
     * Checks the response from twitch to see if the auth should be refreshed
     * https://dev.twitch.tv/docs/authentication/#refreshing-access-tokens
     * Section: Refresh in Response to Server Rejection for Bad Authentication
     *
     * @param ResponseInterface $response Guzzle response object
     * @return bool True if refreshed, false if not
     */
    private function checkResponseForAuthRefresh(ResponseInterface $response)
    {
        if (
            $this->authData !== null &&
            $response->getStatusCode() === 401 && $response->hasHeader('WWW-Authenticate')
        ) {
            $this->refreshToken();
            return true;
        }
        return false;
    }

    /**
     * Injects authentication headers in to the Guzzle headers array
     *
     * @param array $options Guzzle options array
     * @return array Guzzle options array
     */
    private function getAuthHeaders(array $options)
    {
        if ($this->authData === null) {
            return ['headers' => ['Client-ID' => $this->clientId]] + $options;
        } else {
            return ['headers' => ['Authorization' => 'Bearer ' . $this->authData['access_token']]] + $options;
        }
    }

    /**
     * @inheritdoc
     */
    public function send(RequestInterface $request, array $options = [])
    {
        $this->checkTokenExpired();
        $response = parent::send($request, $this->getAuthHeaders($options));
        if ($this->checkResponseForAuthRefresh($response)) {
            $response = parent::send($request, $this->getAuthHeaders($options));
        }
        return $response;
    }

    /**
     * @inheritdoc
     */
    public function request($method, $uri = '', array $options = [])
    {
        $this->checkTokenExpired();
        $response = parent::request($method, $uri, $this->getAuthHeaders($options));
        if ($this->checkResponseForAuthRefresh($response)) {
            $response = parent::request($method, $uri, $this->getAuthHeaders($options));
        }
        return $response;
    }
}
