<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\TypeIndex;

use Semilla\SolidClient\Rdf\Iri;

/**
 * One `solid:TypeRegistration` an application wants in an index: where
 * instances of a class live — a container, or one document. What an index
 * already holds is read as {@see TypeRegistration}.
 */
final readonly class Registration
{
    public function __construct(
        /** Fragment of the registration in the index (`#genealogy-individuals`), stable so re-registering is idempotent. */
        public string $name,
        public string $forClass,
        public Iri $location,
        public bool $isContainer,
    ) {
    }
}
