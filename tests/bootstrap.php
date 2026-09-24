<?php
/**
 * Unit tests run the pure classes without WordPress; every plugin file
 * refuses direct access unless ABSPATH is defined.
 */

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/vendor/autoload.php';
