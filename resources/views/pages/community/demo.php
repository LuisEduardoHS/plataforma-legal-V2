<?php

// Fictional presentation fixtures: no queries, persistence, or legal advice.
return [
    'state' => 'ready', // ready | loading | error. Empty posts renders the empty state.
    'topics' => [['name' => 'Arrendamiento', 'count' => 67], ['name' => 'Derecho laboral', 'count' => 67], ['name' => 'Divorcio', 'count' => 67]],
    'questions' => [['title' => '¿Puedo reclamar un depósito…?', 'replies' => 0, 'post_id' => 'deposito'], ['title' => '¿Me pueden despedir estando…?', 'replies' => 1, 'post_id' => 'despido']],
    'professionals' => [['name' => 'Noé Medina', 'location' => 'San Nicolás de los Garza', 'followers' => 184], ['name' => 'Adrián Cortez', 'location' => 'San Nicolás de los Garza', 'followers' => 67]],
    'posts' => [
        ['id' => 'finiquito', 'author' => 'Lic. Baldemar', 'initials' => 'LB', 'verified' => true, 'category' => 'Derecho laboral', 'time' => 'Hace 2 h', 'published_at' => '2026-10-22 12:00:00', 'title' => '¿Qué conceptos deben incluirse en un finiquito?', 'body' => 'Cuando termina una relación laboral existen diferentes conceptos que pueden formar parte del pago final…', 'type' => 'image', 'replies' => 14],
        ['id' => 'contrato', 'author' => 'Mr. Music', 'initials' => 'MM', 'verified' => false, 'category' => 'Arrendamiento', 'time' => 'Hace 3 h', 'published_at' => '2026-10-22 11:00:00', 'title' => '¿Mi arrendador puede pedirme que desocupe antes de que termine mi contrato?', 'body' => 'Tengo contrato hasta diciembre, pero el propietario me pidió entregar la casa el próximo mes porque quiere venderla. No he incumplido con ninguna mensualidad…', 'type' => 'question', 'replies' => 14],
        ['id' => 'documento', 'author' => 'Wero', 'initials' => 'WE', 'verified' => true, 'category' => 'Derecho laboral', 'time' => 'Hace 2 h', 'published_at' => '2026-10-22 12:10:00', 'title' => '¿Qué debo revisar antes de firmar un contrato?', 'body' => 'Comparto un documento de ejemplo para conversar sobre las cláusulas de un contrato de arrendamiento.', 'type' => 'document', 'replies' => 3],
        ['id' => 'deposito', 'author' => 'Ana Torres', 'initials' => 'AT', 'verified' => false, 'category' => 'Arrendamiento', 'time' => 'Hace 30 min', 'published_at' => '2026-10-22 13:30:00', 'title' => '¿Puedo reclamar un depósito que no me han devuelto?', 'body' => 'Entregué el departamento y me gustaría saber qué documentos conviene conservar para solicitar la devolución de mi depósito.', 'type' => 'question', 'replies' => 0],
        ['id' => 'despido', 'author' => 'María López', 'initials' => 'ML', 'verified' => false, 'category' => 'Derecho laboral', 'time' => 'Hace 1 h', 'published_at' => '2026-10-22 13:00:00', 'title' => '¿Me pueden despedir estando de vacaciones?', 'body' => 'Quisiera conocer qué información debo reunir para consultar mi situación con un profesional.', 'type' => 'text', 'replies' => 1],
    ],
];
