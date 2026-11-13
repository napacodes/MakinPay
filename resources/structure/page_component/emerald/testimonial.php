<?php

return [
    'component_fields' => [
        'heading' => ['translatable' => true, 'type' => 'text', 'class' => 'col-md-12', 'validation' => 'required|string|max:255'],
    ],
    'repeated_content' => [
        'quote'          => ['translatable' => true,  'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'required|string|max:600'],
        'author_name'    => ['translatable' => false, 'type' => 'text',     'class' => 'col-md-4',  'validation' => 'required|string|max:120'],
        'author_role'    => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-4',  'validation' => 'nullable|string|max:120'],
        'author_country' => ['translatable' => false, 'type' => 'text',     'class' => 'col-md-4',  'validation' => 'nullable|string|max:80'],
    ],
    'repeated_content_limit' => 3,
];
