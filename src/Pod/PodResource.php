<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Pod;

use Semilla\SolidClient\Rdf\RdfDocument;

/**
 * What a read returned. For a 304 (an `ifNoneMatch` read of an unchanged
 * resource) the body is empty and the ETag is the one the caller already had.
 */
final readonly class PodResource
{
    /** @param list<string> $links the raw `Link` header values */
    public function __construct(
        public string $url,
        public int $status,
        public ?string $etag,
        public ?string $contentType,
        public string $body,
        public array $links = [],
    ) {
    }

    public function isNotModified(): bool
    {
        return $this->status === 304;
    }

    public function rdf(): RdfDocument
    {
        return RdfDocument::parseTurtle($this->body, $this->url);
    }

    /**
     * The target of the first `Link` with relation `$rel`, absolute — e.g.
     * `acl` for the resource's access control resource (Solid Protocol §4.3).
     */
    public function link(string $rel): ?string
    {
        foreach ($this->links as $header) {
            foreach (explode(',', $header) as $link) {
                if (preg_match('/^\s*<([^>]*)>\s*;(.*)$/', $link, $match) !== 1) {
                    continue;
                }
                if (
                    preg_match('/(^|;)\s*rel="?([^";]*)"?/', $match[2], $relation) === 1
                    && \in_array($rel, preg_split('/\s+/', trim($relation[2])) ?: [], true)
                ) {
                    return self::resolve($match[1], $this->url);
                }
            }
        }

        return null;
    }

    /** True when a `Link: <$type>; rel="type"` header names the type. */
    public function hasLinkType(string $type): bool
    {
        foreach ($this->links as $header) {
            foreach (explode(',', $header) as $link) {
                if (str_contains($link, '<' . $type . '>') && preg_match('/rel="?type"?/', $link) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Enough of RFC 3986 §5.2 for a Link target: absolute, root-relative or relative to the resource's folder. */
    private static function resolve(string $reference, string $base): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $reference) === 1) {
            return $reference;
        }
        $parts = parse_url($base);
        $origin = sprintf(
            '%s://%s%s',
            $parts['scheme'] ?? 'https',
            $parts['host'] ?? '',
            isset($parts['port']) ? ':' . $parts['port'] : '',
        );
        if (str_starts_with($reference, '/')) {
            return $origin . $reference;
        }
        $path = $parts['path'] ?? '/';

        return $origin . substr($path, 0, (int) strrpos($path, '/') + 1) . $reference;
    }
}
