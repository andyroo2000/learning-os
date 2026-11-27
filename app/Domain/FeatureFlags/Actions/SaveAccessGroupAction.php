<?php

namespace App\Domain\FeatureFlags\Actions;

use App\Domain\FeatureFlags\Services\AudienceMemberships;
use App\Domain\FeatureFlags\Services\FeatureAudienceReader;
use App\Domain\FeatureFlags\Support\AudienceInput;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SaveAccessGroupAction
{
    public function __construct(private AudienceMemberships $memberships, private FeatureAudienceReader $reader) {}

    public function handle(?string $id, array $input): array
    {
        $data = AudienceInput::validated($input, true);
        if ($id !== null) {
            abort_unless(Str::isUuid($id), 404);
            $id = strtolower($id);
        }
        try {
            return DB::transaction(fn () => $this->save($id, $data));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'A group with this name already exists.']);
        }
    }

    private function save(?string $id, array $data): array
    {
        $values = ['name' => $data['name'], 'name_key' => mb_strtolower($data['name']), 'updated_at' => now()];
        if ($id === null) {
            abort_unless((int) $data['version'] === 0, 409, 'New groups must start at version zero.');
            $id = (string) Str::uuid();
            DB::table('feature_access_groups')->insert(['id' => $id, ...$values, 'version' => 1, 'created_at' => now()]);
        } else {
            $row = DB::table('feature_access_groups')->where('id', $id)->lockForUpdate()->first();
            abort_if($row === null, 404);
            abort_unless((int) $row->version === (int) $data['version'], 409, 'Group changed. Reload before saving.');
            DB::table('feature_access_groups')->where('id', $id)->update([...$values, 'version' => $row->version + 1]);
        }
        $this->memberships->replace('feature_group_members', ['group_id' => $id], 'user_id', $data['userIds']);

        return $this->reader->group($id);
    }
}
