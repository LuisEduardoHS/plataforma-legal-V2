<?php

// Presentation fixtures. A controller can supply $directory with real, authorized profiles.
$specialties = [
    ['id' => 'laboral', 'label' => 'Derecho laboral'],
    ['id' => 'arrendamiento', 'label' => 'Arrendamiento'],
    ['id' => 'familiar', 'label' => 'Derecho familiar'],
    ['id' => 'civil', 'label' => 'Derecho civil'],
    ['id' => 'mercantil', 'label' => 'Derecho mercantil'],
    ['id' => 'penal', 'label' => 'Derecho penal'],
];
$rows = [
    ['ana-martinez', 'Ana Martínez', 'AM', 'Abogada', 'laboral', 'Monterrey, N. L.', ['Español'], ['En línea', 'Presencial']],
    ['carlos-garcia', 'Carlos García', 'CG', 'Abogado', 'arrendamiento', 'San Nicolás de los Garza, N. L.', ['Español', 'Inglés'], ['En línea']],
    ['sofia-hernandez', 'Sofía Hernández', 'SH', 'Abogada', 'familiar', 'Guadalupe, N. L.', ['Español'], ['En línea', 'Presencial']],
    ['baldemar', 'Lic. Baldemar', 'LB', 'Abogado', 'laboral', 'Monterrey, N. L.', ['Español'], ['En línea', 'Presencial']],
    ['valeria-torres', 'Valeria Torres', 'VT', 'Abogada', 'arrendamiento', 'Saltillo, Coah.', ['Español', 'Inglés'], ['En línea']],
    ['noe', 'Noé Medina', 'NM', 'Abogado', 'civil', 'San Nicolás de los Garza, N. L.', ['Español'], ['Presencial']],
    ['diego-flores', 'Diego Flores', 'DF', 'Abogado', 'mercantil', 'Monterrey, N. L.', ['Español', 'Inglés'], ['En línea', 'Presencial']],
    ['lucia-reyes', 'Lucía Reyes', 'LR', 'Abogada', 'penal', 'Apodaca, N. L.', ['Español'], ['Presencial']],
    ['andres-cortez', 'Andrés Cortez', 'AC', 'Abogado', 'civil', 'San Pedro Garza García, N. L.', ['Español'], ['En línea', 'Presencial']],
    ['mariana-ruiz', 'Mariana Ruiz', 'MR', 'Abogada', 'familiar', 'Monterrey, N. L.', ['Español'], ['En línea']],
    ['pablo-morales', 'Pablo Morales', 'PM', 'Abogado', 'arrendamiento', 'Guadalajara, Jal.', ['Español'], ['En línea']],
    ['daniela-santos', 'Daniela Santos', 'DS', 'Abogada', 'laboral', 'San Pedro Garza García, N. L.', ['Español', 'Inglés'], ['En línea', 'Presencial']],
    ['fernando-leon', 'Fernando León', 'FL', 'Abogado', 'mercantil', 'Guadalupe, N. L.', ['Español'], ['En línea']],
    ['elena-vargas', 'Elena Vargas', 'EV', 'Abogada', 'penal', 'Monterrey, N. L.', ['Español', 'Inglés'], ['Presencial']],
    ['jorge-mendoza', 'Jorge Mendoza', 'JM', 'Abogado', 'civil', 'Querétaro, Qro.', ['Español'], ['En línea']],
    ['paula-salazar', 'Paula Salazar', 'PS', 'Abogada', 'familiar', 'San Nicolás de los Garza, N. L.', ['Español'], ['En línea', 'Presencial']],
    ['ricardo-lopez', 'Ricardo López', 'RL', 'Abogado', 'arrendamiento', 'Monterrey, N. L.', ['Español'], ['En línea', 'Presencial']],
    ['camila-navarro', 'Camila Navarro', 'CN', 'Abogada', 'laboral', 'Ciudad de México', ['Español', 'Inglés'], ['En línea']],
];
$topics = [
    'laboral' => ['Relaciones de trabajo', 'Documentos laborales', 'Terminación de la relación laboral'],
    'arrendamiento' => ['Contratos de renta', 'Depósitos y garantías', 'Relación entre arrendador e inquilino'],
    'familiar' => ['Acuerdos familiares', 'Documentación familiar', 'Orientación sobre trámites'],
    'civil' => ['Contratos civiles', 'Obligaciones y acuerdos', 'Documentación patrimonial'],
    'mercantil' => ['Contratos comerciales', 'Organización de negocios', 'Acuerdos entre empresas'],
    'penal' => ['Orientación inicial', 'Revisión de documentos', 'Acompañamiento profesional'],
];
$labels = array_column($specialties, 'label', 'id');
$profiles = [];
foreach ($rows as [$id, $name, $initials, $role, $specialty, $location, $languages, $modalities]) {
    $profiles[$id] = [
        'id' => $id, 'name' => $name, 'initials' => $initials, 'role' => $role,
        'specialty_id' => $specialty, 'specialty' => $labels[$specialty], 'location' => $location,
        'verified' => true, 'languages' => $languages, 'modalities' => $modalities,
        'topics' => $topics[$specialty],
        'bio' => 'Orientación y acompañamiento en asuntos de '.mb_strtolower($labels[$specialty]).'. La consulta inicial permite conocer tu situación y acordar el alcance de la atención.',
        'conversation_url' => in_array($id, ['baldemar', 'noe'], true) ? route('messages').'#'.$id : null,
    ];
}
return [
    'state' => 'ready', 'is_example' => true,
    'lexa_url' => route('lexa'), 'specialties' => $specialties, 'profiles' => $profiles,
    'sections' => [
        ['id' => 'recommended', 'title' => 'Perfiles que te pueden interesar según tu actividad reciente', 'limit' => 6,
            'profile_ids' => ['ana-martinez', 'carlos-garcia', 'sofia-hernandez', 'baldemar', 'valeria-torres', 'noe', 'diego-flores', 'lucia-reyes', 'andres-cortez']],
        ['id' => 'popular', 'title' => 'Popular en el momento', 'limit' => 4,
            'profile_ids' => ['ana-martinez', 'daniela-santos', 'noe', 'mariana-ruiz', 'carlos-garcia', 'elena-vargas']],
        ['id' => 'suggested', 'title' => 'Sugerencias para ti', 'limit' => 6,
            'profile_ids' => ['pablo-morales', 'daniela-santos', 'fernando-leon', 'elena-vargas', 'jorge-mendoza', 'paula-salazar', 'ricardo-lopez', 'camila-navarro', 'diego-flores']],
    ],
];
