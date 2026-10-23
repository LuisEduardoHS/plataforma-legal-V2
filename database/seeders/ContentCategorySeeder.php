<?php

namespace Database\Seeders;

use App\Models\ContentCategory;
use Illuminate\Database\Seeder;

class ContentCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['Preguntas y consultas', 'preguntas-y-consultas', 'Dudas y consultas de la comunidad sobre situaciones legales en México.'],
            ['Experiencias legales', 'experiencias-legales', 'Experiencias personales con trámites, procedimientos y servicios legales.'],
            ['Recomendaciones', 'recomendaciones', 'Recomendaciones de servicios, herramientas y buenas prácticas legales.'],
            ['Noticias jurídicas', 'noticias-juridicas', 'Actualidad legislativa, judicial y del ejercicio del derecho en México.'],
            ['Guías legales', 'guias-legales', 'Orientación práctica para comprender derechos y realizar trámites legales.'],
            ['Análisis jurídico', 'analisis-juridico', 'Estudio de normas, criterios judiciales y problemas jurídicos.'],
            ['Recursos y documentos', 'recursos-y-documentos', 'Modelos, formatos y materiales de consulta para asuntos legales.'],
            ['Debates de la comunidad', 'debates-de-la-comunidad', 'Intercambio de opiniones y argumentos sobre temas de interés jurídico.'],
        ];

        foreach ($categories as $index => [$name, $slug, $description]) {
            // La activación se administra por separado; nunca se sobrescribe al sembrar.
            ContentCategory::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'description' => $description,
                    'sort_order' => $index + 1,
                ]
            );
        }
    }
}
