<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Rdf;

/** @implements \IteratorAggregate<int, Iri> */
final readonly class Iris implements \IteratorAggregate, \Countable
{
    /** @var list<Iri> */
    private array $items;

    public function __construct(Iri ...$items)
    {
        $this->items = array_values($items);
    }

    /** @return \ArrayIterator<int, Iri> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function first(): ?Iri
    {
        return $this->items[0] ?? null;
    }

    public function contains(Iri $iri): bool
    {
        foreach ($this->items as $item) {
            if ($item->equals($iri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A list for PHP's array functions and spreading; Iris is the type everywhere else.
     *
     * @return list<Iri>
     */
    public function toList(): array
    {
        return $this->items;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** These and `$more`, each IRI once, in the order first seen. */
    public function with(Iri ...$more): self
    {
        $unique = [];
        foreach ([...$this->items, ...$more] as $iri) {
            $unique[$iri->value] ??= $iri;
        }

        return new self(...array_values($unique));
    }

    public function without(Iri ...$removed): self
    {
        return new self(...array_filter($this->items, static function (Iri $iri) use ($removed): bool {
            foreach ($removed as $r) {
                if ($r->equals($iri)) {
                    return false;
                }
            }

            return true;
        }));
    }

    public function sorted(): self
    {
        $items = $this->items;
        usort($items, static fn (Iri $a, Iri $b): int => strcmp($a->value, $b->value));

        return new self(...$items);
    }
}
