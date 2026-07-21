<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Content\Support\ContentSourceSystem;
use Illuminate\Support\Facades\DB;

class GetAdminStatsAction
{
    /** @return array{users: int, episodes: int, courses: int, inviteCodes: array{total: int, used: int, available: int}} */
    public function handle(): array
    {
        $counts = DB::query()
            ->selectRaw('(SELECT COUNT(*) FROM users WHERE convolab_admin_visible = TRUE) as users')
            ->selectRaw(
                '(SELECT COUNT(*) FROM content_episodes WHERE source_system = ?) as episodes',
                [ContentSourceSystem::CONVOLAB],
            )
            ->selectRaw(
                '(SELECT COUNT(*) FROM content_courses WHERE source_system = ?) as courses',
                [ContentSourceSystem::CONVOLAB],
            )
            ->selectRaw('(SELECT COUNT(*) FROM admin_invite_codes) as invite_total')
            ->selectRaw(
                '(SELECT COUNT(*) FROM admin_invite_codes WHERE convolab_used_by IS NOT NULL) as invite_used',
            )
            ->firstOrFail();
        $totalInvites = (int) $counts->invite_total;
        $usedInvites = (int) $counts->invite_used;

        return [
            'users' => (int) $counts->users,
            'episodes' => (int) $counts->episodes,
            'courses' => (int) $counts->courses,
            'inviteCodes' => [
                'total' => $totalInvites,
                'used' => $usedInvites,
                'available' => $totalInvites - $usedInvites,
            ],
        ];
    }
}
