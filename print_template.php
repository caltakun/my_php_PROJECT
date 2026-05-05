<!-- PRINT FORM (CLONED FROM OFFICIAL RIS TEMPLATE) -->
<div id="print-form" style="display:none; font-family: Arial, sans-serif; font-size: 12px;">
    <!-- HEADER -->
    <table width="100%" style="border-collapse: collapse;">
        <tr>
            <td width="60%" align="center">
                <img src="images/RWO7.png" style="width:80px;">
                <strong>REQUISITION AND ISSUE SLIP</strong>
                <img src="images/Bagong_pilipinas_logo.png" style="width:80px;">
            </td>
            <td width="20%" align="center">
                <div style="border:1px solid #000; font-size:11px; padding:2px; margin-top:4px;">
                    FM-OWWA 07-06.04
                </div>
            </td>
        </tr>
    </table>
    <br>
    <!-- ENTITY INFO -->
    <table width="100%" border="1" cellspacing="0" cellpadding="4" style="border-collapse: collapse;">
        <tr>
            <td colspan="4"><strong>Entity Name:</strong> Overseas Workers Welfare Administration</td>
            <td colspan="2"><strong>Fund Cluster:</strong> ____________</td>
        </tr>
        <tr>
            <td colspan="2"><strong>Division:</strong> SUPPLIES</td>
            <td colspan="2"><strong>Office:</strong> OWWA RWO7</td>
            <td colspan="2">
                <strong>Responsibility Center Code:</strong> ___________<br>
                <strong>RIS No.:</strong> ___________
            </td>
        </tr>
    </table>
    <br>
    <!-- MAIN TABLE -->
    <table width="100%" border="1" cellspacing="0" cellpadding="4" style="border-collapse: collapse;">
        <thead>
            <tr>
                <th rowspan="2" width="8%">Stock No.</th>
                <th rowspan="2" width="8%">Unit</th>
                <th rowspan="2" width="32%">Description</th>
                <th rowspan="2" width="8%">Qty</th>
                <th colspan="2" width="10%">Stock Available?</th>
                <th rowspan="2" width="8%">Qty</th>
                <th rowspan="2" width="16%">Remarks</th>
            </tr>
            <tr>
                <th width="5%">Yes</th>
                <th width="5%">No</th>
            </tr>
        </thead>
        <tbody id="print-table-body">
            <!-- Rows will be populated by JS -->
        </tbody>
    </table>
    <br>
    <!-- PURPOSE -->
    <table width="100%">
        <tr>
            <td><strong>Purpose:</strong> <u>For Office use only.</u></td>
        </tr>
    </table>
    <br><br>
    <!-- SIGNATURES -->
    <table width="100%" border="1" cellspacing="0" cellpadding="6" style="border-collapse: collapse; text-align:center;">
        <tr>
            <th>Requested by:</th>
            <th>Approved by:</th>
            <th>Issued by:</th>
            <th>Received by:</th>
        </tr>
        <tr style="height:60px;">
            <td id="p-user"></td>
            <td><strong>Mylah Joy L. Corag</strong></td>
            <td id="p-user"></td>
            <td id="p-user"></td>
        </tr>
        <tr>
            <td>JO</td>
            <td>OWWO II Supply Officer Alternate</td>
            <td>JO</td>
            <td>JO</td>
        </tr>
        <tr>
            <td id="date-requested"></td>
            <td id="date-approved"></td>
            <td id="date-issued"></td>
            <td id="date-received"></td>
        </tr>
    </table>
</div>

<!-- Move script to the end of body for proper execution -->
<script>
function getCurrentDateFormatted() {
    const now = new Date();
    const day = String(now.getDate()).padStart(2, '0');
    const month = now.toLocaleString('en-US', { month: 'short' });
    const year = String(now.getFullYear()).slice(-2);
    return `${day}-${month}-${year}`;
}

async function printUserRequests(userId, username) {
    console.log('Starting printUserRequests for userId:', userId, 'username:', username); // Debug log
    
    const tbody = document.getElementById('print-table-body');
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;">Loading requests...</td></tr>'; // Show loading
    
    try {
        const res = await fetch(`get_user_requests.php?user_id=${encodeURIComponent(userId)}`);
        console.log('Fetch response status:', res.status); // Debug log
        
        if (!res.ok) {
            throw new Error(`Server error: ${res.status} ${res.statusText}`);
        }
        
        const data = await res.json();
        console.log('Fetched data:', data); // Debug log
        
        if (data.error) {
            alert('Error from server: ' + data.error);
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;">No requests found.</td></tr>';
            return;
        }
        
        if (!data.requests || !Array.isArray(data.requests) || data.requests.length === 0) {
            alert('No requests available for this user.');
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;">No requests found.</td></tr>';
            return;
        }
        
        // Populate Requested / Issued / Received by (same user)
        const userText = data.user.username; // use username only (matches your image)

        document.getElementById('p-user').textContent = userText;
        document.getElementById('issued-by').textContent = userText;
        document.getElementById('received-by').textContent = userText;

        
        tbody.innerHTML = ''; // Clear loading message
        
        data.requests.forEach((request, index) => {
            console.log('Processing request:', index, request); // Debug log
            const row = document.createElement('tr');
            row.innerHTML = `
                <td></td>
                <td>pcs</td>
                <td>${request.name || 'N/A'}</td>
                <td>${request.quantity_requested || 0}</td>
                <td>✔</td>
                <td></td>
                <td>${request.quantity_requested || 0}</td>
                <td>For office use</td>
            `;
            tbody.appendChild(row);
        });
        
        // Add empty rows to fill up to 6 for consistency
        const totalRows = Math.max(data.requests.length, 6);
        for (let i = data.requests.length; i < totalRows; i++) {
            const emptyRow = document.createElement('tr');
            emptyRow.innerHTML = '<td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>';
            tbody.appendChild(emptyRow);
        }
        
        // Set current date for all signature dates
        const currentDate = getCurrentDateFormatted();
        document.getElementById('date-requested').textContent = currentDate;
        document.getElementById('date-approved').textContent = currentDate;
        document.getElementById('date-issued').textContent = currentDate;
        document.getElementById('date-received').textContent = currentDate;
        
        // Show and print
        document.getElementById('print-form').style.display = 'block';
        window.print();
        document.getElementById('print-form').style.display = 'none';
        
        console.log('Print completed successfully'); // Debug log
    } catch (e) {
        console.error('Failed to load print data:', e); // Debug log
        alert('Failed to load print data: ' + e.message);
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;">Error loading requests.</td></tr>';
    }
}

function approveAll(ids) {
    if (confirm('Approve all requests?')) {
        location.href = `update_request.php?ids=${ids}&action=approve`;
    }
}

function rejectAll(ids) {
    if (confirm('Reject all requests?')) {
        location.href = `update_request.php?ids=${ids}&action=reject`;
    }
}
</script>