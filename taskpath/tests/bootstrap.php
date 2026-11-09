<?php

declare(strict_types=1);

// Unit tests run against Nextcloud's public interfaces (nextcloud/ocp), without a
// server: every Nextcloud object is a mock.
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/stubs/oc_hooks_emitter.php';
