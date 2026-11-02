<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\TypeIndex;

/** One `solid:TypeRegistration`: where data of one class lives, as documents, containers or both. */
final readonly class TypeRegistration
{
    /**
     * @param list<string> $instances          documents holding data of the class
     * @param list<string> $instanceContainers containers whose members hold it
     */
    public function __construct(
        public string $forClass,
        public array $instances,
        public array $instanceContainers,
    ) {
    }
}
