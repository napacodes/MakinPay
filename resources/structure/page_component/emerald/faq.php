<?php

return [
    'component_fields' => [
        'heading' => ['translatable' => true, 'type' => 'text', 'class' => 'col-md-12', 'validation' => 'required|string|max:255'],
    ],
    'repeated_content' => [
        'question' => ['translatable' => true, 'type' => 'text',     'class' => 'col-md-12', 'validation' => 'required|string|max:255'],
        'answer'   => ['translatable' => true, 'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'required|string|max:1200'],
    ],
    'repeated_content_limit' => 7,
];
