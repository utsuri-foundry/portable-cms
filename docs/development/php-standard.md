# PHP implementation standard

Portable CMS supports PHP 8.2 and newer. Product code is explicit at its boundaries, deterministic in its core, and independently testable without MariaDB, HTTP, filesystems, clocks, randomness, ImageMagick, or providers.

## Language and module shape

- Every PHP file must place `declare(strict_types=1);` immediately after `<?php`.
- Classes and methods must declare visibility. Prefer `final`; use inheritance only when substitution is part of a documented contract.
- Immutable facts and value boundaries should be `final readonly` objects. Do not expose a mutable object and call it a value.
- A module owns one cohesive concern. Split when ownership, dependency direction, resource lifecycle, or testing boundaries differ, not merely to reduce line count.
- PHPStan level 6 is the enforced floor. Collection value types and stable array shapes are required. Raise the level when the imported baseline supports it; never lower it to land a change.
- PHPDoc refines a native type. It does not replace an available native parameter, property, or return type.

```php
// Good: immutable value with a checked invariant.
final readonly class RevisionNumber
{
    public function __construct(public int $value)
    {
        if ($value < 1) {
            throw new InvalidArgumentException('Revision number must be positive');
        }
    }
}

// Bad: public mutable bag with an implicit shape.
class Revision
{
    public $data;
}
```

## Arrays and untrusted input

Arrays crossing HTTP, JSON, PDO, profile, export, or provider boundaries are `mixed` until validated. Check exact keys, type, count, byte length, nesting, canonical identifiers, and relationship targets before constructing domain values. After validation, declare an exact shape or a value object.

```php
/** @return array{id: non-empty-string, revision: positive-int} */
function recordIdentity(mixed $value): array
{
    if (!is_array($value) || array_keys($value) !== ['id', 'revision']) {
        throw new InvalidArgumentException('Record identity is invalid');
    }
    $id = $value['id'];
    $revision = $value['revision'];
    if (!is_string($id) || $id === '' || !is_int($revision) || $revision < 1) {
        throw new InvalidArgumentException('Record identity is invalid');
    }
    return ['id' => $id, 'revision' => $revision];
}
```

Do not cast arbitrary `mixed` values to make analysis pass. Narrow and reject them. Do not use an unbounded `array<string, mixed>` as an internal domain model when a stable shape exists.

## Dependencies, state, and effects

- Constructor-inject required repositories and effect interfaces. Optional capabilities are explicit nullable or capability objects, not service-locator lookups.
- Domain and application code must not read superglobals, environment variables, current time, random bytes, global mutable state, or static process state.
- Time, IDs, actor identity, request identity, and randomness enter as values or injected interfaces.
- Domain state contains serializable facts, never PDO handles, streams, ImageMagick objects, locks, sessions, promises, or provider clients.
- Application services describe and sequence intent. HTTP, database, filesystem, image, AI, mail, and deployment behavior ends at adapters.

```php
// Good: deterministic service and replaceable effects.
final class PublishService
{
    public function __construct(
        private readonly ReleaseRepository $releases,
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
    ) {}
}

// Bad: hidden environment and nondeterministic state.
function publish(): void
{
    $pdo = new PDO((string) getenv('CMS_DSN'));
    $id = bin2hex(random_bytes(16));
    $createdAt = new DateTimeImmutable();
}
```

## Failures and transactions

Expected validation, authorization, conflict, capability, and operational outcomes use typed results or named domain exceptions. HTTP adapters project them into stable safe responses. Unexpected exceptions are logged by class and correlation ID after redaction; raw messages, SQL, paths, provider bodies, and submitted values do not cross the trust boundary.

PDO access must use prepared statements, exception mode, native prepares, explicit transactions, and checked compare-and-set results. The owner of a transaction commits or rolls it back in one visible scope. Do not perform network, mail, image, or public-filesystem effects inside a database transaction. Idempotency keys and unique constraints protect retried writes.

```php
$pdo->beginTransaction();
try {
    $statement = $pdo->prepare(
        'UPDATE cms_records SET revision = :next WHERE id = :id AND revision = :expected',
    );
    $statement->execute(['next' => $next, 'id' => $id, 'expected' => $expected]);
    if ($statement->rowCount() !== 1) {
        throw new RevisionConflict();
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}
```

