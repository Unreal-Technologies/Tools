@echo off
set localServer="S:\xampp\htdocs\localserver"
set ut_php_core="S:\xampp\htdocs\Compiler\UT.Php.Core"
set pll="S:\xampp\htdocs\Compiler\Pll"
set program="S:\xampp\htdocs\Compiler\Tools\Program"

call "Program\PSR.bat" PSR1 %localServer% LocalServer
call "Program\PSR.bat" PSR2 %localServer% LocalServer
call "Program\PSR.bat" PSR12 %localServer% LocalServer
call "Program\PSR.bat" PSR1 %ut_php_core% UT.Php.Core
call "Program\PSR.bat" PSR2 %ut_php_core% UT.Php.Core
call "Program\PSR.bat" PSR12 %ut_php_core% UT.Php.Core
call "Program\PSR.bat" PSR1 %pll% Pll
call "Program\PSR.bat" PSR2 %pll% Pll
call "Program\PSR.bat" PSR12 %pll% Pll
call "Program\PSR.bat" PSR1 %program% Program
call "Program\PSR.bat" PSR2 %program% Program
call "Program\PSR.bat" PSR12 %program% Program

echo.
echo Done
pause