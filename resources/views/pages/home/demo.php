<?php
// Local presentation fixtures. Replace $dashboard from the future controller.
$community = require resource_path('views/pages/community/demo.php');
return [
    'continue' => [
        ['id' => 'rental', 'title' => 'Problemas con contrato de arrendamiento', 'meta' => 'LEXA · Hace 2 días', 'icon' => 'sparkles', 'text' => 'Estábamos ordenando las dudas sobre el contrato…', 'kind' => 'lexa', 'session' => 'consulta-contrato', 'url' => route('lexa')],
        ['id' => 'work', 'title' => 'Consulta sobre despido injustificado', 'meta' => 'Consulta con abogado · Ayer', 'icon' => 'message-circle', 'text' => 'Esperando respuesta', 'kind' => 'messages', 'url' => route('messages') . '#baldemar'],
        ['id' => 'deposit', 'title' => 'Problema con depósito de renta', 'meta' => 'Borrador · Hace 4 horas', 'icon' => 'file-pen-line', 'text' => 'Tu publicación aún no se ha publicado.', 'kind' => 'draft', 'url' => route('community'), 'draft' => 'Tengo una duda sobre la devolución del depósito de mi renta. Quiero explicar mi situación y conocer qué información reunir para una consulta.'],
    ],
    'article' => ['title' => '¿Qué hacer si no recibes tu finiquito correctamente?', 'summary' => 'Una lectura para organizar tus dudas antes de una consulta profesional.', 'category' => 'Derecho laboral', 'duration' => '5 min'],
    'professional' => ['name' => 'Ana Martínez', 'specialty' => 'Derecho laboral', 'location' => 'Monterrey, N. L.'],
    'feed' => array_slice($community['posts'], 0, 2),
    'recommendation' => $community['posts'][1],
];
