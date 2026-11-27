<?php

namespace App\Domain\FeatureFlags\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class AudienceInput
{
    public const FEATURES = ['flashcardsEnabled', 'readingsEnabled', 'studyTimeEnabled', 'learnEnabled'];

    public static function normalize(array $input): array
    {
        foreach (['userIds', 'groupIds'] as $key) {
            if (isset($input[$key]) && is_array($input[$key])) {
                $input[$key] = array_map(fn ($id) => self::normalizeId($id, $key), $input[$key]);
            }
        }
        if (isset($input['name']) && is_string($input['name'])) {
            $input['name'] = trim($input['name']);
        }

        return $input;
    }

    private static function normalizeId(mixed $id, string $key): mixed
    {
        if ($key === 'userIds' && is_int($id)) {
            return (string) $id;
        }
        if (! is_string($id)) {
            return $id;
        }

        return $key === 'userIds' ? trim($id) : strtolower(trim($id));
    }

    public static function rules(bool $group): array
    {
        $rules = [
            'version' => ['required', 'integer', 'min:0', 'max:2147483646'],
            'userIds' => ['present', 'array', 'list', 'max:1000'],
            'userIds.*' => ['required', 'string', 'regex:/^[1-9][0-9]{0,18}$/', 'distinct', Rule::exists('admin_user_projections', 'user_id')],
        ];
        if ($group) {
            return [...$rules, 'name' => ['required', 'string', 'max:80']];
        }

        return [...$rules,
            'groupIds' => ['present', 'array', 'list', 'max:1000'],
            'groupIds.*' => ['required', 'string', 'uuid', 'distinct', Rule::exists('feature_access_groups', 'id')],
        ];
    }

    public static function validated(array $input, bool $group): array
    {
        return Validator::make(self::normalize($input), self::rules($group))->validate();
    }
}
