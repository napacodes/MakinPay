<?php

return [
    'component_fields' => [
        'subheading' => ['translatable' => true, 'type' => 'text', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:120'],
        'caption'    => ['translatable' => true, 'type' => 'text', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:255'],
    ],
    'repeated_content' => [
        'stat_value'  => ['translatable' => false, 'type' => 'text', 'class' => 'col-md-4',  'validation' => 'required|numeric'],
        'stat_prefix' => ['translatable' => false, 'type' => 'text', 'class' => 'col-md-4',  'validation' => 'nullable|string|max:8'],
        'stat_suffix' => ['translatable' => false, 'type' => 'text', 'class' => 'col-md-4',  'validation' => 'nullable|string|max:8'],
        'stat_label'  => ['translatable' => true,  'type' => 'text', 'class' => 'col-md-12', 'validation' => 'required|string|max:120'],
    ],
    'repeated_content_limit' => 4,
];
