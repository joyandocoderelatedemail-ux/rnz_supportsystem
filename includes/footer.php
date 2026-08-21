<?php
// Footer Component & Common Scripts
$is_hw_page = (isset($active_page) && $active_page === 'hardware');
$is_sw_page = (isset($active_page) && $active_page === 'software');

if ($is_hw_page) {
    $modal_categories = array(
        'Thermal Printer' => 'Thermal Receipt Printer',
        'Dot Matrix Printer' => 'Dot Matrix Impact Printer',
        'Barcode Sticker / Label Printer' => 'Barcode Sticker / Label Printer',
        '1D/2D Handheld Barcode Scanner' => '1D/2D Handheld Barcode Scanner',
        'Customer Pole Display (VFD)' => 'Customer Pole Display (VFD)',
        'POS Display Monitor' => 'POS Display Monitor / Touchscreen',
        'Heavy Duty POS Cash Drawer' => 'Heavy Duty POS Cash Drawer',
        'POS System Unit Chassis / PC' => 'POS System Unit / PC Server',
        'Uninterruptible Power Supply (UPS)' => 'UPS / Power Backup',
        'RFID Card Reader' => 'RFID Card Reader',
        'Network Router / LAN / Wi-Fi' => 'Network Router / LAN Cable / Wi-Fi',
        'POS Keyboard & Mouse' => 'POS Keyboard & Mouse',
        'Hardware & Printer' => 'Hardware & Printer (General)',
        'Other Hardware Issue' => 'Other Hardware Concern'
    );
} elseif ($is_sw_page) {
    $modal_categories = array(
        'POS Software' => 'POS Software (General)',
        'Slow / Lagging POS Terminal' => 'Slow / Lagging POS Terminal',
        'Database Connection Error' => 'Database Connection Error',
        'POS Login & User Access Issue' => 'POS Login & User Access Issue',
        'Report & Calculation Discrepancy' => 'Report & Calculation Discrepancy',
        'Database Backup Failure' => 'Database Backup & Restore Issue',
        'Multi-Terminal Data Sync Issue' => 'Multi-Terminal Data Sync Issue',
        'Price / Product Encoding Issue' => 'Price / Product Encoding Issue',
        'Receipt / Sales Posting Error' => 'Receipt / Sales Posting Error',
        'Other Software Issue' => 'Other Software Issue'
    );
} else {
    $modal_categories = array(
        'POS Software' => 'POS Software',
        'Hardware & Printer' => 'Hardware & Printer',
        'Database & Network' => 'Database & Network',
        'System Maintenance' => 'System Maintenance',
        'Billing & Retainer' => 'Billing & Retainer',
        'General Support' => 'General Support',
        'Others' => 'Others'
    );
}
?>
<!-- New Ticket Modal -->
<div id="newTicketModal" class="fixed inset-0 z-50 flex items-center justify-center bg-[#430D07]/40 backdrop-blur-sm hidden transition-opacity">
    <div class="bg-white rounded-3xl shadow-2xl border border-[#FECDAA] max-w-lg w-full p-6 sm:p-8 relative mx-4 animate-in fade-in zoom-in duration-200">
        <!-- Close Button -->
        <button onclick="closeNewTicketModal()" class="absolute top-5 right-5 text-[#9A2512] hover:text-[#430D07] p-2 rounded-full hover:bg-[#FFE8D5] transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>

        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 rounded-2xl bg-[#FFE8D5] text-[#EB3E0B] flex items-center justify-center font-bold">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
            </div>
            <div>
                <h3 class="text-lg font-extrabold text-[#430D07]">
                    <?php 
                        if ($is_hw_page) echo 'Submit Hardware Support Ticket';
                        elseif ($is_sw_page) echo 'Submit Software Support Ticket';
                        else echo 'Submit Support Ticket';
                    ?>
                </h3>
                <p class="text-xs text-[#7C2112]">
                    <?php 
                        if ($is_hw_page) echo 'Hardware issues & replacement requests are prioritized promptly.';
                        elseif ($is_sw_page) echo 'Software bug & database concerns are routed directly to technical staff.';
                        else echo 'Our technical team will review and respond promptly.';
                    ?>
                </p>
            </div>
        </div>

        <form action="tickets.php" method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="action" value="create_ticket">
            
            <div>
                <label class="block text-xs font-bold text-[#430D07] uppercase tracking-wider mb-1.5">Subject / Issue Summary</label>
                <input type="text" name="subject" required placeholder="<?php echo $is_hw_page ? 'e.g., Thermal Printer Not Powering On' : ($is_sw_page ? 'e.g., POS Database Connection Failed on Terminal 1' : 'e.g., POS Thermal Printer Not Responding'); ?>" class="w-full bg-[#FFF5ED] border border-[#FECDAA] text-[#430D07] text-xs sm:text-sm rounded-xl px-4 py-2.5 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-[#430D07] uppercase tracking-wider mb-1.5">
                        <?php 
                            if ($is_hw_page) echo 'Hardware Device';
                            elseif ($is_sw_page) echo 'Software Category';
                            else echo 'Category';
                        ?>
                    </label>
                    <select name="category" id="modalTicketCategorySelect" class="w-full bg-[#FFF5ED] border border-[#FECDAA] text-[#430D07] text-xs sm:text-sm rounded-xl px-3 py-2.5 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all font-semibold">
                        <?php foreach ($modal_categories as $val => $label): ?>
                            <option value="<?php echo sanitize($val); ?>"><?php echo sanitize($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-[#430D07] uppercase tracking-wider mb-1.5">Priority</label>
                    <select name="priority" class="w-full bg-[#FFF5ED] border border-[#FECDAA] text-[#430D07] text-xs sm:text-sm rounded-xl px-3 py-2.5 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                        <option value="Low">Low</option>
                        <option value="Medium" selected>Medium</option>
                        <option value="High">High</option>
                        <option value="Urgent">Urgent</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-[#430D07] uppercase tracking-wider mb-1.5">Detailed Description</label>
                <textarea name="issue_description" rows="3" required placeholder="Please describe what happened, error messages, or steps to reproduce..." class="w-full bg-[#FFF5ED] border border-[#FECDAA] text-[#430D07] text-xs sm:text-sm rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all"></textarea>
            </div>

            <!-- Photo Attachments -->
            <div>
                <label class="block text-xs font-bold text-[#430D07] uppercase tracking-wider mb-1.5">Attach Photos / Screenshots (Optional, Multiple Allowed)</label>
                <div class="flex items-center space-x-3">
                    <label class="cursor-pointer bg-[#FFE8D5] hover:bg-[#FECDAA] text-[#430D07] border border-[#FECDAA] text-xs font-bold px-4 py-2.5 rounded-xl flex items-center space-x-2 transition-all shrink-0">
                        <svg class="w-4 h-4 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <span>Choose Photos</span>
                        <input type="file" name="attachments[]" id="modalTicketPhotoInput" accept="image/*" multiple class="hidden" onchange="previewModalTicketPhotos(this)">
                    </label>
                    <span id="modalTicketPhotoName" class="text-xs text-[#7C2112] truncate max-w-[220px]">No photos chosen</span>
                </div>
                <div id="modalTicketPhotoPreviewBox" class="hidden mt-3 space-y-2">
                    <div class="flex items-center justify-between">
                        <span id="modalTicketPhotoCount" class="text-[11px] font-bold text-[#EB3E0B]">0 photos selected</span>
                        <button type="button" onclick="clearModalTicketPhotos()" class="text-[11px] font-bold text-rose-600 hover:underline">Clear All</button>
                    </div>
                    <div id="modalTicketPhotoGrid" class="flex flex-wrap gap-2.5 max-h-36 overflow-y-auto p-2 bg-[#FFF5ED] border border-[#FECDAA] rounded-2xl">
                        <!-- Previews injected via JS -->
                    </div>
                </div>
            </div>

            <div class="pt-2 flex items-center justify-end space-x-3">
                <button type="button" onclick="closeNewTicketModal()" class="px-4 py-2.5 rounded-xl text-xs sm:text-sm font-semibold text-[#7C2112] hover:bg-[#FFE8D5] transition-colors">
                    Cancel
                </button>
                <button type="submit" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs sm:text-sm px-6 py-2.5 rounded-full shadow-md shadow-[#EB3E0B]/25 transition-all active:scale-95">
                    Submit Ticket
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openNewTicketModal(prefillCat, prefillSubj, prefillDesc) {
    var modal = document.getElementById('newTicketModal');
    if (modal) {
        modal.classList.remove('hidden');
    }
    if (prefillCat) {
        var catSelect = document.getElementById('modalTicketCategorySelect');
        if (catSelect) {
            var found = false;
            for (var i = 0; i < catSelect.options.length; i++) {
                if (catSelect.options[i].value === prefillCat || catSelect.options[i].text.toLowerCase().indexOf(prefillCat.toLowerCase()) !== -1) {
                    catSelect.selectedIndex = i;
                    found = true;
                    break;
                }
            }
            if (!found) {
                var opt = document.createElement('option');
                opt.value = prefillCat;
                opt.text = prefillCat;
                opt.selected = true;
                catSelect.appendChild(opt);
            }
        }
    }
    if (prefillSubj) {
        var subjInput = document.querySelector('#newTicketModal input[name="subject"]');
        if (subjInput) subjInput.value = prefillSubj;
    }
    if (prefillDesc) {
        var descInput = document.querySelector('#newTicketModal textarea[name="issue_description"]');
        if (descInput) descInput.value = prefillDesc;
    }
}
function closeNewTicketModal() {
    document.getElementById('newTicketModal').classList.add('hidden');
}
function previewModalTicketPhotos(input) {
    var previewBox = document.getElementById('modalTicketPhotoPreviewBox');
    var grid = document.getElementById('modalTicketPhotoGrid');
    var nameEl = document.getElementById('modalTicketPhotoName');
    var countEl = document.getElementById('modalTicketPhotoCount');

    if (!input.files || input.files.length === 0) {
        clearModalTicketPhotos();
        return;
    }

    grid.innerHTML = '';
    var total = input.files.length;
    nameEl.textContent = total + (total === 1 ? ' photo selected' : ' photos selected');
    if (countEl) countEl.textContent = total + (total === 1 ? ' photo selected' : ' photos selected');
    previewBox.classList.remove('hidden');

    for (var i = 0; i < total; i++) {
        (function(file) {
            var reader = new FileReader();
            reader.onload = function(e) {
                var thumb = document.createElement('div');
                thumb.className = 'relative inline-block';
                thumb.innerHTML = '<img src="' + e.target.result + '" alt="Preview" class="h-16 w-16 rounded-xl object-cover border border-[#FECDAA] shadow-xs">';
                grid.appendChild(thumb);
            };
            reader.readAsDataURL(file);
        })(input.files[i]);
    }
}
function clearModalTicketPhotos() {
    var input = document.getElementById('modalTicketPhotoInput');
    if (input) input.value = '';
    document.getElementById('modalTicketPhotoName').textContent = 'No photos chosen';
    document.getElementById('modalTicketPhotoPreviewBox').classList.add('hidden');
    document.getElementById('modalTicketPhotoGrid').innerHTML = '';
}
</script>
</body>
</html>
