<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\TypeIndex;

/**
 * A Type Index document (Solid Type Indexes): which class of data lives where.
 * Applications find data through it, never by a hard-coded path.
 */
final readonly class TypeIndex
{
    /** @param list<TypeRegistration> $registrations */
    public function __construct(
        public string $url,
        public array $registrations,
    ) {
    }

    /** @return list<TypeRegistration> */
    public function registrationsFor(string $class): array
    {
        return array_values(array_filter(
            $this->registrations,
            static fn (TypeRegistration $registration): bool => $registration->forClass === $class,
        ));
    }
}
