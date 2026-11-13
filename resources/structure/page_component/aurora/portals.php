<?php

/**
 * One row per portal tab. portal_key is user, merchant or agent; the Agent
 * tab hides itself while the agent programme is switched off. Items are one
 * per line, "fa-solid fa-icon | text".
 */
return [
    'component_fields' => [
        'subheading' => ['translatable' => true, 'type' => 'text', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:255'],
        'heading'    => ['translatable' => true, 'type' => 'text', 'class' => 'col-md-12', 'validation' => 'required|string|max:255'],
    ],
    'repeated_content' => [
        'portal_key' => ['translatable' => false, 'type' => 'text',     'class' => 'col-md-4',  'validation' => 'required|string|in:user,merchant,agent'],
        'tab_label'  => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-4',  'validation' => 'required|string|max:40'],
        'tab_icon'   => ['translatable' => false, 'type' => 'text',     'class' => 'col-md-4',  'validation' => 'nullable|string|max:80'],
        'title'      => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-12', 'validation' => 'required|string|max:160'],
        'blurb'      => ['translatable' => true,  'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:600'],
        'cta_text'   => ['translatable' => true,  'type' => 'text',     'class' => 'col-md-6',  'validation' => 'nullable|string|max:80'],
        'cta_url'    => ['translatable' => false, 'type' => 'text',     'class' => 'col-md-6',  'validation' => 'nullable|string|max:255'],
        'items'      => ['translatable' => true,  'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:1500'],
    ],
    'repeated_content_limit' => 3,
];
