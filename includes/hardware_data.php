<?php
// Hardware Devices Knowledge Base & Diagnostic Trees (PHP 5.6 Compatible)

function get_hardware_devices_list() {
    return array(
        'thermal-printer' => array(
            'id' => 'thermal-printer',
            'name' => 'Thermal Printer',
            'image' => 'hardware_photos/thermal_printer.png',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H7a2 2 0 00-2 2v4h10z"/></svg>',
            'description' => 'Receipt thermal printer for POS systems (58mm / 80mm roll).',
            'common_issues' => array(
                "Printer won't print",
                "Paper jam",
                "Red light blinking",
                "Printer offline",
                "Faded printing",
                "Printing blank receipts",
                "Paper not feeding",
                "Other"
            ),
            'questions' => array(
                array(
                    'id' => 'q1',
                    'text' => 'What issue are you experiencing with your Thermal Printer?',
                    'type' => 'choice',
                    'options' => array(
                        "Printer won't print",
                        "Paper jam",
                        "Red light blinking",
                        "Printer offline",
                        "Faded printing",
                        "Printing blank receipts",
                        "Paper not feeding",
                        "Other"
                    )
                ),
                array(
                    'id' => 'q2',
                    'text' => 'Is the power indicator light ON?',
                    'type' => 'yesno',
                    'no_solution' => 'Please connect the thermal printer power adapter securely to a working electrical outlet and toggle the power switch to ON.'
                ),
                array(
                    'id' => 'q3',
                    'text' => 'Is the USB or Serial data cable connected securely to both the printer and computer?',
                    'type' => 'yesno',
                    'no_solution' => 'Unplug and re-insert the USB cable tightly into both the printer and the System Unit back port.'
                ),
                array(
                    'id' => 'q4',
                    'text' => 'Is the thermal paper roll inserted with the heat-sensitive shiny side facing outward?',
                    'type' => 'yesno',
                    'no_solution' => 'Open the paper lid and turn the paper roll around so the thermal surface feeds towards the print head.'
                )
            ),
            'steps' => array(
                array(
                    'step_num' => 1,
                    'title' => 'Verify Power & Cable Connections',
                    'instruction' => 'Check that the round power plug and USB cable are plugged firmly into the rear of the thermal printer.',
                    'expected' => 'The blue or green power LED stays lit solid without flickering.'
                ),
                array(
                    'step_num' => 2,
                    'title' => 'Perform Hardware Self-Test',
                    'instruction' => 'Turn off printer. Hold down FEED button while turning ON the power switch. Release FEED button after 3 seconds.',
                    'expected' => 'Printer should print out a config receipt showing firmware version and interface port.'
                ),
                array(
                    'step_num' => 3,
                    'title' => 'Check Paper Sensor & Lid Latch',
                    'instruction' => 'Open the top paper cover, clear any scraps of thermal paper, and press the lid down firmly until it clicks locked.',
                    'expected' => 'Red ERROR light turns off, leaving only Power LED lit.'
                ),
                array(
                    'step_num' => 4,
                    'title' => 'Verify Windows Printer Port Setting',
                    'instruction' => 'In Windows Devices and Printers, right-click Thermal Printer -> Printer Properties -> Ports tab. Ensure correct USB port (USB001 or USB002) is selected.',
                    'expected' => 'Clicking "Print Test Page" prints out Windows test receipt.'
                )
            )
        ),

        'dot-matrix-printer' => array(
            'id' => 'dot-matrix-printer',
            'name' => 'Dot Matrix Printer',
            'image' => 'hardware_photos/dot_matrix_printer.jpg',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H7a2 2 0 00-2 2v4h10z"/></svg>',
            'description' => 'Impact dot-matrix printer for continuous multi-part invoice forms.',
            'common_issues' => array(
                "Faded or faint print",
                "Ribbon stuck or torn",
                "Paper tear / jam on tractor feed",
                "Beeping sound constantly",
                "Misaligned text printing",
                "Other"
            ),
            'questions' => array(
                array(
                    'id' => 'q1',
                    'text' => 'What issue are you experiencing with your Dot Matrix Printer?',
                    'type' => 'choice',
                    'options' => array(
                        "Faded or faint print",
                        "Ribbon stuck or torn",
                        "Paper tear / jam on tractor feed",
                        "Beeping sound constantly",
                        "Misaligned text printing",
                        "Other"
                    )
                ),
                array(
                    'id' => 'q2',
                    'text' => 'Is the printer power light turned ON?',
                    'type' => 'yesno',
                    'no_solution' => 'Check the main AC power cord and switch on the dot matrix printer power switch.'
                ),
                array(
                    'id' => 'q3',
                    'text' => 'Is the ribbon cartridge snapped into place correctly?',
                    'type' => 'yesno',
                    'no_solution' => 'Remove the ink ribbon cartridge and re-seat it until both plastic clips lock firmly.'
                )
            ),
            'steps' => array(
                array(
                    'step_num' => 1,
                    'title' => 'Check Ink Ribbon Cartridge',
                    'instruction' => 'Rotate the ribbon tension knob clockwise to tighten loose ribbon ribbon before closing ribbon cover.',
                    'expected' => 'Ribbon moves smoothly without snagging print head pins.'
                ),
                array(
                    'step_num' => 2,
                    'title' => 'Adjust Tractor Feed & Paper Release Lever',
                    'instruction' => 'Ensure the paper release lever is set to Continuous Form position (Tractor icon).',
                    'expected' => 'Pin-feed paper advances evenly when pressing Load/Eject button.'
                )
            )
        ),

        'sticker-printer' => array(
            'id' => 'sticker-printer',
            'name' => 'Sticker Printer',
            'image' => 'hardware_photos/stickerprinter.webp',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 7h10M7 11h10M7 15h10M5 3h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2z"/></svg>',
            'description' => 'Direct thermal or ribbon barcode sticker / label printer.',
            'common_issues' => array(
                "Labels skipping / misaligned",
                "Red light / Out of paper error",
                "Print offset or cut-off text",
                "Ribbon wrinkle",
                "Other"
            ),
            'questions' => array(
                array(
                    'id' => 'q1',
                    'text' => 'Select the main problem with your Label/Sticker Printer:',
                    'type' => 'choice',
                    'options' => array(
                        "Labels skipping / misaligned",
                        "Red light / Out of paper error",
                        "Print offset or cut-off text",
                        "Ribbon wrinkle",
                        "Other"
                    )
                ),
                array(
                    'id' => 'q2',
                    'text' => 'Are the label roll media guides snug against the edges of the sticker roll?',
                    'type' => 'yesno',
                    'no_solution' => 'Slide the green media guides inward so the sticker roll feeds straight without wobbling.'
                )
            ),
            'steps' => array(
                array(
                    'step_num' => 1,
                    'title' => 'Perform Label Gap Calibration',
                    'instruction' => 'Hold FEED button while powering ON sticker printer until green light flashes 3 times, then release.',
                    'expected' => 'Printer feeds 2-3 stickers and automatically stops right at label gap.'
                )
            )
        ),

        'barcode-scanner' => array(
            'id' => 'barcode-scanner',
            'name' => 'Barcode Scanner',
            'image' => 'hardware_photos/barcode_scanner.jpg',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4v16m8-8H4m4-4h.01M16 8h.01M8 16h.01M16 16h.01"/></svg>',
            'description' => '1D/2D Handheld laser or USB barcode reader.',
            'common_issues' => array(
                "Scanner laser light not turning on",
                "Scanning produce no beep or text on screen",
                "Scanning wrong characters or missing numbers",
                "Wireless scanner disconnected",
                "Other"
            ),
            'questions' => array(
                array(
                    'id' => 'q1',
                    'text' => 'What is your Barcode Scanner issue?',
                    'type' => 'choice',
                    'options' => array(
                        "Scanner laser light not turning on",
                        "Scanning produce no beep or text on screen",
                        "Scanning wrong characters or missing numbers",
                        "Wireless scanner disconnected",
                        "Other"
                    )
                ),
                array(
                    'id' => 'q2',
                    'text' => 'Does the red laser beam or LED aim light appear when pulling the trigger or presenting a barcode?',
                    'type' => 'yesno',
                    'no_solution' => 'Try plugging the scanner USB cable into another rear USB port on your System Unit.'
                )
            ),
            'steps' => array(
                array(
                    'step_num' => 1,
                    'title' => 'Test Scanner in Notepad',
                    'instruction' => 'Open Windows Notepad (Start -> Notepad). Scan a standard product barcode.',
                    'expected' => 'The barcode numbers should immediately appear in Notepad followed by an Enter key line jump.'
                )
            )
        ),

        'customer-display' => array(
            'id' => 'customer-display',
            'name' => 'Customer Display',
            'image' => 'hardware_photos/customer_display.jpg',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>',
            'description' => 'VFD or LCD Customer Pole Display screen showing checkout totals.',
            'common_issues' => array(
                "Screen completely blank / black",
                "Garbled or weird symbols displayed",
                "Display not updating during cashier scan",
                "Other"
            ),
            'questions' => array(
                array(
                    'id' => 'q1',
                    'text' => 'Select the issue with Customer Display:',
                    'type' => 'choice',
                    'options' => array(
                        "Screen completely blank / black",
                        "Garbled or weird symbols displayed",
                        "Display not updating during cashier scan",
                        "Other"
                    )
                ),
                array(
                    'id' => 'q2',
                    'text' => 'Is the USB / Serial cable plugged firmly into the computer?',
                    'type' => 'yesno',
                    'no_solution' => 'Plug the customer display cable into a main USB or COM port on the back of the PC.'
                )
            ),
            'steps' => array(
                array(
                    'step_num' => 1,
                    'title' => 'Verify COM Port & Baud Rate',
                    'instruction' => 'Check Device Manager -> Ports (COM & LPT) to find the COM port number of Customer Display, then match it in POS Settings.',
                    'expected' => 'Welcome message (e.g. "WELCOME TO OUR STORE") appears on pole screen.'
                )
            )
        ),

        'mouse' => array(
            'id' => 'mouse',
            'name' => 'Mouse',
            'image' => 'hardware_photos/mouse.jpg',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239a9.01 9.01 0 0112.583 0"/></svg>',
            'description' => 'Optical wired or wireless USB mouse.',
            'common_issues' => array(
                "Cursor not moving",
                "Double click not working or erratic",
                "Wireless mouse unresponsive",
                "Other"
            ),
            'questions' => array(
                array(
                    'id' => 'q1',
                    'text' => 'What is the mouse issue?',
                    'type' => 'choice',
                    'options' => array(
                        "Cursor not moving",
                        "Double click not working or erratic",
                        "Wireless mouse unresponsive",
                        "Other"
                    )
                ),
                array(
                    'id' => 'q2',
                    'text' => 'Is the red optical light under the mouse ON?',
                    'type' => 'yesno',
                    'no_solution' => 'Check mouse USB cable connection or replace AA/AAA battery if wireless.'
                )
            ),
            'steps' => array(
                array(
                    'step_num' => 1,
                    'title' => 'Re-plug USB Mouse Port',
                    'instruction' => 'Unplug mouse USB receiver/cable and connect to a different USB port on the back of the computer.',
                    'expected' => 'Windows plays device connected chime and mouse cursor moves smoothly.'
                )
            )
        ),

        'keyboard' => array(
            'id' => 'keyboard',
            'name' => 'Keyboard',
            'image' => 'hardware_photos/keyboard.jpg',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 10h18M3 14h18M5 6h14a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z"/></svg>',
            'description' => 'Standard USB or POS programmable keyboard.',
            'common_issues' => array(
                "Keys not typing or missing characters",
                "Num Lock or Number pad disabled",
                "Spilled liquid on keys",
                "Other"
            ),
            'questions' => array(
                array(
                    'id' => 'q1',
                    'text' => 'Select Keyboard issue:',
                    'type' => 'choice',
                    'options' => array(
                        "Keys not typing or missing characters",
                        "Num Lock or Number pad disabled",
                        "Spilled liquid on keys",
                        "Other"
                    )
                ),
                array(
                    'id' => 'q2',
                    'text' => 'Is the Num Lock or Caps Lock indicator light working when pressed?',
                    'type' => 'yesno',
                    'no_solution' => 'Reconnect keyboard USB cable to computer.'
                )
            ),
            'steps' => array(
                array(
                    'step_num' => 1,
                    'title' => 'Check Keyboard Input',
                    'instruction' => 'Press Num Lock key to light up Num Lock LED for cashier number entry.',
                    'expected' => 'Number pad types numbers 0-9 accurately.'
                )
            )
        ),

        'monitor' => array(
            'id' => 'monitor',
            'name' => 'Monitor',
            'image' => 'hardware_photos/monitor.png',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>',
            'description' => 'LCD/LED Touchscreen or Cashier display monitor.',
            'common_issues' => array(
                "No signal / Black screen",
                "Touchscreen non-responsive",
                "Screen flickering or color distortion",
                "Other"
            ),
            'questions' => array(
                array(
                    'id' => 'q1',
                    'text' => 'Select Monitor issue:',
                    'type' => 'choice',
                    'options' => array(
                        "No signal / Black screen",
                        "Touchscreen non-responsive",
                        "Screen flickering or color distortion",
                        "Other"
                    )
                ),
                array(
                    'id' => 'q2',
                    'text' => 'Is the monitor power LED indicator light ON?',
                    'type' => 'yesno',
                    'no_solution' => 'Press power button on monitor and verify AC power cable.'
                )
            ),
            'steps' => array(
                array(
                    'step_num' => 1,
                    'title' => 'Secure VGA / HDMI Display Cable',
                    'instruction' => 'Tighten thumbscrews on VGA or HDMI cable on both monitor and System Unit graphics card port.',
                    'expected' => 'Windows desktop screen displays clearly.'
                )
            )
        ),

        'cash-drawer' => array(
            'id' => 'cash-drawer',
            'name' => 'Cash Drawer',
            'image' => 'hardware_photos/cash_drawer.jpg',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 8h14M5 8a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v1a2 2 0 01-2 2M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>',
            'description' => 'RJ11 automated POS metallic cash drawer.',
            'common_issues' => array(
                "Drawer not opening automatically after sale",
                "Key stuck or drawer jammed mechanically",
                "Squeaking or loose tray",
                "Other"
            ),
            'questions' => array(
                array(
                    'id' => 'q1',
                    'text' => 'What is the Cash Drawer problem?',
                    'type' => 'choice',
                    'options' => array(
                        "Drawer not opening automatically after sale",
                        "Key stuck or drawer jammed mechanically",
                        "Squeaking or loose tray",
                        "Other"
                    )
                ),
                array(
                    'id' => 'q2',
                    'text' => 'Is the RJ11 phone-style kick-out cable connected into the bottom of the Thermal Printer DK port?',
                    'type' => 'yesno',
                    'no_solution' => 'Plug cash drawer RJ11 cable tightly into the thermal printer port labeled DK / Drawer.'
                )
            ),
            'steps' => array(
                array(
                    'step_num' => 1,
                    'title' => 'Verify Lock Position & Printer Kick Code',
                    'instruction' => 'Turn physical key to vertical (unlocked) position. Ensure thermal printer properties has Cash Drawer enabled.',
                    'expected' => 'Cash drawer pops open automatically when receipt prints.'
                )
            )
        ),

        'rfid-reader' => array(
            'id' => 'rfid-reader',
            'name' => 'RFID Reader',
            'image' => 'hardware_photos/rfid_reader.jpeg',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 004 11c0 2.473.345 4.866.99 7.132"/></svg>',
            'description' => '13.56MHz / 125KHz RFID card scanner module.',
            'common_issues' => array(
                "Card tap produce no beep",
                "RFID tag UID not reading",
                "USB reader unrecognised",
                "Other"
            ),
            'questions' => array(
                array(
                    'id' => 'q1',
                    'text' => 'Select RFID Reader issue:',
                    'type' => 'choice',
                    'options' => array(
                        "Card tap produce no beep",
                        "RFID tag UID not reading",
                        "USB reader unrecognised",
                        "Other"
                    )
                ),
                array(
                    'id' => 'q2',
                    'text' => 'Is the reader LED glowing red or green when powered?',
                    'type' => 'yesno',
                    'no_solution' => 'Reconnect USB cable to USB 2.0 port.'
                )
            ),
            'steps' => array(
                array(
                    'step_num' => 1,
                    'title' => 'Test Card Scan',
                    'instruction' => 'Tap RFID card directly over scanner center zone.',
                    'expected' => 'Reader beeps once and outputs card UID number.'
                )
            )
        ),

        'system-unit' => array(
            'id' => 'system-unit',
            'name' => 'System Unit',
            'image' => 'hardware_photos/system_unit.jpg',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12v6a2 2 0 002 2h10a2 2 0 002-2v-6m-7 4h4"/></svg>',
            'description' => 'Main PC Server / POS System unit chassis.',
            'common_issues' => array(
                "PC not powering on / No fan spin",
                "Continuous beep code on startup",
                "Blue screen of death (BSOD)",
                "System running very slow / overheating",
                "Other"
            ),
            'questions' => array(
                array(
                    'id' => 'q1',
                    'text' => 'What is the System Unit issue?',
                    'type' => 'choice',
                    'options' => array(
                        "PC not powering on / No fan spin",
                        "Continuous beep code on startup",
                        "Blue screen of death (BSOD)",
                        "System running very slow / overheating",
                        "Other"
                    )
                ),
                array(
                    'id' => 'q2',
                    'text' => 'Is the PSU power switch on the back set to "I" (ON)?',
                    'type' => 'yesno',
                    'no_solution' => 'Flip switch on back of power supply to position I.'
                )
            ),
            'steps' => array(
                array(
                    'step_num' => 1,
                    'title' => 'Power Supply & RAM Re-seat',
                    'instruction' => 'Disconnect AC cable for 10s, hold power button to drain electricity, then reconnect.',
                    'expected' => 'Power LED turns on and system boots to Windows desktop.'
                )
            )
        ),

        'ups' => array(
            'id' => 'ups',
            'name' => 'UPS',
            'image' => 'hardware_photos/ups.jpg',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>',
            'description' => 'Uninterruptible Power Supply battery backup.',
            'common_issues' => array(
                "UPS beeping continuously",
                "Red Replace Battery light lit",
                "PC turns off instantly during brownout",
                "Other"
            ),
            'questions' => array(
                array(
                    'id' => 'q1',
                    'text' => 'Select UPS problem:',
                    'type' => 'choice',
                    'options' => array(
                        "UPS beeping continuously",
                        "Red Replace Battery light lit",
                        "PC turns off instantly during brownout",
                        "Other"
                    )
                ),
                array(
                    'id' => 'q2',
                    'text' => 'Is the main AC wall outlet providing electricity?',
                    'type' => 'yesno',
                    'no_solution' => 'Test wall outlet with a phone charger to confirm electricity presence.'
                )
            ),
            'steps' => array(
                array(
                    'step_num' => 1,
                    'title' => 'Test Battery Charge',
                    'instruction' => 'Leave UPS plugged in for 4 hours to charge internal lead-acid battery.',
                    'expected' => 'Constant beep stops and green Battery Ready light stays ON.'
                )
            )
        ),

        'router' => array(
            'id' => 'router',
            'name' => 'Router',
            'image' => 'hardware_photos/router.jpg',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/></svg>',
            'description' => 'Wi-Fi / Ethernet Network Router.',
            'common_issues' => array(
                "No Internet light (Red WAN)",
                "Wi-Fi network disconnected",
                "POS terminal cannot connect to DB server",
                "Other"
            ),
            'questions' => array(
                array(
                    'id' => 'q1',
                    'text' => 'What is the Router issue?',
                    'type' => 'choice',
                    'options' => array(
                        "No Internet light (Red WAN)",
                        "Wi-Fi network disconnected",
                        "POS terminal cannot connect to DB server",
                        "Other"
                    )
                ),
                array(
                    'id' => 'q2',
                    'text' => 'Are the front LED lights on the router glowing?',
                    'type' => 'yesno',
                    'no_solution' => 'Plug router 12V DC power adapter firmly into power strip.'
                )
            ),
            'steps' => array(
                array(
                    'step_num' => 1,
                    'title' => 'Reboot Router',
                    'instruction' => 'Unplug router power cable for 30 seconds, then plug back in.',
                    'expected' => 'WAN & Internet LEDs turn solid green after 2 minutes.'
                )
            )
        ),

        'lan-cable' => array(
            'id' => 'lan-cable',
            'name' => 'LAN Cable',
            'image' => 'hardware_photos/hardwarelan_cable.jpg',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>',
            'description' => 'Cat5e / Cat6 RJ45 Ethernet patch cable.',
            'common_issues' => array(
                "Network cable unplugged error",
                "Intermittent network dropouts",
                "Broken RJ45 clip plastic tab",
                "Other"
            ),
            'questions' => array(
                array(
                    'id' => 'q1',
                    'text' => 'Select LAN Cable issue:',
                    'type' => 'choice',
                    'options' => array(
                        "Network cable unplugged error",
                        "Intermittent network dropouts",
                        "Broken RJ45 clip plastic tab",
                        "Other"
                    )
                ),
                array(
                    'id' => 'q2',
                    'text' => 'Are the green/amber link LED lights blinking at the computer Ethernet port?',
                    'type' => 'yesno',
                    'no_solution' => 'Unplug RJ45 connector and re-insert firmly until clip clicks.'
                )
            ),
            'steps' => array(
                array(
                    'step_num' => 1,
                    'title' => 'Check Cable Continuity',
                    'instruction' => 'Ensure RJ45 plastic tab is intact and cable isn’t pinched under heavy furniture.',
                    'expected' => 'Windows network status shows "Connected".'
                )
            )
        ),

        'wifi-dongle' => array(
            'id' => 'wifi-dongle',
            'name' => 'Wi-Fi Dongle',
            'image' => 'hardware_photos/wifi_dongle.jpg',
            'icon' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0"/></svg>',
            'description' => 'USB Wireless N / AC Network Adapter.',
            'common_issues' => array(
                "Wi-Fi dongle not detected in Windows",
                "Weak Wi-Fi signal strength",
                "Wi-Fi keeps disconnecting",
                "Other"
            ),
            'questions' => array(
                array(
                    'id' => 'q1',
                    'text' => 'Select Wi-Fi Dongle problem:',
                    'type' => 'choice',
                    'options' => array(
                        "Wi-Fi dongle not detected in Windows",
                        "Weak Wi-Fi signal strength",
                        "Wi-Fi keeps disconnecting",
                        "Other"
                    )
                ),
                array(
                    'id' => 'q2',
                    'text' => 'Is the Wi-Fi icon visible in Windows taskbar system tray?',
                    'type' => 'yesno',
                    'no_solution' => 'Plug USB dongle into a rear motherboard USB 3.0 port.'
                )
            ),
            'steps' => array(
                array(
                    'step_num' => 1,
                    'title' => 'Re-connect to Wi-Fi SSID',
                    'instruction' => 'Click Wi-Fi icon, select store Wi-Fi network, enter password and check "Connect Automatically".',
                    'expected' => 'Internet connection established.'
                )
            )
        )
    );
}

