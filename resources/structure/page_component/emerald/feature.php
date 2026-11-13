<?php

return [
    'component_fields' => [
        'subheading' => ['translatable' => true, 'type' => 'text', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:255'],
        'heading'    => ['translatable' => true, 'type' => 'text', 'class' => 'col-md-12', 'validation' => 'required|string|max:255'],
    ],
    'repeated_content' => [
        'feature_icon_class' => ['translatable' => false, 'type' => 'text',     'class' => 'col-md-12', 'validation' => 'required|string|max:255'],
        'feature_title'      => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-12', 'validation' => 'required|string|max:255'],
        'feature_text'       => ['translatable' => true,  'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:600'],
    ],
    'repeated_content_limit' => 3,
];
