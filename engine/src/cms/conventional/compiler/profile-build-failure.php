<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Compiler;

/** Finite editor reasons; technical exception text never becomes an editor message. */
enum ProfileBuildReason: string
{
    case MissingSetting = 'missing_setting';
    case RequiredValue = 'required_value';
    case MissingTranslation = 'missing_translation';
    case MissingWording = 'missing_wording';
    case Relationship = 'relationship';
    case SharingImage = 'sharing_image';
    case DuplicateRoute = 'duplicate_route';
    case InvalidRoute = 'invalid_route';
    case InvalidContent = 'invalid_content';
    case ProfileError = 'profile_error';
}

final class ProfileBuildFailures extends \InvalidArgumentException
{
    /** @param non-empty-list<ProfileBuildFailure> $failures */
    public function __construct(public readonly array $failures)
    {
        if (!array_is_list($failures) || $failures === [] || count($failures) > 100) {
            throw new \InvalidArgumentException('Profile build failure list is invalid');
        }
        foreach ($failures as $failure) {
            if (!$failure instanceof ProfileBuildFailure) {
                throw new \InvalidArgumentException('Profile build cause is invalid');
            }
        }
        parent::__construct('Profile content has multiple build failures', previous: $failures[0]);
    }
}

final class ProfileBuildFailure extends \InvalidArgumentException
{
    public function __construct(
        public readonly ProfileBuildReason $reason,
        public readonly ?string $recordId = null,
        public readonly ?string $typeId = null,
        public readonly ?string $route = null,
        public readonly ?string $fieldId = null,
        public readonly ?string $locale = null,
        ?\Throwable $previous = null,
    ) {
        if (($route !== null && !CompiledRoute::validRoute($route))
            || ($recordId !== null && preg_match('/\A[a-f0-9]{32}\z/', $recordId) !== 1)
            || ($typeId !== null && preg_match('/\A[a-z][a-z0-9_-]{0,159}\z/', $typeId) !== 1)
            || ($fieldId !== null && strlen($fieldId) > 500)
            || ($locale !== null && preg_match('/\A(?:[a-z]{2,3}(?:-[A-Z]{2})?)?\z/', $locale) !== 1)) {
            throw new \InvalidArgumentException('Profile build context is invalid');
        }
        parent::__construct('Profile build: ' . $reason->value, previous: $previous);
    }

    /** @template T
     * @param \Closure(): T $build
     * @return T
     */
    public static function atRoute(string $route, \Closure $build): mixed
    {
        try {
            return $build();
        } catch (ProfileBuildFailure|ProfileBuildFailures $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new self(ProfileBuildReason::ProfileError, route: $route, previous: $error);
        }
    }

    /** @template T
     * @param \Closure(): T $build
     * @return T
     */
    public static function atRecord(FrozenRecord $record, \Closure $build): mixed
    {
        try {
            return $build();
        } catch (ProfileBuildFailure|ProfileBuildFailures $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new self(ProfileBuildReason::ProfileError, $record->id, $record->typeId, previous: $error);
        }
    }
}
