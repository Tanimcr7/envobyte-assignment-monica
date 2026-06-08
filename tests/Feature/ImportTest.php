<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use App\Models\User;
use App\Models\Account;
use App\Models\Contact;
use App\Models\ImportJob;
use App\Models\Vault;
use App\Domains\Contact\ManageImport\Jobs\StartImportJob;
use App\Domains\Contact\ManageImport\Jobs\ProcessImportChunkJob;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Assume Monica's testing structure creates an account and user
        $this->account = Account::factory()->create();
        $this->user = User::factory()->create(['account_id' => $this->account->id]);
        $this->vault = Vault::factory()->create(['account_id' => $this->account->id]);
        $contact = Contact::create([
            'vault_id' => $this->vault->id,
            'first_name' => $this->user->first_name,
            'last_name' => $this->user->last_name,
            'can_be_deleted' => false,
        ]);
        $this->vault->users()->attach($this->user->id, [
            'permission' => Vault::PERMISSION_MANAGE,
            'contact_id' => $contact->id,
        ]);
        $this->actingAs($this->user);
    }

    public function test_import_initiation_and_status_tracking()
    {
        Storage::fake('local');
        Bus::fake();

        $header = "name,email\n";
        $row1 = "John,john@example.com\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $header . $row1);

        $response = $this->postJson('/api/import', [
            'file' => $file,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id', 'filename', 'total_rows', 'processed_rows', 'failed_rows', 'status', 'created_at'
                ]
            ]);

        $this->assertEquals(1, $response->json('data.total_rows'));
        $this->assertEquals('pending', $response->json('data.status'));

        Bus::assertDispatched(StartImportJob::class);

        // Test status tracking GET
        $jobId = $response->json('data.id');
        $statusResponse = $this->getJson("/api/import/{$jobId}");
        
        $statusResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.total_rows', 1);
    }

    public function test_batch_processing_with_per_row_error_handling()
    {
        $job = ImportJob::create([
            'account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'vault_id' => $this->vault->id,
            'filename' => 'test.csv',
            'file_path' => 'dummy',
            'total_rows' => 2,
            'status' => 'processing',
        ]);

        // Simulating a chunk where one is valid and one is invalid
        $chunk = [
            ['rowNum' => 2, 'data' => ['name' => 'Valid User', 'email' => 'valid@test.com']],
            ['rowNum' => 3, 'data' => ['name' => '', 'email' => 'invalid-email']], // Will fail validation
        ];

        $processJob = new ProcessImportChunkJob($job->id, $chunk);
        $processJob->handle();

        $job->refresh();

        $this->assertEquals(2, $job->processed_rows);
        $this->assertEquals(1, $job->failed_rows);
        $this->assertCount(1, $job->errors);
        $this->assertEquals(3, $job->errors[0]['row']);
    }

    public function test_vcard_batch_processing()
    {
        $job = ImportJob::create([
            'account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'vault_id' => $this->vault->id,
            'filename' => 'contacts.vcf',
            'format' => 'vcard',
            'file_path' => 'dummy',
            'total_rows' => 1,
            'status' => 'processing',
        ]);

        $chunk = [
            [
                'rowNum' => 1,
                'type' => 'vcard',
                'data' => "BEGIN:VCARD\nVERSION:3.0\nFN:Jane Doe\nEND:VCARD\n",
            ],
        ];

        $processJob = new ProcessImportChunkJob($job->id, $chunk);
        $processJob->handle();

        $job->refresh();

        $this->assertEquals(1, $job->processed_rows);
        $this->assertEquals(0, $job->failed_rows);
    }

    public function test_import_cancellation()
    {
        $job = ImportJob::create([
            'account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'vault_id' => $this->vault->id,
            'filename' => 'test.csv',
            'file_path' => 'dummy',
            'status' => 'processing',
        ]);

        $response = $this->postJson("/api/import/{$job->id}/cancel");
        $response->assertStatus(200);

        $this->assertEquals('cancelled', $job->refresh()->status);
    }

    public function test_import_list_and_paginated_errors()
    {
        $job = ImportJob::create([
            'account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'vault_id' => $this->vault->id,
            'filename' => 'contacts.csv',
            'file_path' => 'dummy',
            'total_rows' => 2,
            'processed_rows' => 2,
            'failed_rows' => 2,
            'status' => 'completed',
            'errors' => [
                ['row' => 2, 'message' => "Invalid email: 'bad'"],
                ['row' => 3, 'message' => 'Missing required field: name'],
            ],
        ]);

        $this->getJson('/api/import?page=1&per_page=10')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $job->id)
            ->assertJsonPath('data.0.progress_pct', 100)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 1);

        $this->getJson("/api/import/{$job->id}/errors?page=1&per_page=1")
            ->assertStatus(200)
            ->assertJsonPath('data.0.row', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_error_csv_generation()
    {
        Storage::fake('local');
        $filePath = 'imports/test_errors.csv';
        Storage::disk('local')->put($filePath, "name,email\nValid,valid@test.com\nInvalid,bad");

        $job = ImportJob::create([
            'account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'vault_id' => $this->vault->id,
            'filename' => 'contacts.csv',
            'file_path' => $filePath,
            'status' => 'completed',
            'errors' => [
                ['row' => 3, 'message' => "Invalid email: 'bad'"]
            ]
        ]);

        $response = $this->get("/api/import/{$job->id}/errors.csv");
        
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        
        $csvContent = $response->streamedContent();
        $this->assertStringContainsString('error', $csvContent); // Header added
        $this->assertStringContainsString("Invalid email: 'bad'", $csvContent);
    }
}
