<?php

return [
    'title_max_length' => 200,
    'body_max_length' => 100000,
    'description_max_length' => 5000,
    'summary_max_length' => 1000,
    'max_attachments' => 4,
    'max_tags' => 20,
    'max_specialties' => 20,
    'max_page_size' => 50,
    // Host classification does not certify a user's resource as an official document.
    'institutional_hosts' => ['gob.mx', 'unam.mx', 'inegi.org.mx'],
];
