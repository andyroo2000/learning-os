<?php

namespace App\Domain\Flashcards\Exceptions;

use RuntimeException;

final class LearningPathConflictException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function sameCard(): self
    {
        return new self(
            'A card cannot unlock itself.',
            'learning_path_same_card',
        );
    }

    public static function invalidPredecessorMetadata(): self
    {
        return new self(
            'The predecessor card has incomplete learning path metadata.',
            'learning_path_invalid_predecessor',
        );
    }

    public static function invalidFamilyMetadata(): self
    {
        return new self(
            'The existing learning path has incomplete stage metadata.',
            'learning_path_invalid_family',
        );
    }

    public static function predecessorIsNotTail(): self
    {
        return new self(
            'New successors can only be added after the final stage of a learning path.',
            'learning_path_predecessor_not_tail',
        );
    }

    public static function successorAlreadyLinked(): self
    {
        return new self(
            'The successor card already belongs to another learning path or stage.',
            'learning_path_successor_already_linked',
        );
    }

    public static function unlockRequirementMismatch(): self
    {
        return new self(
            'The successor card is already linked with a different unlock requirement.',
            'learning_path_unlock_requirement_mismatch',
        );
    }

    public static function stageLimitReached(): self
    {
        return new self(
            'The learning path cannot contain another stage.',
            'learning_path_stage_limit',
        );
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
