<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\TypeIndex;

/** @implements \IteratorAggregate<int, Registration> */
final readonly class Registrations implements \IteratorAggregate, \Countable
{
    /** @var list<Registration> */
    private array $items;

    public function __construct(Registration ...$registrations)
    {
        $this->items = array_values($registrations);
    }

    /** @return \ArrayIterator<int, Registration> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return \count($this->items);
    }
}
