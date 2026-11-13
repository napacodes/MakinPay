<?php

/**
 * Bullets are one per line, "fa-solid fa-icon | text", with an optional third
 * part naming a feature key ("… | budgets") so the bullet hides with it.
 */
return [
    'component_fields' => [
        'subheading'  => ['translatable' => true, 'type' => 'text',     'class' => 'col-md-12', 'validation' => 'nullable|string|max:255'],
        'heading'     => ['translatable' => true, 'type' => 'text',     'class' => 'col-md-12', 'validation' => 'required|string|max:255'],
        'description' => ['translatable' => true, 'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:800'],
        'bullets'     => ['translatable' => true, 'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:1500'],
    ],
];
