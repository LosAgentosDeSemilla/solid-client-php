# semilla/solid-client

Solid client for PHP 8.5: Solid-OIDC sign-in (authorization code + PKCE,
DPoP-bound tokens, refresh, Client ID Documents), HTTP over a pod with ETag
preconditions and N3 Patch, WebID profiles and Type Indexes — plus Community
Solid Server's client credentials for scripts and tests.

## Origin and licence

A fork of [dunglas/solid-client-php](https://github.com/dunglas/solid-client-php)
by Kévin Dunglas (MIT, last upstream commit `6dd077a`, "compat with latest
versions of everything"). The licence is kept as it was — see `LICENSE`; every
file carries the upstream copyright notice beside this fork's.

Reference for the fixes: [Fleetbase Solid](https://github.com/fleetbase/solid)
(its own Solid-OIDC client) and
[Project Meent](https://project-meent.github.io/experiment-php-solid-client/).

Born in people-tracker as a Composer path package, copied into solid-contacts
and solid-genealogy, and brought here from the three copies (family docs,
FADR-0008 D2). Its own namespace, `Semilla\SolidClient`, names nothing of any
application.

## Install

Not on Packagist; a VCS repository:

```bash
composer config repositories.solid-client vcs https://github.com/LosAgentosDeSemilla/solid-client-php.git
composer require semilla/solid-client:dev-main
```

## What changed against upstream, and why

| Upstream | Here | Why |
|---|---|---|
| `OidcClient extends Jumbojett\OpenIDConnectClient` | `Oidc\SolidOidcClient`, stateless: `begin()` returns the request to keep, `complete()` takes it back | jumbojett redirects with `header()` + `exit`, keeps state in `$_SESSION` and is curl-bound; none of that works inside a Symfony request |
| dynamic client registration on every login | Client ID Document (the `client_id` is a URL) | Solid-OIDC §5; no registration, no secret, the same client every time |
| the whole OIDC client object stored in the session | `TokenSet` / `AuthorizationRequest` as plain arrays | an object graph in the session broke on upgrades |
| DPoP `ath` = base64url of the *hex* SHA-256 | base64url of the raw SHA-256 | RFC 9449 §4.2 |
| ID token: HS256/384/512 accepted, `R512` typo, no claim checks | asymmetric algorithms only; `iss`, `aud`, `exp`, `iat`, `nonce` checked | HS* against a public key is algorithm confusion |
| no state / `iss` check on the callback | `state` (constant-time) and RFC 9207 `iss` checked | CSRF and mix-up defence |
| `SolidClient`: GET and POST | `Pod\PodClient`: GET, HEAD, PUT, PATCH (N3), DELETE, POST, containers, `Precondition` (If-Match / If-None-Match), typed failures (`NotFound`, `AccessDenied`, `PreconditionFailed`, `Conflict`) | what a pod-writing app needs; a refused write must never read as a written one |
| EasyRdf 1.1 (`easyrdf/easyrdf`) | `sweetrdf/easyrdf` (maintained fork), behind `Rdf\RdfDocument` | PHP 8.5 |
| `web-token/jwt-*` 3.x | `web-token/jwt-library` 4.x | PHP 8.5, Symfony 8 |
| Symfony bundle (login form, authenticator, user provider) | no bundle; the application wires the services and owns its authenticator | GADR-0007-no-bundle-wiring-is-the-projects; the account store and the login page are the application's |
| — | `Profile\ProfileReader::storageOf()` (pim:storage or the `Link: pim:Storage` walk-up), `TypeIndex\TypeIndexReader` | finding data through the Type Index, never by path |
| — | `clientCredentials()` | Community Solid Server client credentials for scripts/tests |

## Use

```php
$proofs = new DpopProofFactory($clock);
$oidc = new SolidOidcClient($http, $proofs, new IdTokenVerifier($http, $clock), $clock);

// 1. Redirect out — keep $request (e.g. in the session).
$issuer = (new ProfileReader(new PodClient($http)))->read($webId)->oidcIssuers[0];
$request = $oidc->begin($issuer, 'https://app.example/client-id.jsonld', 'https://app.example/callback');
// redirect to $request->url

// 2. Callback — then check the WebID's profile lists $tokens->issuer (trustsIssuer()).
$tokens = $oidc->complete($request, $_GET);

// 3. The pod, as that person.
$pod = new PodClient($http, new DpopCredentials($tokens, $proofs));
$doc = $pod->get($url);
$pod->put($url, $turtle, precondition: Precondition::ifMatch($doc->etag));
```

Refresh with `$oidc->refresh($tokens)` before `$tokens->isExpiring($now)`.
Community Solid Server issues a refresh token only when the person ticks
"remember this client" at consent (`offline_access` is otherwise dropped).

## Tests

```bash
composer install
vendor/bin/phpunit --testsuite unit
```

`tests/Integration` run against a Community Solid Server and are skipped
without one. They read `SOLID_BASE_URL` and the development account's
credentials from `.env` and `.env.local` in `SOLID_CLIENT_ENV_DIR`, or in the
working directory — an application's checkout, where `make seed` wrote them:
the pod operations as the account's client credentials, and the full
authorization-code sign-in through the CSS account API with a refresh.
