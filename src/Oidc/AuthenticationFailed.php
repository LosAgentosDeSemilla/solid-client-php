<?php

/*
 * This file is part of semilla/solid-client, a fork of dunglas/solid-client-php.
 * (c) Kévin Dunglas <kevin@dunglas.fr>, people-tracker contributors
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Semilla\SolidClient\Oidc;

use Semilla\SolidClient\SolidClientException;

/**
 * The sign-in did not produce a token anyone should trust: the provider said
 * no, the answer did not match the request, or the ID token did not verify.
 * The message is for the log; show the user something generic.
 */
final class AuthenticationFailed extends SolidClientException
{
}
