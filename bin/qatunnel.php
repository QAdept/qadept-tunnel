#!/usr/bin/env php
<?php

use Qadept\Tunnel\Application;

require __DIR__ . '/../vendor/autoload.php';

$application = new Application('QAdept Tunnel');
$application->run();
