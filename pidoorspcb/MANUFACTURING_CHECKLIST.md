# PiDoors HAT Manufacturing Checklist

**Version:** 2.0
**Date:** January 2026
**Status:** Ready for Manufacturing

---

## Pre-Manufacturing Status

### Files Available

| File | Status | Location |
|------|--------|----------|
| KiCad Project | Ready | `kicad/pidoors-hat.kicad_pro` |
| Schematic | Complete | `kicad/pidoors-hat.kicad_sch` |
| Netlist | Complete | `kicad/pidoors-hat.net` |
| PCB Outline | Complete | `kicad/pidoors-hat.kicad_pcb` |
| BOM with LCSC | Complete | `kicad/pidoors-hat-bom.csv` |
| CPL (SMD only) | Complete | `kicad/pidoors-hat-cpl.csv` |
| Specification | Complete | `PCB_SPECIFICATION.md` |
| Gerber Files | Complete | `kicad/gerbers/` |

### What's Done

- [x] Complete netlist with all 50+ components
- [x] All component connections defined
- [x] GPIO pin assignments verified against software
- [x] Board outline (65mm x 56.5mm Pi HAT)
- [x] Mounting holes at correct positions
- [x] BOM with LCSC part numbers for JLCPCB assembly
- [x] Component placement list (SMD)
- [x] Schematic with all major components placed
- [x] PCB layout with all components placed
- [x] Trace routing for power and signals
- [x] Ground plane on bottom layer
- [x] Gerber files generated (RS-274X format)
- [x] Drill files generated (Excellon format)

### What Needs Completion

- [x] **PCB Layout**: Place footprints and route traces - COMPLETE
- [x] **Gerber Generation**: Export manufacturing files - COMPLETE
- [ ] **Design Rule Check (DRC)**: Run in KiCad to verify (recommended)
- [ ] **Review**: Visual inspection of layout (recommended)

---

## Steps to Complete the Design

### Step 1: Open in KiCad 8

```bash
cd pidoorspcb/kicad
kicad pidoors-hat.kicad_pro
```

### Step 2: Update PCB from Schematic

1. Open PCBnew (PCB Editor)
2. **Tools > Update PCB from Schematic**
3. Click "Update PCB"
4. All footprints will appear in a cluster

### Step 3: Place Components

Follow this placement guide:

```
+---------------------------------------------------------------+
| H1(3.5,3.5)    [J2 Barrel]           [Power ICs]    H2(61.5,3.5)
|                  12V DC             U1, U2, C1, C2              |
|                                                                 |
|  +----------------------------------------------------------+  |
|  |                                                          |  |
|  |              J1 - Raspberry Pi GPIO Header               |  |
|  |                     (Center of board)                    |  |
|  |                                                          |  |
|  +----------------------------------------------------------+  |
|                                                                 |
| [J3 Wiegand]  [J4 RS-485]  [J5 PN532]  [J6 SPI]   [LEDs D8-11] |
|    U4 TXB0102    U3 MAX      I2C        NFC                    |
|                                                                 |
| [J7 Relay]    [J8 Door]   [J9 REX]   [J10 Tamper] [J11 Buzzer] |
|  K1, Q1, D6      U5          R8          R9                    |
|                                                                 |
| H3(3.5,53)                                        H4(61.5,53)  |
+---------------------------------------------------------------+
```

### Step 4: Route Traces

**Power traces (0.5mm+ width):**
- +12V_FUSED from F1 to U1, K1
- +5V from U1 to J1 pins 2,4, and U2
- +3V3 from U2 to NFC headers, pull-ups
- GND plane on bottom layer

**Signal traces (0.25mm width):**
- Wiegand D0/D1 from J3 through R3/R4, TVS, U4 to J1 pins 16,18
- RS-485 A/B from J4 through TVS to U3
- UART TX/RX from J1 pins 8,10 to U3
- I2C SDA/SCL from J1 pins 3,5 to J5
- SPI signals from J1 to J6

### Step 5: Design Rule Check

1. **Inspect > Design Rules Checker**
2. Run DRC
3. Fix any errors (typically clearance or unconnected nets)

### Step 6: Generate Gerbers

1. **File > Fabrication Outputs > Gerbers**
2. Output directory: `gerbers/`
3. Select all layers
4. Check "Use Protel filename extensions"
5. Click "Plot"

6. **File > Fabrication Outputs > Drill Files**
7. Same output directory
8. Generate drill files

---

## JLCPCB Order Checklist

### PCB Fabrication

- [ ] Upload Gerber ZIP to jlcpcb.com
- [ ] Verify dimensions: 65 x 56.5 mm
- [ ] Select options:
  - Layers: 2
  - Thickness: 1.6mm
  - Surface Finish: HASL (LeadFree) or ENIG
  - Solder Mask: Green
  - Silkscreen: White

### SMT Assembly (Optional)

- [ ] Enable "SMT Assembly"
- [ ] Upload BOM: `pidoors-hat-bom.csv`
- [ ] Upload CPL: `pidoors-hat-cpl.csv`
- [ ] Select "Assemble top side"
- [ ] Review component placements
- [ ] Confirm LCSC part numbers match

