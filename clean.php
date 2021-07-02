<?php

$file = file_get_contents( 'php://stdin' );

$offset = strlen( '"""\\' );
$start  = strpos( $file, '"""' ) + $offset;
$end    = strpos( $file, '"""', $start ) - $start - 1;
$file = substr( $file, $start, $end );
echo str_replace('@', '-', $file);
