@echo off
echo .MD Builder (.md)
echo.
set /p name=Enter Library Name:
"S:\xampp\php\php.exe" "Program/MdBuilder.php" %name%
pause