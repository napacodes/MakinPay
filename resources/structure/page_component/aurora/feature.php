<?php

/**
 * No repeated content: the cards render live from Feature Management, so an
 * admin switching a feature off removes it here too. The heading accepts
 * {features} for the number currently switched on.
 */
return [
    'component_fields' => [
        'subheading'  => ['translatable' => true, 'type' => 'text',     'class' => 'col-md-12', 'validation' => 'nullable|string|max:255'],
        'heading'     => ['translatable' => true, 'type' => 'text',     'class' => 'col-md-12', 'validation' => 'required|string|max:255'],
        'description' => ['translatable' => true, 'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:800'],
    ],
];
