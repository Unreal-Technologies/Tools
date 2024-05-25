@ echo off
set phpExe="S:\xampp\php\php.exe"
set phpCbf="Program\phpcs\bin\phpcbf"
set phpCs="Program\phpcs\bin\phpcs"

if not exist "Logs" mkdir Logs

set log="Logs\PhpCs-%3.%1.log"
%phpExe% %phpCbf% --standard=%1 %2 > null
%phpExe% %phpCs% --standard=%1 %2 > %log%
del /f null

for %%x in (%log%) do if not %%~zx==0 (
	echo %3 %1 Errors
	goto :eof
)
echo %3 %1 OK

:eof