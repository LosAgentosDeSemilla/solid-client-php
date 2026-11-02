<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\TypeIndex;

use Semilla\SolidClient\Pod\N3Patch;
use Semilla\SolidClient\Pod\NotFound;
use Semilla\SolidClient\Pod\PodClient;
use Semilla\SolidClient\Profile\ProfileReader;
use Semilla\SolidClient\Profile\WebIdProfile;
use Semilla\SolidClient\Rdf\Iri;
use Semilla\SolidClient\Rdf\RdfDocument;
use Semilla\SolidClient\Vocabulary\Solid;

/**
 * Registers an application's types in the pod's Type Indexes, so that another
 * client finds its data through the index, never by the application's paths.
 *
 * An index the profile does not link yet is made at its default place in the
 * storage (`settings/`, where Community Solid Server's own template would put
 * it) and linked: the public one from the profile (`solid:publicTypeIndex`),
 * the private one from the preferences file (`solid:privateTypeIndex`), which
 * is made and linked from the profile (`pim:preferencesFile`) when there is
 * none. CSS's default profile links neither. No public index is made when
 * there is nothing public to register: its mere existence would be public.
 *
 * Into an existing index only the missing registrations are inserted (N3
 * Patch, idempotent because the registration IRIs are fixed); with all of
 * them there, nothing is written at all.
 */
final readonly class TypeIndexRegistrar
{
    public function __construct(
        private TypeIndexReader $indexes,
    ) {
    }

    /** @param string $storage the root of the storage, e.g. `http://…/dev/` */
    public function register(PodClient $client, WebIdProfile $profile, string $storage, Registrations $public, Registrations $private): void
    {
        $webId = $profile->webId;
        $profileDocument = ProfileReader::documentOf($webId);
        $storage = rtrim($storage, '/') . '/';

        if (\count($public) > 0) {
            $index = $profile->publicTypeIndex;
            $this->complete($client, $index ?? $storage . 'settings/publicTypeIndex.ttl', $public, true);
            if ($index === null) {
                $this->link($client, $profileDocument, $webId, ProfileReader::PUBLIC_TYPE_INDEX, $storage . 'settings/publicTypeIndex.ttl');
            }
        }

        $preferences = $profile->preferencesFile;
        $index = $profile->privateTypeIndex
            ?? ($preferences === null ? null : $this->privateIndexIn($client, $preferences, $webId));
        $this->complete($client, $index ?? $storage . 'settings/privateTypeIndex.ttl', $private, false);
        if ($index === null) {
            if ($preferences === null) {
                $preferences = $storage . 'settings/prefs.ttl';
                $this->link($client, $profileDocument, $webId, ProfileReader::PREFERENCES_FILE, $preferences);
            }
            $this->link($client, $preferences, $webId, ProfileReader::PRIVATE_TYPE_INDEX, $storage . 'settings/privateTypeIndex.ttl');
        }
    }

    /**
     * The registrations as the triples of a Type Index document. A new index is
     * written whole; into an existing one, exactly these are inserted.
     */
    public static function toRdf(Iri $index, Registrations $registrations, bool $isPublic): RdfDocument
    {
        $rdf = RdfDocument::create($index)
            ->addType($index, Solid::TYPE_INDEX, $isPublic ? Solid::LISTED_DOCUMENT : Solid::UNLISTED_DOCUMENT);

        foreach ($registrations as $registration) {
            $iri = $index->withFragment($registration->name);
            $rdf->addType($iri, Solid::TYPE_REGISTRATION)
                ->addIri($iri, Solid::FOR_CLASS, $registration->forClass)
                ->addIri($iri, $registration->isContainer ? Solid::INSTANCE_CONTAINER : Solid::INSTANCE, $registration->location);
        }

        return $rdf;
    }

    private function complete(PodClient $client, string $index, Registrations $registrations, bool $isPublic): void
    {
        try {
            $existing = $this->indexes->as($client)->read($index);
        } catch (NotFound) {
            $existing = null;
        }
        if ($existing !== null && $this->hasAll($existing, $registrations)) {
            return;
        }

        $triples = self::toRdf(new Iri($index), $registrations, $isPublic)->toNTriples();
        $client->patch($index, N3Patch::create()->withInserts($triples));
    }

    private function hasAll(TypeIndex $index, Registrations $registrations): bool
    {
        foreach ($registrations as $registration) {
            if (!$this->has($index, $registration)) {
                return false;
            }
        }

        return true;
    }

    private function has(TypeIndex $index, Registration $wanted): bool
    {
        foreach ($index->registrationsFor($wanted->forClass) as $registration) {
            $locations = $wanted->isContainer ? $registration->instanceContainers : $registration->instances;
            if (\in_array($wanted->location->value, $locations, true)) {
                return true;
            }
        }

        return false;
    }

    private function privateIndexIn(PodClient $client, string $preferences, string $webId): ?string
    {
        try {
            return $client->get($preferences)->rdf()->firstObject($webId, ProfileReader::PRIVATE_TYPE_INDEX);
        } catch (NotFound) {
            return null;
        }
    }

    private function link(PodClient $client, string $document, string $webId, string $predicate, string $target): void
    {
        $client->patch($document, N3Patch::create()->withInserts(sprintf('<%s> <%s> <%s>.', $webId, $predicate, $target)));
    }
}
