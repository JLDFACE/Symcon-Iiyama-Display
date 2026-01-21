# Iiyama Display Control (IP-Symcon)

Dieses Repository stellt ein IP-Symcon Modul zur Ansteuerung von iiyama Digital-Signage/Professional Displays bereit, die das iiyama RS232/LAN Command Protocol unterstützen (typisch TCP Port 5000).

Getestete Zielausstattung (Konfiguration/Mappings): **ProLite LE9864UHS-B1AG** (HDMI1/HDMI2/USB-C + interne Android-Quellen).

## Enthaltene Module

- **Iiyama Display (LAN/RS232)**  
  Steuerung über kurzlebige TCP-Verbindungen pro Request (kein IO-Modul erforderlich).  
  Unterstützt die Funktionsgruppen **A, B, C und E**:
  - Power (Ein/Aus) inkl. Status
  - Input/Quelle setzen & lesen
  - Lautstärke setzen & lesen
  - Geräte-Infos (Model/FW Label) und Operating Hours

## Voraussetzungen

- IP-Symcon ab Version 5.x (SymBox-kompatibel)
- Netzwerkzugriff auf das Display
- Display-Einstellung: LAN Control aktiv; Port üblicherweise **5000**
- Monitor-ID (1..255), Standard ist häufig **1**

## Installation (Module Control / GitHub)

1. In IP-Symcon: **Kernel → Module Control → Hinzufügen**
2. GitHub-Repository URL eintragen
3. Nach der Installation im Objektbaum: **Instanz hinzufügen** → „Iiyama Display (LAN/RS232)“

## Konfiguration

| Parameter | Beschreibung | Default |
|---|---|---|
| Host | IP/Hostname des Displays | - |
| Port | TCP Port für LAN Control | 5000 |
| Monitor ID | Display-Adresse 1..255 | 1 |
| Timeout | Socket-Timeout in ms | 1000 |
| Poll (Slow) | Standard-Polling in Sekunden | 15 |
| Poll (Fast) | Fast-Polling in Sekunden (Transitions/Pending) | 2 |
| FastAfterChange | nach Statusänderung für X Sekunden Fast-Poll | 30 |
| InputDelayAfterPowerOn | Delay (ms) für Input-Set nach echtem Power-On | 8000 |

## Inputs (LE9864UHS-B1AG)

Das Modul bietet im Input-Profil:
- HDMI1, HDMI2, USB-C
- Browser, CMS, File Manager, Media Player, PDF Player, Custom

Hinweis: Die internen Quellen basieren auf den iiyama Input-Type-Codes; je nach Firmware kann die tatsächliche Belegung leicht variieren.

## Variablen

- **Power** (Boolean, schaltbar)
- **Input** (Integer, schaltbar; Profil mit gängigen Quellen)
- **Volume** (Integer 0..100, schaltbar; geräteabhängig)
- **OperatingHours** (Integer, Stunden)
- **ModelName** (String)
- **FirmwareVersion** (String)
- **Online** (Boolean)
- **LastError** (String)

## UX-/Logik-Highlights

- „Kombinierte Bedienung+Status“: Power/Input/Volume sind schaltbar und werden zyklisch verifiziert.
- Pending/Sollwert-Logik: Anzeige „flippt“ nicht während ein Sollwert gerade gesetzt wurde.
- „Soll-Quelle automatisch löschen, sobald erreicht“.
- Input-Delay wird nur nach echtem Power-On (Off→On) angewendet.
- Dynamisches Polling: Slow/Fast + FastAfterChange.

## Diagnose

- **Online** wird bei erfolgreichem Poll/Request gesetzt.
- **LastError** wird nur bei Änderung geschrieben, um Log/DB zu schonen.
- Fehler eskalieren nicht als Fatal; Kommunikation ist robust gegen Timeouts/Lock-Contention.

## Referenz (Protokoll)

Das Modul orientiert sich am iiyama RS232/LAN Command Protocol (TCP 5000), u. a.:
- Power State Get/Set (0x19/0x18)
- Input Source Set / Current Source Get (0xAC/0xAD)
- Volume Get/Set (0x45/0x44)
- Platform/Version Labels (0xA2)
- Operating Hours (Misc Info 0x0F Subcommand 0x02)

## Lizenz

MIT (kann bei Bedarf ergänzt werden).
