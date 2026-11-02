<?php

declare(strict_types=1);

namespace Semilla\SolidClient\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Semilla\SolidClient\Dpop\DpopProofFactory;
use Semilla\SolidClient\Oidc\IdTokenVerifier;
use Semilla\SolidClient\Oidc\SolidOidcClient;
use Semilla\SolidClient\Oidc\TokenSet;
use Semilla\SolidClient\Pod\AccessDenied;
use Semilla\SolidClient\Pod\DpopCredentials;
use Semilla\SolidClient\Pod\N3Patch;
use Semilla\SolidClient\Pod\NotFound;
use Semilla\SolidClient\Pod\PodClient;
use Semilla\SolidClient\Pod\Precondition;
use Semilla\SolidClient\Pod\PreconditionFailed;
use Semilla\SolidClient\Profile\ProfileReader;
use Semilla\SolidClient\TypeIndex\TypeIndexReader;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The client against the real pod server of the stack (docker-compose.yml,
 * service `solid`) as the development account `make seed` creates — the one
 * place the DPoP proofs, ETags and N3 Patch meet a server that was not
 * written to agree with them.
 *
 * Everything happens under a container of its own (`people-tracker-tests/
 * run-<TEST_RUN>-<random>/`) that is deleted afterwards, because the pod is
 * shared with every other checkout. Skipped, saying why, where there are no
 * client credentials: `make seed` writes them into this checkout's .env.local.
 */
#[Group('integration')]
final class CommunitySolidServerTest extends TestCase
{
    private const string EX = 'https://people-tracker.example/ns#';

    private PodClient $anonymous;
    private PodClient $pod;
    private TokenSet $tokens;
    private string $storage;
    private string $container;

    protected function setUp(): void
    {
        $env = self::environment();
        foreach (['SOLID_BASE_URL', 'SOLID_DEV_CLIENT_ID', 'SOLID_DEV_CLIENT_SECRET', 'SOLID_DEV_WEBID'] as $name) {
            if (($env[$name] ?? '') === '') {
                self::markTestSkipped("{$name} is not set; run `make seed` in this checkout.");
            }
        }

        $http = HttpClient::create(['timeout' => 10]);
        $clock = new NativeClock();
        $proofs = new DpopProofFactory($clock);
        $oidc = new SolidOidcClient($http, $proofs, new IdTokenVerifier($http, $clock), $clock);

        $this->tokens = $oidc->clientCredentials(
            $env['SOLID_BASE_URL'],
            $env['SOLID_DEV_CLIENT_ID'],
            $env['SOLID_DEV_CLIENT_SECRET'],
        );
        $this->anonymous = new PodClient($http);
        $this->pod = $this->anonymous->as(new DpopCredentials($this->tokens, $proofs));

        $reader = new ProfileReader($this->anonymous);
        $this->storage = $reader->storageOf($reader->read($this->tokens->webId));
        $run = (getenv('TEST_RUN') ?: 'local') . '-' . bin2hex(random_bytes(4));
        $this->container = "{$this->storage}people-tracker-tests/run-{$run}/";
        $this->pod->createContainer($this->container);
    }

    protected function tearDown(): void
    {
        if (!isset($this->container)) {
            return;
        }
        $this->deleteTree($this->container);
        try {
            // Only if no other run is still using it; a 409 just leaves it.
            $this->pod->delete("{$this->storage}people-tracker-tests/");
        } catch (\Throwable) {
        }
    }

    public function testTheClientCredentialsTokenIsForTheSeededWebId(): void
    {
        self::assertSame(self::environment()['SOLID_DEV_WEBID'], $this->tokens->webId);
        self::assertSame(
            rtrim(self::environment()['SOLID_BASE_URL'], '/') . '/' . self::environment()['SOLID_DEV_POD'] . '/',
            $this->storage,
        );
    }

    public function testTheProfileNamesTheIssuer(): void
    {
        $profile = (new ProfileReader($this->anonymous))->read($this->tokens->webId);

        self::assertTrue($profile->trustsIssuer(self::environment()['SOLID_BASE_URL']));
        self::assertFalse($profile->trustsIssuer('https://idp.invalid/'));
    }

