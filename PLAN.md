# Power Guard – Plan

Unraid-Plugin, das **AutoTweak** und **Tips and Tweaks** vollständig ablöst. Es optimiert das System hardware- und versionsgerecht nach aktuellen Linux-Best-Practices (Kernel 6.18 / Unraid 7.4) und hat eine moderne Weboberfläche.

Status: **P1 veröffentlicht** (Release 2026.10.14), **P2 implementiert** (Apply/Revert/Journal, Branch `phase-2`). P2 ist gegen ein Fake-sysfs getestet (`tests/engine.sh`), noch nicht auf dem Live-System angewendet.

### Korrekturen aus P1 (Live-Erkennung vom 14.10.2026)
- **NMI-Watchdog:** Der Unraid-Kernel hat keinen Hard-Lockup-Detektor (`/proc/sys/kernel/nmi_watchdog` fehlt). Es gibt nichts abzuschalten; der Tweak ist „Nicht zutreffend“.
- **ASPM:** Die Firmware behält die Kontrolle, aktiviert L1 aber selbst auf allen 8 Links (LnkCtl), inklusive I226-V. Die Policy-Datei ist wirkungslos. Für die NIC gilt bereits ein L1-Hinweis.
- **grub.cfg** hat **LF**-Zeilenenden, nicht CRLF. BootParams muss die vorhandenen Zeilenenden erhalten.
- **zram0** existiert, ist aber unbenutzt (disksize 0).
- **USB-Schutz:** Der Hub `2-1` vor den Array-Platten steht auf `auto` (Verstoß). Der xHCI `00:0d.0` hat Runtime-PM `auto`.
- **Tips and Tweaks** ist schon deinstalliert, aber `/boot/config/plugins/dynamix/cpu_turbo_boost.cron` ruft dessen gelöschtes Skript weiter auf (verwaist).

---

## 1. Ausgangslage (Ist-Analyse vom 14.10.2026, nur lesend erhoben)

| Bereich | Befund |
|---|---|
| OS | Unraid 7.4.0-rc.1, Kernel 6.18.54, PHP 8.4 |
| Boot | **GRUB von ZFS-Pool `flash`** (`/boot/grub/grub.cfg`, CRLF); kein `syslinux.cfg` |
| CPU | i5-12600H (Alder Lake, Hybrid): P-Kerne `0-7` (4C/8T), E-Kerne `8-15`; `intel_pstate` active, HWP/EPP, `intel_idle` mit C1E/C6/C8/C10 |
| RAM | 62 GiB, kein Swap, kein zram |
| GPU | Iris Xe (i915 aktiv, xe geladen), `enable_guc=-1` (auto) |
| NIC | Intel I226-V (`igc`), 2,5 GbE, EEE aus, Ring RX/TX 256 (max 4096) |
| Array | 3× Seagate Exos über **USB-Gehäuse ICY BOX IB-3804-C31** am TB4-xHCI `00:0d.0`, LUKS+XFS |
| Pool | `cache` (ZFS, 2× DRAM-lose NVMe), autotrim=on, 82 % belegt, 46 % Fragmentierung |
| Last | 68 Container, 2 VMs (Home Assistant OS, Github Actions) |
| Sonstiges | APC Back-UPS (USB), NanoKVM (USB), Tailscale |

### Probleme mit AutoTweak 1.3.13
- **Kernel-Parameter wirken nicht.** AutoTweak schreibt nur in `/boot/syslinux/syslinux.cfg`, das es hier nicht gibt. `KERNEL_PREFERRED_CSTATES=4` steht deshalb nicht in `/proc/cmdline`.
- Die ASPM-Policy ist laut Konfiguration `powersupersave`, tatsächlich aktiv ist `[default]`. Der Schreibvorgang scheitert vermutlich still, weil das BIOS dem OS keine ASPM-Kontrolle gibt (FADT/_OSC). Das muss geprüft werden.
- AutoTweak hat keine Kenntnis von USB-Array-Platten, kennt keine Hybrid-CPU-spezifischen Defaults und erkennt keine Konflikte.
- Das Projekt ist seit 11/2024 unverändert.

