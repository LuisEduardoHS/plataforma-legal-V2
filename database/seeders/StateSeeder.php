<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\State;
use Illuminate\Database\Seeder;
use RuntimeException;

class StateSeeder extends Seeder
{
    public function run(): void
    {
        $country = Country::where('iso_code', 'MX')->first();

        if (!$country) {
            throw new RuntimeException(
                'México no existe. Ejecuta primero CountrySeeder.'
            );
        }

        $states = [
            '01' => 'Aguascalientes',
            '02' => 'Baja California',
            '03' => 'Baja California Sur',
            '04' => 'Campeche',
            '05' => 'Coahuila de Zaragoza',
            '06' => 'Colima',
            '07' => 'Chiapas',
            '08' => 'Chihuahua',
            '09' => 'Ciudad de México',
            '10' => 'Durango',
            '11' => 'Guanajuato',
            '12' => 'Guerrero',
            '13' => 'Hidalgo',
            '14' => 'Jalisco',
            '15' => 'México',
            '16' => 'Michoacán de Ocampo',
            '17' => 'Morelos',
            '18' => 'Nayarit',
            '19' => 'Nuevo León',
            '20' => 'Oaxaca',
            '21' => 'Puebla',
            '22' => 'Querétaro',
            '23' => 'Quintana Roo',
            '24' => 'San Luis Potosí',
            '25' => 'Sinaloa',
            '26' => 'Sonora',
            '27' => 'Tabasco',
            '28' => 'Tamaulipas',
            '29' => 'Tlaxcala',
            '30' => 'Veracruz de Ignacio de la Llave',
            '31' => 'Yucatán',
            '32' => 'Zacatecas',
        ];

        foreach ($states as $code => $name) {
            State::updateOrCreate(
                [
                    'country_id' => $country->id,
                    'code' => (string) $code,
                ],
                [
                    'name' => $name,
                ]
            );
        }
    }
}
