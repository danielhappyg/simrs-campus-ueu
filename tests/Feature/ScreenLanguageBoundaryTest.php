<?php

namespace Tests\Feature;

use App\Http\Middleware\SetScreenLocale;
use App\Models\Encounter;
use App\Models\Patient;
use App\Support\Clinical\OutpatientLifecycleDenial;
use App\Support\ScreenVocabulary;
use App\Support\SimrsSahabatMenuCatalog;
use App\Support\TeachingVocabulary;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ScreenLanguageBoundaryTest extends TestCase
{
    public function test_screen_locale_is_english_but_each_print_route_remains_indonesian(): void
    {
        app()->setLocale('id');
        foreach ([
            'login' => 'en',
            'home' => 'en',
            'pendaftaran.rawat-jalan.index' => 'en',
            'pendaftaran.kunjungan.cetak' => 'id',
            'finance.settlements.receipt' => 'id',
            'finance.settlement-corrections.refund-receipt' => 'id',
            'finance.cashier-collections.handoff-receipt' => 'id',
        ] as $name => $expected) {
            $request = Request::create('/');
            $route = (new Route('GET', '/', fn () => null))->name($name);
            $request->setRouteResolver(fn () => $route);
            (new SetScreenLocale)->handle($request, function () use ($expected): Response {
                $this->assertSame($expected, app()->getLocale());

                return new Response;
            });
            $this->assertSame('id', app()->getLocale());
        }
    }

    public function test_screen_translation_does_not_change_print_labels_or_patient_values(): void
    {
        $patient = new Patient([
            'full_name' => 'PASIEN SINTETIS',
            'sex' => 'female',
            'marital_status' => Patient::MARITAL_KAWIN,
        ]);
        $encounter = new Encounter([
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'payer_type' => Encounter::PAYER_UMUM,
        ]);
        $encounter->setRelation('patient', $patient);
        $before = $patient->getAttributes();

        $this->assertSame('Female', TeachingVocabulary::options(TeachingVocabulary::SEX)[1]['label']);
        $this->assertSame('Self-pay', TeachingVocabulary::options(TeachingVocabulary::PAYER)[0]['label']);
        $labels = TeachingVocabulary::printLabels($encounter);
        $this->assertSame('Perempuan', $labels['sex']);
        $this->assertSame('Kawin', $labels['marital']);
        $this->assertSame('Rawat jalan', $labels['care_setting']);
        $this->assertSame('Umum', $labels['payer']);
        $this->assertSame($before, $patient->getAttributes());
        $this->assertSame('Catatan pasien tidak diubah.', ScreenVocabulary::label('Catatan pasien tidak diubah.'));
    }

    public function test_english_menus_preserve_routing_and_availability(): void
    {
        $menus = SimrsSahabatMenuCatalog::menusFor('pendaftaran');
        $this->assertSame('Inpatient Care', $menus[0]['label']);
        $this->assertSame('/pendaftaran/rawat-inap', $menus[0]['href']);
        $this->assertSame('pendaftaran-rawatinap', $menus[0]['slug']);
        $this->assertSame('live', $menus[0]['status']);
        $this->assertSame(268, array_sum(array_map('count', SimrsSahabatMenuCatalog::menusByCategory())));
    }

    public function test_error_translation_is_only_for_presentation_not_the_original_exception(): void
    {
        $original = 'Catatan final bersifat tetap dan tidak dapat diubah.';
        $denial = new OutpatientLifecycleDenial('final_immutable', $original);
        app()->setLocale('en');
        $this->assertSame('Final notes are read-only and cannot be changed.', __($denial->getMessage()));
        $this->assertSame($original, $denial->getMessage());
        app()->setLocale('id');
        $this->assertSame($original, __($denial->getMessage()));
    }

    public function test_runtime_dictionary_is_valid_and_has_no_duplicate_keys(): void
    {
        $json = file_get_contents(lang_path('en.json'));
        $this->assertNotFalse($json);
        $messages = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($messages);
        preg_match_all('/^    "((?:[^"\\\\]|\\\\.)*)":/m', $json, $keys);
        $this->assertCount(count($keys[1]), $messages, 'Duplicate translation keys are not allowed.');
        foreach ($messages as $source => $translation) {
            $this->assertIsString($translation, (string) $source);
            $this->assertNotSame('', trim($translation), (string) $source);
        }
        app()->setLocale('en');
        $this->assertSame('The prescription cannot be verified.', __('Resep tidak dapat diverifikasi.'));
        $this->assertSame('The bill has changed. Reload before recording payment.', __('Tagihan berubah. Muat ulang sebelum melunasi.'));
        $this->assertSame('Enter why the observation was not obtained.', __('Alasan observasi tidak diperoleh wajib diisi.'));
        $this->assertSame('2 service sources have no charges ready to bill.', __(':count sumber layanan belum memiliki biaya yang dapat diterbitkan.', ['count' => 2]));
        app()->setLocale('id');
        $this->assertSame('2 sumber layanan belum memiliki biaya yang dapat diterbitkan.', __(':count sumber layanan belum memiliki biaya yang dapat diterbitkan.', ['count' => 2]));
    }
}
