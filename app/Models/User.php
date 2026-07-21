<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Admin\Models\AdminInviteCode;
use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentSourceSystem;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function adminInviteCodes(): HasMany
    {
        return $this->hasMany(AdminInviteCode::class, 'used_by');
    }

    public function adminUserProjection(): HasOne
    {
        return $this->hasOne(AdminUserProjection::class);
    }

    public function convoLabContentEpisodes(): HasMany
    {
        return $this->hasMany(ContentEpisode::class)
            ->where('source_system', ContentSourceSystem::CONVOLAB);
    }

    public function convoLabContentCourses(): HasMany
    {
        return $this->hasMany(ContentCourse::class)
            ->where('source_system', ContentSourceSystem::CONVOLAB);
    }
}
