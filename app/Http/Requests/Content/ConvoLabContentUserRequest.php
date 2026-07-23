<?php

namespace App\Http\Requests\Content;

use App\Http\Support\ConvoLabContentIdentity;
use App\Http\Support\ConvoLabContentIdentityResolver;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

abstract class ConvoLabContentUserRequest extends FormRequest
{
    private const ACTOR_ATTRIBUTE = 'convolab.content_actor';

    private const EFFECTIVE_ATTRIBUTE = 'convolab.content_effective';

    protected function prepareForValidation(): void
    {
        $actor = app(ConvoLabContentIdentityResolver::class)->actor(
            $this,
            $this->requiresActorRole() || $this->query->has('viewAs'),
        );
        $this->attributes->set(self::ACTOR_ATTRIBUTE, $actor);

        $this->merge([
            'convolabUserId' => $actor->convoLabUserId,
        ]);
    }

    protected function passedValidation(): void
    {
        $identity = app(ConvoLabContentIdentityResolver::class)->effective(
            $this,
            $this->actorIdentity(),
            $this->validated('viewAs'),
        );
        $this->attributes->set(self::EFFECTIVE_ATTRIBUTE, $identity);
    }

    public function authorize(): bool
    {
        return true;
    }

    protected function requiresActorRole(): bool
    {
        return false;
    }

    /** @return array<string, list<string>> */
    protected function convoLabUserIdRules(): array
    {
        return [
            'convolabUserId' => ['required', 'uuid'],
            'viewAs' => ['sometimes', 'string', 'uuid'],
        ];
    }

    public function convoLabUserId(): string
    {
        return $this->effectiveIdentity()->convoLabUserId
            ?? throw new LogicException('Effective Convo Lab user ID must be set.');
    }

    public function contentUserId(): int
    {
        return $this->effectiveIdentity()->userId;
    }

    protected function actorIdentity(): ConvoLabContentIdentity
    {
        $identity = $this->attributes->get(self::ACTOR_ATTRIBUTE);

        if (! $identity instanceof ConvoLabContentIdentity) {
            throw new LogicException('Convo Lab content actor must be resolved before validation.');
        }

        return $identity;
    }

    private function effectiveIdentity(): ConvoLabContentIdentity
    {
        $identity = $this->attributes->get(self::EFFECTIVE_ATTRIBUTE);

        if (! $identity instanceof ConvoLabContentIdentity) {
            throw new LogicException('Convo Lab content identity must be resolved after validation.');
        }

        return $identity;
    }
}
