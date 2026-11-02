<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Oidc;

use Jose\Component\Core\Util\Base64UrlSafe;
use Psr\Clock\ClockInterface;
use Semilla\SolidClient\Dpop\DpopKey;
use Semilla\SolidClient\Dpop\DpopProofFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The relying-party half of Solid-OIDC: authorization code with PKCE, tokens
 * bound to a DPoP key, refresh, and — for scripts and tests — Community Solid
 * Server's client credentials.
 *
 * Upstream extended jumbojett/openid-connect-php, which redirects with
 * `header()` + `exit`, keeps its state in `$_SESSION` and registers a new
 * client dynamically on every login. This is stateless instead: {@see begin()}
 * returns the request to keep, {@see complete()} takes it back with the
 * callback's query. Where it is kept is the caller's business.
 *
 * The client is identified by a Client ID Document (Solid-OIDC §5): its
 * `client_id` is a URL the provider dereferences, so there is no registration
 * and no secret.
 */
final readonly class SolidOidcClient
{
    /**
     * `offline_access` gets a refresh token; `prompt=consent` is what
     * Community Solid Server wants before it grants one.
     */
    public const array DEFAULT_SCOPES = ['openid', 'webid', 'offline_access'];

    public function __construct(
        private HttpClientInterface $http,
        private DpopProofFactory $proofs,
        private IdTokenVerifier $idTokens,
        private ClockInterface $clock,
    ) {
    }

    public function discover(string $issuer): ProviderMetadata
    {
        $url = rtrim($issuer, '/') . '/.well-known/openid-configuration';
        try {
            $document = $this->http->request('GET', $url, ['headers' => ['Accept' => 'application/json']])->toArray();
        } catch (\Throwable $e) {
            throw new AuthenticationFailed(sprintf('No OpenID configuration at %s.', $url), 0, $e);
        }

        return ProviderMetadata::fromDocument($issuer, $document);
    }

    /** @param list<string> $scopes */
    public function begin(
        string $issuer,
        string $clientId,
        string $redirectUri,
        array $scopes = self::DEFAULT_SCOPES,
        ?string $prompt = 'consent',
    ): AuthorizationRequest {
        $provider = $this->discover($issuer);
        $state = self::random();
        $nonce = self::random();
        $verifier = self::random(48);

        $query = [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::challenge($verifier),
            'code_challenge_method' => 'S256',
        ];
        if ($prompt !== null) {
            $query['prompt'] = $prompt;
        }
        $separator = str_contains($provider->authorizationEndpoint, '?') ? '&' : '?';

        return new AuthorizationRequest(
            $provider->issuer,
            $clientId,
            $redirectUri,
            $state,
            $nonce,
            $verifier,
            DpopKey::generate(),
            $provider->authorizationEndpoint . $separator . http_build_query($query, '', '&', \PHP_QUERY_RFC3986),
        );
    }

    /**
     * Finishes a sign-in from the callback's query string. The WebID comes
     * from the verified ID token; that the WebID's profile actually lists
     * this issuer is the caller's check ({@see \Semilla\SolidClient\Profile\WebIdProfile::trustsIssuer()}),
     * because only the caller knows how it reads profiles.
     *
     * @param array<string, mixed> $callback the callback's query parameters
     */
    public function complete(AuthorizationRequest $request, array $callback): TokenSet
    {
        if (isset($callback['error'])) {
            throw new AuthenticationFailed(sprintf(
                'The provider refused the sign-in: %s %s',
                self::text($callback['error']),
                self::text($callback['error_description'] ?? ''),
            ));
        }
        if (!hash_equals($request->state, self::text($callback['state'] ?? ''))) {
            throw new AuthenticationFailed('The callback answers another sign-in (state mismatch).');
        }
        // RFC 9207: when the provider names itself, it must be the one asked (mix-up defence).
        if (isset($callback['iss']) && rtrim(self::text($callback['iss']), '/') !== rtrim($request->issuer, '/')) {
            throw new AuthenticationFailed('The callback comes from another issuer.');
        }
        $code = self::text($callback['code'] ?? '');
        if ($code === '') {
            throw new AuthenticationFailed('The callback carries no authorization code.');
        }

        $provider = $this->discover($request->issuer);
        $tokens = $this->tokenRequest($provider, $request->dpopKey, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $request->redirectUri,
            'client_id' => $request->clientId,
            'code_verifier' => $request->codeVerifier,
        ]);

        $idToken = $tokens['id_token'] ?? null;
        if (!\is_string($idToken)) {
            throw new AuthenticationFailed('The provider issued no ID token.');
        }
        $claims = $this->idTokens->verify($idToken, $provider, $request->clientId, $request->nonce);

        return $this->tokenSet(
            $provider->issuer,
            $request->clientId,
            self::webIdOf($claims),
            $request->dpopKey,
            $tokens,
        );
    }

    /**
     * A fresh access token for the same key. A provider that rotates refresh
     * tokens sends a new one; otherwise the old one stays.
     */
    public function refresh(TokenSet $current): TokenSet
    {
        if ($current->refreshToken === null) {
            throw new AuthenticationFailed('This sign-in has no refresh token; sign in again.');
        }

        $provider = $this->discover($current->issuer);
        $tokens = $this->tokenRequest($provider, $current->dpopKey, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $current->refreshToken,
            'client_id' => $current->clientId,
        ]);

        return $this->tokenSet($current->issuer, $current->clientId, $current->webId, $current->dpopKey, [
            'refresh_token' => $current->refreshToken,
            'id_token' => $current->idToken,
            ...$tokens,
        ]);
    }

    /**
     * Community Solid Server's client credentials (a token for the WebID the
     * credentials were minted for, no browser involved). For scripts and
     * tests; a person signs in with {@see begin()}.
     */
    public function clientCredentials(string $issuer, string $clientId, string $clientSecret): TokenSet
    {
        $provider = $this->discover($issuer);
        $key = DpopKey::generate();
        $tokens = $this->tokenRequest($provider, $key, [
            'grant_type' => 'client_credentials',
            'scope' => 'webid',
        ], 'Basic ' . base64_encode(rawurlencode($clientId) . ':' . rawurlencode($clientSecret)));

        // The access token came straight from the issuer over this request, so
        // its claims are read, not verified: there is nobody in between to lie.
        $accessToken = \is_string($tokens['access_token'] ?? null) ? $tokens['access_token'] : '';
        $parts = explode('.', $accessToken);
        $claims = json_decode(Base64UrlSafe::decodeNoPadding($parts[1] ?? ''), true);

        return $this->tokenSet(
            $provider->issuer,
            $clientId,
            self::webIdOf(\is_array($claims) ? $claims : []),
            $key,
            $tokens,
        );
    }

    /**
     * POST to the token endpoint with a DPoP proof; retried once when the
     * provider asks for a nonce (RFC 9449 §8).
     *
     * @param array<string, string> $form
     *
     * @return array<string, mixed>
     */
    private function tokenRequest(
        ProviderMetadata $provider,
        DpopKey $key,
        array $form,
        ?string $authorization = null,
    ): array {
        $nonce = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $headers = [
                'Accept' => 'application/json',
                'DPoP' => $this->proofs->create($key, 'POST', $provider->tokenEndpoint, null, $nonce),
            ];
            if ($authorization !== null) {
                $headers['Authorization'] = $authorization;
            }
            $response = $this->http->request('POST', $provider->tokenEndpoint, [
                'headers' => $headers,
                'body' => $form,
            ]);
            $body = self::json($response);
            $nonce = $response->getHeaders(false)['dpop-nonce'][0] ?? null;
            $wantsNonce = ($body['error'] ?? null) === 'use_dpop_nonce';
            if ($response->getStatusCode() === 400 && $wantsNonce && $nonce !== null) {
                continue;
            }
            if ($response->getStatusCode() !== 200) {
                throw new AuthenticationFailed(sprintf(
                    'The token endpoint refused the %s grant (HTTP %d): %s %s',
                    $form['grant_type'],
                    $response->getStatusCode(),
                    self::text($body['error'] ?? ''),
                    self::text($body['error_description'] ?? ''),
                ));
            }

            return $body;
        }

        throw new AuthenticationFailed('The token endpoint kept asking for a new DPoP nonce.');
    }

    /** @param array<string, mixed> $tokens */
    private function tokenSet(string $issuer, string $clientId, string $webId, DpopKey $key, array $tokens): TokenSet
    {
        $accessToken = $tokens['access_token'] ?? null;
        if (!\is_string($accessToken) || $accessToken === '') {
            throw new AuthenticationFailed('The provider issued no access token.');
        }
        if (strcasecmp(self::text($tokens['token_type'] ?? ''), 'DPoP') !== 0) {
            throw new AuthenticationFailed('The provider issued a token not bound to the DPoP key.');
        }
        $lifetime = \is_int($tokens['expires_in'] ?? null) ? $tokens['expires_in'] : 300;

        return new TokenSet(
            $issuer,
            $clientId,
            $webId,
            $accessToken,
            $this->clock->now()->modify(sprintf('+%d seconds', $lifetime)),
            $key,
            \is_string($tokens['refresh_token'] ?? null) ? $tokens['refresh_token'] : null,
            \is_string($tokens['id_token'] ?? null) ? $tokens['id_token'] : null,
        );
    }

    /**
     * Solid-OIDC §6: the WebID is the `webid` claim; a provider that has none
     * may put it in `sub`, which then has to be a URL.
     *
     * @param array<mixed> $claims
     */
    private static function webIdOf(array $claims): string
    {
        foreach (['webid', 'sub'] as $claim) {
            $value = $claims[$claim] ?? null;
            if (\is_string($value) && preg_match('#^https?://#', $value) === 1) {
                return $value;
            }
        }

        throw new AuthenticationFailed('The token names no WebID.');
    }

    /** @return array<string, mixed> */
    private static function json(ResponseInterface $response): array
    {
        $decoded = json_decode($response->getContent(false), true);

        /** @var array<string, mixed> */
        return \is_array($decoded) ? $decoded : [];
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private static function random(int $bytes = 32): string
    {
        return Base64UrlSafe::encodeUnpadded(random_bytes($bytes));
    }

    private static function challenge(string $verifier): string
    {
        return Base64UrlSafe::encodeUnpadded(hash('sha256', $verifier, true));
    }
}
