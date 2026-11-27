<?php

namespace App\Domain\FeatureFlags\Actions;

use App\Domain\FeatureFlags\Services\AudienceMemberships;
use App\Domain\FeatureFlags\Services\FeatureAudienceReader;
use App\Domain\FeatureFlags\Support\AudienceInput;
use Illuminate\Support\Facades\DB;

final class SaveFeatureAudienceAction
{
    public function __construct(private AudienceMemberships $memberships, private FeatureAudienceReader $reader) {}

    public function handle(string $feature, array $input): array
    {
        abort_unless(in_array($feature, AudienceInput::FEATURES, true), 404);
        $data = AudienceInput::validated($input, false);

        return DB::transaction(function () use ($feature, $data): array {
            $row = DB::table('feature_audiences')->where('feature', $feature)->lockForUpdate()->first();
            abort_if($row === null, 404);
            abort_unless((int) $row->version === (int) $data['version'], 409, 'Audience changed. Reload before saving.');
            $this->memberships->replace('feature_audience_users', ['feature' => $feature], 'user_id', $data['userIds']);
            $this->memberships->replace('feature_audience_groups', ['feature' => $feature], 'group_id', $data['groupIds']);
            DB::table('feature_audiences')->where('feature', $feature)->increment('version');

            return $this->reader->audience($feature);
        });
    }
}