### Konflikte und Altlasten
- **Tips and Tweaks:** `CPU_GOVERNOR="performance"` und ein Turbo-Cron (23:00 aus, 05:00 an). Beides kämpft gegen AutoTweak, der zuletzt laufende gewinnt.
- **User Scripts:** „vm heap size needed for ARK“ (`vm.max_map_count`) und „vmovercommit_memory for redis“ setzen sysctls beim Start.
- **cache_dirs** setzt `vm.vfs_cache_pressure=10`. Das wird respektiert und nicht überschrieben.
- `/etc/sysctl.d/99-tailscale.conf` und `60-libvirtd.conf` bleiben unangetastet.

---

## 2. Ziele / Nicht-Ziele

**Ziele**
1. Alle sinnvollen Funktionen von AutoTweak und Tips and Tweaks abdecken, beide danach deinstallierbar.
2. **Erkennen → Empfehlen → Vorschau → Anwenden → Zurücknehmen.** Keine Änderung ohne sichtbaren Vorher/Nachher-Diff.
3. Empfehlungen werden aus der erkannten Hardware und Kernel-Version berechnet, nicht hart codiert.
4. Kernel-Parameter werden bootloader-korrekt gesetzt (GRUB **und** Syslinux) und sind kompatibel mit Unraids eigenem Boot-Parameter-Editor.
5. Harte Schutzregeln für USB-Speicher, USV und Eingabegeräte.
6. Live-Telemetrie: Paketleistung in Watt (RAPL), C-State-Residenz, Takt pro P/E-Kern, Temperaturen.

**Nicht-Ziele**
- Keine Sicherheits-Mitigations abschalten (`mitigations=off` wird nicht angeboten, nur als Info angezeigt).
- Kein Umschreiben der NVMe-APST-Tabellen (AutoTweak-Hack). Es wird nur der Kernel-Parameter angeboten.
- Kein Undervolting/MSR-Schreiben, kein PC10-LTR-Hack per debugfs.

---

## 3. Funktionsumfang (Tweak-Katalog)

Jeder Tweak ist ein Modul mit `id`, Kategorie, `detect()`, `current()`, `recommend(profile, hw)`, `apply()`, `revert()`, Risiko (niedrig/mittel/hoch), Wirkzeitpunkt (sofort/Reboot) und Begründung mit Quelle.
Status-Chips: **Optimal**, **Empfohlen**, **Abweichend (manuell)**, **Konflikt**, **Nicht zutreffend**.

Die Profile sind **Effizienz** (Default für 24/7-Homelab), **Ausgewogen** und **Leistung**. Jeder Tweak kann auf `Auto` / `Eigener Wert` / `Nicht verwalten` stehen.

### 3.1 CPU & Energie
| Tweak | Ist | Empfehlung Effizienz (dieses System) | Hinweis |
|---|---|---|---|
| Governor | powersave | `powersave` | Bei `intel_pstate` active ist `powersave` der dynamische HWP-Modus. `performance` erzwingt EPP 0 und verbraucht deutlich mehr Strom. |
| EPP | balance_power | `balance_power` (Ausgew.: `balance_performance`, Leist.: `performance`) | pro CPU |
| HWP Dynamic Boost | 1 | 1 | |
| Turbo | an | an; optional **Zeitplan** (ersetzt T&T-Cron) | Zeitplan schaltet zwischen Profilen statt nur Turbo |
| C-State-Begrenzung | keine | keine (C10 wird erreicht) | nur Anzeige der Residenz; Notfall-Option `intel_idle.max_cstate` |
| NMI-Watchdog | an | `nmi_watchdog=0` (Kernel-Parameter + Laufzeit `kernel.nmi_watchdog=0`) | weniger Wakeups |
| Profil-Zeitplan | – | optional, z. B. 01:00–07:00 Effizienz, sonst Ausgewogen | ersetzt T&T `SCHEDULED`/`POWER_SAVE_CPU_GOVERNOR` |

