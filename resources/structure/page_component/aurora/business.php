<?php

/**
 * The first row is the tall featured card with the payment-link mock. A row
 * with a feature_key (e.g. payment_link) hides while that feature is off.
 */
return [
    'component_fields' => [
        'subheading'  => ['translatable' => true, 'type' => 'text',     'class' => 'col-md-12', 'validation' => 'nullable|string|max:255'],
        'heading'     => ['translatable' => true, 'type' => 'text',     'class' => 'col-md-12', 'validation' => 'required|string|max:255'],
        'description' => ['translatable' => true, 'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:800'],
    ],
    'repeated_content' => [
        'icon'        => ['translatable' => false, 'type' => 'text',     'class' => 'col-md-6',  'validation' => 'required|string|max:80'],
        'feature_key' => ['translatable' => false, 'type' => 'text',     'class' => 'col-md-6',  'validation' => 'nullable|string|max:60'],
        'title'       => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-12', 'validation' => 'required|string|max:120'],
        'text'        => ['translatable' => true,  'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:400'],
    ],
    'repeated_content_limit' => 6,
];
