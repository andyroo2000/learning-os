<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Actions\SaveJournalTutor;
use App\Domain\Journal\Enums\JournalTutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class JournalTutorTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_read_or_change_a_tutor(): void
    {
        $this->getJson('/api/journal/tutor')->assertUnauthorized();
        $this->putJson('/api/journal/tutor', ['tutorId' => 'hana'])->assertUnauthorized();
    }

    public function test_users_choose_a_persistent_tutor_without_changing_narration_preferences(): void
    {
        $user = $this->signIn();
        $this->getJson('/api/journal/tutor')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonFragment(['selectedId' => null])->assertJsonCount(2, 'data.tutors');
        $this->putJson('/api/journal/tutor', ['tutorId' => 'hana'])->assertOk()->assertJsonPath('data.selectedId', 'hana');
        $this->putJson('/api/journal/tutor', ['tutorId' => 'ren'])->assertOk()->assertJsonPath('data.selectedId', 'ren');
        $this->getJson('/api/journal/tutor')->assertOk()->assertJsonPath('data.selectedId', 'ren');
        $this->assertDatabaseHas('journal_tutor_preferences', ['user_id' => $user->id, 'tutor_id' => 'ren']);
        $this->assertDatabaseCount('journal_tutor_preferences', 1);
        $this->assertDatabaseCount('journal_audio_preferences', 0);
        $this->signIn();
        $this->getJson('/api/journal/tutor')->assertOk()->assertJsonFragment(['selectedId' => null]);
    }

    public function test_only_the_selected_tutors_are_accepted_and_account_deletion_removes_the_choice(): void
    {
        $user = $this->signIn();
        $this->putJson('/api/journal/tutor', ['tutorId' => 'unknown'])->assertUnprocessable();
        $this->putJson('/api/journal/tutor', ['tutorId' => null])->assertUnprocessable();
        app(SaveJournalTutor::class)->handle($user->id, ['tutorId' => 'hana']);
        $this->assertSame('hana', DB::table('journal_tutor_preferences')->value('tutor_id'));
        DB::table('users')->where('id', $user->id)->delete();
        $this->assertDatabaseCount('journal_tutor_preferences', 0);
    }

    public function test_direct_calls_reject_invalid_tutors(): void
    {
        $user = $this->signIn();
        $this->expectException(ValidationException::class);
        app(SaveJournalTutor::class)->handle($user->id, ['tutorId' => ['hana']]);
    }

    public function test_tutor_snapshots_use_the_selected_samples_and_are_separate_from_narrators(): void
    {
        $this->assertSame('fishaudio:9639f090aa6346329d7d3aca7e6b7226', JournalTutor::Hana->voiceId());
        $this->assertSame('fishaudio:875668667eb94c20b09856b971d9ca2f', JournalTutor::Ren->voiceId());
        foreach (JournalTutor::cases() as $tutor) {
            $this->assertArrayNotHasKey($tutor->voiceId(), config('journal_voices'));
            $this->assertSame($tutor->presentation(), array_diff_key($tutor->snapshot(), ['voiceId' => true]));
        }
    }
}
