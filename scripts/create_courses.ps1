<#
.SYNOPSIS
    Genera 5 corsi Moodle (.mbz) pronti per l'upload.
.DESCRIPTION
    Legge courses.json e genera file .mbz nella cartella dist/.
#>

param([string]$OutputPath = 'dist')

$ErrorActionPreference = 'Stop'

function Ensure-Dir([string]$p) {
    if (-not (Test-Path $p)) { New-Item -ItemType Directory -Path $p -Force | Out-Null }
}

function XmlEsc([string]$s) {
    return [System.Security.SecurityElement]::Escape($s)
}

# ── Load course data from JSON ─────────────────────────────────────────────────

$jsonPath = Join-Path $PSScriptRoot 'courses.json'
$courses  = Get-Content -Path $jsonPath -Raw -Encoding UTF8 | ConvertFrom-Json

$timestamp = [int](Get-Date -Date '2026-03-23T08:00:00Z' -UFormat '%s')
$fullDist  = Join-Path (Get-Location) $OutputPath
Ensure-Dir $fullDist

Write-Host '=== Generazione corsi Moodle ===' -ForegroundColor Cyan
Write-Host ''

$courseIdx = 0

foreach ($course in $courses) {
    $courseIdx++
    $courseId  = $courseIdx
    $ctxId    = 100 + $courseIdx
    $guid     = [guid]::NewGuid().ToString()

    Write-Host "[$courseIdx/5] $($course.FullName) ($($course.ShortName))..." -ForegroundColor Yellow

    $tmp = Join-Path $env:TEMP "moodle_c$courseIdx"
    if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
    Ensure-Dir $tmp

    # ── Collect entries ────────────────────────────────────────────────────────
    $secEntries = @()
    $actEntries = @()
    $modId      = 0
    $instCount  = @{ page = 0; forum = 0; assign = 0 }

    $secIdx = 0
    foreach ($sec in $course.Sections) {
        $secIdx++
        $secId = $secIdx
        $modsInSec = @()

        $secEntries += [PSCustomObject]@{ Id = $secId; Title = $sec.Title }

        foreach ($act in $sec.Activities) {
            $modId++
            $instCount[$act.Type]++
            $iid = $instCount[$act.Type]
            $modsInSec += $modId

            $actEntries += [PSCustomObject]@{
                ModuleId   = $modId
                SectionId  = $secId
                Type       = $act.Type
                Name       = $act.Name
                InstanceId = $iid
            }

            # Activity directory
            $aDir = Join-Path $tmp "activities/$($act.Type)_$modId"
            Ensure-Dir $aDir

            # ── module.xml ─────────────────────────────────────────────────────
            @"
<?xml version="1.0" encoding="UTF-8"?>
<module id="$modId" version="2024042200">
  <modulename>$($act.Type)</modulename>
  <sectionid>$secId</sectionid>
  <sectionnumber>$($secIdx - 1)</sectionnumber>
  <idnumber></idnumber>
  <added>$timestamp</added>
  <score>0</score>
  <indent>0</indent>
  <visible>1</visible>
  <visibleoncoursepage>1</visibleoncoursepage>
  <visibleold>1</visibleold>
  <groupmode>0</groupmode>
  <groupingid>0</groupingid>
  <completion>1</completion>
  <completiongradeitemnumber></completiongradeitemnumber>
  <completionview>0</completionview>
  <completionexpected>0</completionexpected>
  <availability></availability>
  <showdescription>0</showdescription>
  <downloadcontent>1</downloadcontent>
  <lang></lang>
</module>
"@ | Set-Content (Join-Path $aDir 'module.xml') -Encoding UTF8

            # ── activity XML ───────────────────────────────────────────────────
            $actCtx = 300 + $modId

            switch ($act.Type) {
                'page' {
                    $content = if ($act.Content) { $act.Content } else { '' }
                    @"
<?xml version="1.0" encoding="UTF-8"?>
<activity id="$iid" moduleid="$modId" modulename="page" contextid="$actCtx">
  <page id="$iid">
    <name>$(XmlEsc $act.Name)</name>
    <intro></intro>
    <introformat>1</introformat>
    <content>$(XmlEsc $content)</content>
    <contentformat>1</contentformat>
    <legacyfiles>0</legacyfiles>
    <legacyfileslast></legacyfileslast>
    <display>5</display>
    <displayoptions>a:2:{s:12:"printheading";s:1:"1";s:10:"printintro";s:1:"0";}</displayoptions>
    <revision>1</revision>
    <timemodified>$timestamp</timemodified>
  </page>
</activity>
"@ | Set-Content (Join-Path $aDir 'page.xml') -Encoding UTF8
                }
                'forum' {
                    $intro = if ($act.Intro) { $act.Intro } else { '' }
                    @"
<?xml version="1.0" encoding="UTF-8"?>
<activity id="$iid" moduleid="$modId" modulename="forum" contextid="$actCtx">
  <forum id="$iid">
    <type>general</type>
    <name>$(XmlEsc $act.Name)</name>
    <intro>$(XmlEsc $intro)</intro>
    <introformat>1</introformat>
    <duedate>0</duedate>
    <cutoffdate>0</cutoffdate>
    <assessed>0</assessed>
    <assesstimestart>0</assesstimestart>
    <assesstimefinish>0</assesstimefinish>
    <scale>0</scale>
    <maxbytes>512000</maxbytes>
    <maxattachments>9</maxattachments>
    <forcesubscribe>0</forcesubscribe>
    <trackingtype>1</trackingtype>
    <rsstype>0</rsstype>
    <rssarticles>0</rssarticles>
    <timemodified>$timestamp</timemodified>
    <warnafter>0</warnafter>
    <blockafter>0</blockafter>
    <blockperiod>0</blockperiod>
    <completiondiscussions>0</completiondiscussions>
    <completionreplies>0</completionreplies>
    <completionposts>0</completionposts>
    <displaywordcount>0</displaywordcount>
    <lockdiscussionafter>0</lockdiscussionafter>
    <grade_forum>0</grade_forum>
    <discussions>
    </discussions>
  </forum>
</activity>
"@ | Set-Content (Join-Path $aDir 'forum.xml') -Encoding UTF8
                }
                'assign' {
                    $intro = if ($act.Intro) { $act.Intro } else { '' }
                    @"
<?xml version="1.0" encoding="UTF-8"?>
<activity id="$iid" moduleid="$modId" modulename="assign" contextid="$actCtx">
  <assign id="$iid">
    <name>$(XmlEsc $act.Name)</name>
    <intro>$(XmlEsc $intro)</intro>
    <introformat>1</introformat>
    <alwaysshowdescription>1</alwaysshowdescription>
    <submissiondrafts>0</submissiondrafts>
    <sendnotifications>0</sendnotifications>
    <sendlatenotifications>0</sendlatenotifications>
    <sendstudentnotifications>1</sendstudentnotifications>
    <duedate>0</duedate>
    <cutoffdate>0</cutoffdate>
    <gradingduedate>0</gradingduedate>
    <allowsubmissionsfromdate>0</allowsubmissionsfromdate>
    <grade>100</grade>
    <timemodified>$timestamp</timemodified>
    <completionsubmit>1</completionsubmit>
    <requiresubmissionstatement>0</requiresubmissionstatement>
    <teamsubmission>0</teamsubmission>
    <requireallteammemberssubmit>0</requireallteammemberssubmit>
    <teamsubmissiongroupingid>0</teamsubmissiongroupingid>
    <blindmarking>0</blindmarking>
    <hidegrader>0</hidegrader>
    <revealidentities>0</revealidentities>
    <attemptreopenmethod>none</attemptreopenmethod>
    <maxattempts>-1</maxattempts>
    <markingworkflow>0</markingworkflow>
    <markingallocation>0</markingallocation>
    <preventsubmissionnotingroup>0</preventsubmissionnotingroup>
    <activity_groupmode>0</activity_groupmode>
    <plugin_configs>
      <plugin_config>
        <plugin>onlinetext</plugin>
        <subtype>assignsubmission</subtype>
        <name>enabled</name>
        <value>1</value>
      </plugin_config>
      <plugin_config>
        <plugin>file</plugin>
        <subtype>assignsubmission</subtype>
        <name>enabled</name>
        <value>1</value>
      </plugin_config>
      <plugin_config>
        <plugin>file</plugin>
        <subtype>assignsubmission</subtype>
        <name>maxfilesubmissions</name>
        <value>3</value>
      </plugin_config>
      <plugin_config>
        <plugin>file</plugin>
        <subtype>assignsubmission</subtype>
        <name>maxsubmissionsizebytes</name>
        <value>5242880</value>
      </plugin_config>
      <plugin_config>
        <plugin>comments</plugin>
        <subtype>assignfeedback</subtype>
        <name>enabled</name>
        <value>1</value>
      </plugin_config>
    </plugin_configs>
    <overrides>
    </overrides>
    <grades>
    </grades>
    <userflags>
    </userflags>
    <submissions>
    </submissions>
  </assign>
</activity>
"@ | Set-Content (Join-Path $aDir 'assign.xml') -Encoding UTF8
                }
            }

            # ── Supporting files ───────────────────────────────────────────────
            '<?xml version="1.0" encoding="UTF-8"?><inforef><fileref></fileref></inforef>' |
                Set-Content (Join-Path $aDir 'inforef.xml') -Encoding UTF8
            '<?xml version="1.0" encoding="UTF-8"?><activity_gradebook><grade_items></grade_items><grade_letters></grade_letters></activity_gradebook>' |
                Set-Content (Join-Path $aDir 'grades.xml') -Encoding UTF8
            '<?xml version="1.0" encoding="UTF-8"?><grade_history></grade_history>' |
                Set-Content (Join-Path $aDir 'grade_history.xml') -Encoding UTF8
            '<?xml version="1.0" encoding="UTF-8"?><roles></roles>' |
                Set-Content (Join-Path $aDir 'roles.xml') -Encoding UTF8
            '<?xml version="1.0" encoding="UTF-8"?><filters></filters>' |
                Set-Content (Join-Path $aDir 'filters.xml') -Encoding UTF8
            '<?xml version="1.0" encoding="UTF-8"?><comments></comments>' |
                Set-Content (Join-Path $aDir 'comments.xml') -Encoding UTF8
            '<?xml version="1.0" encoding="UTF-8"?><events></events>' |
                Set-Content (Join-Path $aDir 'calendar.xml') -Encoding UTF8
            '<?xml version="1.0" encoding="UTF-8"?><completion></completion>' |
                Set-Content (Join-Path $aDir 'completion.xml') -Encoding UTF8
        }

        # ── Section directory ──────────────────────────────────────────────────
        $sDir = Join-Path $tmp "sections/section_$secId"
        Ensure-Dir $sDir

        $seq = ($modsInSec -join ',')
        @"
<?xml version="1.0" encoding="UTF-8"?>
<section id="$secId">
  <number>$($secIdx - 1)</number>
  <name>$(XmlEsc $sec.Title)</name>
  <summary>$(XmlEsc $sec.Summary)</summary>
  <summaryformat>1</summaryformat>
  <sequence>$seq</sequence>
  <visible>1</visible>
  <availabilityjson></availabilityjson>
  <timemodified>$timestamp</timemodified>
</section>
"@ | Set-Content (Join-Path $sDir 'section.xml') -Encoding UTF8

        '<?xml version="1.0" encoding="UTF-8"?><inforef><fileref></fileref></inforef>' |
            Set-Content (Join-Path $sDir 'inforef.xml') -Encoding UTF8
    }

    # ── Course directory ───────────────────────────────────────────────────────
    $cDir = Join-Path $tmp 'course'
    Ensure-Dir $cDir

    @"
<?xml version="1.0" encoding="UTF-8"?>
<course id="$courseId" contextid="$ctxId">
  <shortname>$(XmlEsc $course.ShortName)</shortname>
  <fullname>$(XmlEsc $course.FullName)</fullname>
  <idnumber></idnumber>
  <summary>$(XmlEsc $course.Summary)</summary>
  <summaryformat>1</summaryformat>
  <format>topics</format>
  <showgrades>1</showgrades>
  <newsitems>5</newsitems>
  <startdate>$timestamp</startdate>
  <enddate>0</enddate>
  <marker>0</marker>
  <maxbytes>0</maxbytes>
  <legacyfiles>0</legacyfiles>
  <showreports>0</showreports>
  <visible>1</visible>
  <groupmode>0</groupmode>
  <groupmodeforce>0</groupmodeforce>
  <defaultgroupingid>0</defaultgroupingid>
  <lang></lang>
  <theme></theme>
  <timecreated>$timestamp</timecreated>
  <timemodified>$timestamp</timemodified>
  <requested>0</requested>
  <enablecompletion>1</enablecompletion>
  <completionnotify>0</completionnotify>
  <hiddensections>0</hiddensections>
  <coursedisplay>0</coursedisplay>
  <category id="1">
    <name>$(XmlEsc $course.Category)</name>
    <description></description>
  </category>
</course>
"@ | Set-Content (Join-Path $cDir 'course.xml') -Encoding UTF8

    '<?xml version="1.0" encoding="UTF-8"?><inforef><fileref></fileref></inforef>' |
        Set-Content (Join-Path $cDir 'inforef.xml') -Encoding UTF8
    '<?xml version="1.0" encoding="UTF-8"?><enrolments><enrols></enrols></enrolments>' |
        Set-Content (Join-Path $cDir 'enrolments.xml') -Encoding UTF8
    '<?xml version="1.0" encoding="UTF-8"?><roles></roles>' |
        Set-Content (Join-Path $cDir 'roles.xml') -Encoding UTF8
    '<?xml version="1.0" encoding="UTF-8"?><course_completion_defaults></course_completion_defaults>' |
        Set-Content (Join-Path $cDir 'completiondefaults.xml') -Encoding UTF8

    # ── Root-level files ───────────────────────────────────────────────────────

    # Build sections block
    $secBlock = ''
    foreach ($s in $secEntries) {
        $secBlock += "        <section><sectionid>$($s.Id)</sectionid><title>$(XmlEsc $s.Title)</title><directory>sections/section_$($s.Id)</directory></section>`n"
    }

    # Build activities block
    $actBlock = ''
    foreach ($a in $actEntries) {
        $actBlock += "        <activity><moduleid>$($a.ModuleId)</moduleid><sectionid>$($a.SectionId)</sectionid><modulename>$($a.Type)</modulename><title>$(XmlEsc $a.Name)</title><directory>activities/$($a.Type)_$($a.ModuleId)</directory></activity>`n"
    }

    @"
<?xml version="1.0" encoding="UTF-8"?>
<moodle_backup>
  <information>
    <name>backup-moodle2-course-$courseId</name>
    <moodle_version>2024042200</moodle_version>
    <moodle_release>4.4+ (Build: 20240422)</moodle_release>
    <backup_version>2024042200</backup_version>
    <backup_release>4.4</backup_release>
    <backup_date>$timestamp</backup_date>
    <mnet_remoteusers>0</mnet_remoteusers>
    <include_files>0</include_files>
    <include_file_references_to_external_content>0</include_file_references_to_external_content>
    <original_wwwroot>https://moodle.example.com</original_wwwroot>
    <original_site_identifier_hash>$guid</original_site_identifier_hash>
    <original_course_id>$courseId</original_course_id>
    <original_course_format>topics</original_course_format>
    <original_course_fullname>$(XmlEsc $course.FullName)</original_course_fullname>
    <original_course_shortname>$(XmlEsc $course.ShortName)</original_course_shortname>
    <original_course_startdate>$timestamp</original_course_startdate>
    <original_course_enddate>0</original_course_enddate>
    <original_course_contextid>$ctxId</original_course_contextid>
    <original_system_contextid>1</original_system_contextid>
    <type>course</type>
    <format>moodle2</format>
    <interactive>0</interactive>
    <mode>10</mode>
    <execution>1</execution>
    <executiontime>0</executiontime>
    <contents>
      <activities>
$actBlock
      </activities>
      <sections>
$secBlock
      </sections>
      <course>
        <courseid>$courseId</courseid>
        <title>$(XmlEsc $course.FullName)</title>
        <directory>course</directory>
      </course>
    </contents>
    <settings>
      <setting><level>root</level><name>filename</name><value>backup-moodle2-course-$courseId.mbz</value></setting>
      <setting><level>root</level><name>users</name><value>0</value></setting>
      <setting><level>root</level><name>anonymize</name><value>0</value></setting>
      <setting><level>root</level><name>role_assignments</name><value>0</value></setting>
      <setting><level>root</level><name>activities</name><value>1</value></setting>
      <setting><level>root</level><name>blocks</name><value>0</value></setting>
      <setting><level>root</level><name>filters</name><value>0</value></setting>
      <setting><level>root</level><name>comments</name><value>0</value></setting>
      <setting><level>root</level><name>badges</name><value>0</value></setting>
      <setting><level>root</level><name>calendarevents</name><value>0</value></setting>
      <setting><level>root</level><name>userscompletion</name><value>0</value></setting>
      <setting><level>root</level><name>logs</name><value>0</value></setting>
      <setting><level>root</level><name>grade_histories</name><value>0</value></setting>
      <setting><level>root</level><name>questionbank</name><value>0</value></setting>
      <setting><level>root</level><name>groups</name><value>0</value></setting>
      <setting><level>root</level><name>competencies</name><value>0</value></setting>
      <setting><level>root</level><name>customfield</name><value>0</value></setting>
      <setting><level>root</level><name>contentbankcontent</name><value>0</value></setting>
      <setting><level>root</level><name>legacyfiles</name><value>0</value></setting>
    </settings>
  </information>
</moodle_backup>
"@ | Set-Content (Join-Path $tmp 'moodle_backup.xml') -Encoding UTF8

    '<?xml version="1.0" encoding="UTF-8"?><files></files>' |
        Set-Content (Join-Path $tmp 'files.xml') -Encoding UTF8
    '<?xml version="1.0" encoding="UTF-8"?><scales_definition></scales_definition>' |
        Set-Content (Join-Path $tmp 'scales.xml') -Encoding UTF8
    '<?xml version="1.0" encoding="UTF-8"?><outcomes_definition></outcomes_definition>' |
        Set-Content (Join-Path $tmp 'outcomes.xml') -Encoding UTF8
    '<?xml version="1.0" encoding="UTF-8"?><roles_definition></roles_definition>' |
        Set-Content (Join-Path $tmp 'roles.xml') -Encoding UTF8
    '<?xml version="1.0" encoding="UTF-8"?><question_categories></question_categories>' |
        Set-Content (Join-Path $tmp 'questions.xml') -Encoding UTF8
    '<?xml version="1.0" encoding="UTF-8"?><course_completion></course_completion>' |
        Set-Content (Join-Path $tmp 'completion.xml') -Encoding UTF8
    '<?xml version="1.0" encoding="UTF-8"?><groups></groups>' |
        Set-Content (Join-Path $tmp 'groups.xml') -Encoding UTF8

    # ── Package as .mbz ────────────────────────────────────────────────────────
    $safeName = ($course.ShortName.ToLower()) + '_' + (($course.FullName -replace '[^a-zA-Z0-9]','_') -replace '_+','_' -replace '_$','')
    $mbzPath  = Join-Path $fullDist "$safeName.mbz"

    if (Test-Path $mbzPath) { Remove-Item $mbzPath -Force }

    $zipPath = $mbzPath -replace '\.mbz$', '.zip'
    Compress-Archive -Path "$tmp\*" -DestinationPath $zipPath -Force
    Rename-Item -Path $zipPath -NewName (Split-Path $mbzPath -Leaf) -Force

    Remove-Item $tmp -Recurse -Force

    $kb = [math]::Round((Get-Item $mbzPath).Length / 1024, 1)
    Write-Host "  -> $(Split-Path $mbzPath -Leaf) ($kb KB)" -ForegroundColor Green
}

Write-Host ''
Write-Host '=== Completato! 5 corsi generati ===' -ForegroundColor Cyan
Write-Host ''

Get-ChildItem $fullDist -Filter '*.mbz' | ForEach-Object {
    $kb = [math]::Round($_.Length / 1024, 1)
    Write-Host "  $($_.Name) ($kb KB)" -ForegroundColor White
}

Write-Host ''
Write-Host 'Per importare: Moodle > Amministrazione > Ripristina corso > Carica file .mbz' -ForegroundColor Cyan
