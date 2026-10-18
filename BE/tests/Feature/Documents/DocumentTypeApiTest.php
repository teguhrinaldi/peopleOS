<?php

namespace Tests\Feature\Documents;

use App\Models\Activity;
use App\Models\DocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DocumentFixtures;
use Tests\TestCase;

class DocumentTypeApiTest extends TestCase
{
    use DocumentFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_default_catalogue_is_seeded_and_listed(): void
    {
        [$employee] = $this->makeStaff('employee');

        $res = $this->actingAsApi($employee)->getJson('/api/v1/document-types')->assertOk();
        $this->assertSame(
            ['EMPLOYMENT_CONTRACT', 'IDENTIFICATION', 'CERTIFICATE', 'PAYSLIP', 'COMPANY_DOCUMENT', 'OTHER'],
            collect($res->json('data'))->pluck('code')->all(),
        );
        $res->assertJsonMissingPath('data.0.is_active'); // configuration details are for managers only

        $this->actingAsApi($this->hr)->getJson('/api/v1/document-types')->assertJsonPath('data.1.requires_expiry', true)
            ->assertJsonPath('data.4.allow_company_documents', true)->assertJsonPath('data.5.default_visible_to_employee', false);
    }

    public function test_hr_creates_updates_and_retires_a_type(): void
    {
        $this->actingAsApi($this->hr);

        $id = $this->postJson('/api/v1/document-types', ['name' => 'Work Permit', 'requires_expiry' => true])
            ->assertCreated()->assertJsonPath('data.code', 'WORK_PERMIT')->assertJsonPath('data.requires_expiry', true)
            ->json('data.id');
        $this->postJson('/api/v1/document-types', ['name' => 'Work permit'])->assertStatus(409)->assertJsonPath('error.code', 'DOCUMENT_TYPE_CODE_TAKEN');

        $this->patchJson("/api/v1/document-types/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->patchJson("/api/v1/document-types/{$id}", ['allow_employee_documents' => false, 'allow_company_documents' => false])->assertUnprocessable();
        $this->patchJson("/api/v1/document-types/{$id}", ['code' => 'X'])->assertUnprocessable();

        $this->assertNotContains('WORK_PERMIT', collect($this->getJson('/api/v1/document-types')->json('data'))->pluck('code'));
        $this->assertContains('WORK_PERMIT', collect($this->getJson('/api/v1/document-types?include_inactive=1')->json('data'))->pluck('code'));
        $this->assertSame(2, Activity::query()->where('entity_type', DocumentType::class)->count());
    }

    public function test_non_managers_cannot_change_types(): void
    {
        [$employee] = $this->makeStaff('employee');
        [$manager] = $this->makeStaff('manager');
        $id = $this->type('OTHER')->id;

        foreach ([$employee, $manager] as $user) {
            $this->actingAsApi($user)->postJson('/api/v1/document-types', ['name' => 'X'])->assertForbidden();
            $this->patchJson("/api/v1/document-types/{$id}", ['name' => 'X'])->assertForbidden();
        }
        $this->assertSame('Other', $this->type('OTHER')->name);
    }
}
