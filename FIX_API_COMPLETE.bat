@echo off
title DPP API Fix Script
cd E:\Dev\Zee\backend-leave

:: ============================================
:: STEP 1: Start Backend
:: ============================================
echo.
echo 1. Starting Laravel Backend Server...
echo.
start "LaravelBackend" /B php artisan serve --port=8000 >NUL 2>&1
timeout /t 5 >nul
echo.
echo Backend launched. Checking if running...
echo.

:: Wait and check
for /L %i in (1 1 10) do (
    timeout /t 1 >nul
    find "running" NUL 2>&1 && (
        echo Backend CONFIRMED running on http://127.0.0.1:8000
        goto :BACKEND_READY
    )
)
echo.
echo WARNING: Backend may not have started. Check manually.
echo "php artisan serve --port=8000" should show "Server running on [http://127.0.0.1:8000]"
goto :TEST_ENDPOINTS

:BACKEND_READY
echo.
echo ============================================
echo STEP 2: Login & Get Token
echo ============================================
echo.
echo Logging in as ali.mwinyi@dpp.zn...
echo.

set "CREDS=%TEMP%\dpp_creds.json"
echo {"email":"ali.mwinyi@dpp.zn","password":"password"} > "%CREDS%"
for /f "tokens=2 delims=" %%A in (
    'curl -s -X POST http://127.0.0.1:8000/api/login -H "Content-Type: application/json" -d "@%CREDS%"'
) do (
    set "AUTH_TOKEN=%%A"
)

if "%AUTH_TOKEN%"=="" (
    echo.
    echo ERROR: Login failed. Backend not running?
    pause
    exit /b 1
)

echo.
echo Login SUCCESSFUL.
echo Token (first 30 chars): %AUTH_TOKEN:~0,30%
echo.

:: ============================================
echo STEP 3: Test All 4 Endpoints
echo ============================================
echo.

:: Test 1: Departments
echo.
echo 1. Testing /api/org/departments...
curl -s -H "Authorization: Bearer %AUTH_TOKEN%" http://127.0.0.1:8000/api/org/departments >nul
set "ERRL1=%errorlevel%"
if %ERRORLEVEL% equ 0 (
    echo   RESULT: SUCCESS - Departments works
) else (
    echo   RESULT: FAILED - 403/500 error
)

:: Test 2: Positions
echo.
echo 2. Testing /api/org/positions...
curl -s -H "Authorization: Bearer %AUTH_TOKEN%" http://127.0.0.1:8000/api/org/positions >nul
set "ERRL2=%errorlevel%"
if %ERRORLEVEL% equ 0 (
    echo   RESULT: SUCCESS - Positions works
) else (
    echo   RESULT: FAILED - 403/500 error
)

:: Test 3: Workflows
echo.
echo 3. Testing /api/workflows...
curl -s -H "Authorization: Bearer %AUTH_TOKEN%" http://127.0.0.1:8000/api/workflows >nul
set "ERRL3=%errorlevel%"
if %ERRORLEVEL% equ 0 (
    echo   RESULT: SUCCESS - Workflows works
) else (
    echo   RESULT: FAILED - 403/500 error (may need HR role)
)

:: Test 4: Employees
echo.
echo 4. Testing /api/org/employees...
curl -s -H "Authorization: Bearer %AUTH_TOKEN%" http://127.0.0.1:8000/api/org/employees >nul
set "ERRL4=%errorlevel%"
if %ERRORLEVEL% equ 0 (
    echo   RESULT: SUCCESS - Employees works
) else (
    echo   RESULT: FAILED - 403/500 error
)

:: ============================================
echo.
echo ============================================
echo STEP 4: Results & Fixes
echo ============================================
echo.
echo ============================================
echo TEST SUMMARY
echo ============================================
echo.
echo 1. Departments: %ERRL1%
echo 2. Positions: %ERRL2%
echo 3. Workflows: %ERRL3%
echo 4. Employees: %ERRL4%
echo.

echo IF ALL 4 ARE "SUCCESS" (or errorlevel 0):
echo   - Add CORS_ORIGIN=http://localhost:3000 to .env
echo   - Restart Next.js: npm run dev
echo   - Divisions/Units UI should now work
echo.
echo IF WORKFLOWS (3) FAILED:
echo   - Login as u3 instead: fatma.hassan@dpp.zn / password
echo   - Or grant policies.view + approval_workflows.view to user
echo.
echo IF EMPLOYEES (4) SHOWS LIMITED DATA:
echo   - This is normal for SELF_SERVICE users
echo   - Login as u1 for full list, or expected behavior
echo.
echo ============================================
echo NEXT STEPS
echo ============================================
echo.
echo 1. Fix any FAILED tests above
echo 2. Add CORS_ORIGIN=http://localhost:3000 to .env
echo 2. Restart frontend: npm run dev (in your frontend folder)
echo 3. Test: http://localhost:3000/admin/org/divisions
echo 4. Test: http://localhost:3000/admin/org/units
echo.
echo ============================================
echo DONE - Check results above, then proceed
echo.
pause