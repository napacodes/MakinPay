<?php

return [
    'component_fields' => [
        'heading' => ['translatable' => true, 'type' => 'text', 'class' => 'col-md-12', 'validation' => 'required|string|max:255'],
    ],
    'repeated_content' => [
        'service_eyebrow' => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-12', 'validation' => 'nullable|string|max:120'],
        'service_title'   => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-12', 'validation' => 'required|string|max:255'],
        'service_text'    => ['translatable' => true,  'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:800'],
        'service_bullets' => ['translatable' => true,  'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:800'],
        'service_mock'    => ['translatable' => false, 'type' => 'text',     'class' => 'col-md-12', 'validation' => 'nullable|string|max:60'],
    ],
    'repeated_content_limit' => 4,
];
