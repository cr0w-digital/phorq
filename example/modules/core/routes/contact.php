<?php

require_once $router->resolve('components');

title('Contact');

if ($req->isPost()) {
    $name    = $req->string('name');
    $email   = $req->string('email');
    $message = $req->string('message');

    $errors = [];
    if (!$name)    $errors['name']    = 'Name is required.';
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Valid email is required.';
    if (!$message) $errors['message'] = 'Message is required.';

    if ($errors) {
        if ($req->isHtmx()) {
            return contact_form($name, $email, $message, $errors);
        }
        return page('Contact', contact_form($name, $email, $message, $errors));
    }

    if ($req->isHtmx()) {
        trigger('toast', ['message' => 'Message sent!']);
        return h('p.success', '✓ Message sent!');
    }

    return redirect('/contact');
}

function contact_form(string $name = '', string $email = '', string $message = '', array $errors = []): array
{
    return h('form#contact-form.contact-form', [
        'hx-post'   => '/contact',
        'hx-target' => '#contact-form',
        'hx-swap'   => 'outerHTML',
    ],
        form_group('Name',    'name',    h('input#name',       ['name' => 'name',    'value' => $name,    'placeholder' => 'Your name']),    $errors['name']    ?? null),
        form_group('Email',   'email',   h('input#email',      ['name' => 'email',   'type' => 'email',   'value' => $email, 'placeholder' => 'you@example.com']), $errors['email']   ?? null),
        form_group('Message', 'message', h('textarea#message', ['name' => 'message', 'rows' => '4',       'placeholder' => 'Your message'], $message), $errors['message'] ?? null),
        h('button.btn.btn-primary', ['type' => 'submit'], 'Send'),
    );
}

return page('Contact',
    h('p', 'Method branching — GET renders the form, POST validates, HTMX swaps on error.'),
    contact_form(),
    demo_meta('Route: ', h('code', '/contact'), ' — uses ', h('code', '$req->isPost()'), ' and ', h('code', '$req->isHtmx()'), '.'),
);