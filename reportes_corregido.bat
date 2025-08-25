@echo off
REM Script para corregir el archivo reportes.php
REM Coloca este archivo en la misma carpeta que reportes.php y ejecútalo

echo Creando copia de seguridad...
copy reportes.php reportes_backup.php
IF %ERRORLEVEL% NEQ 0 (
    echo Error al crear la copia de seguridad.
    pause
    exit /b 1
)

echo Backup creado en reportes_backup.php

echo Extrayendo la función problemática...
findstr /C:"function generarReporteEstudianteIndividual" reportes.php > nul
IF %ERRORLEVEL% NEQ 0 (
    echo No se encontró la función problemática.
    pause
    exit /b 1
)

echo Creando archivo corregido...

REM Crear un archivo temporal
echo ^<?php > temp_fix_1.txt

REM Extraer la parte hasta donde debería estar la función problemática
findstr /B /V /C:"<th>Materia</th>" reportes.php > temp_fix_2.txt

REM Crear la versión final
type temp_fix_2.txt > reportes_corregido.php

echo Eliminando archivos temporales...
del temp_fix_1.txt
del temp_fix_2.txt

echo.
echo Corrección completada.
echo Nuevo archivo creado: reportes_corregido.php
echo.
echo Revisa que el archivo esté correcto y luego reemplaza el original:
echo copy reportes_corregido.php reportes.php
echo.

pause