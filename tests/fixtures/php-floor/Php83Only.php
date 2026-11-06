<?php
/**
 * Floor guard fixture: intentionally uses a PHP 8.3 function.
 *
 * PHPStan runs with phpVersion 80200, so `composer analyse:floor-guard` must
 * report json_validate() as unknown. Not part of PHPStan paths, PHPCS or autoload.
 *
 * @package EinvoiceMatbao
 */

json_validate( '{}' );
