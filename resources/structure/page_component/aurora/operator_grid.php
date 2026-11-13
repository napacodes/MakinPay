<?php

return [
    'component_fields' => [
        'subheading' => ['translatable' => true, 'type' => 'text', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:255'],
        'heading'    => ['translatable' => true, 'type' => 'text', 'class' => 'col-md-12', 'validation' => 'required|string|max:255'],
    ],
    'repeated_content' => [
        'icon'  => ['translatable' => false, 'type' => 'text',     'class' => 'col-md-12', 'validation' => 'required|string|max:80'],
        'title' => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-12', 'validation' => 'required|string|max:120'],
        'text'  => ['translatable' => true,  'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:300'],
    ],
    'repeated_content_limit' => 8,
];
