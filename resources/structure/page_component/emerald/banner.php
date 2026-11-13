<?php

return [
    'component_fields' => [
        'subheading'   => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-12', 'validation' => 'nullable|string|max:255'],
        'heading'      => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-12', 'validation' => 'required|string|max:255'],
        'description'  => ['translatable' => true,  'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:1000'],
        'button_text'  => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-6',  'validation' => 'nullable|string|max:120'],
        'button_url'   => ['translatable' => false, 'type' => 'text',     'class' => 'col-md-6',  'validation' => 'nullable|string|max:255'],
        'button2_text' => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-6',  'validation' => 'nullable|string|max:120'],
        'button2_url'  => ['translatable' => false, 'type' => 'text',     'class' => 'col-md-6',  'validation' => 'nullable|string|max:255'],
        'trust_items'  => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-12', 'validation' => 'nullable|string|max:500'],
    ],
];
