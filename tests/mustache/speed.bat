@echo off
set PHP="E:\lab\www\php-nts\php.exe"
::%PHP% -f "%CD%\speed.php" -- 0 %1
%PHP% -f "%CD%\speed.php" -- 1 %1
%PHP% -f "%CD%\speed.php" -- 2 %1
node speed.js 0 %1
::node speed.js 1 %1
::node speed.js 2 %1
echo.
exit
