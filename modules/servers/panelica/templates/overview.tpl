{*
  Panelica client area — overview + scope-aware self-service tabs.
  Self-service forms POST to WHMCS custom module functions:
    clientarea.php?action=productdetails&id={$serviceId}&modop=custom&a=FunctionName
*}
{assign var=base value="clientarea.php?action=productdetails&id=`$serviceId`&modop=custom"}

<div class="panelica-clientarea" style="margin-top:15px;">

  {if $flash}
    <div class="alert alert-{$flash.type}">{$flash.msg}</div>
  {/if}
  {if $error}
    <div class="alert alert-warning">{$error}</div>
  {/if}

  <ul class="nav nav-tabs" role="tablist" style="margin-bottom:15px;">
    <li class="nav-item active"><a class="nav-link active" href="#pnl-overview" data-toggle="tab" data-bs-toggle="tab">Overview</a></li>
    {if $caps.email}<li class="nav-item"><a class="nav-link" href="#pnl-email" data-toggle="tab" data-bs-toggle="tab">Email ({$emails|@count})</a></li>{/if}
    {if $caps.ftp}<li class="nav-item"><a class="nav-link" href="#pnl-ftp" data-toggle="tab" data-bs-toggle="tab">FTP ({$ftpAccounts|@count})</a></li>{/if}
    {if $caps.subdomain}<li class="nav-item"><a class="nav-link" href="#pnl-sub" data-toggle="tab" data-bs-toggle="tab">Subdomains ({$subdomains|@count})</a></li>{/if}
  </ul>

  <div class="tab-content">

    {* ---------------- Overview ---------------- *}
    <div class="tab-pane active" id="pnl-overview">
      <a class="btn btn-primary" href="{$panelUrl}" target="_blank" rel="noopener">
        <i class="fas fa-external-link-alt"></i> Open Control Panel
      </a>
      <p style="margin:8px 0 18px;color:#6c757d;">Log in with your hosting username and password.</p>

      {if $hasStats}
        <div class="row">
          <div class="col-sm-6" style="margin-bottom:18px;">
            <strong>Disk</strong>
            <div class="progress" style="height:22px;">
              <div class="progress-bar {if $diskPct >= 90}bg-danger{elseif $diskPct >= 70}bg-warning{else}bg-success{/if}"
                   role="progressbar" style="width:{$diskPct}%;min-width:3em;">{$diskUsedMb} MB / {$diskQuota}</div>
            </div>
          </div>
          <div class="col-sm-6" style="margin-bottom:18px;">
            <strong>Bandwidth (this month)</strong>
            <div style="font-size:1.4em;padding-top:4px;">{$bandwidthMb} MB</div>
          </div>
        </div>
        <div class="row text-center">
          <div class="col-3"><div style="font-size:1.6em;font-weight:bold;">{$domainCount}</div><div style="color:#6c757d;">Websites</div></div>
          <div class="col-3"><div style="font-size:1.6em;font-weight:bold;">{$emailCount}</div><div style="color:#6c757d;">Email</div></div>
          <div class="col-3"><div style="font-size:1.6em;font-weight:bold;">{$databaseCount}</div><div style="color:#6c757d;">Databases</div></div>
          <div class="col-3"><div style="font-size:1.6em;font-weight:bold;">{$ftpCount}</div><div style="color:#6c757d;">FTP</div></div>
        </div>
      {/if}
    </div>

    {* ---------------- Email ---------------- *}
    {if $caps.email}
    <div class="tab-pane" id="pnl-email">
      <table class="table table-striped">
        <thead><tr><th>Email Address</th><th>Quota</th><th style="width:80px;"></th></tr></thead>
        <tbody>
        {foreach $emails as $e}
          <tr>
            <td>{$e.email|default:$e.email_address|default:'—'}</td>
            <td>{if $e.quota_mb|default:0 > 0}{$e.quota_mb} MB{else}Unlimited{/if}</td>
            <td>
              <form method="post" action="{$base}&a=DeleteEmail" onsubmit="return confirm('Delete this email account?');" style="margin:0;">
                <input type="hidden" name="id" value="{$e.id}">
                <button class="btn btn-sm btn-danger" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        {foreachelse}
          <tr><td colspan="3" class="text-center" style="color:#6c757d;">No email accounts yet.</td></tr>
        {/foreach}
        </tbody>
      </table>
      <form method="post" action="{$base}&a=CreateEmail" class="form-inline">
        <div class="row g-2">
          <div class="col-auto"><input class="form-control" name="email_user" placeholder="mailbox name" required></div>
          <div class="col-auto"><input class="form-control" name="email_pass" type="password" placeholder="password (min 8)" required></div>
          <div class="col-auto"><input class="form-control" name="email_quota" type="number" min="0" placeholder="quota MB (0=∞)" style="width:150px;"></div>
          <div class="col-auto"><button class="btn btn-success" type="submit">Create Email</button></div>
        </div>
      </form>
    </div>
    {/if}

    {* ---------------- FTP ---------------- *}
    {if $caps.ftp}
    <div class="tab-pane" id="pnl-ftp">
      <table class="table table-striped">
        <thead><tr><th>FTP Username</th><th>Directory</th><th style="width:80px;"></th></tr></thead>
        <tbody>
        {foreach $ftpAccounts as $f}
          <tr>
            <td>{$f.ftp_username|default:$f.username|default:'—'}</td>
            <td>{$f.directory|default:$f.home_directory|default:'/'}</td>
            <td>
              <form method="post" action="{$base}&a=DeleteFtp" onsubmit="return confirm('Delete this FTP account?');" style="margin:0;">
                <input type="hidden" name="id" value="{$f.id}">
                <button class="btn btn-sm btn-danger" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        {foreachelse}
          <tr><td colspan="3" class="text-center" style="color:#6c757d;">No FTP accounts yet.</td></tr>
        {/foreach}
        </tbody>
      </table>
      <form method="post" action="{$base}&a=CreateFtp" class="form-inline">
        <div class="row g-2">
          <div class="col-auto"><input class="form-control" name="ftp_user" placeholder="ftp username" required></div>
          <div class="col-auto"><input class="form-control" name="ftp_pass" type="password" placeholder="password (min 8)" required></div>
          <div class="col-auto"><input class="form-control" name="ftp_dir" placeholder="directory (optional)"></div>
          <div class="col-auto"><button class="btn btn-success" type="submit">Create FTP</button></div>
        </div>
      </form>
    </div>
    {/if}

    {* ---------------- Subdomains ---------------- *}
    {if $caps.subdomain}
    <div class="tab-pane" id="pnl-sub">
      <table class="table table-striped">
        <thead><tr><th>Subdomain</th><th style="width:80px;"></th></tr></thead>
        <tbody>
        {foreach $subdomains as $s}
          <tr>
            <td>{$s.subdomain_name|default:$s.name|default:'—'}</td>
            <td>
              <form method="post" action="{$base}&a=DeleteSubdomain" onsubmit="return confirm('Delete this subdomain?');" style="margin:0;">
                <input type="hidden" name="id" value="{$s.id}">
                <button class="btn btn-sm btn-danger" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        {foreachelse}
          <tr><td colspan="2" class="text-center" style="color:#6c757d;">No subdomains yet.</td></tr>
        {/foreach}
        </tbody>
      </table>
      <form method="post" action="{$base}&a=CreateSubdomain" class="form-inline">
        <div class="row g-2">
          <div class="col-auto"><input class="form-control" name="sub_name" placeholder="e.g. blog" required></div>
          <div class="col-auto"><button class="btn btn-success" type="submit">Create Subdomain</button></div>
        </div>
      </form>
    </div>
    {/if}

  </div>
</div>
