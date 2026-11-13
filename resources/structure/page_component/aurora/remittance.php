<?php

/**
 * Corridors, rates and tier limits render live from the remittance module;
 * the section hides with the International Remittance feature. Each point is
 * "Title: supporting sentence".
 */
return [
    'component_fields' => [
        'subheading'  => ['translatable' => true, 'type' => 'text',     'class' => 'col-md-12', 'validation' => 'nullable|string|max:255'],
        'heading'     => ['translatable' => true, 'type' => 'text',     'class' => 'col-md-12', 'validation' => 'required|string|max:255'],
        'description' => ['translatable' => true, 'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:800'],
        'fee_label'   => ['translatable' => true, 'type' => 'text',     'class' => 'col-md-12', 'validation' => 'nullable|string|max:60'],
        'point_one'   => ['translatable' => true, 'type' => 'text',     'class' => 'col-md-6',  'validation' => 'nullable|string|max:200'],
        'point_two'   => ['translatable' => true, 'type' => 'text',     'class' => 'col-md-6',  'validation' => 'nullable|string|max:200'],
        'point_three' => ['translatable' => true, 'type' => 'text',     'class' => 'col-md-6',  'validation' => 'nullable|string|max:200'],
        'point_four'  => ['translatable' => true, 'type' => 'text',     'class' => 'col-md-6',  'validation' => 'nullable|string|max:200'],
    ],
];
