<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Actions\DeleteCurrentUserAction;
use App\Domain\Auth\Exceptions\InvalidCurrentPasswordException;
use App\Domain\Flashcards\Models\Deck;
use App\Models\User;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DeleteCurrentUserActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_the_user_owned_data_and_authentication_artifacts(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'correct-password123',
        ]);
        $otherUser = User::factory()->create(['email' => 'grace@example.com']);
        $ownedDeck = Deck::factory()->for($user)->create();
        $otherDeck = Deck::factory()->for($otherUser)->create();
        $user->createToken('Ada iPhone');
        $otherUser->createToken('Grace iPhone');

        DB::table('sessions')->insert([
            $this->sessionRow('ada-session', $user->id),
            $this->sessionRow('grace-session', $otherUser->id),
        ]);
        DB::table('password_reset_tokens')->insert([
            ['email' => $user->email, 'token' => 'ada-token', 'created_at' => now()],
            ['email' => $otherUser->email, 'token' => 'grace-token', 'created_at' => now()],
        ]);

        app(DeleteCurrentUserAction::class)->handle($user, 'correct-password123');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('decks', ['id' => $ownedDeck->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'ada-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

        $this->assertDatabaseHas('users', ['id' => $otherUser->id]);
        $this->assertDatabaseHas('decks', ['id' => $otherDeck->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $otherUser->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'grace-session']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $otherUser->email]);
    }

    public function test_it_rolls_back_authentication_artifact_cleanup_when_the_user_delete_fails(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'correct-password123',
        ]);
        $user->createToken('Ada iPhone');
        DB::table('sessions')->insert($this->sessionRow('ada-session', $user->id));
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => 'ada-token',
            'created_at' => now(),
        ]);
        $originalDispatcher = User::getEventDispatcher();
        User::setEventDispatcher(new Dispatcher($this->app));

        try {
            User::deleting(function (): never {
                throw new RuntimeException('Delete failed after authentication cleanup.');
            });

            try {
                app(DeleteCurrentUserAction::class)->handle($user, 'correct-password123');

                $this->fail('Expected the user delete to fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Delete failed after authentication cleanup.', $exception->getMessage());
            }

            $this->assertDatabaseHas('users', ['id' => $user->id]);
            $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id]);
            $this->assertDatabaseHas('sessions', ['id' => 'ada-session']);
            $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
        } finally {
            $originalDispatcher === null
                ? User::unsetEventDispatcher()
                : User::setEventDispatcher($originalDispatcher);
        }
    }

    public function test_it_rejects_an_invalid_current_password_without_deleting_anything(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'correct-password123',
        ]);
        $user->createToken('Ada iPhone');
        DB::table('sessions')->insert($this->sessionRow('ada-session', $user->id));
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => 'ada-token',
            'created_at' => now(),
        ]);

        try {
            app(DeleteCurrentUserAction::class)->handle($user, 'wrong-password');

            $this->fail('Expected an invalid current password exception.');
        } catch (InvalidCurrentPasswordException) {
            // The assertions below prove the rejected attempt has no side effects.
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'ada-session']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    /**
     * @return array<string, int|string|null>
     */
    private function sessionRow(string $id, int $userId): array
    {
        return [
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'test',
            'last_activity' => now()->getTimestamp(),
        ];
    }
}
