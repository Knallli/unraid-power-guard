###2026.10.14b
- Fix: Die Weboberfläche blieb bei „erkennt die Hardware …“ hängen. Unraid lässt multipart-POSTs an Plugin-Endpunkte hängen; die API wird jetzt URL-encoded aufgerufen.
- Fix: Nach dem Zurücknehmen des letzten verwalteten Tweaks wechselt Power Guard in den Beobachtungsmodus.

###2026.10.14a
- Phase 2: Empfehlungen anwenden und zurücknehmen (sysctl, THP, ZFS-ARC, Governor/EPP, Turbo, HWP-Boost).
- Vorschau-Dialog mit Vorher/Nachher und den konkreten Schreibzugriffen; Tweaks mit mittlerem/hohem Risiko sind standardmäßig abgewählt.
- Baseline (Originalwerte vor der ersten Änderung) und Journal; „Zurücknehmen“ pro Tweak oder „Alles auf Baseline“.
- Angewendete Tweaks werden beim Boot und beim Array-Start erneut gesetzt; nicht freigegebene bleiben unangetastet.
- Schreibschutz: nur freigegebene sysfs/procfs-Pfade, Werte gegen die vom Kernel angebotenen Optionen geprüft.
- Deinstallation setzt alle geänderten Werte zurück.

###2026.10.14
- Erste Version (Phase 1): Beobachtungsmodus. Power Guard ändert nichts am System.
- Hardware-Erkennung: CPU-Topologie (P/E-Kerne), cpufreq/EPP/HWP, C-States, Bootloader, NICs (Ring, EEE, WoL), USB-Baum mit Schutzklassen, PCIe/ASPM, ZFS-Pools/ARC.
- Ist/Soll-Plan für die Profile Effizienz, Ausgewogen und Leistung, mit Optimierungs-Score.
- Konflikterkennung: AutoTweak, Tips and Tweaks (inkl. verwaister Cron-Jobs), User Scripts, go-Datei, sysctl.d, cache_dirs.
- Live-Telemetrie: Paketleistung (RAPL), C-State-Residenz, Takt je Kern, Temperaturen.
- CLI `power-guard detect|plan|status|conflicts|telemetry`.
