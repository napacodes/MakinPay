<?php

/**
 * Each item is "Title: supporting sentence" — the view splits on the first
 * colon so an admin can edit both halves in one field — with its own icon.
 */
return [
    'component_fields' => [
        'subheading'  => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-12', 'validation' => 'nullable|string|max:255'],
        'heading'     => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-12', 'validation' => 'required|string|max:255'],
        'description' => ['translatable' => true,  'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:800'],
        'item_one'    => ['translatable' => true,  'type' => 'textarea', 'class' => 'col-md-8',  'validation' => 'nullable|string|max:400'],
        'icon_one'    => ['translatable' => false, 'type' => 'text',     'class' => 'col-md-4',  'validation' => 'nullable|string|max:80'],
        'item_two'    => ['translatable' => true,  'type' => 'textarea', 'class' => 'col-md-8',  'validation' => 'nullable|string|max:400'],
        'icon_two'    => ['translatable' => false, 'type' => 'text',     'class' => 'col-md-4',  'validation' => 'nullable|string|max:80'],
        'item_three'  => ['translatable' => true,  'type' => 'textarea', 'class' => 'col-md-8',  'validation' => 'nullable|string|max:400'],
        'icon_three'  => ['translatable' => false, 'type' => 'text',     'class' => 'col-md-4',  'validation' => 'nullable|string|max:80'],
        'item_four'   => ['translatable' => true,  'type' => 'textarea', 'class' => 'col-md-8',  'validation' => 'nullable|string|max:400'],
        'icon_four'   => ['translatable' => false, 'type' => 'text',     'class' => 'col-md-4',  'validation' => 'nullable|string|max:80'],
        'item_five'   => ['translatable' => true,  'type' => 'textarea', 'class' => 'col-md-8',  'validation' => 'nullable|string|max:400'],
        'icon_five'   => ['translatable' => false, 'type' => 'text',     'class' => 'col-md-4',  'validation' => 'nullable|string|max:80'],
        'item_six'    => ['translatable' => true,  'type' => 'textarea', 'class' => 'col-md-8',  'validation' => 'nullable|string|max:400'],
        'icon_six'    => ['translatable' => false, 'type' => 'text',     'class' => 'col-md-4',  'validation' => 'nullable|string|max:80'],
    ],
];
