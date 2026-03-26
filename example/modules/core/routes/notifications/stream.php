<?php

declare(strict_types=1);

// Subscribes this client to the notifications topic.
// The configured publisher handles the transport:
//   Redis   — holds SSE connection, forwards events from channel
//   Mercure — redirects client to hub with subscriber JWT

subscribe('/topics/notifications');