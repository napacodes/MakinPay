<?php

/**
 * No repeated content: plans, prices and feature rows render live from
 * SubscriptionPlan, so only the section heading is editable here.
 */
return [
    'component_fields' => [
        'subheading' => ['translatable' => true, 'type' => 'text', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:255'],
        'heading'    => ['translatable' => true, 'type' => 'text', 'class' => 'col-md-12', 'validation' => 'required|string|max:255'],
    ],
];
