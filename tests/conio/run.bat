@echo off
set PHP="E:\lab\www\php-nts\php.exe"
%PHP% -f "%CD%\%1" -- "%2"
exit
