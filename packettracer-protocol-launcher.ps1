param(
  [Parameter(ValueFromRemainingArguments = $true)]
  [string[]]$ProtocolArgs
)

function Show-LauncherMessage {
  param(
    [string]$Message,
    [string]$Title = "CCNA Lab Tracker"
  )

  try {
    Add-Type -AssemblyName PresentationFramework -ErrorAction Stop
    [System.Windows.MessageBox]::Show($Message, $Title) | Out-Null
  } catch {
    Write-Error $Message
  }
}

if (-not $ProtocolArgs -or -not $ProtocolArgs[0]) {
  Show-LauncherMessage "No lab file was supplied to the Packet Tracer launcher."
  exit 1
}

$rawValue = $ProtocolArgs[0]
$encodedValue = if ($rawValue.StartsWith("ccna-pkt:", [System.StringComparison]::OrdinalIgnoreCase)) {
  $rawValue.Substring(9)
} else {
  $rawValue
}

$decodedValue = [System.Uri]::UnescapeDataString($encodedValue)

try {
  if ($decodedValue -match "^file:/") {
    $labPath = ([System.Uri]$decodedValue).LocalPath
  } else {
    $labPath = $decodedValue
  }
} catch {
  Show-LauncherMessage "The lab path could not be understood.`n`n$decodedValue"
  exit 1
}

if (-not (Test-Path -LiteralPath $labPath)) {
  Show-LauncherMessage "The target lab file was not found.`n`n$labPath"
  exit 1
}

try {
  Start-Process -FilePath $labPath -WorkingDirectory (Split-Path -Parent $labPath) | Out-Null
} catch {
  try {
    $packetTracerPath = "C:\Program Files\Cisco Packet Tracer 9.0.0\bin\PacketTracer.exe"
    if (-not (Test-Path -LiteralPath $packetTracerPath)) {
      throw "Packet Tracer executable was not found."
    }
    Start-Process -FilePath $packetTracerPath -ArgumentList @($labPath) -WorkingDirectory (Split-Path -Parent $labPath) | Out-Null
  } catch {
    Show-LauncherMessage ("Packet Tracer could not be launched.`n`n" + $_.Exception.Message)
    exit 1
  }
}