### 3.2 PCIe / Geräte-PM
| Tweak | Empfehlung | Schutz |
|---|---|---|
| ASPM-Policy | `powersupersave`, nur wenn das OS die ASPM-Kontrolle hat (dmesg/_OSC prüfen, Write verifizieren) | sonst Hinweis „BIOS: ASPM auf Auto/Enabled stellen“ |
| ASPM pro Gerät (`link/l1_aspm`, `l1_2_aspm`) | Opt-out-Liste pro Gerät | **I226-V**: Warnhinweis wegen bekannter Link-Abbrüche mit L1. Vorgeschlagen wird erst nach einem 24-h-Stabilitätstest. |
| PCI Runtime PM (`power/control=auto`) | auto für alle Geräte außer der Sperrliste | Sperre automatisch für: xHCI mit angeschlossenem USB-Speicher (`00:0d.0`), `mei_me`, GPU bei laufendem Transcoding-Container (optional) |
| USB-Autosuspend | `auto` nur für unkritische Geräte | **Hart gesperrt:** `usb-storage`/`uas` samt Eltern-Hubs, HID, USV (`Back-UPS`), NanoKVM. Diese Geräte werden aktiv auf `on` gehalten. |
| SATA ALPM | `med_power_with_dipm` | hier „Nicht zutreffend“ (kein SATA-Host) |
| NVMe APST | Kernel-Default (APST an) | Option `nvme_core.default_ps_max_latency_us` nur bei Problemen |
| Audio-Codec PM | `snd_hda_intel power_save=1`, falls das Modul geladen ist | hier nicht zutreffend |

### 3.3 Speicher / VM-Subsystem
| Tweak | Ist | Empfehlung | Begründung |
|---|---|---|---|
| Dirty-Limits | ratio 20/10 (≈12 GiB) | `vm.dirty_background_bytes=256M`, `vm.dirty_bytes=1G` | Langsame USB-Paritätsschreibvorgänge: kleine absolute Limits verhindern minutenlange Stalls. Ersetzt T&T `VM_DIRTY`/`VM_BACKGROUND`. |
| dirty_expire / writeback | Default | Default (anpassbar) | ersetzt T&T `VM_EXPIRE`/`VM_WRITEBACK` |
| THP | always | `madvise` (+ defrag `madvise`) | Valkey/Redis/Postgres-Empfehlung |
| `vm.max_map_count` | 265000 | `1048576` | moderner Distro-Default; ersetzt das User Script |
| `vm.overcommit_memory` | 1 | `1`, wenn Redis/Valkey-Container erkannt werden | ersetzt das User Script |
| `vm.swappiness` | 60 | „Nicht zutreffend“ ohne Swap | |
| `vm.vfs_cache_pressure` | 10 | nicht verwalten, wenn cache_dirs aktiv ist | Konfliktvermeidung |
| `vm.min_free_kbytes` | 67584 | ~ 0,2 % RAM (≈128 MiB) bei VMs und ZFS | Reserve gegen Allokationsfehler |

### 3.4 ZFS
| Tweak | Ist | Empfehlung |
|---|---|---|
| ARC max | 0 → c_max ≈ 61 GiB | **16 GiB** (RAM/4, Untergrenze 4 GiB). Live über `/sys/module/zfs/parameters/zfs_arc_max`, beim Boot erneut gesetzt. Unraids `zfs.conf` bleibt unberührt. |
| ARC min | Default | Default |
| autotrim | on | Optimal; alternativ wöchentlicher `zpool trim` |
| Pool-Füllstand / Fragmentierung | 82 % / 46 % | nur Warnung ab 80 % (Performance bricht bei ZFS ein) |
| Dataset-Hinweise | compression `on`, recordsize 128K | Info: `compression=zstd` bzw. `lz4` und `recordsize=1M` für Medien/Backups, `16K`–`64K` für DB/VM-Datasets (Info, nichts automatisch) |

### 3.5 Netzwerk (ersetzt T&T-NIC-Optionen)
| Tweak | Ist | Empfehlung |
|---|---|---|
| Qdisc / CC | fq + bbr | Optimal |
| TCP-Puffer | 32 MiB | passend zur NIC-Geschwindigkeit (2,5G: 32 MiB) |
| `tcp_mtu_probing` | 0 | 1 |
| Ring-Buffer igc | 256/256 | 1024/1024 (`ethtool -G`) |
| EEE | aus | aus lassen (I226 + EEE ist instabil) |
| Flow Control / TSO | Default | Default, manuell überschreibbar (T&T-Parität) |
| IRQ-Affinität | – | optional: NIC-IRQs auf E-Kerne `8-15` (AutoTweak-Parität, Default aus) |
| `fs.inotify.max_user_instances` | 128 | `1024` (68 Container) |
| `fs.inotify.max_user_watches` | 524288 | Optimal |
| `fs.file-max` / nofile | Default | T&T-Parität `MAX_OPEN_FILES` |

