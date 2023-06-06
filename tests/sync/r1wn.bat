@echo off
set PHP="E:\lab\www\php-nts\php.exe"
%PHP% -f "%CD%\test1.php" -- "%1" %2
exit
