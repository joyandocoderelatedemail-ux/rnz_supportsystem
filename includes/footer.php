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

        <form action="tickets" method="POST" class="space-y-4">
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
                <textarea name="issue_description" rows="4" required placeholder="Please describe what happened, error messages, or steps to reproduce..." class="w-full bg-[#FFF5ED] border border-[#FECDAA] text-[#430D07] text-xs sm:text-sm rounded-xl p-4 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all"></textarea>
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
</script>
</body>
</html>
