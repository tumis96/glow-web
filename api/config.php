<?php
/** GET /api/config.php → katalog služeb, pravidla dostupnosti a limity pro frontend. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/services.php';
require_once __DIR__ . '/../includes/slots.php';

[$min, $max] = bookingWindow();
jsonOut(['ok' => true, 'minDate' => $min, 'maxDate' => $max] + publicConfig());
