<?php

namespace Tests\Unit\Requests;

use App\Domain\Courses\Enums\CourseStatus;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Support\StudyCardDraftCursor;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Http\Requests\Courses\ListCoursesRequest;
use App\Http\Requests\Study\ListStudyBrowserRequest;
use App\Http\Requests\Study\ListStudyCardDraftsRequest;
use App\Http\Requests\Study\ListStudyNewCardQueueRequest;
use App\Http\Requests\Sync\ListSyncFeedEntriesRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomListRequestNormalizationTest extends TestCase
{
    public function test_courses_request_normalizes_shared_cursor_and_course_filters(): void
    {
        $cursor = (new Cursor([
            'updated_at' => '2026-06-11 12:34:56',
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh35',
        ]))->encode();

        $request = $this->validatedRequest(new ListCoursesRequest, [
            'cursor' => ' '.$cursor.' ',
            'per_page' => ' +2 ',
            'status' => ' READY ',
            'native_language' => ' EN ',
            'target_language' => ' JA ',
        ]);

        $this->assertSame($cursor, $request->validated('cursor'));
        $this->assertSame(2, $request->pageSize()->value());
        $this->assertSame(CourseStatus::Ready, $request->status());
        $this->assertSame('en', $request->nativeLanguage());
        $this->assertSame('ja', $request->targetLanguage());
    }

    public function test_courses_request_preserves_invalid_array_shapes_for_validation(): void
    {
        $request = $this->requestWithValidator(new ListCoursesRequest, [
            'cursor' => ['not-a-cursor'],
            'per_page' => ['2'],
            'status' => ['ready'],
            'native_language' => ['en'],
            'target_language' => ['ja'],
        ]);

        try {
            $request->validated();
            $this->fail('Expected validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertValidationErrorKeys(
                ['cursor', 'per_page', 'status', 'native_language', 'target_language'],
                $exception
            );
        }
    }

    public function test_sync_feed_request_normalizes_metadata_and_signed_pagination_inputs(): void
    {
        $request = $this->validatedRequest(new ListSyncFeedEntriesRequest, [
            'after_checkpoint' => ' +7 ',
            'per_page' => ' +2 ',
            'domain' => ' FlashCards ',
            'resource_type' => ' Card ',
            'resource_id' => ' Card-1 ',
            'operation' => ' DELETE ',
        ]);

        $this->assertSame(7, $request->afterCheckpoint());
        $this->assertSame(2, $request->pageSize()->value());
        $this->assertSame('flashcards', $request->domain());
        $this->assertSame('card', $request->resourceType());
        $this->assertSame('card-1', $request->resourceId());
        $this->assertSame(SyncFeedOperation::Delete->value, $request->operation());
    }

    public function test_sync_feed_request_preserves_invalid_array_shapes_for_validation(): void
    {
        $request = $this->requestWithValidator(new ListSyncFeedEntriesRequest, [
            'after_checkpoint' => ['1'],
            'per_page' => ['2'],
            'domain' => ['flashcards'],
            'resource_type' => ['card'],
            'resource_id' => ['card-1'],
            'operation' => ['delete'],
        ]);

        try {
            $request->validated();
            $this->fail('Expected validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertValidationErrorKeys(
                ['after_checkpoint', 'domain', 'resource_type', 'resource_id', 'operation', 'per_page'],
                $exception
            );
        }
    }

    public function test_study_browser_request_normalizes_query_inputs(): void
    {
        $cursor = $this->studyBrowserCursor(2);
        $courseId = (string) Str::ulid();
        $deckId = (string) Str::ulid();

        $request = $this->validatedRequest(new ListStudyBrowserRequest, [
            'q' => ' company ',
            'noteType' => ' Japanese ',
            'cardType' => ' RECOGNITION ',
            'queueState' => ' REVIEW ',
            'sortField' => ' CREATED_ON ',
            'sortDirection' => ' DESC ',
            'cursor' => ' '.$cursor.' ',
            'limit' => ' +3 ',
            'courseId' => ' '.strtoupper($courseId).' ',
            'deckId' => ' '.strtoupper($deckId).' ',
        ]);

        $this->assertSame('company', $request->searchQuery());
        $this->assertSame('Japanese', $request->noteType());
        $this->assertSame('recognition', $request->cardType());
        $this->assertSame(CardStudyStatus::Review->value, $request->queueState());
        $this->assertSame('created_on', $request->sortField());
        $this->assertSame('desc', $request->sortDirection());
        $this->assertSame($cursor, $request->cursor());
        $this->assertSame(3, $request->limit());
        $this->assertSame(strtolower($courseId), $request->courseId());
        $this->assertSame(strtolower($deckId), $request->deckId());
    }

    public function test_study_browser_request_preserves_invalid_array_shapes_for_validation(): void
    {
        $request = $this->requestWithValidator(new ListStudyBrowserRequest, [
            'q' => ['company'],
            'noteType' => ['Japanese'],
            'cardType' => ['recognition'],
            'queueState' => ['review'],
            'sortField' => ['created_on'],
            'sortDirection' => ['desc'],
            'cursor' => ['not-a-cursor'],
            'limit' => ['1'],
            'courseId' => ['01ktt2q9z5vfpxsqgc3mwrdh35'],
            'deckId' => ['01ktt2q9z5vfpxsqgc3mwrdh35'],
        ]);

        try {
            $request->validated();
            $this->fail('Expected validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertValidationErrorKeys(
                ['q', 'noteType', 'cardType', 'queueState', 'sortField', 'sortDirection', 'cursor', 'limit', 'courseId', 'deckId'],
                $exception
            );
        }
    }

    public function test_study_browser_request_rejects_blank_numeric_inputs_after_trimming(): void
    {
        $request = $this->requestWithValidator(new ListStudyBrowserRequest, [
            'limit' => ' ',
            'courseId' => ' ',
            'deckId' => ' ',
        ]);

        try {
            $request->validated();
            $this->fail('Expected validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertValidationErrorKeys(['limit', 'courseId', 'deckId'], $exception);
        }
    }

    public function test_study_card_drafts_request_normalizes_query_inputs(): void
    {
        $cursor = $this->studyCardDraftCursor();

        $request = $this->validatedRequest(new ListStudyCardDraftsRequest, [
            'cursor' => ' '.$cursor.' ',
            'limit' => ' +4 ',
        ]);

        $this->assertSame($cursor, $request->cursor());
        $this->assertSame(4, $request->limit());
    }

    public function test_study_card_drafts_request_preserves_invalid_array_shapes_for_validation(): void
    {
        $request = $this->requestWithValidator(new ListStudyCardDraftsRequest, [
            'cursor' => ['not-a-cursor'],
            'limit' => ['1'],
        ]);

        try {
            $request->validated();
            $this->fail('Expected validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertValidationErrorKeys(['cursor', 'limit'], $exception);
        }
    }

    public function test_study_card_drafts_request_rejects_blank_numeric_inputs_after_trimming(): void
    {
        $request = $this->requestWithValidator(new ListStudyCardDraftsRequest, [
            'limit' => ' ',
        ]);

        try {
            $request->validated();
            $this->fail('Expected validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertValidationErrorKeys(['limit'], $exception);
        }
    }

    public function test_study_new_card_queue_request_normalizes_query_inputs(): void
    {
        $courseId = (string) Str::ulid();
        $deckId = (string) Str::ulid();

        $request = $this->validatedRequest(new ListStudyNewCardQueueRequest, [
            'cursor' => ' +5 ',
            'limit' => ' +6 ',
            'q' => '  ',
            'courseId' => ' '.strtoupper($courseId).' ',
            'deckId' => ' '.strtoupper($deckId).' ',
        ]);

        $this->assertSame(5, $request->cursor());
        $this->assertSame(6, $request->limit());
        $this->assertNull($request->q());
        $this->assertSame(strtolower($courseId), $request->courseId());
        $this->assertSame(strtolower($deckId), $request->deckId());
    }

    public function test_study_new_card_queue_request_preserves_invalid_array_shapes_for_validation(): void
    {
        $request = $this->requestWithValidator(new ListStudyNewCardQueueRequest, [
            'cursor' => ['1'],
            'limit' => ['2'],
            'q' => ['company'],
            'courseId' => ['01ktt2q9z5vfpxsqgc3mwrdh35'],
            'deckId' => ['01ktt2q9z5vfpxsqgc3mwrdh35'],
        ]);

        try {
            $request->validated();
            $this->fail('Expected validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertValidationErrorKeys(['cursor', 'limit', 'q', 'courseId', 'deckId'], $exception);
        }
    }

    public function test_study_new_card_queue_request_rejects_blank_numeric_inputs_after_trimming(): void
    {
        $request = $this->requestWithValidator(new ListStudyNewCardQueueRequest, [
            'cursor' => ' ',
            'limit' => ' ',
            'courseId' => ' ',
            'deckId' => ' ',
        ]);

        try {
            $request->validated();
            $this->fail('Expected validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertValidationErrorKeys(['cursor', 'limit', 'courseId', 'deckId'], $exception);
        }
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertValidationErrorKeys(array $expected, ValidationException $exception): void
    {
        $this->assertEqualsCanonicalizing($expected, array_keys($exception->errors()));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function validatedRequest(FormRequest $request, array $input): FormRequest
    {
        $request = $this->requestWithValidator($request, $input);
        $request->validated();

        return $request;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function requestWithValidator(FormRequest $request, array $input): FormRequest
    {
        $request->merge($input);
        $this->runPrepareForValidation($request);

        $validator = Validator::make($request->all(), $request->rules());

        if (method_exists($request, 'after')) {
            foreach ($request->after() as $after) {
                $validator->after($after);
            }
        }

        $request->setValidator($validator);

        return $request;
    }

    private function runPrepareForValidation(FormRequest $request): void
    {
        $method = new \ReflectionMethod($request, 'prepareForValidation');
        $method->invoke($request);
    }

    private function studyBrowserCursor(int $offset): string
    {
        return rtrim(strtr(base64_encode(json_encode([
            'offset' => $offset,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function studyCardDraftCursor(): string
    {
        $draft = new StudyCardDraft;
        $draft->id = (string) Str::ulid();
        $draft->created_at = CarbonImmutable::parse('2026-06-11 12:34:56', 'UTC');

        return StudyCardDraftCursor::encode($draft);
    }
}
