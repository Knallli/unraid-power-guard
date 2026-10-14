# Power Guard

Hardware-aware power and performance tuning for **Unraid 7**. Intended to replace AutoTweak and Tips and Tweaks.

**Detect → Recommend → Preview → Apply → Revert.** Recommendations are computed from the detected hardware and kernel, nothing changes without a visible before/after.

> Status: early. The current release runs in **observe mode** only: it detects, recommends and shows conflicts, but changes nothing.

## Features
- Hardware detection: hybrid CPU topology (P/E cores), cpufreq/EPP/HWP, C-states, bootloader (GRUB/Syslinux), NICs (ring, EEE, WoL), USB tree with protection classes (storage incl. upstream hubs, HID, UPS, KVM), effective PCIe ASPM state, ZFS pools/ARC.
- Per-tweak status (Optimal / Empfohlen / Abweichend / Konflikt / Nicht zutreffend) for the profiles *Effizienz*, *Ausgewogen*, *Leistung*, with an optimization score.
- Conflict detection: AutoTweak, Tips and Tweaks (incl. orphaned cron jobs), User Scripts, `/boot/config/go`, `sysctl.d`, cache_dirs.
- Live telemetry: package power (RAPL), C-state residency, per-core frequency, temperatures.
- CLI: `power-guard detect|plan|status|conflicts|telemetry`.

## Install
Plugins → Install Plugin:

```
https://github.com/Knallli/unraid-power-guard/releases/latest/download/power-guard.plg
```

Then open *Utilities → Power Guard*.

## Development
- `./build.sh [version]` builds `dist/power-guard-<version>-x86_64-1.tgz` and `dist/power-guard.plg`.
- `tests/smoke.sh <plugin-dir>` runs a read-only smoke test on an Unraid host.
- Roadmap and design: [PLAN.md](PLAN.md) (German).
