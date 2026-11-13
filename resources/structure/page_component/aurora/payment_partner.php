<?php

return [
    'component_fields' => [
        'caption' => ['translatable' => true, 'type' => 'text', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:255'],
    ],
    'repeated_content' => [
        'gateway_name' => ['translatable' => false, 'type' => 'text', 'class' => 'col-md-12', 'validation' => 'required|string|max:120'],
    ],
    'repeated_content_limit' => 24,
];
