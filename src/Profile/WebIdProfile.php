<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Profile;

/**
 * What a WebID profile says about its agent that a Solid application needs:
 * who may sign them in, where their pods are, and where their Type Indexes
 * are (Solid WebID Profile, Type Indexes). Anything missing is empty or null —
 * Community Solid Server's default profile, for one, names an issuer and
 * nothing else.
 */
final readonly class WebIdProfile
{
    /**
     * @param list<string> $oidcIssuers
     * @param list<string> $storages
     */
    public function __construct(
        public string $webId,
        public array $oidcIssuers,
        public array $storages,
        public ?string $publicTypeIndex,
        public ?string $privateTypeIndex,
        public ?string $preferencesFile,
    ) {
    }

    /** Solid-OIDC §5.1: a WebID may only be asserted by an issuer its profile lists. */
    public function trustsIssuer(string $issuer): bool
    {
        $normalised = rtrim($issuer, '/');
        foreach ($this->oidcIssuers as $listed) {
            if (rtrim($listed, '/') === $normalised) {
                return true;
            }
        }

        return false;
    }
}
