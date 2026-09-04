<?php

namespace Tests\Unit;

use App\Models\Encounter;
use App\Models\Patient;
use App\Support\TeachingVocabulary;
use PHPUnit\Framework\TestCase;

class TeachingVocabularyTest extends TestCase
{
    public function test_labels_match_stored_codes_used_on_the_desk(): void
    {
        $this->assertSame('Laki-laki', TeachingVocabulary::label(TeachingVocabulary::SEX, Patient::SEX_LAKI_LAKI));
        $this->assertSame('Lainnya', TeachingVocabulary::label(TeachingVocabulary::SEX, Patient::SEX_LAINNYA));
        $this->assertSame('Kawin', TeachingVocabulary::label(TeachingVocabulary::MARITAL, Patient::MARITAL_KAWIN));
        $this->assertSame('BPJS', TeachingVocabulary::label(TeachingVocabulary::PAYER, Encounter::PAYER_BPJS));
        $this->assertSame('Datang sendiri', TeachingVocabulary::label(TeachingVocabulary::ADMISSION, Encounter::ADMISSION_DATANG_SENDIRI));
        $this->assertSame('Rawat jalan', TeachingVocabulary::label(TeachingVocabulary::CARE_SETTING, Encounter::CARE_SETTING_OUTPATIENT));
        $this->assertSame('—', TeachingVocabulary::label(TeachingVocabulary::RELIGION, null));
        $this->assertSame('UNKNOWN', TeachingVocabulary::label(TeachingVocabulary::PAYER, 'UNKNOWN'));
    }

    public function test_option_lists_preserve_code_values(): void
    {
        $sex = TeachingVocabulary::options(TeachingVocabulary::SEX);

        $this->assertSame(Patient::SEX_LAKI_LAKI, $sex[0]['value']);
        $this->assertSame('Laki-laki', $sex[0]['label']);
        $this->assertSame(['male', 'female', 'other', 'unknown'], array_column($sex, 'value'));
    }
}
