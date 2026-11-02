<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\TypeIndex;

use Semilla\SolidClient\Pod\PodClient;
use Semilla\SolidClient\Profile\ProfileReader;
use Semilla\SolidClient\Profile\WebIdProfile;

/**
 * Reads Type Indexes. The public one is linked from the profile; the private
 * one from the profile or, more usually, from the preferences file the
 * profile links — which only its owner can read, so read with the owner's
 * credentials ({@see as()}).
 */
final readonly class TypeIndexReader
{
    public const string TYPE_REGISTRATION = 'http://www.w3.org/ns/solid/terms#TypeRegistration';
    public const string FOR_CLASS = 'http://www.w3.org/ns/solid/terms#forClass';
    public const string INSTANCE = 'http://www.w3.org/ns/solid/terms#instance';
    public const string INSTANCE_CONTAINER = 'http://www.w3.org/ns/solid/terms#instanceContainer';

    public function __construct(
        private PodClient $pods,
    ) {
    }

    public function as(PodClient $pods): self
    {
        return new self($pods);
    }

    public function read(string $url): TypeIndex
    {
        $document = $this->pods->get($url)->rdf();
        $registrations = [];
        foreach ($document->subjectsWith(self::FOR_CLASS) as $subject) {
            foreach ($document->objects($subject, self::FOR_CLASS) as $class) {
                $registrations[] = new TypeRegistration(
                    $class,
                    $document->objects($subject, self::INSTANCE),
                    $document->objects($subject, self::INSTANCE_CONTAINER),
                );
            }
        }

        return new TypeIndex($url, $registrations);
    }

    /** Null when the profile links no public Type Index (Community Solid Server's default profile does not). */
    public function publicOf(WebIdProfile $profile): ?TypeIndex
    {
        return $profile->publicTypeIndex === null ? null : $this->read($profile->publicTypeIndex);
    }

    public function privateOf(WebIdProfile $profile): ?TypeIndex
    {
        $url = $profile->privateTypeIndex;
        if ($url === null && $profile->preferencesFile !== null) {
            $preferences = $this->pods->get($profile->preferencesFile)->rdf();
            $url = $preferences->firstObject($profile->webId, ProfileReader::PRIVATE_TYPE_INDEX);
        }

        return $url === null ? null : $this->read($url);
    }
}
