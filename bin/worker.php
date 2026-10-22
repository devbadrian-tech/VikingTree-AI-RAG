<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
(new Arbor\Importer(new Arbor\Repository()))->work($argv[1] ?? '');
