<?php
// Footer Component & Common Scripts
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
                <h3 class="text-lg font-extrabold text-[#430D07]">Submit Support Ticket</h3>
                <p class="text-xs text-[#7C2112]">Our technical team will review and respond promptly.</p>
            </div>
        </div>

        <form action="tickets" method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="action" value="create_ticket">
            
            <div>
                <label class="block text-xs font-bold text-[#430D07] uppercase tracking-wider mb-1.5">Subject / Issue Summary</label>
                <input type="text" name="subject" required placeholder="e.g., POS Thermal Printer Not Responding" class="w-full bg-[#FFF5ED] border border-[#FECDAA] text-[#430D07] text-xs sm:text-sm rounded-xl px-4 py-2.5 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-[#430D07] uppercase tracking-wider mb-1.5">Category</label>
                    <select name="category" class="w-full bg-[#FFF5ED] border border-[#FECDAA] text-[#430D07] text-xs sm:text-sm rounded-xl px-3 py-2.5 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                        <option value="POS Software">POS Software</option>
                        <option value="Hardware & Printer">Hardware & Printer</option>
                        <option value="Database & Network">Database & Network</option>
                        <option value="System Maintenance">System Maintenance</option>
                        <option value="Billing & Retainer">Billing & Retainer</option>
                        <option value="General Support">General Support</option>
                        <option value="Others">Others</option>
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

            <!-- Photo Attachment -->
            <div>
                <label class="block text-xs font-bold text-[#430D07] uppercase tracking-wider mb-1.5">Attach Photo / Screenshot (Optional)</label>
                <div class="flex items-center space-x-3">
                    <label class="cursor-pointer bg-[#FFE8D5] hover:bg-[#FECDAA] text-[#430D07] border border-[#FECDAA] text-xs font-bold px-4 py-2.5 rounded-xl flex items-center space-x-2 transition-all shrink-0">
                        <svg class="w-4 h-4 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <span>Choose Photo</span>
                        <input type="file" name="attachment" id="modalTicketPhotoInput" accept="image/*" class="hidden" onchange="previewModalTicketPhoto(this)">
                    </label>
                    <span id="modalTicketPhotoName" class="text-xs text-[#7C2112] truncate max-w-[220px]">No photo chosen</span>
                </div>
                <div id="modalTicketPhotoPreviewBox" class="hidden mt-2.5 relative inline-block">
                    <img id="modalTicketPhotoImg" src="" alt="Selected Photo" class="h-20 w-auto rounded-xl object-cover border border-[#FECDAA] shadow-sm">
                    <button type="button" onclick="clearModalTicketPhoto()" class="absolute -top-2 -right-2 w-5 h-5 bg-rose-600 text-white rounded-full flex items-center justify-center text-xs font-bold shadow-md hover:bg-rose-700">&times;</button>
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
function openNewTicketModal() {
    document.getElementById('newTicketModal').classList.remove('hidden');
}
function closeNewTicketModal() {
    document.getElementById('newTicketModal').classList.add('hidden');
}
function previewModalTicketPhoto(input) {
    if (input.files && input.files[0]) {
        var file = input.files[0];
        document.getElementById('modalTicketPhotoName').textContent = file.name;
        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('modalTicketPhotoImg').src = e.target.result;
            document.getElementById('modalTicketPhotoPreviewBox').classList.remove('hidden');
        };
        reader.readAsDataURL(file);
    }
}
function clearModalTicketPhoto() {
    var input = document.getElementById('modalTicketPhotoInput');
    if (input) input.value = '';
    document.getElementById('modalTicketPhotoName').textContent = 'No photo chosen';
    document.getElementById('modalTicketPhotoPreviewBox').classList.add('hidden');
    document.getElementById('modalTicketPhotoImg').src = '';
}
</script>
</body>
</html>