    public function testADocumentIsWrittenReadAndGuardedByItsETag(): void
    {
        $url = $this->container . 'tvrzeni.ttl';
        $turtle = sprintf('<#a> <%sstav> "první" .', self::EX);

        $created = $this->pod->put($url, $turtle, precondition: Precondition::createOnly());
        self::assertTrue($created->created());

        $read = $this->pod->get($url);
        self::assertSame(200, $read->status);
        self::assertNotNull($read->etag);
        self::assertSame([$url . '#a'], $read->rdf()->subjectsWith(self::EX . 'stav'));

        $unchanged = $this->pod->get($url, precondition: Precondition::ifNoneMatch($read->etag));
        self::assertTrue($unchanged->isNotModified());

        try {
            $this->pod->put($url, $turtle, precondition: Precondition::createOnly());
            self::fail('Creating over an existing document went through.');
        } catch (PreconditionFailed $e) {
            self::assertSame(412, $e->status);
        }

        $second = sprintf('<#a> <%sstav> "druhý" .', self::EX);
        $this->pod->put($url, $second, precondition: Precondition::ifMatch($read->etag));
        $second = $this->pod->head($url);
        self::assertNotSame($read->etag, $second->etag);

        // Someone else's write landed between our read and our write: refused.
        try {
            $this->pod->put($url, $turtle, precondition: Precondition::ifMatch($read->etag));
            self::fail('A write against a stale ETag went through.');
        } catch (PreconditionFailed $e) {
            self::assertSame(412, $e->status);
        }
        self::assertStringContainsString('druhý', $this->pod->get($url)->body);
    }

    public function testAnN3PatchChangesOneTriple(): void
    {
        $url = $this->container . 'patch.ttl';
        $this->pod->put($url, sprintf('<#a> <%sstav> "první" .', self::EX));
        $etag = $this->pod->head($url)->etag;
        self::assertNotNull($etag);

        $this->pod->patch(
            $url,
            N3Patch::create()
                ->withPrefix('ex', self::EX)
                ->withDeletes('<#a> ex:stav "první".')
                ->withInserts('<#a> ex:stav "opravený".'),
            Precondition::ifMatch($etag),
        );

        $body = $this->pod->get($url)->body;
        self::assertStringContainsString('opravený', $body);
        self::assertStringNotContainsString('první', $body);

        $this->expectException(PreconditionFailed::class);
        $this->pod->patch(
            $url,
            N3Patch::create()->withInserts(sprintf('<#a> <%sstav> "pozdě".', self::EX)),
            Precondition::ifMatch($etag),
        );
    }

    public function testAContainerListsWhatIsInIt(): void
    {
        $this->pod->put($this->container . 'jeden.ttl', '<#x> <#y> <#z> .');
        $this->pod->createContainer($this->container . 'vnoreny/');
        $posted = $this->pod->post($this->container, '<#x> <#y> <#z> .', slug: 'poslany');
        self::assertNotNull($posted->location);

        $members = $this->pod->listContainer($this->container);
        $urls = array_map(static fn ($member): string => $member->url, $members);
        sort($urls);

        self::assertContains($this->container . 'jeden.ttl', $urls);
        self::assertContains($this->container . 'vnoreny/', $urls);
        self::assertContains($posted->location, $urls);
        self::assertCount(1, array_filter($members, static fn ($member): bool => $member->isContainer()));
    }

    public function testATypeIndexIsReadWithItsRegistrations(): void
    {
        $url = $this->container . 'publicTypeIndex.ttl';
        $this->pod->put($url, <<<TTL
            @prefix solid: <http://www.w3.org/ns/solid/terms#>.
            @prefix schema: <http://schema.org/>.
            <> a solid:TypeIndex, solid:ListedDocument.
            <#claims> a solid:TypeRegistration;
                solid:forClass schema:Claim;
                solid:instanceContainer <{$this->container}fakta/>.
            <#people> a solid:TypeRegistration;
                solid:forClass schema:Person;
                solid:instance <{$this->container}lide.ttl>.
            TTL);

        $index = (new TypeIndexReader($this->pod))->read($url);

        $claims = $index->registrationsFor('http://schema.org/Claim');
        self::assertCount(1, $claims);
        self::assertSame([$this->container . 'fakta/'], $claims[0]->instanceContainers);
        $people = $index->registrationsFor('http://schema.org/Person');
        self::assertSame([$this->container . 'lide.ttl'], $people[0]->instances);
    }

    public function testAStrangerCannotReadThePrivateDocumentAndADeletedOneIsGone(): void
    {
        $url = $this->container . 'soukrome.ttl';
        $this->pod->put($url, '<#x> <#y> <#z> .');

        try {
            $this->anonymous->get($url);
            self::fail('An anonymous reader got a private document.');
        } catch (AccessDenied $e) {
            self::assertSame(401, $e->status);
        }

        $etag = $this->pod->head($url)->etag;
        self::assertNotNull($etag);
        $this->pod->delete($url, Precondition::ifMatch($etag));

        $this->expectException(NotFound::class);
        $this->pod->get($url);
    }

    private function deleteTree(string $container): void
    {
        try {
            $members = $this->pod->listContainer($container);
        } catch (NotFound) {
            return;
        }
        foreach ($members as $member) {
            $member->isContainer() ? $this->deleteTree($member->url) : $this->pod->delete($member->url);
        }
        $this->pod->delete($container);
    }

    /**
     * The checkout's .env and .env.local, read directly: phpunit boots the
     * test environment, which skips .env.local (where `make seed` writes the
     * credentials) and may point SOLID_BASE_URL at a host that does not
     * resolve (dstl: nothing leaves the test container — except this test).
     *
     * @return array<string, string>
     */
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
