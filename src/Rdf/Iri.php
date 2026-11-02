<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Rdf;

/** An absolute IRI. Blank nodes never cross into the domain: everything we write is named. */
final readonly class Iri implements \Stringable
{
    public const int MAX_LENGTH = 2048;

    private const string PATTERN = '~^[a-z][a-z0-9+.\-]*:[^\s<>"{}|\\\\^`]+$~i';

    public string $value;

    public function __construct(string $value)
    {
        if (strlen($value) > self::MAX_LENGTH || preg_match(self::PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an absolute IRI', $value));
        }

        $this->value = $value;
    }

    public static function tryFrom(?string $value): ?self
    {
        return $value === null ? null : new self($value);
    }

    /** The document an IRI lives in: everything before `#`. */
    public function document(): self
    {
        $hash = strpos($this->value, '#');

        return $hash === false ? $this : new self(substr($this->value, 0, $hash));
    }

    public function withFragment(string $fragment): self
    {
        return new self($this->document()->value . '#' . $fragment);
    }

    /** `https://www.irozhlas.cz/zpravy/…` → `https://www.irozhlas.cz/`: the web site as a source. */
    public function origin(): self
    {
        $parts = parse_url($this->value);
        if (!isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException(sprintf('"%s" has no origin', $this->value));
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return new self(strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . $port . '/');
    }

    public function startsWith(string $prefix): bool
    {
        return str_starts_with($this->value, $prefix);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
