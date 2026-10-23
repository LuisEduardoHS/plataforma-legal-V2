<?php

namespace Database\Seeders;

use App\Models\LegalSpecialty;
use Illuminate\Database\Seeder;

class LegalSpecialtySeeder extends Seeder
{
    public function run(): void
    {
        $specialties = [
            ['Derecho civil', 'derecho-civil', 'Contratos, obligaciones, responsabilidad civil y asuntos patrimoniales.'],
            ['Derecho familiar', 'derecho-familiar', 'Divorcios, custodia, pensión alimenticia, adopciones y sucesiones familiares.'],
            ['Derecho penal', 'derecho-penal', 'Delitos, defensa penal y procedimientos penales.'],
            ['Derecho laboral', 'derecho-laboral', 'Relaciones de trabajo, despidos, prestaciones y conflictos laborales.'],
            ['Derecho mercantil', 'derecho-mercantil', 'Operaciones comerciales, sociedades mercantiles y títulos de crédito.'],
            ['Derecho corporativo', 'derecho-corporativo', 'Constitución, administración y cumplimiento legal de empresas.'],
            ['Derecho fiscal', 'derecho-fiscal', 'Impuestos, obligaciones tributarias y controversias fiscales.'],
            ['Derecho administrativo', 'derecho-administrativo', 'Trámites, actos administrativos y controversias con autoridades.'],
            ['Derecho constitucional', 'derecho-constitucional', 'Derechos constitucionales y control de constitucionalidad.'],
            ['Derechos humanos', 'derechos-humanos', 'Protección y defensa de los derechos humanos.'],
            ['Derecho inmobiliario', 'derecho-inmobiliario', 'Compraventa, arrendamiento y regularización de inmuebles.'],
            ['Propiedad intelectual', 'propiedad-intelectual', 'Marcas, derechos de autor, patentes y protección de creaciones.'],
            ['Derecho migratorio', 'derecho-migratorio', 'Residencia, nacionalidad y procedimientos migratorios.'],
            ['Derecho ambiental', 'derecho-ambiental', 'Regulación ambiental, autorizaciones y responsabilidad ambiental.'],
            ['Derecho agrario', 'derecho-agrario', 'Propiedad ejidal, comunal y conflictos agrarios.'],
            ['Derecho de seguridad social', 'derecho-seguridad-social', 'Pensiones, prestaciones y controversias de seguridad social.'],
            ['Derecho de protección de datos', 'derecho-proteccion-datos', 'Privacidad, tratamiento de datos personales y cumplimiento normativo.'],
            ['Derecho bancario y financiero', 'derecho-bancario-financiero', 'Servicios financieros, créditos y regulación bancaria.'],
            ['Derecho electoral', 'derecho-electoral', 'Normativa electoral y procedimientos ante autoridades electorales.'],
            ['Amparo', 'amparo', 'Juicios de amparo y protección jurisdiccional de derechos.'],
        ];

        foreach ($specialties as $index => [$name, $slug, $description]) {
            LegalSpecialty::updateOrCreate(
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
