<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PolicyFlow — Travel Insurance Issuance Engine</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
    <style>
        :root {
            --bg: #0f172a;
            --surface: #1e293b;
            --surface-hover: #334155;
            --border: #334155;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --text: #f8fafc;
            --text-muted: #94a3b8;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Instrument Sans', sans-serif; }
        body { background: var(--bg); color: var(--text); padding: 24px; min-height: 100vh; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 20px; margin-bottom: 24px; }
        .badge { background: rgba(16, 185, 129, 0.2); color: #34d399; padding: 6px 12px; border-radius: 9999px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; }
        .badge::before { content: ""; width: 8px; height: 8px; background: #34d399; border-radius: 50%; display: inline-block; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 24px; }
        .card h2 { font-size: 18px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; color: #e2e8f0; }
        .btn { background: var(--primary); color: white; border: none; padding: 10px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.15s ease; font-size: 14px; display: inline-flex; align-items: center; gap: 6px; }
        .btn:hover { background: var(--primary-hover); }
        .btn-success { background: #059669; } .btn-success:hover { background: #047857; }
        .btn-warning { background: #d97706; } .btn-warning:hover { background: #b45309; }
        .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text); }
        .btn-outline:hover { background: var(--surface-hover); }
        .actions-bar { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; }
        pre { background: #0b1120; border: 1px solid #1e293b; border-radius: 8px; padding: 14px; overflow-x: auto; font-family: monospace; font-size: 13px; color: #38bdf8; max-height: 380px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border); }
        th { color: var(--text-muted); font-weight: 600; text-transform: uppercase; font-size: 11px; letter-spacing: 0.05em; }
        .status-pill { padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        .status-ISSUED { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .status-VALIDATED { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
        .status-NEEDS_RECONCILIATION { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
        .status-FAILED { background: rgba(239, 68, 68, 0.2); color: #f87171; }
        .status-SUBMITTED { background: rgba(168, 85, 247, 0.2); color: #c084fc; }
        .endpoint-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 13px; }
        .method { font-weight: 700; font-size: 11px; padding: 2px 6px; border-radius: 4px; }
        .method-POST { background: #059669; color: white; }
        .method-GET { background: #2563eb; color: white; }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <div>
                <h1 style="font-size: 24px; font-weight: 700;">🛡️ PolicyFlow</h1>
                <p style="color: var(--text-muted); font-size: 14px; margin-top: 4px;">Fault-Tolerant Travel Insurance Issuance & Reconciliation Engine</p>
            </div>
            <div>
                <span class="badge">Engine Live • Port 8000</span>
            </div>
        </header>

        <div class="grid">
            <!-- Left Column: Interactive Console -->
            <div>
                <div class="card">
                    <h2>⚡ Interactive API Testing Console</h2>
                    <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 16px;">Trigger backend operations with fabricated sample data directly:</p>
                    
                    <div class="actions-bar">
                        <button class="btn" onclick="createPolicy()">1. Create Intake (₹225)</button>
                        <button class="btn btn-success" onclick="submitPolicy()">2. Submit Issuance</button>
                        <button class="btn btn-warning" onclick="testTimeout()">3. Test Timeout</button>
                        <button class="btn" style="background: #9333ea;" onclick="reconcilePolicy()">4. Reconcile Policy</button>
                        <button class="btn btn-outline" onclick="loadPolicies()">🔄 Refresh Table</button>
                    </div>

                    <div style="margin-top: 16px;">
                        <span style="font-size: 12px; font-weight: 600; color: var(--text-muted);">LIVE JSON RESPONSE:</span>
                        <pre id="jsonViewer">// Click any action above to inspect live API responses...</pre>
                    </div>
                </div>

                <div class="card">
                    <h2>📋 API Endpoints Specification</h2>
                    <div class="endpoint-row">
                        <div><span class="method method-POST">POST</span> <code>/api/policy-requests</code></div>
                        <span style="color: var(--text-muted);">Intake & Pricing</span>
                    </div>
                    <div class="endpoint-row">
                        <div><span class="method method-GET">GET</span> <code>/api/policy-requests/{id}</code></div>
                        <span style="color: var(--text-muted);">Get Request + Audit Trail</span>
                    </div>
                    <div class="endpoint-row">
                        <div><span class="method method-GET">GET</span> <code>/api/policy-requests</code></div>
                        <span style="color: var(--text-muted);">List / Filter / Pagination</span>
                    </div>
                    <div class="endpoint-row">
                        <div><span class="method method-POST">POST</span> <code>/api/policy-requests/{id}/submit</code></div>
                        <span style="color: var(--text-muted);">Submit to Mock Insurer</span>
                    </div>
                    <div class="endpoint-row">
                        <div><span class="method method-POST">POST</span> <code>/api/policy-requests/{id}/reconcile</code></div>
                        <span style="color: var(--text-muted);">Recover Uncertain Outcomes</span>
                    </div>
                    <div class="endpoint-row">
                        <div><span class="method method-POST">POST</span> <code>/api/mock-insurer/callback</code></div>
                        <span style="color: var(--text-muted);">Idempotent Callback</span>
                    </div>
                </div>
            </div>

            <!-- Right Column: Live Stored Policies -->
            <div>
                <div class="card">
                    <h2>📊 Stored Policy Requests (<span id="policyCount">0</span>)</h2>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Client Req ID</th>
                                    <th>Customer</th>
                                    <th>Premium</th>
                                    <th>Status</th>
                                    <th>Policy No.</th>
                                </tr>
                            </thead>
                            <tbody id="policiesTable">
                                <tr><td colspan="6" style="text-align: center; color: var(--text-muted);">Loading database records...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <h2>🧮 Sample Pricing Rule Formula</h2>
                    <p style="font-size: 13px; color: var(--text-muted); line-height: 1.6;">
                        <strong>Formula:</strong> <code>base_rate × days × age_multiplier × destination_multiplier × coverage_multiplier</code><br>
                        <strong>Example:</strong> PLUS (₹20) × 5 days × Age 28 (1.00) × INTERNATIONAL (1.80) × ₹300,000 (1.25)<br>
                        <strong>Total:</strong> <span style="color: #34d399; font-weight: 700;">₹225.00</span> (Stored as 22,500 paise in snapshot)
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
        let latestPolicyId = 1;

        async function createPolicy() {
            const clientReqId = 'AGT-UI-' + Math.floor(1000 + Math.random() * 9000);
            const payload = {
                client_request_id: clientReqId,
                agent_id: "AGENT-17",
                customer_name: "Sample Customer",
                date_of_birth: "1998-04-10",
                destination: "INTERNATIONAL",
                trip_start: "2026-11-10",
                trip_end: "2026-11-14",
                plan_code: "PLUS",
                sum_insured: 300000
            };

            const res = await fetch('/api/policy-requests', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            document.getElementById('jsonViewer').innerText = JSON.stringify(data, null, 2);
            if (data.data && data.data.id) {
                latestPolicyId = data.data.id;
            }
            loadPolicies();
        }

        async function submitPolicy() {
            const res = await fetch(`/api/policy-requests/${latestPolicyId}/submit`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ scenario: "SUCCESS" })
            });
            const data = await res.json();
            document.getElementById('jsonViewer').innerText = JSON.stringify(data, null, 2);
            loadPolicies();
        }

        async function testTimeout() {
            const clientReqId = 'AGT-TIMEOUT-' + Math.floor(1000 + Math.random() * 9000);
            const createRes = await fetch('/api/policy-requests', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    client_request_id: clientReqId,
                    agent_id: "AGENT-17",
                    customer_name: "Timeout Customer",
                    date_of_birth: "1992-03-12",
                    destination: "DOMESTIC",
                    trip_start: "2026-12-01",
                    trip_end: "2026-12-03",
                    plan_code: "BASIC",
                    sum_insured: 100000
                })
            });
            const created = await createRes.json();
            const id = created.data.id;
            latestPolicyId = id;

            const submitRes = await fetch(`/api/policy-requests/${id}/submit`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ scenario: "TIMEOUT_AFTER_ISSUE" })
            });
            const data = await submitRes.json();
            document.getElementById('jsonViewer').innerText = JSON.stringify(data, null, 2);
            loadPolicies();
        }

        async function reconcilePolicy() {
            const res = await fetch(`/api/policy-requests/${latestPolicyId}/reconcile`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }
            });
            const data = await res.json();
            document.getElementById('jsonViewer').innerText = JSON.stringify(data, null, 2);
            loadPolicies();
        }

        async function loadPolicies() {
            try {
                const res = await fetch('/api/policy-requests?per_page=20', {
                    headers: { 'Accept': 'application/json' }
                });
                const json = await res.json();
                const list = json.data || [];
                document.getElementById('policyCount').innerText = list.length;

                if (list.length === 0) {
                    document.getElementById('policiesTable').innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No records found. Click "Create Intake" to add one!</td></tr>';
                    return;
                }

                document.getElementById('policiesTable').innerHTML = list.map(item => `
                    <tr>
                        <td><strong>#${item.id}</strong></td>
                        <td><code>${item.client_request_id}</code></td>
                        <td>${item.customer_name}</td>
                        <td><strong>₹${Number(item.premium).toFixed(2)}</strong></td>
                        <td><span class="status-pill status-${item.status}">${item.status}</span></td>
                        <td><code>${item.policy_number || '—'}</code></td>
                    </tr>
                `).join('');
            } catch (e) {
                console.error(e);
            }
        }

        loadPolicies();
    </script>
</body>
</html>
