<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Testing;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The pods on `pod.invalid` as an LDP server keeps them, in memory: documents
 * with a version (`dc:modified`, also the ETag) that changes on every write,
 * containers that list their members with it, `Link` headers naming the
 * storage root and each container's ACR, and N3 Patch inserts (enough of N3
 * Patch for Type Index and ACR registrations: the inserted triples are
 * appended). `https://pod.invalid/<name>/` is a storage root.
 *
 * Every write is logged ({@see $writes}), which is how a scenario tells "the
 * second import wrote nothing" from "the second import wrote the same again";
 * every read too ({@see $reads}), for "the second ingest fetched nothing".
 * PUT honours `If-Match` and `If-None-Match: *` and answers with the new ETag.
 * Answers 401 to a request without a DPoP-bound token it knows, and 403 to a
 * token whose WebID does not own the storage the URL is in (the pod's ACR,
 * FADR-0003). The signed-in owner's storage is {@see STORAGE}; a scenario puts
 * documents there through the back door ({@see place()}, {@see remove()}) and
 * reads them with {@see document()}.
 *
 * Every document has an ACR of its own (`<document>.acr`, named in its `Link`,
 * never listed); files keep their media type (and, as on CSS, answer 501 when
 * asked for as Turtle); another WebID may GET a document whose ACR names it as
 * `acp:agent` — enough ACP to show who reads what.
 *
 * Grew in people-tracker, solid-contacts and solid-genealogy (family docs,
 * FADR-0008 D2); the shape every one of them tests against.
 */
final class FakePod
{
    public const string ORIGIN = 'https://pod.invalid/';
    public const string STORAGE = 'https://pod.invalid/owner/';

    /** @var array<string, string> url => body */
    private array $documents = [];

    /** @var array<string, string> url => dc:modified */
    private array $modified = [];

    /** @var array<string, string> url => media type, of what was PUT */
    private array $types = [];

    /** @var array<string, true> container urls */
    private array $containers = [];

    /** @var list<string> `METHOD url` of every write, in order */
    public private(set) array $writes = [];

    /** @var list<string> `METHOD url` of every read (GET, HEAD), in order */
    public private(set) array $reads = [];

    private int $clock = 0;

    /** @var array<string, string> access token => WebID, told by {@see FakeSolidServer} */
    private array $tokens = [];

    /** @var array<string, string> storage root => owner WebID */
    private const array OWNERS = [
        self::STORAGE => 'https://pod.invalid/owner/profile/card#me',
        'https://pod.invalid/stranger/' => 'https://pod.invalid/stranger/profile/card#me',
    ];

    public function grant(string $accessToken, string $webId): void
    {
        $this->tokens[$accessToken] = $webId;
    }

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        /** @var array<string, list<string>> $headers */
        $headers = $options['normalized_headers'] ?? [];
        $authorization = $headers['authorization'][0] ?? '';
        if (!str_starts_with($authorization, 'Authorization: DPoP ')) {
            return new MockResponse('', ['http_code' => 401]);
        }
        $webId = $this->tokens[substr($authorization, \strlen('Authorization: DPoP '))] ?? null;
        if ($webId === null) {
            return new MockResponse('', ['http_code' => 401]);
        }
        if (!$this->owns($webId, $url) && !(($method === 'GET' || $method === 'HEAD') && $this->readableBy($webId, $url))) {
            return new MockResponse('', ['http_code' => 403]);
        }
        $body = \is_string($options['body'] ?? null) ? $options['body'] : '';
        if ($method !== 'GET' && $method !== 'HEAD' && !self::preconditionHolds($this->documents[$url] ?? null, $this->etag($url), $headers)) {
            return new MockResponse('', ['http_code' => 412]);
        }

        $type = $this->types[$url] ?? 'text/turtle';
        if (($method === 'GET' || $method === 'HEAD') && !str_starts_with($type, 'text/turtle') && self::header($headers, 'accept') === 'text/turtle') {
            // As Community Solid Server: a file that is not RDF cannot be had as Turtle.
            return new MockResponse('', ['http_code' => 501]);
        }

