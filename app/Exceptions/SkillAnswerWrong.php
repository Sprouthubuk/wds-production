<?php

namespace App\Exceptions;

/** The checkout skill question was answered wrongly (or not at all). Nothing was created and nothing was paid. */
class SkillAnswerWrong extends EntryException
{
    public function __construct(string $message, public readonly int $competitionId)
    {
        parent::__construct($message);
    }

    public function render()
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'skill_wrong', 'competitionId' => $this->competitionId], 422);
    }
}
