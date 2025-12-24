<!DOCTYPE html>
<html>
<head>
    <title>Debug User Data</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .box { background: white; padding: 20px; margin: 10px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        pre { background: #f0f0f0; padding: 10px; border-radius: 4px; overflow-x: auto; }
        button { padding: 10px 20px; margin: 5px; cursor: pointer; }
    </style>
</head>
<body>
    <h1>Debug User Session</h1>

    <div class="box">
        <h2>LocalStorage Data</h2>
        <button onclick="showStorage()">Show Storage</button>
        <button onclick="clearStorage()">Clear Storage</button>
        <pre id="storage-output">Click "Show Storage" to view data</pre>
    </div>

    <div class="box">
        <h2>Avatar Preview</h2>
        <div id="avatar-preview"></div>
    </div>

    <script>
        function showStorage() {
            const output = document.getElementById('storage-output');
            const user = localStorage.getItem('user');
            const token = localStorage.getItem('auth_token');

            let result = 'AUTH TOKEN:\n' + (token || 'Not found') + '\n\n';
            result += 'USER DATA:\n' + (user || 'Not found') + '\n\n';

            if (user) {
                try {
                    const parsed = JSON.parse(user);
                    result += 'PARSED USER:\n' + JSON.stringify(parsed, null, 2);

                    // Test avatar generation
                    if (parsed.full_name) {
                        testAvatar(parsed.full_name);
                    } else if (parsed.name) {
                        testAvatar(parsed.name);
                    }
                } catch (e) {
                    result += '\nERROR PARSING: ' + e.message;
                }
            }

            output.textContent = result;
        }

        function testAvatar(fullName) {
            const names = fullName.trim().split(' ');
            let initials = '';
            if (names.length >= 2) {
                initials = names[0][0] + names[names.length - 1][0];
            } else {
                initials = names[0][0];
            }
            initials = initials.toUpperCase();

            const colors = ['#008080', '#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FFEAA7', '#DFE6E9', '#6C5CE7'];
            let hash = 0;
            for (let i = 0; i < fullName.length; i++) {
                hash = fullName.charCodeAt(i) + ((hash << 5) - hash);
            }
            const color = colors[Math.abs(hash) % colors.length];

            const svgContent = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="50" fill="${color}"/><text x="50" y="67" font-size="40" fill="white" text-anchor="middle" font-family="Arial, sans-serif" font-weight="bold">${initials}</text></svg>`;
            const encodedSvg = 'data:image/svg+xml;base64,' + btoa(svgContent);

            const preview = document.getElementById('avatar-preview');
            preview.innerHTML = `
                <p><strong>Name:</strong> ${fullName}</p>
                <p><strong>Initials:</strong> ${initials}</p>
                <p><strong>Color:</strong> ${color}</p>
                <img src="${encodedSvg}" style="width: 100px; height: 100px; border-radius: 50%; border: 2px solid #ccc;">
            `;
        }

        function clearStorage() {
            localStorage.clear();
            alert('Storage cleared! Please login again.');
            window.location.href = 'admin-login.html';
        }
    </script>
</body>
</html>
