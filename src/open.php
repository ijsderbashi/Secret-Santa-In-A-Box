<?php
// Retired tracking endpoint. Old assignment emails may still request this URL.
// Loading their image must not record a panel visit or any other activity.
header('Cache-Control: no-store');
http_response_code(204);
exit;