Never interpolate untrusted SQL identifiers or values. Dynamic identifiers require an allowlist selected before SQL construction.

## Output and rendering

One reviewed helper owns each output context:

- HTML text uses the text escaper;
- HTML attributes use the attribute escaper;
- application URLs use the URL/path policy before attribute escaping;
- JSON uses `json_encode` with throwing errors and the correct response type;
- rich text is an allowlisted AST rendered into a distinct trusted-fragment type.

Templates may not echo raw values. A value escaped for one context is not safe in another. Never accept editor-supplied HTML, PHP, CSS, JavaScript, URL schemes, or event attributes as trusted markup.

## Administration translations

Interface text uses ICU MessageFormat catalogs under
`engine/src/cms/conventional/i18n/messages`, one PHP array per language and area.
Pass `Translator` explicitly through `AdminChrome`; do not use a global locale.
Domain validation returns `Message` keys and parameters. `LocalizedFailure`
retains an English rendering for logs.

Browser islands receive `client.*` patterns through the server-rendered
`data-messages` attribute and use `client/messages.js`. Every catalog must match
the English keys, parameters and plural categories. The PHP catalog tests reject
missing, unused or mismatched entries. Profile field labels remain profile data.

The interface language follows the account preference, then a bounded
`Accept-Language` header, then English. It is independent of content languages:
set the document's `lang` to the interface language and each content panel's
`lang` to the language being edited.

## Resources and cleanup

The function or object that acquires a resource owns its release. Streams, locks, leases, temporary paths, image objects, transactions, and error handlers require visible `finally` cleanup. Ownership transfer must be explicit in the interface. Cleanup failure is either checked and surfaced or deliberately recorded as a bounded diagnostic.

PHP's error-control operator is forbidden in the engine, profiles, and operator
tools. No suppression exceptions are active. Tests may contain deliberate policy fixtures.

Native operations that can raise expected host warnings use the engine-owned
`HostIo` boundary, loaded by the runtime bootstrap or explicitly by standalone
qualification/operator entrypoints. `attempt()` runs one narrow synchronous
operation, captures native `E_WARNING` occurrences without retaining raw messages,
and returns a typed result containing the value (or `false`) and warning count.
The previous handler is restored in `finally`, including nested scopes and
unexpected exceptions. Other PHP error kinds are not reclassified as I/O failures.
Do not use it around domain workflows, suspension, arbitrary provider code, or
an entire request. A caller must check the native result against its contract:
zero bytes, a short write, an unavailable read, and a permission failure are not
interchangeable success states.

`createPrivateFile()` owns exclusive file creation, checked permissions before
any secret bytes are written, exact byte counts, flushing, stream closure, and
removal of its own partial file on failure. Installation/configuration writers
must not report success after a short write or retain a broadly-readable file
when securing its permissions failed.

`required()` is for boolean operations such as securing a private file or
directory: false or a warning raises the stable redacted `HostIoFailure`.
`cleanup()` is best effort, returns a boolean, and records one fixed
`cleanup_failed` system-log event on failure without replacing the primary
exception. Optional cleanup of an already-moved file must first check whether
the owned path remains. Required destruction must additionally verify absence
and fail the owning operation if residue remains. No cleanup may follow symlinks
or escape its established owned root.

`PrivateDiagnosticLog` writes already-redacted entries only, uses owner-only
permissions before appending, refuses symbolic-link destinations, and uses a
nonblocking lock. Failed logging records only `diagnostic_write_failed` through
the system-log fallback. A broken fallback cannot recurse, expose the configured
path or payload, or prevent an error response. The fallback is best effort, not
a claim that a broken host log provides durable observability.

External programs, when an accepted capability needs them, receive argument arrays, bounded paths, timeouts, checked exit status, controlled environment, and redacted diagnostics. Content-derived shell strings are forbidden.

## Required proof

Every change includes the cheapest proof of its invariant: pure tests for domain rules, adapter tests for effects, hostile-path tests for boundaries, and transaction tests for concurrency. Run:

```text
nix develop -c just check-php
```

The root gate also checks strict types, suppressions, formatting, level-6 analysis, templates, unit behavior, bundle construction, and MariaDB integration.
