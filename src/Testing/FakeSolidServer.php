<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Testing;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Core\Util\Base64UrlSafe;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Psr\Clock\ClockInterface;
use Semilla\SolidClient\Dpop\DpopKey;
use Semilla\SolidClient\Oidc\TokenSet;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Everything outside the application a Solid sign-in talks to, as the test
 * container's HTTP client sees it (framework.http_client.mock_response_factory,
 * `when@test`): WebID profiles on `pod.invalid` and an identity provider on
 * `idp.invalid` with discovery, keys and a token endpoint that really checks
 * PKCE and DPoP and really signs its ID tokens.
 *
 * The browser's visit to the provider (log in, consent) is {@see approve()}:
 * a scenario calls it with the URL the application redirected to and gets the
 * callback's query back. Any request this fake does not know fails loudly.
 *
 * Beside it, on the same HTTP client: the pods' documents ({@see FakePod},
 * `$pod`, a service of its own). The owner's profile names {@see STORAGE} as
 * its `pim:storage`; a scenario puts documents there with {@see putDocument()},
 * and every container above them lists its members with `dc:modified`, as
 * Community Solid Server does.
 *
 * Community Solid Server's `client_credentials` grant ({@see OWNER_CLIENT_ID},
 * {@see STRANGER_CLIENT_ID}: what an application's .env.test configures), and
 * every access token it mints is told to {@see FakePod}, which then refuses a
 * WebID that does not own the pod with 403.
 *
 * Anything else the application talks to is not here: a request this fake does
 * not know fails loudly. An application with more servers to fake puts one
 * dispatcher in front (its own `mock_response_factory`) that hands this fake
 * every URL it does not own.
 */
final class FakeSolidServer
{
    public const string ISSUER = 'https://idp.invalid/';
    public const string OWNER = 'https://pod.invalid/owner/profile/card#me';
    public const string STRANGER = 'https://pod.invalid/stranger/profile/card#me';
    public const string STORAGE = FakePod::STORAGE;

    /** Client credentials minted for the owner (the application's .env.test). */
    public const string OWNER_CLIENT_ID = 'owner-client';
    public const string OWNER_CLIENT_SECRET = 'owner-secret';
    public const string STRANGER_CLIENT_ID = 'stranger-client';
    public const string STRANGER_CLIENT_SECRET = 'stranger-secret';

    private JWK $signingKey;

    /** @var array<string, list<string>> WebID => the issuers its profile lists */
    private array $profiles = [];

    /**
     * @var array<string, array{nonce: string, challenge: string, clientId: string, redirectUri: string, webId: string}>
     */
    private array $codes = [];

    /** @var array<string, string> refresh token => WebID */
    private array $refreshTokens = [];

    private bool $refusesRefreshes = false;

    /** @var list<string> grant types the token endpoint has served, in order */
    public private(set) array $grants = [];

    public function __construct(
        private readonly ClockInterface $clock,
        public readonly FakePod $pod,
    ) {
        $this->signingKey = JWKFactory::createECKey('P-256', ['alg' => 'ES256', 'use' => 'sig', 'kid' => 'fake']);
        $this->profiles[self::OWNER] = [self::ISSUER];
        $this->profiles[self::STRANGER] = [self::ISSUER];
    }

    /** @param list<string> $issuers */
    public function publishProfile(string $webId, array $issuers): void
    {
        $this->profiles[$webId] = $issuers;
    }

    /** Writes (or rewrites, with a new modification time) a document of the owner's pod, behind the application's back. */
    public function putDocument(string $url, string $turtle): void
    {
        if (!str_starts_with($url, self::STORAGE) || str_ends_with($url, '/')) {
            throw new \LogicException("{$url} is not a document of the owner's pod.");
        }
        $this->pod->place($url, $turtle);
    }

    public function deleteDocument(string $url): void
    {
        $this->pod->remove($url);
    }

    /** From now on the provider refuses every refresh, as one does after revoking a session. */
    public function refuseRefreshes(): void
    {
        $this->refusesRefreshes = true;
    }

