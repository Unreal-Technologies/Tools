<?php

require_once '../Pll/Loader.php';
require_once 'Program/Compiler/Compiler.php';
echo 'Loaded: UT.Php.Core version ' . Pll\Loader::initialize('UT.Php.Core', '../') . "\r\n";

try {
    new Program\Compiler\Compiler();
} catch (\UT_Php_Core\Exceptions\UndefinedException $uex) {
    echo 'Error: ' . $uex -> getMessage() . "\r\n\r\n";
}
