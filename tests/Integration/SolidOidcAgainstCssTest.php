<?php

declare(strict_types=1);

namespace Semilla\SolidClient\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Semilla\SolidClient\Dpop\DpopProofFactory;
use Semilla\SolidClient\Oidc\IdTokenVerifier;
use Semilla\SolidClient\Oidc\SolidOidcClient;
use Semilla\SolidClient\Pod\DpopCredentials;
use Semilla\SolidClient\Pod\PodClient;
use Semilla\SolidClient\Profile\ProfileReader;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The whole authorization-code sign-in against the stack's Community Solid
 * Server, as the development account: PKCE, a DPoP-bound token, the ID token
 * verified against the server's keys, a refresh, and a pod write with the
 * refreshed token.
 *
 * The person's part (log in, pick the WebID, consent) goes through the CSS
 * account JSON API — the same calls its own login page makes. The client is
 * identified by a Client ID Document this test serves itself on
 * http://localhost:<port> (CSS accepts plain http only on localhost), from a
 * `php -S` it starts; the solid container shares this one's network
 * namespace, so it can fetch it there.
 */
#[Group('integration')]
final class SolidOidcAgainstCssTest extends TestCase
{
    /** @var resource|null */
    private $server = null;
    private string $documentRoot = '';
    private HttpClientInterface $http;
    /** @var array<string, string> */
    private array $cookies = [];

    protected function setUp(): void
    {
        $env = self::environment();
        foreach (['SOLID_BASE_URL', 'SOLID_DEV_EMAIL', 'SOLID_DEV_PASSWORD', 'SOLID_DEV_POD'] as $name) {
            if (($env[$name] ?? '') === '') {
                self::markTestSkipped("{$name} is not set in .env.");
            }
        }
        $this->http = HttpClient::create(['timeout' => 10, 'max_redirects' => 0]);
        try {
            $this->http->request('GET', $env['SOLID_BASE_URL'] . '.well-known/openid-configuration')->getStatusCode();
        } catch (\Throwable) {
            self::markTestSkipped("No pod server at {$env['SOLID_BASE_URL']}; is the stack up (make up)?");
        }
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if ($this->documentRoot !== '') {
            @unlink($this->documentRoot . '/client-id.jsonld');
            @rmdir($this->documentRoot);
        }
    }

    public function testAPersonSignsInAndTheTokenIsRefreshedAndWritesToThePod(): void
    {
        $env = self::environment();
        $issuer = $env['SOLID_BASE_URL'];
        $webId = rtrim($issuer, '/') . "/{$env['SOLID_DEV_POD']}/profile/card#me";
        [$clientId, $redirectUri] = $this->serveClientIdDocument();

        $clock = new NativeClock();
        $proofs = new DpopProofFactory($clock);
        $oidc = new SolidOidcClient($this->http, $proofs, new IdTokenVerifier($this->http, $clock), $clock);

        $request = $oidc->begin($issuer, $clientId, $redirectUri);
        $callback = $this->signInAtTheProvider($request->url, $redirectUri, $env, $webId);
        $tokens = $oidc->complete($request, $callback);

        self::assertSame($webId, $tokens->webId);
        self::assertNotNull($tokens->refreshToken, 'offline_access with prompt=consent should yield a refresh token.');
        self::assertTrue(
            (new ProfileReader(new PodClient($this->http)))->read($tokens->webId)->trustsIssuer($tokens->issuer),
        );

        $refreshed = $oidc->refresh($tokens);
        self::assertNotSame($tokens->accessToken, $refreshed->accessToken);
        self::assertSame($tokens->dpopKey->thumbprint(), $refreshed->dpopKey->thumbprint());

        $pod = new PodClient($this->http, new DpopCredentials($refreshed, $proofs));
        $name = 'people-tracker-oidc-' . bin2hex(random_bytes(4)) . '.ttl';
        $url = rtrim($issuer, '/') . "/{$env['SOLID_DEV_POD']}/{$name}";
        try {
            self::assertTrue($pod->put($url, '<#a> <#b> <#c> .')->created());
            self::assertSame(200, $pod->get($url)->status);
        } finally {
            $pod->delete($url);
        }
    }

