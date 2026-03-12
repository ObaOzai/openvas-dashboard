# OpenVAS PHP Dashboard
**Obá Ozái - JD Correa-Landreau**
**AstroPema AI LLC | mail.astropema.ai | 2026-03-12**

Lightweight PHP dashboard for GVM/OpenVAS. Communicates with the host `gvmd` daemon
directly via GMP XML protocol over the Unix socket. No broken gsad required.

---

## The Problem

The `gsad` React frontend shipped with Greenbone Community Edition via Ubuntu apt
packages is broken on Ubuntu 22 and 24. This is a known, long-standing packaging
issue confirmed by Greenbone: *"the packets on Ubuntu are still broken."*
The scanner, manager, and database work fine — only the UI is missing.

---

## The Solution

Run a lightweight PHP dashboard in Docker, connected directly to the host `gvmd`
socket via GMP XML protocol. No data migration. No duplicate scanner stack.
All existing scan history and configurations are preserved.

---

## Deployment

### 1. Copy project to server
```bash
scp -r openvas-dashboard/ oba@your-server:~/openvas/
```

### 2. Create .env file with your gvmd admin password
```bash
cd ~/openvas
echo "GVM_PASS=your_gvmd_admin_password" > .env
chmod 600 .env
```

### 3. Fix socket permissions — critical step

The gvmd socket is owned `_gvm:_gvm 660`. Add `_gvm` to the docker group
so the container can access it. Do NOT use chown in the systemd override —
it runs as `_gvm` user (not root) and will crash-loop gvmd.
```bash
sudo usermod -aG docker _gvm
```

### 4. Install systemd override so permissions survive reboots
```bash
sudo mkdir -p /etc/systemd/system/gvmd.service.d/
sudo cp Socket-perms.conf /etc/systemd/system/gvmd.service.d/socket-perms.conf
sudo systemctl daemon-reload
sudo systemctl restart gvmd
sleep 5
ls -la /run/gvmd/gvmd.sock
# Expected: srw-rw---- 1 _gvm _gvm
```

### 5. Verify/reset gvmd admin password
```bash
# Test credentials
sudo -u _gvm gvm-cli --gmp-username admin --gmp-password YOUR_PASSWORD \
  socket --socketpath /run/gvmd/gvmd.sock -X '<get_version/>'

# Reset if needed
sudo -u _gvm gvmd --user=admin --new-password=YOUR_NEW_PASSWORD
```

Note: `--gmp-username` and `--gmp-password` go before the `socket` subcommand.

### 6. Build and start container
```bash
cd ~/openvas
docker compose -f Docker-compose.yml build
docker compose -f Docker-compose.yml up -d
docker compose -f Docker-compose.yml logs --tail=30
```

### 7. Test locally on server
```bash
curl http://127.0.0.1:9393/
# Should return HTML (200 OK)
```

### 8. Access via SSH tunnel
```bash
ssh -L 9393:127.0.0.1:9393 user@your-server -N
# Browser: http://localhost:9393
# Force data refresh: http://localhost:9393/?flush=1
```

### 9. Set up nginx reverse proxy (LAN access)
```bash
sudo cp Openvas-dashboard.conf /etc/nginx/sites-available/openvas-dashboard.conf
sudo ln -s /etc/nginx/sites-available/openvas-dashboard.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
# Browser: https://192.168.0.x:9393
```

---

## Architecture
```
[ Browser ]
    |
    | HTTPS (LAN)  or  SSH tunnel (any)
    v
[ Nginx :9393 ]  -->  [ Docker: PHP+Apache :9393 ]
                              |
                              | GMP XML over Unix socket
                              v
                       [ Host: /run/gvmd/gvmd.sock ]
                              |
                       [ Host: gvmd (apt) ]
                       [ Host: PostgreSQL/gvmd DB ]
                       [ Host: ospd-openvas ]
```

---

## File Layout
```
openvas-dashboard/
├── Docker-compose.yml
├── docker-file.sh              ← Dockerfile
├── index.php                   ← PHP dashboard
├── .env                        ← create this (GVM_PASS=...), never commit
├── Openvas-dashboard.conf      ← nginx reverse proxy config
└── Socket-perms.conf           ← systemd override for socket permissions
```

---

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| "Cannot connect to gvmd socket" | Socket permissions wrong | `ls -la /run/gvmd/gvmd.sock` — rerun steps 3+4 |
| "Authentication failed" | Wrong GVM_PASS | Reset: `sudo -u _gvm gvmd --user=admin --new-password=...` |
| gvmd crash-loops after override | chown in ExecStartPost fails | Use chmod only — handle group via `usermod -aG docker _gvm` |
| Port 9393 in use | Port conflict | Change host port in Docker-compose.yml |
| Container won't start | Build or mount error | `docker compose -f Docker-compose.yml logs` |
| Apache 403 on HTML guide | mod_security blocking code content | Add `SecRuleEngine Off` in `<Files>` block in Apache vhost |

---

## Dashboard Features

- Severity counts by CVSS range: Critical / High / Medium / Low / Log
- Overall risk level indicator (CRITICAL / HIGH / MEDIUM / LOW)
- GVMD connection status with live pulse indicator
- Severity breakdown bar chart
- Top affected hosts table
- Scan tasks with status badges and last-run timestamps
- 120-second cache with auto-refresh and manual flush (`?flush=1`)

---

## Tested On

- Ubuntu 24.04 LTS
- gvmd 22.4 (apt)
- Docker 24
- php:8.2-apache

---

## Community Guide

Full step-by-step HTML guide with architecture diagrams:
**https://astropema.ai/openvas-config.html**

---

*AstroPema AI LLC | OpenVAS PHP Dashboard v1.0 | 2026-03-12*
