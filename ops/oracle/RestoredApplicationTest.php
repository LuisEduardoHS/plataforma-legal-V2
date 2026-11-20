<?php

namespace Tests\Deployment;

use App\Models\LexaLegalChunk;
use App\Models\LexaLegalSource;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\Lexa\Contracts\AiProvider;
use App\Services\Lexa\Data\LexaCompletion;
use App\Services\Lexa\Data\LexaPrompt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class RestoredApplicationTest extends TestCase
{
    public function test_seed_or_recover_synthetic_private_data_and_citations(): void
    {
        Http::preventStrayRequests();
        $this->assertSame('lefenda_arm64_test', DB::selectOne('SELECT DATABASE() db')->db);
        $this->assertSame(getenv('DB_SOCKET'), DB::connection()->getConfig('unix_socket'));
        $statePath = getenv('QA_STATE_PATH');
        if (getenv('QA_PHASE') === 'seed') {
            $owner = User::factory()->create(['status' => 'active']);
            $other = User::factory()->create(['status' => 'active']);
            $source = new LexaLegalSource;
            $source->forceFill(['instrument' => 'Norma QA sintética', 'short_name' => 'QA', 'norm_type' => 'federal_law', 'jurisdiction_scope' => 'federal', 'source_name' => 'Cámara de Diputados', 'source_url' => 'https://www.diputados.gob.mx/LeyesBiblio/index.htm', 'published_at' => '2020-01-01', 'valid_from' => '2020-01-01', 'is_verified' => true])->save();
            $text = 'Arrendamiento contrato sintético para prueba documental, no es legislación real.';
            $chunk = new LexaLegalChunk;
            $chunk->forceFill(['lexa_legal_source_id' => $source->id, 'article_ref' => 'Artículo 1', 'content' => $text, 'content_hash' => hash('sha256', $text), 'part' => 1])->save();
            config(['lexa.enabled_generation' => true]);
            $this->app->bind(AiProvider::class, fn () => new class implements AiProvider
            {
                public function isConfigured(): bool
                {
                    return true;
                }

                public function generate(LexaPrompt $prompt): LexaCompletion
                {
                    if (count($prompt->legalSources) !== 1) {
                        throw new \RuntimeException('Synthetic source not retrieved');
                    }

                    return new LexaCompletion('Respuesta sintética QA.', 'fake', 'test', citations: [['chunk_id' => $prompt->legalSources[0]['chunk_id']]]);
                }
            });
            $conversation = $this->actingAs($owner)->postJson('/lexa/conversaciones', ['content' => 'Arrendamiento contrato sintético'])->assertCreated()->json('conversation.id');
            $citation = $this->postJson("/lexa/conversaciones/$conversation/generar", [])->assertCreated()->json('message.citations.0');
            $this->assertSame($source->id, $citation['source_id']);
            $attachment = app(AttachmentService::class)->store($owner, UploadedFile::fake()->createWithContent('synthetic.txt', 'Synthetic private object for restored application.'));
            file_put_contents($statePath, json_encode(['owner' => $owner->id, 'other' => $other->id, 'conversation' => $conversation, 'citation' => $citation, 'attachment' => $attachment->id], JSON_THROW_ON_ERROR));
        }
        $state = json_decode(file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);
        $owner = User::findOrFail($state['owner']);
        $other = User::findOrFail($state['other']);
        $this->actingAs($owner)->get('/lexa')->assertOk();
        $this->assertEquals($state['citation'], $this->getJson('/lexa/conversaciones/'.$state['conversation'].'/mensajes')->assertOk()->json('messages.1.citations.0'));
        $this->actingAs($other)->getJson('/lexa/conversaciones/'.$state['conversation'].'/mensajes')->assertNotFound();
        $download = app(AttachmentService::class)->download($owner, $state['attachment']);
        ob_start();
        $download->sendContent();
        $bytes = ob_get_clean();
        $this->assertSame('Synthetic private object for restored application.', $bytes);
        try {
            app(AttachmentService::class)->download($other, $state['attachment']);
            $this->fail('Foreign attachment disclosed');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->assertSame(1, DB::table('lexa_generations')->where('status', 'completed')->count());
    }
}
