<?php

/**
 * Stats are "value: label" and split on the first colon. Badge text and stats
 * accept {version} (the installed release), {features} (how many features
 * Feature Management has switched on) and {card_issuers} (card-issuer drivers
 * shipped), so none of them goes stale.
 */
return [
    'component_fields' => [
        'badge_label'       => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-3',  'validation' => 'nullable|string|max:20'],
        'badge_text'        => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-9',  'validation' => 'nullable|string|max:160'],
        'heading'           => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-8',  'validation' => 'required|string|max:255'],
        'heading_highlight' => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-4',  'validation' => 'nullable|string|max:120'],
        'description'       => ['translatable' => true,  'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:1000'],
        'button_text'       => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-6',  'validation' => 'nullable|string|max:120'],
        'button_url'        => ['translatable' => false, 'type' => 'text',     'class' => 'col-md-6',  'validation' => 'nullable|string|max:255'],
        'button2_text'      => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-6',  'validation' => 'nullable|string|max:120'],
        'button2_url'       => ['translatable' => false, 'type' => 'text',     'class' => 'col-md-6',  'validation' => 'nullable|string|max:255'],
        'stat_one'          => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-4',  'validation' => 'nullable|string|max:120'],
        'stat_two'          => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-4',  'validation' => 'nullable|string|max:120'],
        'stat_three'        => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-4',  'validation' => 'nullable|string|max:120'],
    ],
];
