<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Rdf;

/** A pod document that does not have the shape docs/ontologie.md promises. Pods are written by anyone. */
final class MalformedDocument extends \RuntimeException
{
    public static function missing(Iri $document, string $subject, string $predicate): self
    {
        return new self(sprintf('%s: <%s> has no <%s>', $document, $subject, $predicate));
    }

    public static function invalid(Iri $document, string $subject, string $predicate, string $why): self
    {
        return new self(sprintf('%s: <%s> <%s> — %s', $document, $subject, $predicate, $why));
    }

    public static function unparsable(Iri $document, \Throwable $previous): self
    {
        return new self(sprintf('%s: not valid Turtle — %s', $document, $previous->getMessage()), 0, $previous);
    }
}
