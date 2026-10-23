<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;

class TagSeeder extends Seeder
{
    public function run(): void
    {
        $tags = [
            ['Despido injustificado', 'despido-injustificado', 'Terminación de la relación laboral y reclamación de derechos laborales.'],
            ['Arrendamiento', 'arrendamiento', 'Renta de inmuebles, obligaciones y controversias entre las partes.'],
            ['Pensión alimenticia', 'pension-alimenticia', 'Obligaciones alimentarias, determinación y cumplimiento de pensiones.'],
            ['Contratos', 'contratos', 'Elaboración, interpretación y cumplimiento de acuerdos y obligaciones.'],
            ['Divorcio', 'divorcio', 'Disolución del matrimonio y sus consecuencias legales.'],
            ['Guarda y custodia', 'guarda-y-custodia', 'Cuidado de niñas, niños y adolescentes y acuerdos de convivencia.'],
            ['Herencias', 'herencias', 'Testamentos, sucesiones y distribución de bienes hereditarios.'],
            ['Seguridad social', 'seguridad-social', 'Acceso a prestaciones, pensiones y servicios de seguridad social.'],
            ['Protección de datos personales', 'proteccion-de-datos-personales', 'Privacidad y ejercicio de derechos sobre los datos personales.'],
            ['Derechos del consumidor', 'derechos-del-consumidor', 'Reclamaciones y protección en la adquisición de bienes y servicios.'],
            ['Marcas', 'marcas', 'Registro, uso y protección de signos distintivos.'],
            ['Amparo', 'amparo', 'Protección judicial frente a actos que vulneran derechos.'],
        ];

        foreach ($tags as $index => [$name, $slug, $description]) {
            // El slug identifica el registro y permite conservar su ID y activación.
            Tag::updateOrCreate(
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
