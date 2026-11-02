<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Pod;

/**
 * A Solid N3 Patch (Solid Protocol §5.3.1): triples to insert, triples to
 * delete, and an optional condition with variables. The triples are Turtle
 * fragments — full IRIs in angle brackets, or prefixes declared with
 * {@see withPrefix()}. Immutable; every `with…` returns a new patch.
 *
 * A delete of a triple that is not there fails the whole patch with 409,
 * which is the server's way of saying "you read an older version".
 */
final readonly class N3Patch
{
    /** @param array<string, string> $prefixes */
    private function __construct(
        private string $inserts = '',
        private string $deletes = '',
        private string $where = '',
        private array $prefixes = [],
    ) {
    }

    public static function create(): self
    {
        return new self();
    }

    public function withPrefix(string $prefix, string $iri): self
    {
        return new self($this->inserts, $this->deletes, $this->where, [...$this->prefixes, $prefix => $iri]);
    }

    public function withInserts(string $triples): self
    {
        return new self(self::join($this->inserts, $triples), $this->deletes, $this->where, $this->prefixes);
    }

    public function withDeletes(string $triples): self
    {
        return new self($this->inserts, self::join($this->deletes, $triples), $this->where, $this->prefixes);
    }

    public function withWhere(string $triples): self
    {
        return new self($this->inserts, $this->deletes, self::join($this->where, $triples), $this->prefixes);
    }

    public function toN3(): string
    {
        if ($this->inserts === '' && $this->deletes === '') {
            throw new \LogicException('An N3 Patch with nothing to insert or delete changes nothing.');
        }

        $lines = ['@prefix solid: <http://www.w3.org/ns/solid/terms#>.'];
        foreach ($this->prefixes as $prefix => $iri) {
            $lines[] = sprintf('@prefix %s: <%s>.', $prefix, $iri);
        }
        $clauses = ['a solid:InsertDeletePatch'];
        if ($this->where !== '') {
            $clauses[] = sprintf("solid:where {\n%s\n}", $this->where);
        }
        if ($this->deletes !== '') {
            $clauses[] = sprintf("solid:deletes {\n%s\n}", $this->deletes);
        }
        if ($this->inserts !== '') {
            $clauses[] = sprintf("solid:inserts {\n%s\n}", $this->inserts);
        }
        $lines[] = '';
        $lines[] = '_:patch ' . implode(";\n    ", $clauses) . '.';

        return implode("\n", $lines) . "\n";
    }

    private static function join(string $existing, string $more): string
    {
        $more = trim($more);

        return $existing === '' ? $more : $existing . "\n" . $more;
    }
}
