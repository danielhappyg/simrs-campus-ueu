<?php

namespace App\Modules\Teaching\Enums;

enum DebriefNoteType: string
{
    case FacilitatorSynthesis = 'FACILITATOR_SYNTHESIS';
    case GuidedReflection = 'GUIDED_REFLECTION';
    case FollowUpAction = 'FOLLOW_UP_ACTION';

    public function label(): string
    {
        return match ($this) {
            self::FacilitatorSynthesis => 'Sintesis fasilitator',
            self::GuidedReflection => 'Refleksi terpandu',
            self::FollowUpAction => 'Tindak lanjut pembelajaran',
        };
    }
}
