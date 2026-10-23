<?php

namespace Tests\Feature;

use App\Models\ContentCategory;
use App\Models\Municipality;
use App\Models\Tag;
use Database\Seeders\ContentCategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\TagSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SharedCatalogTest extends TestCase
{
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();

        // No RefreshDatabase: usar únicamente MySQL de pruebas y transacciones.
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('plataforma_legal_v2_test', DB::connection()->getDatabaseName());
        $this->assertSame('plataforma_legal_v2_test', DB::selectOne('SELECT DATABASE() AS db')->db);

        DB::beginTransaction();
        $this->transactionStarted = true;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->transactionStarted) {
                DB::rollBack();
            }
        } finally {
            parent::tearDown();
        }
    }

    public static function catalogs(): array
    {
        return [
            'content categories' => [ContentCategory::class, ContentCategorySeeder::class, [
                'preguntas-y-consultas', 'experiencias-legales', 'recomendaciones',
                'noticias-juridicas', 'guias-legales', 'analisis-juridico',
                'recursos-y-documentos', 'debates-de-la-comunidad',
            ]],
            'tags' => [Tag::class, TagSeeder::class, [
                'despido-injustificado', 'arrendamiento', 'pension-alimenticia',
                'contratos', 'divorcio', 'guarda-y-custodia', 'herencias',
                'seguridad-social', 'proteccion-de-datos-personales',
                'derechos-del-consumidor', 'marcas', 'amparo',
            ]],
        ];
    }

    #[DataProvider('catalogs')]
    public function test_seeder_inserts_expected_catalog_in_presentation_order(string $model, string $seeder, array $slugs): void
    {
        $this->seed($seeder);

        $records = $model::whereIn('slug', $slugs)->active()->ordered()->get();

        $this->assertSame($slugs, $records->pluck('slug')->all());
        $this->assertSame(range(1, count($slugs)), $records->pluck('sort_order')->all());

        foreach ($records as $record) {
            $this->assertNotEmpty($record->name);
            $this->assertNotEmpty($record->description);
            $this->assertTrue($record->is_active);
            $this->assertNotNull($record->created_at);
            $this->assertNotNull($record->updated_at);
        }
    }

    #[DataProvider('catalogs')]
    public function test_defaults_and_casts_allow_minimal_insertion(string $model, string $seeder, array $slugs): void
    {
        $record = $model::create(['name' => 'Tema de prueba', 'slug' => 'back-01-defaults'])->fresh();

        $this->assertTrue($record->is_active);
        $this->assertSame(0, $record->sort_order);
        $this->assertNull($record->description);
    }

    #[DataProvider('catalogs')]
    public function test_mysql_rejects_duplicate_slugs(string $model, string $seeder, array $slugs): void
    {
        $model::create(['name' => 'Original', 'slug' => 'back-01-unique']);

        try {
            $model::create(['name' => 'Duplicado', 'slug' => 'back-01-unique']);
            $this->fail('MySQL permitió un slug duplicado.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', (string) $exception->getCode());
            $this->assertSame(1062, (int) $exception->errorInfo[1]);
        }
    }

    #[DataProvider('catalogs')]
    public function test_reseeding_preserves_identifiers_and_creation_timestamps(string $model, string $seeder, array $slugs): void
    {
        $this->seed($seeder);
        $before = $model::orderBy('id')->get(['id', 'slug', 'created_at'])->toArray();

        $this->seed($seeder);

        $this->assertSame($before, $model::orderBy('id')->get(['id', 'slug', 'created_at'])->toArray());
        $this->assertSame(count($slugs), $model::whereIn('slug', $slugs)->count());
    }

    #[DataProvider('catalogs')]
    public function test_seeder_preserves_inactive_records_and_updates_catalog_metadata(string $model, string $seeder, array $slugs): void
    {
        $this->seed($seeder);
        $record = $model::where('slug', $slugs[0])->firstOrFail();
        $original = $record->only(['id', 'name', 'description', 'sort_order']);
        $record->update(['is_active' => false, 'name' => 'Nombre anterior', 'description' => null, 'sort_order' => 99]);

        $this->seed($seeder);

        $this->assertSame($original, $record->fresh()->only(array_keys($original)));
        $this->assertFalse($record->fresh()->is_active);
        $this->assertFalse($model::active()->whereKey($record->id)->exists());
    }

    #[DataProvider('catalogs')]
    public function test_seeder_keeps_custom_records(string $model, string $seeder, array $slugs): void
    {
        $custom = $model::create([
            'name' => 'Tema personalizado', 'slug' => 'back-01-custom',
            'description' => 'Descripción personalizada', 'is_active' => false, 'sort_order' => 50,
        ])->fresh();
        $before = $custom->toArray();

        $this->seed($seeder);
        $this->seed($seeder);

        $this->assertSame($before, $custom->fresh()->toArray());
    }

    #[DataProvider('catalogs')]
    public function test_scopes_combine_and_break_order_ties_deterministically(string $model, string $seeder, array $slugs): void
    {
        $z = $model::create(['name' => 'Zeta', 'slug' => 'back-01-z', 'sort_order' => 10]);
        $a = $model::create(['name' => 'Alfa', 'slug' => 'back-01-a', 'sort_order' => 10]);
        $a2 = $model::create(['name' => 'Alfa', 'slug' => 'back-01-a2', 'sort_order' => 10]);
        $first = $model::create(['name' => 'Primero', 'slug' => 'back-01-first', 'sort_order' => 1]);
        $inactive = $model::create(['name' => 'Inactivo', 'slug' => 'back-01-inactive', 'sort_order' => 0, 'is_active' => false]);
        $ids = [$z->id, $a->id, $a2->id, $first->id, $inactive->id];

        $this->assertSame(
            [$first->id, $a->id, $a2->id, $z->id],
            $model::whereKey($ids)->active()->ordered()->pluck('id')->all()
        );
        $this->assertSame(
            [$inactive->id, $first->id, $a->id, $a2->id, $z->id],
            $model::whereKey($ids)->ordered()->pluck('id')->all()
        );
    }

    public function test_database_seeder_integrates_catalogs_without_affecting_existing_relationships_or_creating_users(): void
    {
        $usersBefore = DB::table('users')->count();
        $this->seed(DatabaseSeeder::class);

        $tables = [
            'countries', 'states', 'municipalities', 'legal_specialties',
            'roles', 'permissions', 'role_has_permissions',
        ];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        $this->seed(ContentCategorySeeder::class);
        $this->seed(TagSeeder::class);

        foreach ($tables as $table) {
            $this->assertEquals($before[$table], DB::table($table)->get()->map(fn ($row) => (array) $row)->all());
        }

        $this->assertSame(8, ContentCategory::count());
        $this->assertSame(12, Tag::count());
        $this->assertSame(20, DB::table('legal_specialties')->count());
        $this->assertSame(32, DB::table('states')->count());
        $this->assertSame(2478, DB::table('municipalities')->count());
        $this->assertSame($usersBefore, DB::table('users')->count());

        $monterrey = Municipality::whereHas('state', fn ($query) => $query->where('code', '19'))
            ->where('code', '039')->firstOrFail();
        $this->assertSame('Nuevo León', $monterrey->state->name);
        $this->assertSame('MX', $monterrey->state->country->iso_code);
    }
}
