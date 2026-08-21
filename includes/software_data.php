<?php
// Software Issues Knowledge Base & Diagnostic Trees (PHP 5.6 Compatible)

function get_software_issues_list() {
    return array(
        'update-data' => array(
            'id' => 'update-data',
            'name' => 'Update Data',
            'category' => 'Software Support',
            'icon' => '<svg class="w-6 h-6 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>',
            'description' => 'Request remote technical support to update POS database tables, client records, tax configurations, or system data via UltraViewer.',
            'main_cause' => 'Requires remote technical access via UltraViewer to execute database adjustments and data updates directly on the POS terminal.',
            'is_remote' => true,
            'questions' => array(
                array(
                    'id' => 'q1',
                    'text' => 'Is UltraViewer installed and running on your POS computer terminal?',
                    'type' => 'yesno',
                    'no_solution' => 'Please download and open UltraViewer on your POS terminal so the technician can connect.'
                )
            ),
            'steps' => array(
                array(
                    'step_num' => 1,
                    'title' => 'Open UltraViewer on POS Terminal',
                    'instruction' => 'Launch UltraViewer on your desktop. Locate the "Your ID" and "Password" displayed on the UltraViewer main window.',
                    'expected' => 'UltraViewer status shows "Ready to connect".'
                ),
                array(
                    'step_num' => 2,
                    'title' => 'Submit Ticket with UltraViewer Credentials & Remarks',
                    'instruction' => 'Submit a support ticket providing your UltraViewer ID, Password, and specific remarks on which data needs updating.',
                    'expected' => 'Support ticket created and assigned to technical staff.'
                )
            )
        ),

        'update-item-info' => array(
            'id' => 'update-item-info',
            'name' => 'Update Item Info',
            'category' => 'Software Support',
            'icon' => '<svg class="w-6 h-6 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>',
            'description' => 'Request remote technical assistance to encode new items, update product selling prices, edit barcodes, or reorganize menu categories via UltraViewer.',
            'main_cause' => 'Requires remote technical access via UltraViewer to update product lists, prices, barcodes, and inventory records.',
            'is_remote' => true,
            'questions' => array(
                array(
                    'id' => 'q1',
                    'text' => 'Do you have the item details/prices list ready and UltraViewer open on your POS terminal?',
                    'type' => 'yesno',
                    'no_solution' => 'Prepare the list of item names, SKU barcodes, and updated prices, then open UltraViewer.'
                )
            ),
            'steps' => array(
                array(
                    'step_num' => 1,
                    'title' => 'Prepare Item List & Launch UltraViewer',
                    'instruction' => 'Have your list of items, prices, or barcode corrections ready. Open UltraViewer on the POS terminal.',
                    'expected' => 'UltraViewer is active and ready with ID & Password.'
                ),
                array(
                    'step_num' => 2,
                    'title' => 'Submit Ticket with UltraViewer Credentials & Remarks',
                    'instruction' => 'Submit a ticket with your UltraViewer ID, Password, and detailed remarks describing the items and prices to update.',
                    'expected' => 'Technical support connects remotely and updates item info.'
                )
            )
        ),

        'slow-pos' => array(
            'id' => 'slow-pos',
            'name' => 'Slow / Lagging POS Terminal',
            'category' => 'Performance',
            'image' => 'hardware_photos/thermal_printer.png',
            'icon' => '<svg class="w-6 h-6 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>',
            'description' => 'POS transaction processing is slow, lagging, or taking noticeable time to respond during item lookup and checkout.',
            'common_causes' => array(
                'High system RAM / CPU memory consumption',
                'Stuck background print spooler tasks',
                'Accumulated temporary database cache',
                'Network switch latency or server connection lag'
            ),
            'questions' => array(
                array(
                    'id' => 'q1',
                    'text' => 'When does the POS terminal experience lagging or slowness?',
                    'type' => 'choice',
                    'options' => array(
                        'During barcode scanning & item lookup',
                        'When printing receipts or closing payment',
                        'Constantly slow right from computer startup',
                        'Only during peak store hours'
                    )
                ),
                array(
                    'id' => 'q2',
                    'text' => 'Are there extra applications or browser tabs open on the POS terminal unit?',
                    'type' => 'yesno',
                    'no_solution' => 'Proceeding to system optimization and print queue diagnostic.'
                ),
                array(
                    'id' => 'q3',
                    'text' => 'Has the POS terminal computer been restarted within the last 24 hours?',
                    'type' => 'yesno',
                    'no_solution' => 'Restarting the POS terminal flushes accumulated RAM cache and clears frozen background tasks.'
                )
            ),
            'steps' => array(
                array(
                    'step_num' => 1,
                    'title' => 'Close Unused Background Applications & Windows',
                    'instruction' => 'Check the Windows Taskbar. Close any extra web browsers, streaming media, heavy documents, or non-POS software running in the background.',
                    'expected' => 'System RAM is freed up and the POS user interface responds immediately.'
                ),
                array(
                    'step_num' => 2,
                    'title' => 'Clear Windows Print Spooler Queue',
                    'instruction' => 'Go to Windows Control Panel -> Devices & Printers. Double-click your receipt printer and cancel any pending or errored print documents sitting in the queue.',
                    'expected' => 'Print spooler stops hanging the POS application during receipt generation.'
                ),
                array(
                    'step_num' => 3,
                    'title' => 'Clear Temporary POS Database Cache',
                    'instruction' => 'Inside POS Software -> Go to Settings / System Maintenance -> Click "Clear Temp Cache" or "Re-index Local Database".',
                    'expected' => 'Database index optimization completes and item search speed is restored.'
                ),
                array(
                    'step_num' => 4,
                    'title' => 'Perform POS Terminal System Reboot',
                    'instruction' => 'Save open register shifts, exit the POS application cleanly, click Windows Start -> Power -> Restart. Wait 1 minute after Windows boots before opening POS.',
                    'expected' => 'Terminal boots clean with optimal CPU/RAM performance.'
                )
            )
        ),

        'account-in-use' => array(
            'id' => 'account-in-use',
            'name' => 'Account Already In Use / User Locked',
            'category' => 'User & Security',
            'icon' => '<svg class="w-6 h-6 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>',
            'description' => 'Cashier or user account is unable to log in because the system displays "Account is already logged in on another terminal / active session".',
            'main_cause' => 'The user did not log out properly from their previous terminal session (e.g. system force closed, browser closed unexpectedly, network disruption, or power outage).',
            'questions' => array(
                array(
                    'id' => 'q1',
                    'text' => 'Did the previous cashier or user exit the POS software cleanly by clicking Log Out?',
                    'type' => 'yesno',
                    'no_solution' => 'Abruptly shutting down or closing POS without clicking Log Out leaves the session token active in the database.'
                ),
                array(
                    'id' => 'q2',
                    'text' => 'Do you have access to an Admin or Manager supervisor login account on the POS?',
                    'type' => 'yesno',
                    'no_solution' => 'An Admin or Manager account is required to access Accounts Management and release locked active cashier sessions.'
                )
            ),
            'steps' => array(
                array(
                    'step_num' => 1,
                    'title' => 'Understand the Cause & Login as Admin',
                    'instruction' => 'Main Cause: The user session remained active in the database because the previous user did not click Log Out. Solution: On the POS login screen, log in using an Admin or Manager supervisor account.',
                    'expected' => 'Admin dashboard / main menu opens successfully.'
                ),
                array(
                    'step_num' => 2,
                    'title' => 'Navigate to Accounts / Active Cashiers',
                    'instruction' => 'From the Admin menu, click on "Accounts" (or Cashier Management / Active Sessions).',
                    'expected' => 'The list of registered cashier accounts and active sessions appears.'
                ),
                array(
                    'step_num' => 3,
                    'title' => 'Select the Locked Account & Click "Log Out"',
                    'instruction' => 'Locate the specific cashier account that is showing "Already In Use". Click the "Log Out" or "Release Session" button next to their name.',
                    'expected' => 'The system prompts confirmation and marks the cashier session as Inactive / Logged Out.'
                ),
                array(
                    'step_num' => 4,
                    'title' => 'Cashier Log In Verification',
                    'instruction' => 'Log out from the Admin account. Have the cashier attempt logging in again with their normal cashier credentials.',
                    'expected' => 'Cashier logs in smoothly without any "Account already in use" prompt.'
                )
            )
        ),

        'db-connection-error' => array(
            'id' => 'db-connection-error',
            'name' => 'Database Connection Failed',
            'category' => 'Database',
            'icon' => '<svg class="w-6 h-6 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>',
            'description' => 'POS terminal displays "Unable to connect to local host / Server Database" error on startup.',
            'common_causes' => array(
                'MySQL / Database service stopped on main server PC',
                'Unplugged LAN cable or disconnected Wi-Fi network',
                'IP address change on main server computer'
            ),
            'questions' => array(
                array(
                    'id' => 'q1',
                    'text' => 'Is the main Server computer powered ON and connected to the network switch?',
                    'type' => 'yesno',
                    'no_solution' => 'Turn on the main Server computer unit and ensure the LAN network cables are securely connected.'
                )
            ),
            'steps' => array(
                array(
                    'step_num' => 1,
                    'title' => 'Verify Server Unit Power & Cable',
                    'instruction' => 'Check the main Server host computer. Ensure it is turned ON and LAN cable indicator light is blinking green.',
                    'expected' => 'Server is powered on and connected to local network.'
                ),
                array(
                    'step_num' => 2,
                    'title' => 'Restart Database Service (MySQL / XAMPP)',
                    'instruction' => 'On the Server PC, open XAMPP Control Panel or Services.msc -> Stop and Start the "MySQL" service.',
                    'expected' => 'MySQL status turns green / Running.'
                ),
                array(
                    'step_num' => 3,
                    'title' => 'Test Terminal Reconnection',
                    'instruction' => 'On the POS terminal, click "Retry Connection" or restart the POS application.',
                    'expected' => 'POS connects to database and opens login screen.'
                )
            )
        ),

        'z-read-shift-error' => array(
            'id' => 'z-read-shift-error',
            'name' => 'Shift Closing / Z-Read Error',
            'category' => 'Register & Reports',
            'icon' => '<svg class="w-6 h-6 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
            'description' => 'Unable to perform End-of-Day (Z-Read) shift close report or cash drawer reconciliation fails.',
            'common_causes' => array(
                'Unsaved / open pending transaction held on register',
                'Thermal receipt printer out of paper during report generation',
                'Unreconciled terminal shift date mismatch'
            ),
            'questions' => array(
                array(
                    'id' => 'q1',
                    'text' => 'Are there any pending or held sales transactions on the cashier screen?',
                    'type' => 'yesno',
                    'no_solution' => 'Complete or void any pending transactions on hold before performing Z-Read.'
                )
            ),
            'steps' => array(
                array(
                    'step_num' => 1,
                    'title' => 'Clear Held / Pending Transactions',
                    'instruction' => 'Go to Register -> Recall Held Transactions. Either void or finalize all pending orders.',
                    'expected' => 'Held transactions count becomes 0.'
                ),
                array(
                    'step_num' => 2,
                    'title' => 'Verify Thermal Printer Paper Roll',
                    'instruction' => 'Ensure receipt printer has sufficient thermal paper roll to print the full Z-Read summary report.',
                    'expected' => 'Printer is ready with paper.'
                ),
                array(
                    'step_num' => 3,
                    'title' => 'Execute End of Day Z-Read',
                    'instruction' => 'Go to Reports -> End of Day -> Click Generate Z-Read & Close Shift.',
                    'expected' => 'Z-Read report prints out and shift registers are closed.'
                )
            )
        )
    );
}

function get_software_issue($issue_id) {
    $list = get_software_issues_list();
    if (isset($list[$issue_id])) {
        return $list[$issue_id];
    }
    return null;
}
?>
