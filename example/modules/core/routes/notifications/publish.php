<?php

declare(strict_types=1);

if (!\phorq\get_publisher()) {
    return json(['error' => 'No publisher configured'], 503);
}

$message = $req->string('message');
$level   = $req->string('level', 'info');

if (!$message) {
    return json(['error' => 'Message is required'], 422);
}

// Build the notification HTML fragment
$html = \phml\render(
    h('.notification.notification--' . $level,
        h('span.notification-level', ucfirst($level)),
        h('span.notification-message', $message),
        h('span.notification-time', date('H:i:s')),
    )
);

// Publish to all subscribers — transport handled by configured publisher
publish('/topics/notifications', event('notification', $html));

// HTMX response — confirm to the sender
if ($req->isHtmx()) {
    trigger('notification:sent');
    return h('p.success', '✓ Sent at ' . date('H:i:s'));
}

return json(['ok' => true, 'message' => $message, 'level' => $level]);