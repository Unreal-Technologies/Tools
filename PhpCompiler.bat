@echo off
echo Php Compiler (.pll)
echo.
set /p name=Enter Library Name:
"S:\xampp\php\php.exe" "Program/Compiler.php" %name%
pause