### 3.6 Weitere Tips-and-Tweaks-Parität
- **Prozesse beim Array-Stopp beenden** (`KILL="ssh,bash"`): über das Event `stopping_svcs` mit konfigurierbarer Liste.
- **Logs archivieren:** wird nicht übernommen (enhanced.log und Syslog-Server decken das ab). Das wird im Migrationsdialog vermerkt.

### 3.7 Konflikterkennung
Folgendes wird geprüft und als Banner mit „Was tun?“-Anleitung angezeigt:
- installierte Plugins: `autotweak`, `tips.and.tweaks`, `ca.turbo` …
- `.cron`-Dateien, die Turbo oder Governor setzen
- User Scripts mit `sysctl`/`echo > /proc/sys`
- `/boot/config/go` mit Tuning-Zeilen
- abweichende Werte, die nach dem Anwenden von außen geändert wurden (Drift-Erkennung alle 5 min)

---

## 4. Architektur

```
src/usr/local/emhttp/plugins/power-guard/
├── PowerGuard.page          # Menu="Utilities", Markdown="false", Icon/Tag="bolt"
├── include/api.php          # JSON-API (POST + csrf_token), ruft lib auf
├── lib/
│   ├── Hardware.php         # Erkennung (sysfs, lspci, ethtool, zpool, lsusb-Topologie)
│   ├── Tweak.php            # Basisklasse + Registry
│   ├── tweaks/*.php         # ein Modul je Tweak
│   ├── BootParams.php       # GRUB/Syslinux tokenweise, Backup, CRLF, Validierung
│   ├── State.php            # Config (Flash), Baseline (RAM), Journal
│   └── Telemetry.php        # RAPL, cpuidle, cpufreq, hwmon
├── bin/power-guard          # PHP-CLI: detect|plan|apply|revert|status|telemetry
├── event/{started,stopping_svcs}
└── assets/{app.js,app.css}
plugin/power-guard.plg       # Template; Version und MD5 setzt das Build
build.sh                     # tgz + plg nach dist/
```

- **Sprache:** PHP 8.4 für Logik, CLI und API (in Unraid vorhanden, sauberes JSON). Bash nur für plg und Events.
- **Persistenz:** `/boot/config/plugins/power-guard/config.json` (Profil, Overrides, Sperrlisten). Die Baseline (Ist-Werte vor dem ersten Apply nach dem Boot) liegt in `/var/local/power-guard/baseline.json`. Das Journal mit allen Änderungen und Zeitstempeln ist auf 1 MiB rotiert.
- **Boot-Ablauf:** Die plg-Installation beim Boot führt `power-guard apply --boot` aus, das Event `started` wiederholt das Apply für ZFS-, Docker- und NIC-abhängige Teile.
- **Kernel-Parameter:** Nur eigene Tokens werden ergänzt, ersetzt oder entfernt, fremde Tokens (`unraiduuid`, Unraid-UI-Parameter) bleiben erhalten. Die Backups landen in `/boot/grub/backups/` (gleiches Schema wie Unraids `manage_boot_params.sh`). Die vorhandenen Zeilenenden (LF oder CRLF) bleiben erhalten; das Ergebnis wird vorher validiert (mindestens ein `menuentry`, `linux`-Zeile vorhanden). Betroffen sind nur die 4 Unraid-Einträge, nicht Memtest.
- **Sicherheit:** API nur über POST mit Unraid-`csrf_token`; Whitelist der Aktionen; alle Shell-Argumente `escapeshellarg`; sysfs-Pfade gegen Regex validieren.
- **Deinstallation:** `revert` auf die Baseline, eigene Boot-Tokens entfernen, Hinweis auf Reboot.

---

## 5. UI-Konzept

Einspaltig auf dem Handy, Grid ab 900 px. Kein externes CDN (Server kann offline sein). Das Design passt sich an das Unraid-Theme an (`$display['theme']`, hell/dunkel); Akzentfarbe Amber/Grün.