        return match ($method) {
            'GET', 'HEAD' => $this->read($url, $method === 'HEAD'),
            'PUT' => $this->put($url, $body, str_ends_with($url, '/'), self::header($headers, 'content-type')),
            'PATCH' => $this->patch($url, $body),
            'DELETE' => $this->delete($url),
            default => new MockResponse('', ['http_code' => 405]),
        };
    }

    /** Back door: a document put into the pod by somebody else, outside any import. */
    public function place(string $url, string $turtle): void
    {
        $this->store($url, $turtle);
    }

    /** Back door: a document somebody else deleted. */
    public function remove(string $url): void
    {
        unset($this->documents[$url], $this->modified[$url]);
    }

    /**
     * The WebIDs the document's own ACR lets read it.
     *
     * @return list<string>
     */
    public function readersOf(string $url): array
    {
        preg_match_all('/acp:agent\s+((?:<[^>]+>\s*,?\s*)+)/', $this->documents[$url . '.acr'] ?? '', $m);
        preg_match_all('/<([^>]+)>/', implode(' ', $m[1]), $agents);
        sort($agents[1]);

        return $agents[1];
    }

    private function readableBy(string $webId, string $url): bool
    {
        return \in_array($webId, $this->readersOf($url), true);
    }

    /** @param array<string, list<string>> $headers */
    private static function header(array $headers, string $name): ?string
    {
        return isset($headers[$name][0]) ? trim(substr($headers[$name][0], strpos($headers[$name][0], ':') + 1)) : null;
    }

    private function owns(string $webId, string $url): bool
    {
        foreach (self::OWNERS as $storage => $owner) {
            if (str_starts_with($url, $storage)) {
                return $owner === $webId;
            }
        }

        return false;
    }

    public function forgetRequests(): void
    {
        $this->reads = [];
        $this->writes = [];
    }

    public function document(string $url): ?string
    {
        return $this->documents[$url] ?? null;
    }

    public function has(string $url): bool
    {
        return isset($this->documents[$url]);
    }

    public function body(string $url): string
    {
        return $this->documents[$url] ?? throw new \LogicException("The pod has no {$url}.");
    }

    /** @return list<string> the documents (not containers) directly in the container */
    public function documentsIn(string $container): array
    {
        return array_values(array_filter(
            array_keys($this->documents),
            static fn (string $url): bool => self::parentOf($url) === $container && !str_ends_with($url, '.acr'),
        ));
    }

    /** @return list<string> the documents anywhere under the container, sorted */
    public function documentsUnder(string $container): array
    {
        $urls = array_values(array_filter(
            array_keys($this->documents),
            static fn (string $url): bool => str_starts_with($url, $container),
        ));
        sort($urls);

        return $urls;
    }

    /** What a patch inserted into a document this fake does not serve itself (a profile). */
    public function insertedInto(string $url): string
    {
        return $this->documents[$url] ?? '';
    }

    private function read(string $url, bool $headOnly): MockResponse
    {
        $this->reads[] = ($headOnly ? 'HEAD ' : 'GET ') . $url;
        $links = [];
        if (preg_match('#^https://pod\.invalid/[^/]+/$#', $url) === 1) {
            $links[] = '<http://www.w3.org/ns/pim/space#Storage>; rel="type"';
            $this->containers[$url] = true;
        }
        if (isset($this->containers[$url])) {
            $links[] = sprintf('<%s.acr>; rel="acl"', $url);
            $body = $this->listing($url);
        } elseif (isset($this->documents[$url])) {
            $body = $this->documents[$url];
            if (!str_ends_with($url, '.acr')) {
                $links[] = sprintf('<%s.acr>; rel="acl"', $url);
            }
        } else {
            return new MockResponse('', ['http_code' => 404]);
        }

        return new MockResponse($headOnly ? '' : $body, ['response_headers' => [
            'Content-Type' => $this->types[$url] ?? 'text/turtle',
            'ETag' => $this->etag($url) ?? '"container"',
            'Link' => implode(', ', $links),
        ]]);
    }

    private function put(string $url, string $body, bool $isContainer, ?string $type = null): MockResponse
    {
        $this->writes[] = "PUT {$url}";
        if ($isContainer) {
            $this->container($url);

            return new MockResponse('', ['http_code' => 201]);
        }
        $existed = isset($this->documents[$url]);
        $this->store($url, $body);
        if ($type !== null) {
            $this->types[$url] = $type;
        }

        return new MockResponse('', [
            'http_code' => $existed ? 205 : 201,
            'response_headers' => ['ETag' => (string) $this->etag($url)],
        ]);
    }

    private function patch(string $url, string $body): MockResponse
    {
        $this->writes[] = "PATCH {$url}";
        if (preg_match('/solid:inserts\s*\{(.*)\}\s*\.\s*$/s', $body, $inserts) !== 1) {
            return new MockResponse('', ['http_code' => 422]);
        }
        preg_match_all('/^@prefix [^\n]*$/m', $body, $prefixes);
        $this->store($url, ($this->documents[$url] ?? '') . "\n" . implode("\n", $prefixes[0]) . "\n" . $inserts[1] . "\n");

        return new MockResponse('', ['http_code' => 205]);
    }

    private function delete(string $url): MockResponse
    {
        $this->writes[] = "DELETE {$url}";
        if (!isset($this->documents[$url])) {
            return new MockResponse('', ['http_code' => 404]);
        }
        unset($this->documents[$url], $this->modified[$url]);

        return new MockResponse('', ['http_code' => 205]);
    }

    private function etag(string $url): ?string
    {
        return isset($this->modified[$url]) ? sprintf('"%s"', $this->modified[$url]) : null;
    }

    /** @param array<string, list<string>> $headers */
    private static function preconditionHolds(?string $stored, ?string $etag, array $headers): bool
    {
        $value = static fn (string $name): ?string => isset($headers[$name][0])
            ? trim(substr($headers[$name][0], strpos($headers[$name][0], ':') + 1))
            : null;
        $ifMatch = $value('if-match');
        if ($value('if-none-match') === '*' && $stored !== null) {
            return false;
        }

        return $ifMatch === null || ($ifMatch === '*' ? $stored !== null : $ifMatch === $etag);
    }

    private function store(string $url, string $body): void
    {
        $this->documents[$url] = $body;
        $this->modified[$url] = sprintf('2026-10-29T12:00:00.%03dZ', ++$this->clock);
        $this->container(self::parentOf($url));
    }

    private function container(string $url): void
    {
        while (str_starts_with($url, self::ORIGIN) && $url !== self::ORIGIN) {
            $this->containers[$url] = true;
            $url = self::parentOf($url);
        }
    }

    private function listing(string $container): string
    {
        $turtle = "@prefix ldp: <http://www.w3.org/ns/ldp#>.\n@prefix dc: <http://purl.org/dc/terms/>.\n"
            . "@prefix xsd: <http://www.w3.org/2001/XMLSchema#>.\n<{$container}> a ldp:BasicContainer.\n";
        foreach ([...array_keys($this->documents), ...array_keys($this->containers)] as $member) {
            if ($member !== $container && self::parentOf($member) === $container && !str_ends_with($member, '.acr')) {
                $turtle .= "<{$container}> ldp:contains <{$member}>.\n";
                if (isset($this->modified[$member])) {
                    $turtle .= "<{$member}> dc:modified \"{$this->modified[$member]}\"^^xsd:dateTime.\n";
                }
            }
        }

        return $turtle;
    }

    private static function parentOf(string $url): string
    {
        $path = rtrim($url, '/');

        return substr($path, 0, (int) strrpos($path, '/') + 1);
    }
}
