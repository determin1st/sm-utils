@echo off
set PHP="E:\lab\www\php-nts\php.exe"
:: opcache.opt_debug_level
:: 0x10000: Output OPCodes prior to optimizations.
:: 0x20000: Output OPCodes After optimizations.
:: 0x40000: Output OPCodes with Context-Free Grammar
:: 0x200000: Output OPCodes with Static Single Assignments forms.

:: check & remove:
:: FETCH_CONSTANT ~ use constants
:: INIT_NS_FCALL_BY_NAME ~ use functions

::%PHP% -d opcache.opt_debug_level=0x20000 -f "%CD%\..\error.php" 2> error.opcode
::%PHP% -d opcache.opt_debug_level=0x20000 -f "%CD%\..\mustache.php" 2> mustache.opcode
::%PHP% -d opcache.opt_debug_level=0x20000 -f "%CD%\..\sync.php" 2> sync.opcode
%PHP% -d opcache.opt_debug_level=0x20000 -f "%CD%\..\conio.php" 2> conio.opcode

exit