1. **Header:** Profilwahl als Segment-Control, darunter der **Optimierungs-Score** (Ring, z. B. „87 / 100“) und der Button „Empfehlungen anwenden“.
2. **Live-Kacheln:** Paketleistung in W mit 10-min-Sparkline (RAPL), Package-C-State-Residenz, Takt P- vs. E-Kerne (Balken je Kern), Temperaturen (CPU, NVMe, DDR5 SPD, Board).
3. **Hardware-Steckbrief:** CPU-Topologie, Bootloader, NIC, Pools, USB-Speicherbaum (mit Schloss-Symbol für geschützte Geräte).
4. **Tweak-Liste** nach Kategorie, mit Filter „nur Abweichungen“. Pro Zeile: Status-Chip, Ist → Soll, Risiko, Reboot-Badge. Aufklappbar mit Begründung und Override.
5. **Vorschau-Dialog:** Diff aller Änderungen inklusive GRUB-Zeile vorher/nachher; „Anwenden“ / „Abbrechen“.
6. **Konflikte & Migration:** Banner plus Assistent „AutoTweak / Tips and Tweaks ablösen“.
7. **Journal:** Zeitachse aller Änderungen mit „Zurücknehmen“ pro Eintrag oder „alles auf Baseline“.

Telemetrie wird per Polling alle 2 s nur bei sichtbarem Tab abgefragt. Nchan ist optional für später.

---

## 6. Migration (Ablösung)

1. Power Guard installieren. Es läuft im Modus **Beobachten**, ändert also nichts und zeigt Ist-Werte und Konflikte.
2. Den Migrationsassistenten öffnen. Er liest `autotweak.cfg` und `tips.and.tweaks.cfg` und schlägt äquivalente Einstellungen vor.
3. Diese Schritte erledigt der Benutzer manuell, das Plugin zeigt nur eine Checkliste:
   - AutoTweak deinstallieren (dessen Uninstall setzt Governor und EPP auf `performance` zurück!)
   - Tips and Tweaks deinstallieren und dessen `.cron` entfernen
   - die User Scripts „vm heap size…“ und „vmovercommit…“ deaktivieren
4. Power Guard aktivieren und eine Vorschau anzeigen. Danach wird angewendet; für den GRUB-Teil ist ein Reboot nötig.
5. 24 h beobachten (Drift, dmesg, NIC-Link). Danach können optionale Tweaks mit mittlerem Risiko (ASPM L1 am I226) freigeschaltet werden.

---

## 7. Umsetzungsphasen

| Phase | Inhalt | Ergebnis |
|---|---|---|
| P1 | Gerüst, Build, plg, Page, API, Hardware-Erkennung, nur-lesender Statusmodus | installierbar, ändert nichts |
| P2 | sysctl-, THP-, ZFS-ARC-, CPU/EPP-Module + Apply/Revert/Journal | AutoTweak-Kern ersetzt |
| P3 | BootParams (GRUB/Syslinux) + Kernel-Parameter-Tweaks | C-States/NMI wirksam |
| P4 | PCI/USB-PM mit Schutzregeln, ASPM-Erkennung, NIC (Ring, IRQ, Offloads) | AutoTweak + T&T vollständig |
| P5 | Telemetrie, Score, Profil-Zeitplan, Konflikt-/Drift-Erkennung, Migrationsassistent | UI komplett |
| P6 | Doku, Changelog, GitHub-Release (`Knallli/unraid-power-guard`) | CA-tauglich |

---

## 8. Tests & Validierung

- `php -l` für alle PHP-Dateien (auf dem Server in `/tmp`, lokal ist kein PHP vorhanden)
- `shellcheck` für Event- und Build-Skripte
- Unit-Tests für BootParams gegen Fixture-Kopien von `grub.cfg` und `syslinux.cfg` (CRLF/LF, Tokens mit `=`, Safe-Mode-Einträge)
- Erkennung und `plan` auf dem echten Server im Probelauf (nur lesend)
- Gestufter Live-Test: erst ein sysctl-Tweak, dann ein Revert, dann Reboot-Test
- Schutzregel-Test: Nach dem Apply müssen alle USB-Speicher-, Hub- und USV-Geräte `power/control=on` haben, sonst wird abgebrochen und zurückgerollt.

---

## 9. Offene Fragen

1. Soll das Standardprofil **Effizienz** sein (24/7, Strom sparen) oder **Ausgewogen**?
2. Soll der ARC auf 16 GiB begrenzt werden oder ein anderer Wert (VMs/Container brauchen ca. 37 GiB)?
3. Brauchst du Wake-on-LAN? (Davon hängt die NIC-PM ab.)
4. Soll das Logs-Archiv aus Tips and Tweaks ersatzlos entfallen?
5. Veröffentlichung über GitHub `Knallli/…` und später Community Applications, oder nur privat?
