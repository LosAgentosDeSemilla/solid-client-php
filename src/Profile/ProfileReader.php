<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Profile;

use Semilla\SolidClient\Pod\AccessDenied;
use Semilla\SolidClient\Pod\NotFound;
use Semilla\SolidClient\Pod\PodClient;
use Semilla\SolidClient\SolidClientException;

/**
 * Reads WebID profiles, and finds an agent's storage when the profile does
 * not name one. Upstream's `getProfile()`/`getOidcIssuer()`, with the parts a
 * pod-writing application needs on top.
 */
final readonly class ProfileReader
{
    public const string OIDC_ISSUER = 'http://www.w3.org/ns/solid/terms#oidcIssuer';
    public const string STORAGE = 'http://www.w3.org/ns/pim/space#storage';
    public const string STORAGE_TYPE = 'http://www.w3.org/ns/pim/space#Storage';
    public const string PREFERENCES_FILE = 'http://www.w3.org/ns/pim/space#preferencesFile';
    public const string PUBLIC_TYPE_INDEX = 'http://www.w3.org/ns/solid/terms#publicTypeIndex';
    public const string PRIVATE_TYPE_INDEX = 'http://www.w3.org/ns/solid/terms#privateTypeIndex';

    public function __construct(
        private PodClient $pods,
    ) {
    }

    /** Reads with the given client's credentials, so a private type index link is visible to its owner. */
    public function as(PodClient $pods): self
    {
        return new self($pods);
    }

    public function read(string $webId): WebIdProfile
    {
        if (preg_match('#^https?://#', $webId) !== 1) {
            throw new SolidClientException(sprintf('A WebID is an http(s) URL, "%s" is not.', $webId));
        }

        $document = $this->pods->get(self::documentOf($webId))->rdf();

        return new WebIdProfile(
            $webId,
            $document->objects($webId, self::OIDC_ISSUER),
            $document->objects($webId, self::STORAGE),
            $document->firstObject($webId, self::PUBLIC_TYPE_INDEX),
            $document->firstObject($webId, self::PRIVATE_TYPE_INDEX),
            $document->firstObject($webId, self::PREFERENCES_FILE),
        );
    }

    /**
     * The agent's storage: the profile's `pim:storage` when it names one,
     * otherwise the nearest container above the profile document that says
     * `Link: <pim:Storage>; rel="type"` (Solid Protocol §4.1).
     */
    public function storageOf(WebIdProfile $profile): string
    {
        if ($profile->storages !== []) {
            return $profile->storages[0];
        }

        $url = self::documentOf($profile->webId);
        while (($parent = self::parentOf($url)) !== null) {
            $url = $parent;
            try {
                if ($this->pods->head($url)->hasLinkType(self::STORAGE_TYPE)) {
                    return $url;
                }
            } catch (AccessDenied | NotFound) {
                continue;
            }
        }

        throw new SolidClientException(sprintf('No storage found for %s.', $profile->webId));
    }

    public static function documentOf(string $webId): string
    {
        $hash = strpos($webId, '#');

        return $hash === false ? $webId : substr($webId, 0, $hash);
    }

    /** The container one level up, or null at the host root. */
    private static function parentOf(string $url): ?string
    {
        $parts = parse_url($url);
        $path = rtrim($parts['path'] ?? '/', '/');
        if ($path === '' || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $parentPath = substr($path, 0, (int) strrpos($path, '/') + 1);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return sprintf('%s://%s%s%s', $parts['scheme'], $parts['host'], $port, $parentPath);
    }
}
