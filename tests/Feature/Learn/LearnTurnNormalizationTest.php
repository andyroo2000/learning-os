<?php

namespace Tests\Feature\Learn;

use App\Domain\Learn\Models\LearnSession;
use Illuminate\Support\Str;

class LearnTurnNormalizationTest extends LearnFeatureTestCase
{
    public function test_validated_numeric_versions_and_uuid_case_preserve_retry_identity(): void
    {
        $this->signIn();
        $session = $this->postJson('/api/learn/lessons/quartet-1-rashii/session')->assertOk()->json('data');
        $requestId = (string) Str::uuid();
        $input = ['action' => 'next', 'expectedVersion' => '1', 'requestId' => strtoupper($requestId)];
        $this->postJson('/api/learn/sessions/'.$session['id'].'/turn', $input)
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.state.index', 1);
        $input['requestId'] = $requestId;
        $this->postJson('/api/learn/sessions/'.$session['id'].'/turn', $input)
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.state.index', 1);
        $this->assertSame($requestId, LearnSession::findOrFail($session['id'])->last_request_id);
    }
}
