###2026.10.14
- Erste Version (Phase 1): Beobachtungsmodus. Power Guard ändert nichts am System.
- Hardware-Erkennung: CPU-Topologie (P/E-Kerne), cpufreq/EPP/HWP, C-States, Bootloader, NICs (Ring, EEE, WoL), USB-Baum mit Schutzklassen, PCIe/ASPM, ZFS-Pools/ARC.
- Ist/Soll-Plan für die Profile Effizienz, Ausgewogen und Leistung, mit Optimierungs-Score.
- Konflikterkennung: AutoTweak, Tips and Tweaks (inkl. verwaister Cron-Jobs), User Scripts, go-Datei, sysctl.d, cache_dirs.
- Live-Telemetrie: Paketleistung (RAPL), C-State-Residenz, Takt je Kern, Temperaturen.
- CLI `power-guard detect|plan|status|conflicts|telemetry`.
