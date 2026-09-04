<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Data\StagedStudyImportUpload;
use App\Domain\Study\Exceptions\StudyImportValidationException;
use App\Domain\Study\Models\StudyImportJob;
use RuntimeException;
use Throwable;

class StageStudyImportUploadAction
{
    private const STREAM_CHUNK_BYTES = 1024 * 1024;

    /** @param resource|string $contents */
    public function handle(mixed $contents): StagedStudyImportUpload
    {
        $this->assertValidContents($contents);
        $stagedContents = $this->temporaryFile();

        return $this->stageContents($contents, $stagedContents);
    }

    private function assertValidContents(mixed $contents): void
    {
        if (! is_resource($contents) && ! is_string($contents)) {
            throw new RuntimeException('Study import contents must be a stream or string.');
        }
    }

    /** @return resource */
    private function temporaryFile()
    {
        $stagedContents = tmpfile();

        if ($stagedContents === false) {
            throw new RuntimeException('Unable to create temporary storage for the study import upload.');
        }

        return $stagedContents;
    }

    /**
     * @param  resource|string  $contents
     * @param  resource  $stagedContents
     */
    private function stageContents(mixed $contents, $stagedContents): StagedStudyImportUpload
    {
        $actualContentSizeBytes = 0;

        try {
            if (is_string($contents)) {
                $this->appendChunk($stagedContents, $contents, $actualContentSizeBytes);
            } else {
                $this->appendStream($contents, $stagedContents, $actualContentSizeBytes);
            }

            rewind($stagedContents);

            return new StagedStudyImportUpload($stagedContents, $actualContentSizeBytes);
        } catch (Throwable $exception) {
            fclose($stagedContents);

            throw $exception;
        }
    }

    /**
     * @param  resource  $contents
     * @param  resource  $stagedContents
     */
    private function appendStream($contents, $stagedContents, int &$actualContentSizeBytes): void
    {
        while (! feof($contents)) {
            $chunk = $this->readChunk($contents);

            if ($chunk === null) {
                return;
            }

            $this->appendChunk($stagedContents, $chunk, $actualContentSizeBytes);
        }
    }

    /** @param resource $contents */
    private function readChunk($contents): ?string
    {
        $chunk = fread($contents, self::STREAM_CHUNK_BYTES);

        if ($chunk === false) {
            throw new RuntimeException('Unable to read the study import upload stream.');
        }

        if ($chunk !== '') {
            return $chunk;
        }

        if (feof($contents)) {
            return null;
        }

        throw new RuntimeException('Study import upload stream stopped before EOF.');
    }

    /** @param resource $stagedContents */
    private function appendChunk($stagedContents, string $chunk, int &$actualContentSizeBytes): void
    {
        $actualContentSizeBytes += strlen($chunk);

        if ($actualContentSizeBytes > StudyImportJob::MAX_ASYNC_IMPORT_BYTES) {
            throw new StudyImportValidationException('file', 'Study import upload must not exceed '.StudyImportJob::MAX_ASYNC_IMPORT_BYTES.' bytes.');
        }

        $remaining = $chunk;

        while ($remaining !== '') {
            $written = fwrite($stagedContents, $remaining);

            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to stage the study import upload.');
            }

            $remaining = substr($remaining, $written);
        }
    }
}
