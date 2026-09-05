<?php

namespace Tests\Feature\Study;

use App\Http\Requests\Study\UploadStudyImportFileRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StudyImportUploadRequestValidationTest extends TestCase
{
    public function test_upload_request_rejects_non_digit_content_length_headers(): void
    {
        $this->assertInvalidContentLengthHeader('not-a-number');
    }

    public function test_upload_request_rejects_same_length_content_length_over_native_integer(): void
    {
        $this->assertInvalidContentLengthHeader($this->contentLengthAtNativeIntegerPlusOne());
    }

    public function test_upload_request_rejects_longer_content_length_over_native_integer(): void
    {
        $this->assertInvalidContentLengthHeader('1'.PHP_INT_MAX);
    }

    public function test_upload_request_accepts_native_integer_limit_content_length(): void
    {
        $request = $this->makeUploadRequestWithContentLength((string) PHP_INT_MAX);

        $request->validateResolved();

        $this->assertSame(PHP_INT_MAX, $request->contentSizeBytes());
    }

    public function test_upload_request_accepts_leading_zero_content_length(): void
    {
        $request = $this->makeUploadRequestWithContentLength('007');

        $request->validateResolved();

        $this->assertSame(7, $request->contentSizeBytes());
    }

    private function makeUploadRequestWithContentLength(string $contentLength): UploadStudyImportFileRequest
    {
        $request = UploadStudyImportFileRequest::create(
            '/api/study/imports/'.strtolower((string) Str::ulid()).'/upload',
            'PUT',
            server: ['CONTENT_LENGTH' => $contentLength],
            content: 'anki bytes',
        );

        $request->setContainer($this->app);
        $request->setRedirector($this->app['redirect']);

        return $request;
    }

    private function assertInvalidContentLengthHeader(string $contentLength): void
    {
        $request = $this->makeUploadRequestWithContentLength($contentLength);

        try {
            $request->validateResolved();
            $this->fail('Expected malformed content length to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
            $this->assertSame(
                ['Study import upload content length is invalid.'],
                $exception->errors()['file'],
            );
        }
    }

    private function contentLengthAtNativeIntegerPlusOne(): string
    {
        $digits = str_split((string) PHP_INT_MAX);

        for ($index = count($digits) - 1; $index >= 0; $index--) {
            if ($digits[$index] !== '9') {
                $digits[$index] = (string) ((int) $digits[$index] + 1);

                return implode('', $digits);
            }

            $digits[$index] = '0';
        }

        return '1'.implode('', $digits);
    }
}
