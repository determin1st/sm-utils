@echo off
set PHP="E:\lab\www\php-nts\php.exe"
start "" %PHP% -f "%CD%\%1" -- "%2"
exit
