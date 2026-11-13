<?php

return [
    'component_fields' => [
        'subheading' => ['translatable' => true, 'type' => 'text', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:255'],
        'heading'    => ['translatable' => true, 'type' => 'text', 'class' => 'col-md-12', 'validation' => 'required|string|max:255'],
    ],
    'repeated_content' => [
        'step_title' => ['translatable' => true, 'type' => 'text',     'class' => 'col-md-12', 'validation' => 'required|string|max:180'],
        'step_text'  => ['translatable' => true, 'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:400'],
    ],
    'repeated_content_limit' => 4,
];
