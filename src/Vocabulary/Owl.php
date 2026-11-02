<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, Los Agentos de Semilla contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Vocabulary;

final class Owl
{
    public const string PREFIX = 'owl';
    public const string NS = 'http://www.w3.org/2002/07/owl#';

    public const string SAME_AS = self::NS . 'sameAs';
}
