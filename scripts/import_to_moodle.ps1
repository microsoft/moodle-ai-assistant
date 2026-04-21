<#
.SYNOPSIS
    Imports courses into Moodle running in Docker.
.DESCRIPTION
    Copies courses.json and import_courses.php into the Moodle container
    and runs the import script via Moodle CLI.
#>

$containerName = 'moodle-assistant-moodle-1'

# Verify container is running
$running = docker inspect -f '{{.State.Running}}' $containerName 2>&1
if ($running -ne 'true') {
    Write-Host 'ERROR: Moodle container is not running. Run docker-compose up -d first.' -ForegroundColor Red
    exit 1
}

Write-Host '=== Importing courses into Moodle ===' -ForegroundColor Cyan
Write-Host ''

# Copy files into container
Write-Host 'Copying files to container...' -ForegroundColor Yellow
docker cp "$PSScriptRoot\courses.json" "${containerName}:/tmp/courses.json"
docker cp "$PSScriptRoot\import_courses.php" "${containerName}:/tmp/import_courses.php"

# Run the import script as www-data (Moodle's web user)
Write-Host 'Running import...' -ForegroundColor Yellow
Write-Host ''
docker exec -u www-data $containerName php /tmp/import_courses.php /tmp/courses.json 2>&1

Write-Host ''
Write-Host '=== Done! ===' -ForegroundColor Cyan
Write-Host 'Open http://localhost:8080 to see the courses.' -ForegroundColor White
