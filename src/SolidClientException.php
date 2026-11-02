<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient;

/**
 * Everything this package throws on purpose. Catch this to handle "the pod or
 * the identity provider said no" without catching programming errors.
 */
class SolidClientException extends \RuntimeException
{
}