    /**
     * The person signs in at the provider and consents. `$asWebId` is the WebID
     * the provider vouches for — another one than the profile's owner is how a
     * scenario plays a provider asserting a WebID that never chose it.
     *
     * @return array<string, string> the query of the redirect back to the application
     */
    public function approve(string $authorizationUrl, string $asWebId = self::OWNER): array
    {
        parse_str((string) parse_url($authorizationUrl, \PHP_URL_QUERY), $query);
        $param = static fn (string $key): string => \is_string($query[$key] ?? null)
            ? $query[$key]
            : throw new \LogicException("The authorization request has no {$key}.");
        if ($param('code_challenge_method') !== 'S256' || $param('response_type') !== 'code') {
            throw new \LogicException('The authorization request is not a PKCE code request.');
        }

        $code = bin2hex(random_bytes(8));
        $this->codes[$code] = [
            'nonce' => $param('nonce'),
            'challenge' => $param('code_challenge'),
            'clientId' => $param('client_id'),
            'redirectUri' => $param('redirect_uri'),
            'webId' => $asWebId,
        ];

        return ['code' => $code, 'state' => $param('state'), 'iss' => self::ISSUER];
    }

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        foreach (array_keys($this->profiles) as $webId) {
            if ($method === 'GET' && $url === strtok($webId, '#')) {
                return $this->profile($webId);
            }
        }

