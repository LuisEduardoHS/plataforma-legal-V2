<?php

// Presentation fixtures only. A future controller can supply $messaging.
$contact = fn ($id, $name, $initials, $specialty = null) => [
    'id' => $id, 'name' => $name, 'initials' => $initials,
    'verified' => $specialty !== null, 'specialty' => $specialty, 'profile_url' => null,
];
return [
    'state' => 'ready',
    'current_user' => ['id' => 'me', 'name' => 'Tú'],
    'conversations' => [
        [
            'id' => 'baldemar',
            'contact' => $contact('baldemar', 'Lic. Baldemar', 'LB', 'Derecho laboral'),
            'unread_count' => 2,
            'messages' => [
                ['id' => 'b1', 'sender_id' => 'baldemar', 'text' => 'Hola, ¿en qué puedo orientarte?', 'created_at' => '2026-10-21T16:20:00-06:00', 'read_at' => null, 'attachments' => []],
                ['id' => 'b2', 'sender_id' => 'me', 'text' => 'Buen día. Quisiera saber qué documentos conviene reunir para una consulta.', 'created_at' => '2026-10-22T10:28:00-06:00', 'read_at' => '2026-10-22T10:29:00-06:00', 'attachments' => []],
                ['id' => 'b3', 'sender_id' => 'baldemar', 'text' => 'Podemos empezar por revisar la información general. Evita compartir datos sensibles por ahora.', 'created_at' => '2026-10-22T10:30:00-06:00', 'read_at' => null, 'attachments' => []],
                ['id' => 'b4', 'sender_id' => 'baldemar', 'text' => 'Te comparto estos archivos de referencia.', 'created_at' => '2026-10-22T10:31:00-06:00', 'read_at' => null, 'attachments' => [
                    ['id' => 'sample-pdf', 'name' => 'contrato-ejemplo.pdf', 'kind' => 'document', 'mime' => 'application/pdf', 'size' => 664, 'url' => asset('demo/messages/contrato-ejemplo.pdf')],
                    ['id' => 'sample-image', 'name' => 'notas.png', 'kind' => 'image', 'mime' => 'image/png', 'size' => 1939, 'url' => asset('demo/messages/notas.png')],
                ]],
            ],
        ],
        ['id' => 'music', 'contact' => $contact('music', 'Mr. Music', 'MM'), 'unread_count' => 0, 'messages' => [
            ['id' => 'm1', 'sender_id' => 'music', 'text' => '¡Hola! Gracias por compartir la publicación sobre arrendamiento.', 'created_at' => '2026-10-22T09:45:00-06:00', 'read_at' => null, 'attachments' => []],
        ]],
        ['id' => 'noe', 'contact' => $contact('noe', 'Noé Medina', 'NM', 'Derecho civil'), 'unread_count' => 1, 'messages' => [
            ['id' => 'n1', 'sender_id' => 'noe', 'text' => 'Buen día. Podemos revisar tu consulta cuando tengas los documentos.', 'created_at' => '2026-10-22T09:10:00-06:00', 'read_at' => null, 'attachments' => []],
        ]],
        ['id' => 'silentema', 'contact' => $contact('silentema', 'Silentema', 'SI'), 'unread_count' => 0, 'messages' => []],
    ],
];
