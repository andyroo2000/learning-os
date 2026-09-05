<?php

namespace Tests\Feature\Study\Concerns;

use Illuminate\Testing\TestResponse;

trait MakesStudyImportUploadRequests
{
    private function putImportUpload(
        string $url,
        string $contents,
        ?string $contentType,
        int|string|null $contentLength = null,
    ): TestResponse {
        $server = [
            'HTTP_ACCEPT' => 'application/json',
        ];

        if ($contentType !== null) {
            $server['CONTENT_TYPE'] = $contentType;
        }

        if ($contentLength !== null) {
            $server['CONTENT_LENGTH'] = $contentLength;
        }

        return $this->call('PUT', $url, [], [], [], $server, $contents);
    }
}
