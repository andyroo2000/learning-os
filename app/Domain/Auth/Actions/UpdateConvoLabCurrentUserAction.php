<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\Auth\Data\UpdateConvoLabProfileData;
use App\Domain\Auth\Support\ConvoLabAccountSource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UpdateConvoLabCurrentUserAction
{
    public function __construct(private readonly CopyConvoLabSampleContentAction $copySampleContent) {}

    public function handle(string $convoLabUserId, UpdateConvoLabProfileData $data): AdminUserProjection
    {
        $convoLabUserId = Str::lower(trim($convoLabUserId));
        if (! Str::isUuid($convoLabUserId)) {
            throw (new ModelNotFoundException)->setModel(AdminUserProjection::class);
        }
        if ($data->attributes === []) {
            throw new \InvalidArgumentException('At least one profile field is required.');
        }

        return DB::transaction(function () use ($convoLabUserId, $data): AdminUserProjection {
            $account = AdminUserProjection::query()
                ->where('convolab_id', $convoLabUserId)
                ->lockForUpdate()
                ->firstOrFail();
            $completeOnboarding = ! $account->onboarding_completed
                && ($data->attributes['onboarding_completed'] ?? false) === true;

            foreach ($data->attributes as $column => $value) {
                $account->setAttribute($column, $value);
            }
            $account->source_system = ConvoLabAccountSource::LEARNING_OS;
            $account->updated_at = now();
            $account->save();

            if ($completeOnboarding) {
                $this->copySampleContent->handle($account);
            }

            return $account->refresh();
        });
    }
}
