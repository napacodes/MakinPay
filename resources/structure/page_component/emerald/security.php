<?php

/**
 * Each item is "Title: supporting sentence" — the view splits on the first
 * colon so an admin can edit both halves in one field.
 */
return [
    'component_fields' => [
        'subheading' => ['translatable' => true, 'type' => 'text',     'class' => 'col-md-12', 'validation' => 'nullable|string|max:255'],
        'heading'    => ['translatable' => true, 'type' => 'text',     'class' => 'col-md-12', 'validation' => 'required|string|max:255'],
        'item_one'   => ['translatable' => true, 'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:400'],
        'item_two'   => ['translatable' => true, 'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:400'],
        'item_three' => ['translatable' => true, 'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:400'],
        'item_four'  => ['translatable' => true, 'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:400'],
        'item_five'  => ['translatable' => true, 'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:400'],
        'item_six'   => ['translatable' => true, 'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:400'],
    ],
];
