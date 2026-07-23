<?php

namespace App\Domain\Content\Actions;

use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\Content\Data\ContentGenerationQuotaStatus;
use App\Domain\Content\Enums\ContentGenerationType;
use App\Domain\Content\Exceptions\ContentGenerationCooldownException;
use App\Domain\Content\Exceptions\ContentGenerationQuotaExceededException;
use App\Domain\Content\Models\ContentGenerationCooldown;
use App\Domain\Content\Models\ContentGenerationLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ManageContentGenerationQuotaAction
{
    public function status(string $convoLabUserId): ContentGenerationQuotaStatus
    {
        $now = CarbonImmutable::now('UTC');
        $projection = $this->projection($convoLabUserId);

        return $this->statusFor($projection, $now);
    }

    public function reserve(
        string $convoLabUserId,
        ContentGenerationType $type,
        ?string $contentId = null,
    ): ?ContentGenerationLog {
        return DB::transaction(function () use ($convoLabUserId, $type, $contentId): ?ContentGenerationLog {
            $now = CarbonImmutable::now('UTC');
            // This account row is the shared serialization lock for every generation type.
            // PostgreSQL supplies the row lock; SQLite feature tests cover the transaction order.
            $projection = AdminUserProjection::query()
                ->whereKey($this->normalizeUserId($convoLabUserId))
                ->lockForUpdate()
                ->firstOrFail();

            if ($projection->role === 'admin') {
                return null;
            }

            $status = $this->statusFor($projection, $now);
            if ($status->cooldownRemainingSeconds > 0) {
                throw new ContentGenerationCooldownException(
                    $status->cooldownRemainingSeconds,
                    $now->addSeconds($status->cooldownRemainingSeconds),
                );
            }
            if ($status->remaining === 0) {
                throw new ContentGenerationQuotaExceededException($status);
            }

            $cooldown = ContentGenerationCooldown::query()
                ->whereKey($projection->convolab_id)
                ->first() ?? new ContentGenerationCooldown;
            $cooldown->convolab_user_id = $projection->convolab_id;
            $cooldown->available_at = $now->addSeconds($this->cooldownSeconds());
            $cooldown->save();

            $log = new ContentGenerationLog;
            $log->id = (string) Str::uuid();
            $log->setAttribute('userId', $projection->convolab_id);
            $log->setAttribute('contentType', $type->value);
            $log->setAttribute('contentId', $contentId);
            $log->setAttribute('createdAt', $now);
            $log->save();

            return $log;
        });
    }

    public function complete(?ContentGenerationLog $reservation, ?string $contentId): void
    {
        if ($reservation === null || $contentId === null) {
            return;
        }

        ContentGenerationLog::query()
            ->whereKey($reservation->getKey())
            ->update(['contentId' => $contentId]);
    }

    public function cancel(?ContentGenerationLog $reservation): void
    {
        if ($reservation !== null) {
            ContentGenerationLog::query()->whereKey($reservation->getKey())->delete();
        }
    }

    private function statusFor(
        AdminUserProjection $projection,
        CarbonImmutable $now,
    ): ContentGenerationQuotaStatus {
        $nextMonth = $now->startOfMonth()->addMonth();

        if ($projection->role === 'admin') {
            return new ContentGenerationQuotaStatus(
                unlimited: true,
                used: 0,
                limit: 0,
                remaining: 0,
                resetsAt: $nextMonth,
                cooldownRemainingSeconds: 0,
            );
        }

        $limit = $this->monthlyLimit();
        $used = ContentGenerationLog::query()
            ->where('userId', $projection->convolab_id)
            ->where('createdAt', '>=', $now->startOfMonth())
            ->where('createdAt', '<', $nextMonth)
            ->count();
        $availableAt = ContentGenerationCooldown::query()
            ->whereKey($projection->convolab_id)
            ->value('available_at');
        $cooldownRemaining = $availableAt === null
            ? 0
            : max(0, (int) ceil(
                (CarbonImmutable::parse($availableAt)->getTimestampMs() - $now->getTimestampMs()) / 1000,
            ));

        return new ContentGenerationQuotaStatus(
            unlimited: false,
            used: $used,
            limit: $limit,
            remaining: max(0, $limit - $used),
            resetsAt: $nextMonth,
            cooldownRemainingSeconds: $cooldownRemaining,
        );
    }

    private function projection(string $convoLabUserId): AdminUserProjection
    {
        return AdminUserProjection::query()
            ->whereKey($this->normalizeUserId($convoLabUserId))
            ->firstOrFail();
    }

    private function normalizeUserId(string $convoLabUserId): string
    {
        $normalized = Str::lower(trim($convoLabUserId));

        if (! Str::isUuid($normalized)) {
            return '';
        }

        return $normalized;
    }

    private function monthlyLimit(): int
    {
        $limit = (int) config('content_generation.monthly_limit', 30);

        return $limit > 0 ? $limit : 30;
    }

    private function cooldownSeconds(): int
    {
        $seconds = (int) config('content_generation.cooldown_seconds', 30);

        return $seconds > 0 ? $seconds : 30;
    }
}