    /** @return array{string, string} the client id and the redirect URI it lists */
    private function serveClientIdDocument(): array
    {
        $port = random_int(18100, 18999);
        $this->documentRoot = sys_get_temp_dir() . '/solid-client-id-' . bin2hex(random_bytes(4));
        mkdir($this->documentRoot);
        $clientId = "http://localhost:{$port}/client-id.jsonld";
        $redirectUri = "http://localhost:{$port}/callback";
        file_put_contents($this->documentRoot . '/client-id.jsonld', json_encode([
            '@context' => ['https://www.w3.org/ns/solid/oidc-context.jsonld'],
            'client_id' => $clientId,
            'client_name' => 'semilla/solid-client integration test',
            'redirect_uris' => [$redirectUri],
            'scope' => 'openid webid offline_access',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

        // [::]: the pod server resolves `localhost` to ::1 first.
        $this->server = proc_open(
            [\PHP_BINARY, '-S', "[::]:{$port}", '-t', $this->documentRoot],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        for ($i = 0; $i < 50; $i++) {
            try {
                if ($this->http->request('GET', $clientId)->getStatusCode() === 200) {
                    return [$clientId, $redirectUri];
                }
            } catch (\Throwable) {
            }
            usleep(100_000);
        }

        self::fail("The Client ID Document server on port {$port} did not start.");
    }

    /**
     * What the person does at the provider, through its account JSON API.
     *
     * @param array<string, string> $env
     *
     * @return array<string, string> the query of the redirect back
     */
    private function signInAtTheProvider(
        string $authorizationUrl,
        string $redirectUri,
        array $env,
        string $webId,
    ): array {
        $base = rtrim($env['SOLID_BASE_URL'], '/');
        $this->follow($authorizationUrl, $redirectUri);

        $controls = $this->json('GET', "{$base}/.account/");
        $this->json('POST', self::control($controls, 'password', 'login'), [
            'email' => $env['SOLID_DEV_EMAIL'],
            'password' => $env['SOLID_DEV_PASSWORD'],
            'remember' => false,
        ]);
        $controls = $this->json('GET', "{$base}/.account/");

        $picked = $this->json('POST', self::control($controls, 'oidc', 'webId'), ['webId' => $webId]);
        $this->follow(\is_string($picked['location'] ?? null) ? $picked['location'] : '', $redirectUri);
        // "Remember this client": without it CSS drops offline_access and issues no refresh token.
        $consented = $this->json('POST', self::control($controls, 'oidc', 'consent'), ['remember' => true]);
        $back = $this->follow(\is_string($consented['location'] ?? null) ? $consented['location'] : '', $redirectUri);

        self::assertStringStartsWith($redirectUri, $back, 'The provider did not send the browser back.');
        parse_str((string) parse_url($back, \PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        return $query;
    }

    /** Follows redirects like a browser until one points at `$stopAt` (returned) or none is left. */
    private function follow(string $url, string $stopAt): string
    {
        for ($i = 0; $i < 10 && !str_starts_with($url, $stopAt); $i++) {
            $response = $this->send('GET', $url);
            $location = $response->getHeaders(false)['location'][0] ?? null;
            if ($location === null) {
                return $url;
            }
            $origin = (string) preg_replace('#^(https?://[^/]+).*$#', '$1', $url);
            $url = str_starts_with($location, 'http') ? $location : $origin . $location;
        }

        return $url;
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function json(string $method, string $url, ?array $body = null): array
    {
        $response = $this->send($method, $url, $body);
        $decoded = json_decode($response->getContent(false), true);
        self::assertIsArray($decoded, "{$method} {$url} answered {$response->getStatusCode()} without JSON.");
        self::assertLessThan(400, $response->getStatusCode(), "{$method} {$url}: " . $response->getContent(false));

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $url, ?array $body = null): ResponseInterface
    {
        $headers = ['Accept' => 'application/json'];
        if ($this->cookies !== []) {
            $headers['Cookie'] = implode('; ', array_map(
                static fn (string $name, string $value): string => "{$name}={$value}",
                array_keys($this->cookies),
                $this->cookies,
            ));
        }
        $options = ['headers' => $headers];
        if ($body !== null) {
            $options['json'] = $body;
        }
        $response = $this->http->request($method, $url, $options);
        foreach ($response->getHeaders(false)['set-cookie'] ?? [] as $cookie) {
            [$pair] = explode(';', $cookie, 2);
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($value === '' || stripos($cookie, 'max-age=0') !== false) {
                unset($this->cookies[trim($name)]);
            } else {
                $this->cookies[trim($name)] = $value;
            }
        }

        return $response;
    }

    /** @param array<string, mixed> $controls */
    private static function control(array $controls, string $group, string $name): string
    {
        $value = $controls['controls'][$group][$name] ?? null;
        self::assertIsString($value, "The account API offers no {$group}.{$name} control.");

        return $value;
    }

    /** @return array<string, string> the checkout's .env and .env.local, read directly (CommunitySolidServerTest) */
    private static function environment(): array
    {
        $root = getenv('SOLID_CLIENT_ENV_DIR') ?: (string) getcwd();
        $values = [];
        foreach (['.env', '.env.local'] as $file) {
            if (is_file("{$root}/{$file}")) {
                $values = [...$values, ...(new Dotenv())->parse((string) file_get_contents("{$root}/{$file}"))];
            }
        }

        return $values;
    }
}
