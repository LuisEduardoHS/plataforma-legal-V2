<?php

// Local interface examples only: no AI requests, document parsing or persistence.
return [
    'current_user' => ['id' => 'me', 'name' => 'Tú'],
    'modes' => [
        ['id' => 'orientation', 'label' => 'Orientación'],
        ['id' => 'contract', 'label' => 'Revisar un contrato'],
        ['id' => 'organize', 'label' => 'Ordenar mi consulta'],
    ],
    'suggestions' => [
        ['label' => 'Problema laboral', 'prompt' => 'Tengo una situación en mi trabajo y quiero ordenar mis dudas antes de consultar a un profesional.', 'icon' => 'briefcase-business', 'mode' => 'orientation'],
        ['label' => 'Quiero revisar un contrato', 'prompt' => 'Quiero revisar un contrato. ¿Cómo puedo organizar los puntos que me gustaría aclarar?', 'icon' => 'file-text', 'mode' => 'contract'],
        ['label' => 'Tengo una duda sobre una renta', 'prompt' => 'Tengo una duda sobre una renta y quiero explicar mi situación con claridad.', 'icon' => 'house', 'mode' => 'orientation'],
    ],
    'example_responses' => [
        'orientation' => "Podemos empezar por ordenar lo que quieres consultar:\n\n1. ¿Qué sucedió y cuándo?\n2. ¿Qué parte te gustaría entender mejor?\n3. ¿Qué resultado esperas de una consulta profesional?\n\nCuéntame por dónde prefieres comenzar, sin incluir datos personales innecesarios.",
        'contract' => "Puedes preparar una lista de los puntos que te gustaría aclarar: el propósito del documento, los compromisos que describe y las partes que te generan dudas.\n\n¿Qué apartado quieres conversar primero? Esta respuesta de ejemplo no implica una revisión del archivo.",
        'organize' => "Podemos estructurar tu consulta en tres partes: un resumen de lo sucedido, la información que tienes disponible y tus preguntas principales.\n\nEmpieza por un resumen breve en tus propias palabras.",
    ],
    'sessions' => [
        ['id' => 'consulta-laboral', 'title' => 'Preparar una consulta laboral', 'mode' => 'orientation', 'messages' => [
            ['id' => 'laboral-user', 'role' => 'user', 'text' => 'Quiero preparar mis preguntas para una consulta sobre mi trabajo.', 'attachments' => []],
            ['id' => 'laboral-lexa', 'role' => 'assistant', 'text' => 'Puedes comenzar con un resumen de lo ocurrido y una lista de las dudas que quieres aclarar. ¿Qué parte te gustaría explicar primero?', 'example' => true, 'attachments' => []],
        ]],
        ['id' => 'consulta-contrato', 'title' => 'Dudas sobre un contrato', 'mode' => 'contract', 'messages' => [
            ['id' => 'contrato-user', 'role' => 'user', 'text' => 'Tengo dudas sobre cómo organizar la información de un contrato.', 'attachments' => []],
            ['id' => 'contrato-lexa', 'role' => 'assistant', 'text' => 'Puedes separar tu consulta por apartados y anotar las expresiones que te gustaría aclarar con un profesional.', 'example' => true, 'attachments' => []],
        ]],
        ['id' => 'consulta-renta', 'title' => 'Una pregunta sobre mi renta', 'mode' => 'orientation', 'messages' => [
            ['id' => 'renta-user', 'role' => 'user', 'text' => 'Quiero ordenar una pregunta sobre mi renta.', 'attachments' => []],
            ['id' => 'renta-lexa', 'role' => 'assistant', 'text' => 'Empieza por describir tu situación y lo que quieres entender mejor, evitando compartir datos personales innecesarios.', 'example' => true, 'attachments' => []],
        ]],
    ],
];