/**
 * Get single hardware device by ID
 */
function get_hardware_device($device_id) {
    $list = get_hardware_devices_list();
    if (isset($list[$device_id])) {
        return $list[$device_id];
    }
    return null;
}

/**
 * Format raw hardware diagnostic log to expand Q1 [q1] into actual question text and answer
 */
function format_diagnostic_log_text($raw_log) {
    if (empty($raw_log) || strpos($raw_log, '=== HARDWARE DIAGNOSTIC LOG ===') === false) {
        return $raw_log;
    }

    // Extract device name if present
    $device_key = 'thermal-printer';
    if (preg_match('/Device:\s*([^\r\n]+)/i', $raw_log, $m)) {
        $dev_name = trim($m[1]);
        $devices = get_hardware_devices_list();
        foreach ($devices as $dk => $dinfo) {
            if (strcasecmp($dinfo['name'], $dev_name) === 0) {
                $device_key = $dk;
                break;
            }
        }
    }

    $devices = get_hardware_devices_list();
    $q_map = array();
    if (isset($devices[$device_key]['questions'])) {
        foreach ($devices[$device_key]['questions'] as $q_item) {
            $q_map[$q_item['id']] = $q_item['text'];
        }
    }

    $lines = explode("\n", $raw_log);
    $new_lines = array();

    foreach ($lines as $line) {
        $line_trimmed = trim($line);
        if (preg_match('/^Q\d+\s*\[(q\d+)\]:\s*(.+)$/i', $line_trimmed, $matches)) {
            $qid = strtolower($matches[1]);
            $answer = trim($matches[2]);
            $qtext = isset($q_map[$qid]) ? $q_map[$qid] : ("Question " . strtoupper($qid));
            $new_lines[] = "Question: " . $qtext;
            $new_lines[] = "Answer: " . $answer;
            $new_lines[] = "";
        } else {
            $new_lines[] = $line;
        }
    }

    return implode("\n", $new_lines);
}
?>
