<?php

/**
 * No repeated content: the plan cards render live from WalletEarnPlan, so the
 * only editable copy here is the section heading and the risk disclosure.
 */
return [
    'component_fields' => [
        'subheading' => ['translatable' => true, 'type' => 'text',     'class' => 'col-md-12', 'validation' => 'nullable|string|max:255'],
        'heading'    => ['translatable' => true, 'type' => 'text',     'class' => 'col-md-12', 'validation' => 'required|string|max:255'],
        'disclosure' => ['translatable' => true, 'type' => 'textarea', 'class' => 'col-md-12', 'validation' => 'nullable|string|max:600'],
    ],
];
