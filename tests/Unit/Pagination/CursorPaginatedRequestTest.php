<?php

namespace Tests\Unit\Pagination;

use App\Http\Requests\Api\CursorPaginatedRequest;
use App\Http\Requests\Courses\ListCoursesRequest;
use App\Http\Requests\Flashcards\ListCardsRequest;
use App\Http\Requests\Flashcards\ListDeckCardsRequest;
use App\Http\Requests\Flashcards\ListDecksRequest;
use App\Http\Requests\Flashcards\ListDueCardsRequest;
use App\Http\Requests\Flashcards\ListNewCardsRequest;
use App\Http\Requests\Media\ListMediaAssetsRequest;
use App\Http\Requests\Reviews\ListCardReviewEventsRequest;
use App\Http\Requests\Reviews\ListReviewEventsRequest;
use App\Http\Requests\Study\ListStudyImportJobsRequest;
use App\Support\Pagination\CursorPagination;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CursorPaginatedRequestTest extends TestCase
{
    public function test_it_exposes_cursor_page_size(): void
    {
        $request = $this->validatedRequest(['per_page' => 25]);

        $this->assertSame(25, $request->pageSize()->value());
    }

    public function test_it_uses_the_default_page_size_when_per_page_is_missing(): void
    {
        $request = $this->validatedRequest();

        $this->assertSame(CursorPagination::DEFAULT_PAGE_SIZE, $request->pageSize()->value());
    }

    public function test_it_uses_endpoint_max_when_default_page_size_exceeds_the_endpoint_cap(): void
    {
        $request = $this->validatedRequest(maxPerPage: 10);

        $this->assertSame(10, $request->pageSize()->value());
    }

    public function test_it_reads_the_validated_page_size_instead_of_raw_input(): void
    {
        $request = $this->validatedRequest(['per_page' => 25]);
        $request->merge(['per_page' => 10]);

        $this->assertSame(25, $request->pageSize()->value());
    }

    public function test_it_rejects_indexed_array_page_sizes(): void
    {
        $request = $this->validatedRequest(['per_page' => [10]]);

        $this->expectException(ValidationException::class);

        $request->pageSize();
    }

    #[DataProvider('cursorPaginatedRequestClasses')]
    public function test_cursor_paginated_requests_reject_blank_page_sizes_after_trimming(string $requestClass, array $_cursorParameters): void
    {
        $request = new $requestClass;
        $request->merge(['per_page' => ' ']);

        $this->runPrepareForValidation($request);
        $request->setValidator($this->validatorFor($request));

        try {
            $request->pageSize();
            $this->fail('Expected validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('per_page', $exception->errors());
        }
    }

    public function test_it_accepts_valid_laravel_cursor_tokens(): void
    {
        $cursor = (new Cursor(['id' => '01ktt2q9z5vfpxsqgc3mwrdh35']))->encode();
        $request = $this->validatedRequest(['cursor' => $cursor]);

        $this->assertSame($cursor, $request->validated('cursor'));
    }

    public function test_it_rejects_malformed_cursor_tokens(): void
    {
        $request = $this->validatedRequest(['cursor' => 'not-a-cursor']);

        $this->expectException(ValidationException::class);

        $request->validated();
    }

    public function test_it_rejects_cursor_tokens_without_pagination_parameters(): void
    {
        $cursor = (new Cursor([]))->encode();
        $request = $this->validatedRequest(['cursor' => $cursor]);

        $this->expectException(ValidationException::class);

        $request->validated();
    }

    public function test_it_rejects_cursor_tokens_missing_endpoint_parameters(): void
    {
        $cursor = (new Cursor(['id' => '01ktt2q9z5vfpxsqgc3mwrdh35']))->encode();
        $request = new ListStudyImportJobsRequest;
        $request->merge(['cursor' => $cursor]);
        $request->setValidator($this->validatorFor($request));

        $this->expectException(ValidationException::class);

        $request->validated();
    }

    public function test_it_rejects_cursor_tokens_from_differently_shaped_endpoints(): void
    {
        $cursor = (new Cursor([
            'cards.due_at' => '2026-06-11 12:34:56',
            'cards.id' => '01ktt2q9z5vfpxsqgc3mwrdh35',
        ]))->encode();
        $request = new ListNewCardsRequest;
        $request->merge(['cursor' => $cursor]);
        $request->setValidator($this->validatorFor($request));

        $this->expectException(ValidationException::class);

        $request->validated();
    }

    public function test_it_rejects_array_cursor_tokens(): void
    {
        $request = $this->validatedRequest(['cursor' => ['not-a-cursor']]);

        $this->expectException(ValidationException::class);

        $request->validated();
    }

    #[DataProvider('cursorPaginatedRequestClasses')]
    public function test_cursor_paginated_requests_trim_shared_cursor_inputs(string $requestClass, array $cursorParameters): void
    {
        $cursor = (new Cursor($cursorParameters))->encode();
        $request = new $requestClass;

        $request->merge([
            'cursor' => ' '.$cursor.' ',
            'per_page' => ' 2 ',
        ]);

        $this->runPrepareForValidation($request);
        $request->setValidator($this->validatorFor($request));

        $this->assertSame($cursor, $request->validated('cursor'));
        $this->assertSame(2, $request->pageSize()->value());
    }

    /**
     * @return array<string, array{class-string<CursorPaginatedRequest>, array<string, mixed>}>
     */
    public static function cursorPaginatedRequestClasses(): array
    {
        return [
            'courses' => [ListCoursesRequest::class, self::timestampCursor('updated_at')],
            'cards' => [ListCardsRequest::class, self::timestampCursor('created_at')],
            'deck cards' => [ListDeckCardsRequest::class, self::timestampCursor('created_at')],
            'decks' => [ListDecksRequest::class, self::timestampCursor('created_at')],
            'due cards' => [ListDueCardsRequest::class, self::timestampCursor('cards.due_at', 'cards.id')],
            'new cards' => [ListNewCardsRequest::class, ['cards.new_queue_position' => 10, 'cards.id' => '01ktt2q9z5vfpxsqgc3mwrdh35']],
            'media assets' => [ListMediaAssetsRequest::class, self::timestampCursor('created_at')],
            'card review events' => [ListCardReviewEventsRequest::class, self::timestampCursor('reviewed_at')],
            'review events' => [ListReviewEventsRequest::class, self::timestampCursor('card_review_events.reviewed_at', 'card_review_events.id')],
            'study imports' => [ListStudyImportJobsRequest::class, self::timestampCursor('updated_at')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function timestampCursor(string $timestampKey, string $idKey = 'id'): array
    {
        return [
            $timestampKey => '2026-06-11 12:34:56',
            $idKey => '01ktt2q9z5vfpxsqgc3mwrdh35',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function validatedRequest(array $input = [], int $maxPerPage = CursorPagination::MAX_PAGE_SIZE): CursorPaginatedRequest
    {
        $request = new class($maxPerPage) extends CursorPaginatedRequest
        {
            public function __construct(private readonly int $endpointMaxPageSize)
            {
                parent::__construct();
            }

            protected function maxPerPage(): int
            {
                return $this->endpointMaxPageSize;
            }

            protected function cursorParameters(): array
            {
                // No endpoint-specific parameters: these tests exercise base structural checks only.
                return [];
            }
        };

        $request->merge($input);
        // FormRequest::validated() runs this validator lazily, so invalid input throws instead of exercising clamping.
        $request->setValidator($this->validatorFor($request));

        return $request;
    }

    private function validatorFor(CursorPaginatedRequest $request): \Illuminate\Validation\Validator
    {
        $validator = Validator::make($request->all(), $request->rules());

        foreach ($request->after() as $after) {
            $validator->after($after);
        }

        return $validator;
    }

    private function runPrepareForValidation(CursorPaginatedRequest $request): void
    {
        $method = new \ReflectionMethod($request, 'prepareForValidation');
        $method->invoke($request);
    }
}
