<?php

/*
 * Copyright (c) 2017, 2018 François Kooman <fkooman@tuxed.net>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace fkooman\OAuth\Client;

use DateTime;
use fkooman\OAuth\Client\Exception\AuthorizeException;
use fkooman\OAuth\Client\Exception\OAuthException;
use fkooman\OAuth\Client\Exception\TokenException;
use fkooman\OAuth\Client\Http\HttpClientInterface;
use fkooman\OAuth\Client\Http\Request;
use fkooman\OAuth\Client\Http\Response;
use ParagonIE\ConstantTime\Base64;
use GuzzleHttp\Exception\ClientException;

class OAuthClient
{
    /** @var TokenStorageInterface */
    private $tokenStorage;

    /** @var \fkooman\OAuth\Client\Http\HttpClientInterface */
    private $httpClient;

    /** @var SessionInterface */
    private $session;

    /** @var RandomInterface */
    private $random;

    /** @var \DateTime */
    private $dateTime;

    /** @var null|Provider */
    private $provider = null;

    /** @var null|string */
    private $userId = null;

    /**
     * @param TokenStorageInterface    $tokenStorage
     * @param Http\HttpClientInterface $httpClient
     * @param null|SessionInterface    $session
     * @param null|RandomInterface     $random
     * @param null|\DateTime           $dateTime
     */
    public function __construct(
        TokenStorageInterface $tokenStorage,
        HttpClientInterface $httpClient,
        SessionInterface $session = null,
        RandomInterface $random = null,
        DateTime $dateTime = null
    ) {
        $this->tokenStorage = $tokenStorage;
        $this->httpClient = $httpClient;
        if (null === $session) {
            $session = new Session();
        }
        $this->session = $session;
        if (null === $random) {
            $random = new Random();
        }
        $this->random = $random;
        if (null === $dateTime) {
            $dateTime = new DateTime();
        }
        $this->dateTime = $dateTime;
    }

    /**
     * @param \DateTime $dateTime
     *
     * @return void
     */
    public function setDateTime(DateTime $dateTime)
    {
        $this->dateTime = $dateTime;
    }

    /**
     * @param Provider $provider
     *
     * @return void
     */
    public function setProvider(Provider $provider)
    {
        $this->provider = $provider;
    }

    /**
     * @param string $userId
     *
     * @return void
     */
    public function setUserId($userId)
    {
        $this->userId = $userId;
    }

    /**
     * Perform a HTTP request.
     */
    public function send($requestScope, $httpClient, $request)
    {
        if (null === $this->userId) {
            throw new OAuthException('userId not set');
        }

        $accessToken = $this->getAccessToken($requestScope);
        if (!$accessToken) {
            return false;
        }

        if ($accessToken->isExpired($this->dateTime)) {
            // access_token is expired, try to refresh it
            if (null === $accessToken->getRefreshToken()) {
                // we do not have a refresh_token, delete this access token, it
                // is useless now...
                $this->tokenStorage->deleteAccessToken($this->userId, $accessToken);

                return false;
            }

            // try to refresh the AccessToken
            $accessToken = $this->refreshAccessToken($accessToken);
            if (!$accessToken) {
                // didn't work
                return false;
            }
        }

        try {
          // add Authorization header to the request headers
          return $httpClient->send($request, ['headers' => ['Authorization' => sprintf('Bearer %s', $accessToken->getToken())]]);
        } catch (ClientException $e) {
          if ($e->getResponse()->getStatusCode() === 401) {
            // the access_token was not accepted, but isn't expired, we assume
            // the user revoked it, also no need to try with refresh_token
            $this->tokenStorage->deleteAccessToken($this->userId, $accessToken);
          }
          throw $e;
        }
    }

    /**
     * Obtain an authorization request URL to start the authorization process
     * at the OAuth provider.
     *
     * @param string $scope       the space separated scope tokens
     * @param string $redirectUri the URL registered at the OAuth provider, to
     *                            be redirected back to
     *
     * @return string the authorization request URL
     *
     * @see https://tools.ietf.org/html/rfc6749#section-3.3
     * @see https://tools.ietf.org/html/rfc6749#section-3.1.2
     */
    public function getAuthorizeUri($scope, $redirectUri)
    {
        if (null === $this->userId) {
            throw new OAuthException('userId not set');
        }
        if (null === $this->provider) {
            throw new OAuthException('provider not set');
        }

        $queryParameters = [
            'client_id' => $this->provider->getClientId(),
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'state' => $this->random->getHex(16),
            'response_type' => 'code',
            'access_type' => 'offline',
        ];

        $authorizeUri = sprintf(
            '%s%s%s',
            $this->provider->getAuthorizationEndpoint(),
            false === strpos($this->provider->getAuthorizationEndpoint(), '?') ? '?' : '&',
            http_build_query($queryParameters, '&')
        );
        $this->session->set(
            '_oauth2_session',
            array_merge(
                $queryParameters,
                [
                    'user_id' => $this->userId,
                    'provider_id' => $this->provider->getProviderId(),
                ]
            )
        );

        return $authorizeUri;
    }

    /**
     * @param array $getData
     *
     * @return void
     */
    public function handleCallback(array $getData)
    {
        if (array_key_exists('error', $getData)) {
            // remove the session
            $this->session->take('_oauth2_session');

            throw new AuthorizeException(
                $getData['error'],
                array_key_exists('error_description', $getData) ? $getData['error_description'] : null
            );
        }

        if (!array_key_exists('code', $getData)) {
            throw new OAuthException(
                'missing "code" query parameter from server response'
            );
        }

        if (!array_key_exists('state', $getData)) {
            throw new OAuthException(
                'missing "state" query parameter from server response'
            );
        }

        $this->doHandleCallback($getData['code'], $getData['state']);
    }

    /**
     * Verify if an AccessToken in the list that matches this scope, bound to
     * providerId and userId.
     *
     * This method has NO side effects, i.e. it will not try to use, refresh or
     * delete AccessTokens. If a token is expired, but a refresh token is
     * available it is assumed that an AccessToken is available.
     *
     * NOTE: this does not mean that the token will also be accepted by the
     * resource server!
     *
     * @param string $scope
     *
     * @return bool
     */
    public function hasAccessToken($scope)
    {
        $accessToken = $this->getAccessToken($scope);
        if (false === $accessToken) {
            return false;
        }

        // is it expired? but do we have a refresh_token?
        if ($accessToken->isExpired($this->dateTime)) {
            // access_token is expired
            if (null !== $accessToken->getRefreshToken()) {
                // but we have a refresh_token
                return true;
            }

            // no refresh_token
            return false;
        }

        // not expired
        return true;
    }

    /**
     * @param string $responseCode  the code passed to the "code" query parameter on the callback URL
     * @param string $responseState the state passed to the "state" query parameter on the callback URL
     *
     * @return void
     */
    private function doHandleCallback($responseCode, $responseState)
    {
        if (null === $this->userId) {
            throw new OAuthException('userId not set');
        }
        if (null === $this->provider) {
            throw new OAuthException('provider not set');
        }

        // get and delete the OAuth session information
        $sessionData = $this->session->take('_oauth2_session');

        if (!hash_equals($sessionData['state'], $responseState)) {
            // the OAuth state from the initial request MUST be the same as the
            // state used by the response
            throw new OAuthException('invalid session (state)');
        }

        // session providerId MUST match current set Provider
        if ($sessionData['provider_id'] !== $this->provider->getProviderId()) {
            throw new OAuthException('invalid session (provider_id)');
        }

        // session userId MUST match current set userId
        if ($sessionData['user_id'] !== $this->userId) {
            throw new OAuthException('invalid session (user_id)');
        }

        // prepare access_token request
        $tokenRequestData = [
            'client_id' => $this->provider->getClientId(),
            'client_secret' => $this->provider->getSecret(),
            'grant_type' => 'authorization_code',
            'code' => $responseCode,
            'redirect_uri' => $sessionData['redirect_uri'],
        ];

        $response = $this->httpClient->send(
            Request::post(
                $this->provider->getTokenEndpoint(),
                $tokenRequestData,
                self::getAuthorizationHeader($this->provider)
            )
        );

        if (!$response->isOkay()) {
            throw new TokenException('unable to obtain access_token', $response);
        }

        $this->tokenStorage->storeAccessToken(
            $this->userId,
            AccessToken::fromCodeResponse(
                $this->provider,
                $this->dateTime,
                $response->json(),
                // in case server does not return a scope, we know it granted
                // our requested scope (according to OAuth specification)
                $sessionData['scope']
            )
        );
    }

    /**
     * @param AccessToken $accessToken
     *
     * @return false|AccessToken
     */
    private function refreshAccessToken(AccessToken $accessToken)
    {
        if (null === $this->userId) {
            throw new OAuthException('userId not set');
        }
        if (null === $this->provider) {
            throw new OAuthException('provider not set');
        }

        // prepare access_token request
        $tokenRequestData = [
            'client_id' => $this->provider->getClientId(),
            'client_secret' => $this->provider->getSecret(),
            'grant_type' => 'refresh_token',
            'refresh_token' => $accessToken->getRefreshToken(),
            'scope' => $accessToken->getScope(),
        ];

        $response = $this->httpClient->send(
            Request::post(
                $this->provider->getTokenEndpoint(),
                $tokenRequestData,
                self::getAuthorizationHeader($this->provider)
            )
        );

        if (!$response->isOkay()) {
            $responseData = $response->json();
            if (array_key_exists('error', $responseData) && 'invalid_grant' === $responseData['error']) {
                // delete the access_token, we assume the user revoked it, that
                // is why we get "invalid_grant"
                $this->tokenStorage->deleteAccessToken($this->userId, $accessToken);

                return false;
            }

            throw new TokenException('unable to refresh access_token', $response);
        }

        // delete old AccessToken as we'll write a new one anyway...
        $this->tokenStorage->deleteAccessToken($this->userId, $accessToken);

        $accessToken = AccessToken::fromRefreshResponse(
            $this->provider,
            $this->dateTime,
            $response->json(),
            // provide the old AccessToken to borrow some fields if the server
            // does not provide them on "refresh"
            $accessToken
        );

        // store the refreshed AccessToken
        $this->tokenStorage->storeAccessToken($this->userId, $accessToken);

        return $accessToken;
    }

    /**
     * Find an AccessToken in the list that matches this scope, bound to
     * providerId and userId.
     *
     * @param string $scope
     *
     * @return false|AccessToken
     */
    private function getAccessToken($scope)
    {
        if (null === $this->userId) {
            throw new OAuthException('userId not set');
        }
        if (null === $this->provider) {
            throw new OAuthException('provider not set');
        }

        $accessTokenList = $this->tokenStorage->getAccessTokenList($this->userId);
        foreach ($accessTokenList as $accessToken) {
            if ($this->provider->getProviderId() !== $accessToken->getProviderId()) {
                continue;
            }
            if ($scope !== $accessToken->getScope()) {
                continue;
            }

            return $accessToken;
        }

        return false;
    }

    /**
     * @param Provider $provider
     *
     * @return array
     */
    private static function getAuthorizationHeader(Provider $provider)
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => sprintf(
                'Basic %s',
                Base64::encode(
                    sprintf('%s:%s', $provider->getClientId(), $provider->getSecret())
                )
            ),
        ];
    }
}
