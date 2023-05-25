@echo off
set PHP="E:\lab\www\php-nts\php.exe"
%PHP% -d opcache.opt_debug_level=0x20000 -f "%CD%\..\mustache.php" 2> mustache.opcode
exit
