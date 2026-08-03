# Bring the client's work back into the repo.
#
# Downloads every uploaded photo plus the copy and background changes from the
# live preview into public/assets/uploads/, rewriting the image URLs to local
# paths. After this the repo renders the client's final version on its own,
# with no backend — which is the copy you hand to the WordPress build.
#
#   powershell -ExecutionPolicy Bypass -File scripts\pull.ps1 -Site https://your-preview.vercel.app
#
# Then review and commit:
#   git add public/assets/uploads
#   git commit -m "Pull client photos and copy"

param(
  [Parameter(Mandatory = $true)][string]$Site
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$outDir = Join-Path $root "public\assets\uploads"

New-Item -ItemType Directory -Force -Path $outDir | Out-Null

$stateUrl = $Site.TrimEnd("/") + "/api/state"
Write-Host "reading $stateUrl"

# Decoded from the raw bytes rather than through Invoke-RestMethod, which on
# Windows PowerShell 5.1 falls back to ISO-8859-1 and turns every ö into Ã¶.
$response = Invoke-WebRequest -Uri $stateUrl -UseBasicParsing -Headers @{ "Cache-Control" = "no-cache" }
$state = [System.Text.Encoding]::UTF8.GetString($response.RawContentStream.ToArray()) | ConvertFrom-Json

if (-not $state.ok) {
  Write-Warning "the server reported a problem: $($state.error)"
}

$extByType = @{
  "image/jpeg" = ".jpg"; "image/png" = ".png"; "image/webp" = ".webp"
  "image/avif" = ".avif"; "image/gif" = ".gif"; "image/svg+xml" = ".svg"
}

$localImages = [ordered]@{}
$count = 0

foreach ($entry in $state.images.PSObject.Properties) {
  $slot = $entry.Name
  $url = $entry.Value

  # Vercel Blob hands back absolute URLs; the local dev store uses paths
  # relative to the site root.
  if ($url -notmatch '^https?://') { $url = $Site.TrimEnd("/") + $url }

  # The pathname carries the real extension; the ?v= cache buster does not.
  $clean = ($url -split "\?")[0]
  $ext = [System.IO.Path]::GetExtension($clean)

  if (-not $ext) {
    $type = (Invoke-WebRequest -Uri $url -Method Head -UseBasicParsing).Headers["Content-Type"]
    if ($type -and $extByType.ContainsKey($type)) { $ext = $extByType[$type] } else { $ext = ".jpg" }
  }

  $fileName = "$slot$ext"
  $target = Join-Path $outDir $fileName

  Write-Host ("  {0,-28} -> assets/uploads/{1}" -f $slot, $fileName)
  Invoke-WebRequest -Uri $url -OutFile $target -UseBasicParsing

  $localImages[$slot] = "assets/uploads/$fileName"
  $count++
}

# Background images live in styles as absolute blob URLs; point them at the
# local copies too, so the checked-in version needs no network at all.
$styles = [ordered]@{}
if ($state.styles) {
  foreach ($entry in $state.styles.PSObject.Properties) {
    $value = [ordered]@{}
    foreach ($field in $entry.Value.PSObject.Properties) {
      $fieldValue = $field.Value
      if ($field.Name -eq "image" -and $fieldValue) {
        foreach ($slot in $localImages.Keys) {
          if ($fieldValue -like "*$slot.*") { $fieldValue = $localImages[$slot]; break }
        }
      }
      $value[$field.Name] = $fieldValue
    }
    $styles[$entry.Name] = $value
  }
}

$texts = [ordered]@{}
if ($state.texts) {
  foreach ($entry in $state.texts.PSObject.Properties) { $texts[$entry.Name] = $entry.Value }
}

# Comments come along too, so the review thread is preserved in the repo
# rather than living only in the preview.
$notes = [ordered]@{}
if ($state.notes) {
  foreach ($entry in $state.notes.PSObject.Properties) { $notes[$entry.Name] = $entry.Value }
}

$slotFits = [ordered]@{}
if ($state.slots) {
  foreach ($entry in $state.slots.PSObject.Properties) { $slotFits[$entry.Name] = $entry.Value }
}

$out = [ordered]@{ images = $localImages; texts = $texts; styles = $styles; notes = $notes; slots = $slotFits }
$json = $out | ConvertTo-Json -Depth 8
[System.IO.File]::WriteAllText((Join-Path $outDir "state.json"), $json, [System.Text.UTF8Encoding]::new($false))

$openNotes = @($notes.Values | Where-Object { -not $_.resolved }).Count

Write-Host ""
Write-Host "$count image(s), $($texts.Count) text change(s), $($styles.Count) background change(s)"
Write-Host "$($notes.Count) note(s), $openNotes still open"
Write-Host "written to public/assets/uploads/"
Write-Host ""
Write-Host "review, then:  git add public/assets/uploads && git commit -m ""Pull client content"""
