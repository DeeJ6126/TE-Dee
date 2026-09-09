@echo off
setlocal

set "NEO4J_HOME=%USERPROFILE%\.Neo4jDesktop2\Data\dbmss\dbms-c5bb13d1-8ea4-47bc-95ba-227bf634a785"

if not exist "%NEO4J_HOME%\bin\neo4j.bat" (
    echo [ERROR] TE-KG Neo4j installation not found:
    echo %NEO4J_HOME%
    pause
    exit /b 1
)

cd /d "%NEO4J_HOME%"
title TE-KG - Neo4j Console

echo Starting TE-KG...
echo.
echo Keep this window open while Neo4j is running.
echo Close this window to stop Neo4j.
echo.

call "%NEO4J_HOME%\bin\neo4j.bat" console

set "EXIT_CODE=%ERRORLEVEL%"
echo.
echo Neo4j exited with code %EXIT_CODE%.
pause

endlocal
exit /b %EXIT_CODE%