### Through-Hole Components (Manual Assembly)

These must be soldered by hand after receiving boards:

| Component | Quantity | Notes |
|-----------|----------|-------|
| J1 - 2x20 Header | 1 | Solder last |
| J2 - Barrel Jack | 1 | |
| J3, J4 - 4-pos Terminal | 2 | |
| J7 - 3-pos Terminal | 1 | |
| J8, J9, J11 - 2-pos Terminal | 3 | |
| K1 - Relay | 1 | Observe orientation |
| U5 - PC817 | 1 | Pin 1 indicator |
| C1 - 100uF/35V | 1 | Observe polarity! |
| C2 - 100uF/10V | 1 | Observe polarity! |

---

## Quality Assurance Checklist

### Visual Inspection

- [ ] All traces connected (no broken traces)
- [ ] No shorts between adjacent pads
- [ ] Silkscreen readable and not overlapping pads
- [ ] Mounting holes in correct positions
- [ ] Board outline correct dimensions

### Electrical Verification

- [ ] Continuity: +12V from J2 pin 1 to F1
- [ ] Continuity: GND from J2 pin 2 to J1 pin 6
- [ ] No short: +12V to GND
- [ ] No short: +5V to GND
- [ ] No short: +3V3 to GND

### First Article Test

Before powering:
- [ ] Visual inspection complete
- [ ] No obvious shorts

Power-on test (without Pi):
- [ ] Apply 12V DC
- [ ] Measure 5V rail: 4.9-5.1V
- [ ] Measure 3.3V rail: 3.2-3.4V
- [ ] Power LED illuminates

With Raspberry Pi:
- [ ] Pi boots normally
- [ ] GPIO test passes (see specification)

---

## GPIO Pin Reference (Corrected)

| Function | GPIO | Physical Pin |
|----------|------|--------------|
| Wiegand D0 | GPIO 24 | Pin 18 |
| Wiegand D1 | GPIO 23 | Pin 16 |
| Relay/Latch | GPIO 18 | Pin 12 |
| Door Sensor | GPIO 27 | Pin 13 |
| REX Button | GPIO 17 | Pin 11 |
| LED Granted | GPIO 25 | Pin 22 |
| LED Denied | GPIO 22 | Pin 15 |
| Activity LED | GPIO 13 | Pin 33 |
| Buzzer | GPIO 12 | Pin 32 |
| Tamper | GPIO 6 | Pin 31 |
| UART TX | GPIO 14 | Pin 8 |
| UART RX | GPIO 15 | Pin 10 |
| I2C SDA | GPIO 2 | Pin 3 |
| I2C SCL | GPIO 3 | Pin 5 |
| SPI SCLK | GPIO 11 | Pin 23 |
| SPI MOSI | GPIO 10 | Pin 19 |
| SPI MISO | GPIO 9 | Pin 21 |
| SPI CE0 | GPIO 8 | Pin 24 |
| NFC Reset | GPIO 25 | Pin 22 |
| NFC IRQ | GPIO 16 | Pin 36 |

---

## Cost Estimate

| Item | Qty 5 | Qty 10 | Qty 100 |
|------|-------|--------|---------|
| PCB (JLCPCB) | $2 | $2 | $10 |
| SMT Assembly | $15 | $25 | $150 |
| SMD Components | $10 | $18 | $130 |
| THT Components | $15 | $28 | $200 |
| **Per Board Total** | **~$8.40** | **~$7.30** | **~$4.90** |

*Prices approximate, excluding shipping*

---

## Support Files Location

```
pidoorspcb/
├── PCB_SPECIFICATION.md      # Full design specification
├── MANUFACTURING_CHECKLIST.md # This file
└── kicad/
    ├── pidoors-hat.kicad_pro  # KiCad project
    ├── pidoors-hat.kicad_sch  # Schematic
    ├── pidoors-hat.kicad_pcb  # PCB (fully routed)
    ├── pidoors-hat.net        # Complete netlist
    ├── pidoors-hat-bom.csv    # BOM with LCSC parts
    ├── pidoors-hat-cpl.csv    # Component placement
    ├── README.md              # KiCad-specific instructions
    └── gerbers/               # Manufacturing files
        ├── pidoors-hat-F_Cu.gtl      # Top copper
        ├── pidoors-hat-B_Cu.gbl      # Bottom copper
        ├── pidoors-hat-F_Mask.gts    # Top solder mask
        ├── pidoors-hat-B_Mask.gbs    # Bottom solder mask
        ├── pidoors-hat-F_SilkS.gto   # Top silkscreen
        ├── pidoors-hat-B_SilkS.gbo   # Bottom silkscreen
        ├── pidoors-hat-Edge_Cuts.gm1 # Board outline
        ├── pidoors-hat-PTH.drl       # Plated holes
        ├── pidoors-hat-NPTH.drl      # Non-plated holes
        ├── pidoors-hat-gerbers.tar.gz # Archive for upload
        ├── generate_gerbers.sh       # KiCad regeneration script
        └── README.md                 # Gerber instructions
```

---

*Generated for PiDoors Project v2.2*
