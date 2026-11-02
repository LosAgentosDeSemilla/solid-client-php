<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Oidc;

use Semilla\SolidClient\Dpop\DpopKey;

/**
 * A DPoP-bound access token, the key it is bound to, and how to get the next
 * one. Plain data on purpose: it round-trips through {@see toArray()} into a
 * session, and the upstream habit of putting a whole OIDC client object into
 * the session is what broke on every PHP upgrade.
 */
final readonly class TokenSet
{
    public function __construct(
        public string $issuer,
        public string $clientId,
        public string $webId,
        public string $accessToken,
        public \DateTimeImmutable $expiresAt,
        public DpopKey $dpopKey,
        public ?string $refreshToken = null,
        public ?string $idToken = null,
    ) {
    }

    /** True from `$leewaySeconds` before expiry on, so a request never leaves with a token that dies in flight. */
    public function isExpiring(\DateTimeImmutable $now, int $leewaySeconds = 60): bool
    {
        return $now->modify(sprintf('+%d seconds', $leewaySeconds)) >= $this->expiresAt;
    }

    public function canRefresh(): bool
    {
        return $this->refreshToken !== null;
    }

    /** @return array{issuer: string, clientId: string, webId: string, accessToken: string, expiresAt: int, dpopKey: array<string, mixed>, refreshToken: ?string, idToken: ?string} */
    public function toArray(): array
    {
        return [
            'issuer' => $this->issuer,
            'clientId' => $this->clientId,
            'webId' => $this->webId,
            'accessToken' => $this->accessToken,
            'expiresAt' => $this->expiresAt->getTimestamp(),
            'dpopKey' => $this->dpopKey->toArray(),
            'refreshToken' => $this->refreshToken,
            'idToken' => $this->idToken,
        ];
    }

    /** @param array<string, mixed> $values as {@see toArray()} wrote them */
    public static function fromArray(array $values): self
    {
        $string = static fn (string $key): string => \is_string($values[$key] ?? null)
            ? $values[$key]
            : throw new \InvalidArgumentException(sprintf('A stored token set has no "%s".', $key));
        $optional = static fn (string $key): ?string => \is_string($values[$key] ?? null) ? $values[$key] : null;
        $expiresAt = \is_int($values['expiresAt'] ?? null)
            ? \DateTimeImmutable::createFromFormat('U', (string) $values['expiresAt'])
            : false;
        $key = $values['dpopKey'] ?? null;
        if ($expiresAt === false || !\is_array($key)) {
            throw new \InvalidArgumentException('A stored token set has no expiry or no DPoP key.');
        }

        /** @var array<string, mixed> $key */
        return new self(
            $string('issuer'),
            $string('clientId'),
            $string('webId'),
            $string('accessToken'),
            $expiresAt,
            DpopKey::fromArray($key),
            $optional('refreshToken'),
            $optional('idToken'),
        );
    }
}