        return match ("{$method} {$url}") {
            'GET ' . self::ISSUER . '.well-known/openid-configuration' => self::json([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER . 'auth',
                'token_endpoint' => self::ISSUER . 'token',
                'jwks_uri' => self::ISSUER . 'jwks',
                'end_session_endpoint' => self::ISSUER . 'session/end',
            ]),
            'GET ' . self::ISSUER . 'jwks' => self::json(['keys' => [$this->signingKey->toPublic()->all()]]),
            'POST ' . self::ISSUER . 'token' => $this->token($url, $options),
            default => match (true) {
                str_starts_with($url, FakePod::ORIGIN) => ($this->pod)($method, $url, $options),
                default => throw new \LogicException("FakeSolidServer does not answer {$method} {$url}."),
            },
        };
    }

    private function profile(string $webId): MockResponse
    {
        $issuers = implode(', ', array_map(
            static fn (string $issuer): string => "<{$issuer}>",
            $this->profiles[$webId],
        ));

        $storage = $webId === self::OWNER ? "\n    <http://www.w3.org/ns/pim/space#storage> <" . self::STORAGE . '>;' : '';

        // Plus whatever the application patched into it (the Type Index links).
        return new MockResponse(
            "<{$webId}> a <http://xmlns.com/foaf/0.1/Person>;{$storage}\n"
            . "    <http://www.w3.org/ns/solid/terms#oidcIssuer> {$issuers}.\n"
            . $this->pod->insertedInto((string) strtok($webId, '#')),
            ['response_headers' => ['Content-Type' => 'text/turtle']],
        );
    }

    /** @param array<string, mixed> $options */
    private function token(string $url, array $options): MockResponse
    {
        $this->assertDpopProof($url, $options);
        parse_str(\is_string($options['body'] ?? null) ? $options['body'] : '', $form);
        $grant = \is_string($form['grant_type'] ?? null) ? $form['grant_type'] : '';
        $this->grants[] = $grant;

        if ($grant === 'authorization_code') {
            $issued = $this->codes[\is_string($form['code'] ?? null) ? $form['code'] : ''] ?? null;
            unset($this->codes[\is_string($form['code'] ?? null) ? $form['code'] : '']);
            $verifier = \is_string($form['code_verifier'] ?? null) ? $form['code_verifier'] : '';
            if (
                $issued === null
                || Base64UrlSafe::encodeUnpadded(hash('sha256', $verifier, true)) !== $issued['challenge']
                || ($form['redirect_uri'] ?? null) !== $issued['redirectUri']
                || ($form['client_id'] ?? null) !== $issued['clientId']
            ) {
                return self::json(['error' => 'invalid_grant'], 400);
            }

            return $this->tokens($issued['webId'], $issued['clientId'], $issued['nonce']);
        }

        if ($grant === 'refresh_token') {
            $refreshToken = \is_string($form['refresh_token'] ?? null) ? $form['refresh_token'] : '';
            $webId = $this->refreshTokens[$refreshToken] ?? null;
            if ($webId === null || $this->refusesRefreshes) {
                return self::json(['error' => 'invalid_grant'], 400);
            }

            return $this->tokens($webId, \is_string($form['client_id'] ?? null) ? $form['client_id'] : '', null);
        }

        if ($grant === 'client_credentials') {
            return $this->clientCredentials($options);
        }

        return self::json(['error' => 'unsupported_grant_type'], 400);
    }

    /**
     * CSS's client credentials: HTTP Basic with the pair, an access token
     * whose claims name the WebID it was minted for, no refresh token.
     *
     * @param array<string, mixed> $options
     */
    private function clientCredentials(array $options): MockResponse
    {
        /** @var array<string, list<string>> $headers */
        $headers = $options['normalized_headers'] ?? [];
        $basic = base64_decode(substr($headers['authorization'][0] ?? '', \strlen('Authorization: Basic ')), true);
        [$id, $secret] = array_map('rawurldecode', explode(':', (string) $basic, 2) + ['', '']);
        $webId = match ([$id, $secret]) {
            [self::OWNER_CLIENT_ID, self::OWNER_CLIENT_SECRET] => self::OWNER,
            [self::STRANGER_CLIENT_ID, self::STRANGER_CLIENT_SECRET] => self::STRANGER,
            default => null,
        };
        if ($webId === null) {
            return self::json(['error' => 'invalid_client'], 401);
        }
        $claims = Base64UrlSafe::encodeUnpadded(json_encode(['webid' => $webId, 'client_id' => $id], \JSON_THROW_ON_ERROR));
        $token = 'eyJhbGciOiJub25lIn0.' . $claims . '.' . bin2hex(random_bytes(8));
        $this->pod->grant($token, $webId);

        return self::json(['access_token' => $token, 'token_type' => 'DPoP', 'expires_in' => 600, 'scope' => 'webid']);
    }

    private function tokens(string $webId, string $clientId, ?string $nonce): MockResponse
    {
        $now = $this->clock->now()->getTimestamp();
        $refresh = bin2hex(random_bytes(8));
        $this->refreshTokens[$refresh] = $webId;
        $access = 'fake-access-' . bin2hex(random_bytes(8));
        $this->pod->grant($access, $webId);
        $body = [
            'access_token' => $access,
            'token_type' => 'DPoP',
            'expires_in' => 300,
            'refresh_token' => $refresh,
            'scope' => 'openid webid offline_access',
        ];
        if ($nonce !== null) {
            $body['id_token'] = $this->sign([
                'iss' => self::ISSUER,
                'aud' => $clientId,
                'sub' => $webId,
                'webid' => $webId,
                'nonce' => $nonce,
                'iat' => $now,
                'exp' => $now + 300,
            ]);
        }

        return self::json($body);
    }

    /** @param array<string, mixed> $options */
    private function assertDpopProof(string $url, array $options): void
    {
        /** @var array<string, list<string>> $headers */
        $headers = $options['normalized_headers'] ?? [];
        $line = $headers['dpop'][0] ?? throw new \LogicException('A token request without a DPoP proof.');
        $jws = (new CompactSerializer())->unserialize(substr($line, \strlen('DPoP: ')));
        $header = $jws->getSignature(0)->getProtectedHeader();
        $jwk = new JWK(\is_array($header['jwk'] ?? null) ? $header['jwk'] : []);
        $claims = json_decode((string) $jws->getPayload(), true);
        if (
            ($header['typ'] ?? null) !== 'dpop+jwt'
            || !(new JWSVerifier(new AlgorithmManager([new ES256()])))->verifyWithKey($jws, $jwk, 0)
            || !\is_array($claims) || ($claims['htm'] ?? null) !== 'POST' || ($claims['htu'] ?? null) !== $url
        ) {
            throw new \LogicException('The DPoP proof does not verify, or is not for this request.');
        }
    }

    /** @param array<string, mixed> $claims */
    private function sign(array $claims): string
    {
        $jws = (new JWSBuilder(new AlgorithmManager([new ES256()])))
            ->create()
            ->withPayload(json_encode($claims, \JSON_THROW_ON_ERROR))
            ->addSignature($this->signingKey, ['alg' => 'ES256', 'kid' => 'fake', 'typ' => 'JWT'])
            ->build();

        return (new CompactSerializer())->serialize($jws, 0);
    }

    /**
     * Tokens as a finished sign-in leaves them, without the round trip: what
     * {@see \App\Tests\Dsl\World::enterAs()} puts in the session of an actor
     * who has a pod. Expires five minutes from the test clock's now.
     */
    public function tokensFor(string $webId): TokenSet
    {
        $access = 'fake-access-' . bin2hex(random_bytes(8));
        $this->pod->grant($access, $webId);

        return new TokenSet(
            self::ISSUER,
            'http://localhost/client-id.jsonld',
            $webId,
            $access,
            $this->clock->now()->modify('+300 seconds'),
            DpopKey::generate(),
        );
    }

    /** @param array<string, mixed> $body */
    private static function json(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['Content-Type' => 'application/json'],
        ]);
    }
}
