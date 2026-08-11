{*
  Panelica client area — dashboard + lazy-loaded, AJAX self-service.
  All list/create/delete go through panelica_Api (JSON); the file manager
  through panelica_FmAjax. The page renders instantly; each tab loads on demand.
*}
<style>{literal}
.pnl-wrap{margin-top:15px}
.pnl-gauges{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px}
.pnl-gcard{flex:1 1 220px;border:1px solid rgba(128,128,128,.2);border-radius:12px;padding:16px 18px;background:rgba(128,128,128,.04)}
.pnl-gcard .lbl{font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;opacity:.6;margin-bottom:6px}
.pnl-gcard .val{font-size:1.5rem;font-weight:700;line-height:1.1}
.pnl-gcard .sub{font-size:.8rem;opacity:.6;margin-top:2px}
.pnl-bar{height:8px;border-radius:6px;background:rgba(128,128,128,.2);margin-top:10px;overflow:hidden}
.pnl-bar > span{display:block;height:100%;border-radius:6px;background:#2e7d32}
.pnl-bar.warn > span{background:#f39c12}.pnl-bar.crit > span{background:#e74c3c}
.pnl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px;margin:8px 0 6px}
.pnl-tile{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:20px 10px;border:1px solid rgba(128,128,128,.2);border-radius:12px;text-decoration:none;color:inherit;background:rgba(128,128,128,.03);transition:.15s;cursor:pointer;text-align:center}
.pnl-tile:hover{transform:translateY(-2px);border-color:#3b82f6;background:rgba(59,130,246,.08);text-decoration:none;color:inherit}
.pnl-tile i{font-size:1.7rem;color:#3b82f6}
.pnl-tile .t{font-weight:600;font-size:.92rem}
.pnl-tile .c{font-size:.78rem;opacity:.6}
.pnl-sec{border:1px solid rgba(128,128,128,.2);border-radius:12px;padding:18px;margin-top:12px}
.pnl-sec h5{margin:0 0 14px;font-weight:700}
.pnl-badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:.75rem;font-weight:600}
.pnl-badge.ok{background:rgba(46,125,50,.15);color:#2e7d32}
.pnl-badge.no{background:rgba(231,76,60,.15);color:#e74c3c}
.pnl-formrow{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:10px}
.pnl-formrow .fld{display:flex;flex-direction:column;gap:3px}
.pnl-formrow label{font-size:.72rem;opacity:.6;margin:0}
.pnl-formrow input,.pnl-formrow select{min-width:110px}
{/literal}</style>

<div class="pnl-wrap">
  {if $error}<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> {$error}</div>{/if}
  <div id="pnl-flash"></div>

  <ul class="nav nav-tabs" role="tablist" style="margin-bottom:15px;">
    <li class="nav-item active"><a class="nav-link active" href="#pnl-overview" data-toggle="tab" data-bs-toggle="tab"><i class="fas fa-gauge-high"></i> Dashboard</a></li>
    {if $caps.email}<li class="nav-item"><a class="nav-link" href="#pnl-email" data-toggle="tab" data-bs-toggle="tab"><i class="fas fa-envelope"></i> Email</a></li>{/if}
    {if $caps.wordpress}<li class="nav-item"><a class="nav-link" href="#pnl-wp" data-toggle="tab" data-bs-toggle="tab"><i class="fab fa-wordpress"></i> WordPress</a></li>{/if}
    {if $caps.dns}<li class="nav-item"><a class="nav-link" href="#pnl-dns" data-toggle="tab" data-bs-toggle="tab"><i class="fas fa-globe"></i> DNS</a></li>{/if}
    {if $caps.files}<li class="nav-item"><a class="nav-link" href="#pnl-files" data-toggle="tab" data-bs-toggle="tab"><i class="fas fa-hdd"></i> Files</a></li>{/if}
    {if $caps.ftp}<li class="nav-item"><a class="nav-link" href="#pnl-ftp" data-toggle="tab" data-bs-toggle="tab"><i class="fas fa-folder-open"></i> FTP</a></li>{/if}
    {if $caps.subdomain}<li class="nav-item"><a class="nav-link" href="#pnl-sub" data-toggle="tab" data-bs-toggle="tab"><i class="fas fa-sitemap"></i> Subdomains</a></li>{/if}
    {if $caps.mysql}<li class="nav-item"><a class="nav-link" href="#pnl-db" data-toggle="tab" data-bs-toggle="tab"><i class="fas fa-database"></i> Databases</a></li>{/if}
    {if $caps.redirect}<li class="nav-item"><a class="nav-link" href="#pnl-redirect" data-toggle="tab" data-bs-toggle="tab"><i class="fas fa-share"></i> Redirects</a></li>{/if}
    {if $caps.cron}<li class="nav-item"><a class="nav-link" href="#pnl-cron" data-toggle="tab" data-bs-toggle="tab"><i class="fas fa-clock"></i> Cron</a></li>{/if}
    {if $caps.backup}<li class="nav-item"><a class="nav-link" href="#pnl-backup" data-toggle="tab" data-bs-toggle="tab"><i class="fas fa-box-archive"></i> Backups</a></li>{/if}
    {if $caps.ssl}<li class="nav-item"><a class="nav-link" href="#pnl-ssl" data-toggle="tab" data-bs-toggle="tab"><i class="fas fa-lock"></i> SSL</a></li>{/if}
    {if $caps.settings}<li class="nav-item"><a class="nav-link" href="#pnl-settings" data-toggle="tab" data-bs-toggle="tab"><i class="fab fa-php"></i> PHP</a></li>{/if}
  </ul>

  <div class="tab-content">

    {* ---------- DASHBOARD (server-rendered, instant) ---------- *}
    <div class="tab-pane active" id="pnl-overview">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
        <div><div style="font-size:1.15rem;font-weight:700;" id="db-domain">Your hosting account</div>
          <div style="opacity:.6;font-size:.85rem;">Manage everything without leaving the client area.</div></div>
        <a class="btn btn-primary" href="{$panelUrl}" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> Open Control Panel</a>
      </div>
      <div class="pnl-gauges">
        <div class="pnl-gcard"><div class="lbl">Disk usage</div><div class="val"><span id="db-disk">…</span> MB</div><div class="sub">of <span id="db-diskq">…</span></div>
          <div class="pnl-bar" id="db-bar"><span id="db-barfill" style="width:0%"></span></div></div>
        <div class="pnl-gcard"><div class="lbl">Bandwidth (this month)</div><div class="val"><span id="db-bw">…</span> MB</div><div class="sub">transfer used</div></div>
        <div class="pnl-gcard"><div class="lbl">At a glance</div><div class="val"><span id="db-sites">…</span> <small style="font-size:.9rem;font-weight:500;opacity:.6;">sites</small></div>
          <div class="sub" id="db-counts">…</div></div>
      </div>
      <div class="pnl-grid">
        {if $caps.email}<a class="pnl-tile" href="#pnl-email" data-toggle="tab" data-bs-toggle="tab"><i class="fas fa-envelope"></i><span class="t">Email</span><span class="c">accounts &amp; forwarders</span></a>{/if}
        {if $caps.wordpress}<a class="pnl-tile" href="#pnl-wp" data-toggle="tab" data-bs-toggle="tab"><i class="fab fa-wordpress"></i><span class="t">WordPress</span><span class="c">sites &amp; login</span></a>{/if}
        {if $caps.dns}<a class="pnl-tile" href="#pnl-dns" data-toggle="tab" data-bs-toggle="tab"><i class="fas fa-globe"></i><span class="t">DNS Zone</span><span class="c">records</span></a>{/if}
        {if $caps.files}<a class="pnl-tile" href="#pnl-files" data-toggle="tab" data-bs-toggle="tab"><i class="fas fa-hdd"></i><span class="t">File Manager</span><span class="c">browse &amp; edit</span></a>{/if}
        {if $caps.ftp}<a class="pnl-tile" href="#pnl-ftp" data-toggle="tab" data-bs-toggle="tab"><i class="fas fa-folder-open"></i><span class="t">FTP</span><span class="c">accounts</span></a>{/if}
        {if $caps.subdomain}<a class="pnl-tile" href="#pnl-sub" data-toggle="tab" data-bs-toggle="tab"><i class="fas fa-sitemap"></i><span class="t">Subdomains</span><span class="c">manage</span></a>{/if}
        {if $caps.mysql}<a class="pnl-tile" href="#pnl-db" data-toggle="tab" data-bs-toggle="tab"><i class="fas fa-database"></i><span class="t">Databases</span><span class="c">users</span></a>{/if}
        {if $caps.redirect}<a class="pnl-tile" href="#pnl-redirect" data-toggle="tab" data-bs-toggle="tab"><i class="fas fa-share"></i><span class="t">Redirects</span><span class="c">301/302</span></a>{/if}
        {if $caps.cron}<a class="pnl-tile" href="#pnl-cron" data-toggle="tab" data-bs-toggle="tab"><i class="fas fa-clock"></i><span class="t">Cron Jobs</span><span class="c">schedule</span></a>{/if}
        {if $caps.backup}<a class="pnl-tile" href="#pnl-backup" data-toggle="tab" data-bs-toggle="tab"><i class="fas fa-box-archive"></i><span class="t">Backups</span><span class="c">create/restore</span></a>{/if}
        {if $caps.ssl}<a class="pnl-tile" href="#pnl-ssl" data-toggle="tab" data-bs-toggle="tab"><i class="fas fa-lock"></i><span class="t">SSL / TLS</span><span class="c">certificate</span></a>{/if}
        {if $caps.settings}<a class="pnl-tile" href="#pnl-settings" data-toggle="tab" data-bs-toggle="tab"><i class="fab fa-php"></i><span class="t">PHP Version</span><span class="c">per website</span></a>{/if}
        <a class="pnl-tile" href="{$panelUrl}databases/phpmyadmin/" target="_blank" rel="noopener"><i class="fas fa-table"></i><span class="t">phpMyAdmin</span><span class="c">database admin</span></a>
      </div>
    </div>

    {* ---------- EMAIL (accounts + forwarders + autoresponders) ---------- *}
    {if $caps.email}
    <div class="tab-pane" id="pnl-email">
      <div class="pnl-sec" data-pnl-tab="deliverability"><h5><i class="fas fa-shield-halved"></i> Email deliverability (SPF &amp; DKIM)</h5>
        <div id="pnl-deliv" style="opacity:.6;">Loading…</div>
      </div>
      <div class="pnl-sec"><h5><i class="fas fa-envelope"></i> Email accounts</h5>
        <table class="table table-striped"><thead><tr><th>Email address</th><th>Quota</th><th style="width:80px;"></th></tr></thead>
          <tbody id="pt-email" data-pnl-tab="email" data-pnl-cols="3"><tr><td colspan="3" style="opacity:.6;">Loading…</td></tr></tbody></table>
        <form class="pnl-formrow" data-pnl-create="email">
          <div class="fld"><label>Mailbox</label><input class="form-control" name="email_user" placeholder="info" required></div>
          <div class="fld"><label>Password</label><input class="form-control" type="password" name="email_pass" placeholder="min 8" required></div>
          <div class="fld"><label>Quota MB (0=∞)</label><input class="form-control" type="number" min="0" name="email_quota" style="width:130px;"></div>
          <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Create</button></form>
      </div>
      <div class="pnl-sec"><h5><i class="fas fa-share"></i> Forwarders</h5>
        <table class="table table-striped"><thead><tr><th>From</th><th>To</th><th style="width:80px;"></th></tr></thead>
          <tbody id="pt-forwarders" data-pnl-tab="forwarders" data-pnl-cols="3"><tr><td colspan="3" style="opacity:.6;">Loading…</td></tr></tbody></table>
        <form class="pnl-formrow" data-pnl-create="forwarders">
          <div class="fld"><label>From</label><input class="form-control" name="fwd_source" placeholder="info@your-domain.com" required></div>
          <div class="fld"><label>To</label><input class="form-control" name="fwd_dest" placeholder="dest@example.com" required></div>
          <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Add</button></form>
      </div>
      <div class="pnl-sec"><h5><i class="fas fa-reply"></i> Autoresponders</h5>
        <table class="table table-striped"><thead><tr><th>Mailbox</th><th>Subject</th><th style="width:80px;"></th></tr></thead>
          <tbody id="pt-autoresponders" data-pnl-tab="autoresponders" data-pnl-cols="3"><tr><td colspan="3" style="opacity:.6;">Loading…</td></tr></tbody></table>
        <form class="pnl-formrow" data-pnl-create="autoresponders">
          <div class="fld"><label>Mailbox</label><select class="form-control" id="ar_email_id" name="ar_email_id" required><option value="">— select —</option></select></div>
          <div class="fld"><label>Subject</label><input class="form-control" name="ar_subject" required></div>
          <div class="fld"><label>Message</label><input class="form-control" name="ar_message" style="width:220px;" required></div>
          <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Add</button></form>
      </div>
    </div>
    {/if}

    {* ---------- DNS ---------- *}
    {if $caps.dns}
    <div class="tab-pane" id="pnl-dns"><div class="pnl-sec"><h5><i class="fas fa-globe"></i> DNS zone <span id="db-dnsdomain" style="opacity:.6;"></span></h5>
      <table class="table table-striped"><thead><tr><th style="width:70px;">Type</th><th>Name</th><th>Content</th><th style="width:80px;">TTL</th><th style="width:80px;"></th></tr></thead>
        <tbody id="pt-dns" data-pnl-tab="dns" data-pnl-cols="5"><tr><td colspan="5" style="opacity:.6;">Loading…</td></tr></tbody></table>
      <form class="pnl-formrow" data-pnl-create="dns">
        <div class="fld"><label>Type</label><select class="form-control" name="dns_type"><option>A</option><option>AAAA</option><option>CNAME</option><option>MX</option><option>TXT</option><option>NS</option><option>SRV</option></select></div>
        <div class="fld"><label>Name</label><input class="form-control" name="dns_name" placeholder="@ or sub" required></div>
        <div class="fld"><label>Content</label><input class="form-control" name="dns_content" placeholder="value / IP" required></div>
        <div class="fld"><label>TTL</label><input class="form-control" type="number" name="dns_ttl" value="3600" style="width:100px;"></div>
        <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Add</button></form></div></div>
    {/if}

    {* ---------- WORDPRESS ---------- *}
    {if $caps.wordpress}
    <div class="tab-pane" id="pnl-wp"><div class="pnl-sec"><h5><i class="fab fa-wordpress"></i> WordPress sites</h5>
      <table class="table table-striped"><thead><tr><th>Site</th><th style="width:80px;">PHP</th><th style="width:100px;">Status</th><th style="width:180px;"></th></tr></thead>
        <tbody id="pt-wordpress" data-pnl-tab="wordpress" data-pnl-cols="4"><tr><td colspan="4" style="opacity:.6;">Loading…</td></tr></tbody></table>
      <div style="opacity:.55;font-size:.78rem;margin-top:8px;">One-click login opens the WordPress admin without a password.</div>
    </div></div>
    {/if}

    {* ---------- FILE MANAGER (AJAX) ---------- *}
    {if $caps.files}
    <div class="tab-pane" id="pnl-files">
      <div class="pnl-sec"><h5><i class="fas fa-hdd"></i> File manager</h5>
        <div id="fm-crumbs" style="margin-bottom:12px;font-size:.92rem;"></div>
        <div id="fm-msg"></div>
        <table class="table table-striped"><thead><tr><th>Name</th><th style="width:100px;">Size</th><th style="width:140px;">Modified</th><th style="width:80px;">Perms</th><th style="width:110px;"></th></tr></thead>
          <tbody id="fm-body"><tr><td colspan="5" class="text-center" style="opacity:.6;">Loading…</td></tr></tbody></table>
        <div style="display:flex;gap:20px;flex-wrap:wrap;">
          <div class="pnl-formrow" style="margin:0;"><div class="fld"><label>New folder</label><input class="form-control" id="fm-newdir" placeholder="folder"></div>
            <button type="button" class="btn btn-success" onclick="fmCreate('mkdir')"><i class="fas fa-folder-plus"></i> Create</button></div>
          <div class="pnl-formrow" style="margin:0;"><div class="fld"><label>New file</label><input class="form-control" id="fm-newfile" placeholder="file.txt"></div>
            <button type="button" class="btn btn-secondary" onclick="fmCreate('newfile')"><i class="fas fa-file-circle-plus"></i> Create</button></div>
        </div>
      </div>
      <div class="pnl-sec" id="fm-editor" style="display:none;"><h5><i class="fas fa-edit"></i> Editing <code id="fm-edit-path"></code></h5>
        <textarea id="fm-edit-content" class="form-control" rows="16" style="font-family:monospace;font-size:.85rem;white-space:pre;"></textarea>
        <div style="margin-top:10px;"><button type="button" class="btn btn-primary" onclick="fmSave()"><i class="fas fa-save"></i> Save</button>
          <button type="button" class="btn btn-link" onclick="fmCloseEditor()">Close</button></div></div>
    </div>
    {/if}

    {* ---------- FTP ---------- *}
    {if $caps.ftp}
    <div class="tab-pane" id="pnl-ftp"><div class="pnl-sec"><h5><i class="fas fa-folder-open"></i> FTP accounts</h5>
      <table class="table table-striped"><thead><tr><th>Username</th><th>Directory</th><th style="width:80px;"></th></tr></thead>
        <tbody id="pt-ftp" data-pnl-tab="ftp" data-pnl-cols="3"><tr><td colspan="3" style="opacity:.6;">Loading…</td></tr></tbody></table>
      <form class="pnl-formrow" data-pnl-create="ftp">
        <div class="fld"><label>Username</label><input class="form-control" name="ftp_user" required></div>
        <div class="fld"><label>Password</label><input class="form-control" type="password" name="ftp_pass" placeholder="min 8" required></div>
        <div class="fld"><label>Directory</label><input class="form-control" name="ftp_dir" placeholder="optional"></div>
        <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Create</button></form></div></div>
    {/if}

    {* ---------- SUBDOMAINS ---------- *}
    {if $caps.subdomain}
    <div class="tab-pane" id="pnl-sub"><div class="pnl-sec"><h5><i class="fas fa-sitemap"></i> Subdomains</h5>
      <table class="table table-striped"><thead><tr><th>Subdomain</th><th style="width:80px;"></th></tr></thead>
        <tbody id="pt-subdomains" data-pnl-tab="subdomains" data-pnl-cols="2"><tr><td colspan="2" style="opacity:.6;">Loading…</td></tr></tbody></table>
      <form class="pnl-formrow" data-pnl-create="subdomains">
        <div class="fld"><label>Subdomain</label><input class="form-control" name="sub_name" placeholder="blog" required></div>
        <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Create</button></form></div></div>
    {/if}

    {* ---------- DATABASES ---------- *}
    {if $caps.mysql}
    <div class="tab-pane" id="pnl-db"><div class="pnl-sec"><h5><i class="fas fa-database"></i> Database users</h5>
      <p style="opacity:.6;font-size:.85rem;">For full database administration open <a href="{$panelUrl}databases/phpmyadmin/" target="_blank" rel="noopener">phpMyAdmin</a>.</p>
      <table class="table table-striped"><thead><tr><th>Username</th><th style="width:80px;"></th></tr></thead>
        <tbody id="pt-mysql" data-pnl-tab="mysql" data-pnl-cols="2"><tr><td colspan="2" style="opacity:.6;">Loading…</td></tr></tbody></table>
      <form class="pnl-formrow" data-pnl-create="mysql">
        <div class="fld"><label>Username</label><input class="form-control" name="db_user" required></div>
        <div class="fld"><label>Password</label><input class="form-control" type="password" name="db_pass" placeholder="min 8" required></div>
        <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Create user</button></form></div></div>
    {/if}

    {* ---------- REDIRECTS ---------- *}
    {if $caps.redirect}
    <div class="tab-pane" id="pnl-redirect"><div class="pnl-sec"><h5><i class="fas fa-share"></i> Redirects</h5>
      <table class="table table-striped"><thead><tr><th>Source</th><th>Destination</th><th style="width:70px;">Type</th><th style="width:80px;"></th></tr></thead>
        <tbody id="pt-redirects" data-pnl-tab="redirects" data-pnl-cols="4"><tr><td colspan="4" style="opacity:.6;">Loading…</td></tr></tbody></table>
      <form class="pnl-formrow" data-pnl-create="redirects">
        <div class="fld"><label>Source path</label><input class="form-control" name="rdr_source" placeholder="/old-page" required></div>
        <div class="fld"><label>Destination URL</label><input class="form-control" name="rdr_dest" placeholder="https://example.com/new" style="width:220px;" required></div>
        <div class="fld"><label>Type</label><select class="form-control" name="rdr_type"><option>301</option><option>302</option><option>307</option><option>308</option></select></div>
        <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Add</button></form></div></div>
    {/if}

    {* ---------- CRON ---------- *}
    {if $caps.cron}
    <div class="tab-pane" id="pnl-cron"><div class="pnl-sec"><h5><i class="fas fa-clock"></i> Cron jobs</h5>
      <table class="table table-striped"><thead><tr><th>Name</th><th>Command</th><th style="width:150px;">Schedule</th><th style="width:80px;"></th></tr></thead>
        <tbody id="pt-cron" data-pnl-tab="cron" data-pnl-cols="4"><tr><td colspan="4" style="opacity:.6;">Loading…</td></tr></tbody></table>
      <form class="pnl-formrow" data-pnl-create="cron">
        <div class="fld"><label>Name</label><input class="form-control" name="cron_name" placeholder="backup" style="width:100px;"></div>
        <div class="fld"><label>Command</label><input class="form-control" name="cron_command" placeholder="/usr/bin/php ~/cron.php" style="width:220px;" required></div>
        <div class="fld"><label>Min</label><input class="form-control" name="cron_minute" value="*" style="width:56px;"></div>
        <div class="fld"><label>Hour</label><input class="form-control" name="cron_hour" value="*" style="width:56px;"></div>
        <div class="fld"><label>Day</label><input class="form-control" name="cron_dom" value="*" style="width:56px;"></div>
        <div class="fld"><label>Mon</label><input class="form-control" name="cron_month" value="*" style="width:56px;"></div>
        <div class="fld"><label>DoW</label><input class="form-control" name="cron_dow" value="*" style="width:56px;"></div>
        <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Add</button></form>
      <div style="opacity:.55;font-size:.78rem;margin-top:8px;">Standard cron syntax; <code>*</code> = every.</div></div></div>
    {/if}

    {* ---------- BACKUPS ---------- *}
    {if $caps.backup}
    <div class="tab-pane" id="pnl-backup"><div class="pnl-sec"><h5><i class="fas fa-box-archive"></i> Backups</h5>
      <form class="pnl-formrow" data-pnl-create="backups" style="margin-bottom:14px;">
        <div class="fld"><label>Backup name (optional)</label><input class="form-control" name="backup_name" placeholder="my-backup"></div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-play"></i> Create backup now</button></form>
      <table class="table table-striped"><thead><tr><th>Backup</th><th style="width:120px;">Size</th><th style="width:160px;">Created</th><th style="width:150px;"></th></tr></thead>
        <tbody id="pt-backups" data-pnl-tab="backups" data-pnl-cols="4"><tr><td colspan="4" style="opacity:.6;">Loading…</td></tr></tbody></table></div></div>
    {/if}

    {* ---------- SSL ---------- *}
    {if $caps.ssl}
    <div class="tab-pane" id="pnl-ssl"><div class="pnl-sec" data-pnl-tab="ssl"><h5><i class="fas fa-lock"></i> SSL / TLS certificate</h5>
      <div id="ssl-status" style="opacity:.6;">Loading…</div>
      <div style="margin-top:12px;"><button type="button" id="ssl-btn" class="btn btn-primary" style="display:none;" onclick="pnlSslIssue(this)"></button></div>
      <div style="opacity:.55;font-size:.8rem;margin-top:8px;">Issuance runs in the background (~1 min). Point your DNS to this server first.</div></div></div>
    {/if}

    {* ---------- SETTINGS (PHP + ModSecurity) ---------- *}
    {if $caps.settings}
    <div class="tab-pane" id="pnl-settings">
      <div class="pnl-sec" data-pnl-tab="settings"><h5><i class="fab fa-php"></i> PHP version</h5>
        <div class="pnl-formrow">
          <div class="fld"><label>PHP version for this website</label><select class="form-control" id="set-phpver" style="width:140px;"><option>Loading…</option></select></div>
          <button type="button" class="btn btn-primary" onclick="pnlPhpSave(this)"><i class="fas fa-save"></i> Apply version</button>
        </div>
        <div style="opacity:.55;font-size:.78rem;margin-top:8px;">Memory, execution time and upload limits are set by your hosting plan.</div>
      </div>
    </div>
    {/if}

  </div>
</div>

<script>
var PNL_API = "clientarea.php?action=productdetails&id={$serviceId}&modop=custom&a=Api";
{if $caps.files}var PNL_FM_URL = "clientarea.php?action=productdetails&id={$serviceId}&modop=custom&a=FmAjax";{/if}
</script>
<script>{literal}
function pnlEsc(s){var d=document.createElement('div');d.textContent=(s==null?'':s);return d.innerHTML;}
// Escapes a value for use inside an onclick="..." attribute: first as a JS
// single-quoted string (\ and '), then HTML-attribute-safe (& < > "). Without
// the HTML part a value containing a double-quote — legal in a Linux file name,
// e.g. `x" onmouseover="alert(1)` — would break out of the attribute (XSS).
function pnlJs(s){return String(s==null?'':s).replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function pnlTok(){return '&token='+encodeURIComponent(window.csrfToken||'');}
function pnlFlash(t,ok){var m=document.getElementById('pnl-flash');if(!m)return;m.innerHTML=t?('<div class="alert alert-'+(ok?'success':'warning')+'">'+pnlEsc(t)+'</div>'):'';if(t&&ok)setTimeout(function(){m.innerHTML='';},4000);}
// A reply that is not JSON - a session expired into a login page, a fatal, a
// gateway error - used to reject, and the callers that act on the result had no
// failure branch: the button disabled itself and stayed that way with nothing
// on screen. Both helpers now answer in the shape every caller already handles.
function pnlFail(e){return {ok:false,error:'The panel could not be reached. Please try again.'};}
function pnlApi(op,tab,data){
  var body='pnl_op='+op+'&pnl_tab='+tab+pnlTok();
  for(var k in (data||{})){body+='&'+encodeURIComponent(k)+'='+encodeURIComponent(data[k]);}
  return fetch(PNL_API,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body}).then(function(r){return r.json();}).catch(pnlFail);
}
function pnlGet(qs){
  return fetch(PNL_API+qs+pnlTok(),{credentials:'same-origin'}).then(function(r){return r.json();}).catch(pnlFail);
}
function pnlLoad(tab){
  if(tab==='ssl'){return pnlLoadSsl();}
  if(tab==='settings'){return pnlLoadSettings();}
  if(tab==='deliverability'){return pnlLoadDeliverability();}
  var tb=document.getElementById('pt-'+tab);if(!tb)return;
  var cols=parseInt(tb.getAttribute('data-pnl-cols')||'2',10);
  pnlGet('&pnl_op=list&pnl_tab='+tab).then(function(d){
    if(!d.ok){tb.innerHTML='<tr><td colspan="'+cols+'" style="color:#e74c3c;">'+pnlEsc(d.error)+'</td></tr>';return;}
    var rows=d.rows||[];
    if(!rows.length){tb.innerHTML='<tr><td colspan="'+cols+'" class="text-center" style="opacity:.6;">None yet.</td></tr>';}
    else{tb.innerHTML=rows.map(function(r){
      var tds=(r.cells||[]).map(function(c){return '<td style="word-break:break-all;">'+pnlEsc(c)+'</td>';}).join('');
      var act;
      if(tab==='wordpress'){act='<button type="button" class="btn btn-sm btn-primary" onclick="pnlWpLogin(\''+pnlJs(r.id)+'\',this)"><i class="fab fa-wordpress"></i> Login</button> '
        +'<button type="button" class="btn btn-sm btn-secondary" onclick="pnlWpUpd(\'wp_plugins\',\''+pnlJs(r.id)+'\',this)"><i class="fas fa-plug"></i> Plugins</button> '
        +'<button type="button" class="btn btn-sm btn-secondary" onclick="pnlWpUpd(\'wp_core\',\''+pnlJs(r.id)+'\',this)"><i class="fas fa-rotate"></i> Core</button> '
        +'<button type="button" class="btn btn-sm btn-secondary" onclick="pnlWpBackups(\''+pnlJs(r.id)+'\',\''+pnlJs(r.site_url||r.domain_name||'')+'\')"><i class="fas fa-clock-rotate-left"></i> Backups</button>';}
      else{var extra=(tab==='backups')?('<button type="button" class="btn btn-sm btn-secondary" onclick="pnlRestore(\''+pnlJs(r.id)+'\',this)"><i class="fas fa-undo"></i> Restore</button> '):'';
        act=extra+'<button type="button" class="btn btn-sm btn-danger" onclick="pnlDel(\''+tab+'\',\''+pnlJs(r.id)+'\',this)"><i class="fas fa-trash"></i></button>';}
      return '<tr>'+tds+'<td>'+act+'</td></tr>';
    }).join('');}
    if(tab==='email'){var sel=document.getElementById('ar_email_id');if(sel){sel.innerHTML='<option value="">— select —</option>'+rows.map(function(r){return '<option value="'+pnlEsc(r.id)+'">'+pnlEsc(r.label||r.cells[0])+'</option>';}).join('');}}
  }).catch(function(){tb.innerHTML='<tr><td colspan="'+cols+'">Load failed.</td></tr>';});
}
function pnlLoadSsl(){
  pnlGet('&pnl_op=list&pnl_tab=ssl').then(function(d){
    var st=document.getElementById('ssl-status'),bt=document.getElementById('ssl-btn');if(!st)return;
    if(!d||!d.ok){st.innerHTML='<span style="color:#e74c3c;">'+pnlEsc((d&&d.error)||'Failed to load.')+'</span>';st.style.opacity='1';if(bt)bt.style.display='none';return;}
    var s=d.ssl||{};var has=!!s.has_ssl;
    st.innerHTML='Domain: <strong>'+pnlEsc(s.domain_name||'')+'</strong><br>Status: '+(has?'<span class="pnl-badge ok"><i class="fas fa-check"></i> Certificate active</span>':'<span class="pnl-badge no"><i class="fas fa-times"></i> No certificate</span>');st.style.opacity='1';
    if(bt){bt.style.display='';bt.innerHTML='<i class="fas fa-certificate"></i> '+(has?'Renew certificate':"Get free Let's Encrypt certificate");}
  });
}
function pnlSslIssue(btn){btn.disabled=true;pnlApi('ssl_issue','ssl',{}).then(function(d){btn.disabled=false;pnlFlash(d.ok?'SSL certificate requested — issuance runs in the background.':d.error,d.ok);});}
function pnlDkimRecord(domain,pubkey){
  if(!domain||!pubkey)return '';
  var name='default._domainkey.'+domain, val='v=DKIM1; k=rsa; p='+pubkey;
  return '<div style="margin-top:6px;font-size:.85em;"><div style="opacity:.7;">Publish this DNS TXT record:</div>'
    +'<div style="margin-top:3px;"><code style="word-break:break-all;">'+pnlEsc(name)+'</code></div>'
    +'<div style="margin-top:3px;"><code style="word-break:break-all;">'+pnlEsc(val)+'</code></div></div>';
}
function pnlDelivRender(d){
  var el=document.getElementById('pnl-deliv');if(!el)return;
  if(!d||!d.ok||!d.deliverability){el.innerHTML='<span style="color:#e74c3c;">'+pnlEsc((d&&d.error)||'Failed to load.')+'</span>';return;}
  var x=d.deliverability, ok='<span class="pnl-badge ok"><i class="fas fa-check"></i> Configured</span>',
      no='<span class="pnl-badge no"><i class="fas fa-times"></i> Not configured</span>';
  var h='Domain: <strong>'+pnlEsc(x.domain_name||'')+'</strong>';
  // SPF (read-only status)
  h+='<div style="margin-top:12px;"><strong>SPF</strong> &nbsp;'+(x.spf&&x.spf.enabled?ok:no)+'</div>';
  if(x.spf&&x.spf.record){h+='<div style="margin-top:4px;font-size:.85em;"><code style="word-break:break-all;">'+pnlEsc(x.spf.record)+'</code></div>';}
  else{h+='<div style="margin-top:4px;font-size:.85em;opacity:.7;">No SPF record found on this domain.</div>';}
  // DKIM (status + enable action)
  h+='<div style="margin-top:16px;"><strong>DKIM</strong> &nbsp;'+(x.dkim&&x.dkim.enabled?ok:no)+'</div>';
  if(x.dkim&&x.dkim.enabled){h+=pnlDkimRecord(x.domain_name,x.dkim.public_key);}
  else{h+='<div style="margin-top:6px;"><button type="button" class="btn btn-sm btn-success" onclick="pnlDkimEnable(this)"><i class="fas fa-key"></i> Enable DKIM signing</button></div>';}
  el.innerHTML=h;el.style.opacity='1';
}
function pnlLoadDeliverability(){
  var el=document.getElementById('pnl-deliv');if(el){el.innerHTML='Loading…';el.style.opacity='.6';}
  pnlGet('&pnl_op=list&pnl_tab=deliverability').then(pnlDelivRender);
}
function pnlDkimEnable(btn){var t=btn.innerHTML;btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Enabling…';
  pnlApi('dkim_enable','',{}).then(function(d){if(d.ok){pnlFlash(d.msg||'DKIM enabled.',true);pnlLoadDeliverability();}else{btn.disabled=false;btn.innerHTML=t;pnlFlash(d.error||'Failed to enable DKIM.',false);}});}
function pnlLoadSettings(){
  pnlGet('&pnl_op=list&pnl_tab=settings').then(function(d){
    var sel=document.getElementById('set-phpver');
    if(!d||!d.ok){if(sel)sel.innerHTML='<option>'+pnlEsc((d&&d.error)||'Failed to load')+'</option>';return;}
    var s=d.settings||{},php=s.php||{};
    if(sel){sel.innerHTML=(s.versions||[]).filter(function(v){return v.available;}).map(function(v){return '<option'+(v.version===php.php_version?' selected':'')+'>'+pnlEsc(v.version)+'</option>';}).join('')||'<option>'+pnlEsc(php.php_version||'')+'</option>';}
  }).catch(function(){});
}
function pnlPhpSave(btn){btn.disabled=true;pnlApi('php_save','',{php_version:document.getElementById('set-phpver').value}).then(function(d){btn.disabled=false;pnlFlash(d.ok?'PHP version applied.':d.error,d.ok);});}
function pnlDel(tab,id,btn){if(!confirm('Delete this item?'))return;btn.disabled=true;pnlApi('delete',tab,{pnl_id:id}).then(function(d){if(d.ok){pnlFlash('Deleted.',true);pnlLoad(tab);}else{pnlFlash(d.error,false);btn.disabled=false;}});}
function pnlRestore(id,btn){if(!confirm('Restore this backup? Current data will be overwritten.'))return;btn.disabled=true;pnlApi('restore','backups',{pnl_id:id}).then(function(d){btn.disabled=false;pnlFlash(d.ok?'Restore started (background).':d.error,d.ok);});}
function pnlWpLogin(id,btn){btn.disabled=true;pnlApi('wp_login','',{pnl_id:id}).then(function(d){btn.disabled=false;if(d.ok&&d.url){window.open(d.url,'_blank','noopener');}else pnlFlash(d.error||'Login failed.',false);});}
function pnlWpUpd(op,id,btn){var t=btn.innerHTML;btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';pnlApi(op,'',{pnl_id:id}).then(function(d){btn.disabled=false;btn.innerHTML=t;pnlFlash(d.ok?(d.msg||'Updated.'):(d.error||'Update failed.'),d.ok);});}
function pnlBytes(n){n=Number(n)||0;if(n<1024)return n+' B';var u=['KB','MB','GB','TB'],i=-1;do{n/=1024;i++;}while(n>=1024&&i<u.length-1);return n.toFixed(1)+' '+u[i];}
function pnlBkClose(){var o=document.getElementById('pnl-bk-modal');if(o)o.parentNode.removeChild(o);}
function pnlWpBackups(id,site){
  pnlBkClose();
  var o=document.createElement('div');o.id='pnl-bk-modal';
  o.style.cssText='position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.5);display:flex;align-items:flex-start;justify-content:center;padding:40px 12px;overflow:auto;';
  o.innerHTML='<div style="background:var(--bs-body-bg,#fff);color:var(--bs-body-color,#222);border-radius:8px;max-width:640px;width:100%;box-shadow:0 8px 40px rgba(0,0,0,.3);">'
    +'<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid rgba(128,128,128,.25);">'
    +'<h5 style="margin:0;"><i class="fab fa-wordpress"></i> Backups <small style="opacity:.6;">'+pnlEsc(site)+'</small></h5>'
    +'<button type="button" class="btn btn-sm btn-light" onclick="pnlBkClose()">&times;</button></div>'
    +'<div style="padding:14px 18px;">'
    +'<button type="button" class="btn btn-sm btn-primary" id="pnl-bk-create" onclick="pnlBkCreate(\''+pnlJs(id)+'\')"><i class="fas fa-plus"></i> Create backup</button>'
    +'<div id="pnl-bk-msg" style="margin-top:10px;"></div>'
    +'<div id="pnl-bk-list" style="margin-top:12px;"><div style="opacity:.6;">Loading…</div></div>'
    +'</div></div>';
  o.addEventListener('click',function(e){if(e.target===o)pnlBkClose();});
  document.body.appendChild(o);
  o.setAttribute('data-id',id);
  pnlBkReload(id);
}
function pnlBkMsg(t,ok){var m=document.getElementById('pnl-bk-msg');if(m)m.innerHTML=t?('<div class="alert alert-'+(ok?'success':'warning')+'" style="margin:0;padding:8px 12px;">'+pnlEsc(t)+'</div>'):'';}
function pnlBkReload(id){
  pnlApi('wp_backups','',{pnl_id:id}).then(function(d){
    var el=document.getElementById('pnl-bk-list');if(!el)return;
    if(!d.ok){el.innerHTML='<div style="opacity:.6;">'+pnlEsc(d.error||'Failed to load.')+'</div>';return;}
    var b=d.backups||[];
    if(!b.length){el.innerHTML='<div style="opacity:.6;">No backups yet.</div>';return;}
    var h='<table class="table table-sm" style="margin:0;"><thead><tr><th>Name</th><th>Size</th><th>Status</th><th></th></tr></thead><tbody>';
    for(var i=0;i<b.length;i++){var x=b[i];var done=(x.status==='completed');
      h+='<tr><td style="word-break:break-all;">'+pnlEsc(x.name)+'</td><td>'+pnlBytes(x.size)+'</td>'
        +'<td>'+pnlEsc(x.status)+'</td><td>'+(done?('<button type="button" class="btn btn-sm btn-outline-danger" onclick="pnlBkRestore(\''+pnlJs(id)+'\',\''+pnlJs(x.id)+'\',this)"><i class="fas fa-undo"></i> Restore</button>'):'<i class="fas fa-spinner fa-spin" style="opacity:.5;"></i>')+'</td></tr>';}
    h+='</tbody></table>';el.innerHTML=h;
  });
}
function pnlBkCreate(id){var btn=document.getElementById('pnl-bk-create');btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Creating…';
  pnlApi('wp_backup','',{pnl_id:id}).then(function(d){btn.disabled=false;btn.innerHTML='<i class="fas fa-plus"></i> Create backup';pnlBkMsg(d.ok?(d.msg||'Backup started.'):(d.error||'Failed.'),d.ok);if(d.ok){pnlBkReload(id);setTimeout(function(){pnlBkReload(id);},8000);}});}
function pnlBkRestore(id,bid,btn){if(!confirm('Restore this backup? Current site files and database will be overwritten.'))return;var t=btn.innerHTML;btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';
  pnlApi('wp_restore','',{pnl_id:id,backup_id:bid}).then(function(d){btn.disabled=false;btn.innerHTML=t;pnlBkMsg(d.ok?(d.msg||'Restore completed.'):(d.error||'Restore failed.'),d.ok);});}
document.addEventListener('submit',function(e){
  var f=e.target.closest&&e.target.closest('form[data-pnl-create]');if(!f)return;e.preventDefault();
  var tab=f.getAttribute('data-pnl-create'),data={};
  f.querySelectorAll('input[name],select[name],textarea[name]').forEach(function(i){if(i.name!=='token')data[i.name]=i.value;});
  var btn=f.querySelector('button[type=submit]');if(btn)btn.disabled=true;
  pnlApi('create',tab,data).then(function(d){if(btn)btn.disabled=false;if(d.ok){f.reset();pnlFlash('Created.',true);pnlLoad(tab);}else pnlFlash(d.error,false);});
});
// Lazy-load a pane's data the first time it is shown.
var pnlLoadedPane={};
function pnlShowPane(id){
  var pane=document.getElementById(id);if(!pane||pnlLoadedPane[id])return;pnlLoadedPane[id]=true;
  pane.querySelectorAll('[data-pnl-tab]').forEach(function(el){pnlLoad(el.getAttribute('data-pnl-tab'));});
  if(id==='pnl-files' && typeof fmLoad==='function'){fmLoad(null);}
}
document.addEventListener('click',function(e){var a=e.target.closest&&e.target.closest('a[href^="#pnl-"]');if(a){var id=a.getAttribute('href').substring(1);setTimeout(function(){pnlShowPane(id);},30);}});
var PNL_HREF2CAP={'pnl-email':'email','pnl-wp':'wordpress','pnl-dns':'dns','pnl-files':'files','pnl-ftp':'ftp','pnl-sub':'subdomain','pnl-db':'mysql','pnl-redirect':'redirect','pnl-cron':'cron','pnl-backup':'backup','pnl-ssl':'ssl','pnl-settings':'settings'};
function pnlApplyCaps(caps){
  var wrap=document.querySelector('.pnl-wrap');if(!wrap)return;
  wrap.querySelectorAll('.nav-tabs .nav-link[href^="#pnl-"]').forEach(function(a){var cap=PNL_HREF2CAP[a.getAttribute('href').substring(1)];if(cap&&caps[cap]===false){var li=a.closest('.nav-item');if(li)li.style.display='none';}});
  wrap.querySelectorAll('.pnl-tile[href^="#pnl-"]').forEach(function(a){var cap=PNL_HREF2CAP[a.getAttribute('href').substring(1)];if(cap&&caps[cap]===false)a.style.display='none';});
}
function pnlDashboard(){
  pnlGet('&pnl_op=dashboard').then(function(d){
    if(!d||!d.ok)return;
    var set=function(id,v){var e=document.getElementById(id);if(e)e.textContent=(v==null?'':v);};
    if(d.domain_name){set('db-domain',d.domain_name);var fs=document.querySelector('input[name="fwd_source"]');if(fs)fs.placeholder='info@'+d.domain_name;var dh=document.getElementById('db-dnsdomain');if(dh)dh.textContent=d.domain_name;}
    set('db-disk',d.disk_used);set('db-diskq',d.disk_quota);set('db-bw',d.bandwidth);set('db-sites',d.domain_count);
    set('db-counts',d.email_count+' email · '+d.database_count+' db · '+d.ftp_count+' ftp');
    var bar=document.getElementById('db-barfill');if(bar)bar.style.width=(d.disk_pct||0)+'%';
    var bw=document.getElementById('db-bar');if(bw){bw.classList.remove('warn','crit');if(d.disk_pct>=90)bw.classList.add('crit');else if(d.disk_pct>=70)bw.classList.add('warn');}
    pnlApplyCaps(d.caps||{});
  }).catch(function(){});
}
// Inject WHMCS CSRF token into any legacy form + activate deep-linked tab.
(function(){
  var wrap=document.querySelector('.pnl-wrap');
  if(wrap){wrap.querySelectorAll('form').forEach(function(f){if(!f.querySelector('input[name="token"]')){var i=document.createElement('input');i.type='hidden';i.name='token';i.value=window.csrfToken||'';f.appendChild(i);}});}
  pnlDashboard();
  var h=(window.location.hash||'').replace('#','');
  if(h && document.getElementById(h)){
    var link=wrap&&wrap.querySelector('.nav-tabs .nav-link[href="#'+h+'"]');
    if(link){wrap.querySelectorAll('.nav-tabs .nav-link').forEach(function(a){a.classList.remove('active');});wrap.querySelectorAll('.tab-content > .tab-pane').forEach(function(p){p.classList.remove('active','show');});link.classList.add('active');var pane=document.getElementById(h);if(pane)pane.classList.add('active','show');}
    setTimeout(function(){pnlShowPane(h);},80);
  }
})();
{/literal}</script>

{if $caps.files}
<script>{literal}
var fmCur=null;
function fmMsg(t,ok){var m=document.getElementById('fm-msg');if(m)m.innerHTML=t?('<div class="alert alert-'+(ok?'success':'warning')+'">'+pnlEsc(t)+'</div>'):'';}
function fmLoad(path){fmMsg('');
  fetch(PNL_FM_URL+'&fm_op=list'+(path?('&fm_path='+encodeURIComponent(path)):'')+pnlTok(),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
    if(!d.ok){fmMsg(d.error||'Error',false);return;}
    fmCur=d.path;
    document.getElementById('fm-crumbs').innerHTML=(d.crumbs||[]).map(function(c){return '<a href="#" onclick="fmLoad(\''+pnlJs(c.path)+'\');return false;"><i class="fas fa-folder"></i> '+pnlEsc(c.name)+'</a>';}).join(' <span style="opacity:.4;">/</span> ');
    var rows='';
    if(d.parent){rows+='<tr><td colspan="5"><a href="#" onclick="fmLoad(\''+pnlJs(d.parent)+'\');return false;"><i class="fas fa-level-up-alt"></i> ..</a></td></tr>';}
    (d.files||[]).forEach(function(f){var isDir=(f.type==='folder');
      var name=isDir?('<i class="fas fa-folder" style="color:#f1c40f;"></i> <a href="#" onclick="fmLoad(\''+pnlJs(f.path)+'\');return false;">'+pnlEsc(f.name)+'</a>'):('<i class="fas fa-file" style="opacity:.5;"></i> '+pnlEsc(f.name));
      var act=(isDir?'':('<button type="button" class="btn btn-sm btn-secondary" onclick="fmEdit(\''+pnlJs(f.path)+'\')"><i class="fas fa-edit"></i></button> '))+'<button type="button" class="btn btn-sm btn-danger" onclick="fmDelete(\''+pnlJs(f.path)+'\',\''+pnlJs(f.name)+'\')"><i class="fas fa-trash"></i></button>';
      rows+='<tr><td>'+name+'</td><td>'+(isDir?'—':pnlEsc(f.size_formatted||f.size||''))+'</td><td style="font-size:.83em;opacity:.7;">'+pnlEsc(f.modified_str||'')+'</td><td><code style="font-size:.78em;">'+pnlEsc(f.permissions_octal||f.permissions||'')+'</code></td><td>'+act+'</td></tr>';});
    if(!(d.files||[]).length){rows+='<tr><td colspan="5" class="text-center" style="opacity:.6;">Empty folder.</td></tr>';}
    document.getElementById('fm-body').innerHTML=rows;
  }).catch(function(){fmMsg('Load failed.',false);});
}
function fmPost(op,data){var body='fm_op='+op+pnlTok();for(var k in data){body+='&'+k+'='+encodeURIComponent(data[k]);}return fetch(PNL_FM_URL,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body}).then(function(r){return r.json();}).catch(pnlFail);}
function fmCreate(op){var id=(op==='mkdir')?'fm-newdir':'fm-newfile';var el=document.getElementById(id);var name=(el.value||'').trim();if(!name)return;fmPost(op,{fm_path:fmCur,fm_name:name}).then(function(d){if(d.ok){el.value='';fmLoad(fmCur);}else fmMsg(d.error,false);});}
function fmDelete(path,name){if(!confirm('Delete '+name+'?'))return;fmPost('delete',{fm_target:path}).then(function(d){if(d.ok)fmLoad(fmCur);else fmMsg(d.error,false);});}
function fmEdit(path){fetch(PNL_FM_URL+'&fm_op=read&fm_path='+encodeURIComponent(path)+pnlTok(),{credentials:'same-origin'}).then(function(r){return r.json();}).catch(pnlFail).then(function(d){if(!d.ok){fmMsg(d.error,false);return;}document.getElementById('fm-edit-path').textContent=d.path;document.getElementById('fm-edit-content').value=d.content;var ed=document.getElementById('fm-editor');ed.setAttribute('data-path',d.path);ed.style.display='';ed.scrollIntoView({behavior:'smooth'});});}
function fmSave(){var ed=document.getElementById('fm-editor');fmPost('save',{fm_file:ed.getAttribute('data-path'),fm_content:document.getElementById('fm-edit-content').value}).then(function(d){fmMsg(d.ok?'Saved.':(d.error||'Error'),d.ok);});}
function fmCloseEditor(){document.getElementById('fm-editor').style.display='none';}
{/literal}</script>
{/if}
