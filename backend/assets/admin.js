const api = async (payload) => {
  const res = await fetch('api.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  });
  return res.json();
}

async function refreshBackups() {
  const r = await api({action:'list_backups', csrf: window.CSRF_TOKEN});
  const tbody = document.querySelector('#backupsTable tbody');
  tbody.innerHTML = '';
  if (!r.ok || !r.backups || r.backups.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5">无备份</td></tr>';
    return;
  }
  for (const b of r.backups) {
    const tr = document.createElement('tr');
    const nameTd = document.createElement('td'); nameTd.textContent = b.name;
    const hashTd = document.createElement('td'); 
    if (b.hash) {
      const code = document.createElement('code');
      code.className = 'text-muted';
      code.textContent = b.hash;
      code.style.fontSize = '0.85em';
      hashTd.appendChild(code);
    } else {
      hashTd.textContent = '--';
    }
    const mtd = document.createElement('td'); mtd.textContent = new Date(b.mtime * 1000).toLocaleString();
    const sizeTd = document.createElement('td'); sizeTd.textContent = b.size_human || '--';
    const actionTd = document.createElement('td');
    const btnRestore = document.createElement('button'); btnRestore.className='btn btn-sm btn-outline-primary'; btnRestore.textContent='恢复';
    btnRestore.onclick = async ()=>{ if(!confirm('确认恢复此备份？当前内容将被替换。')) return; btnRestore.disabled=true; const res=await api({action:'restore', backup:b.name, csrf: window.CSRF_TOKEN}); alert(res.ok ? '恢复成功' : '恢复失败: ' + res.msg); btnRestore.disabled=false; refreshBackups(); };
    const btnDelete = document.createElement('button'); btnDelete.className='btn btn-sm btn-outline-danger ms-2'; btnDelete.textContent='删除';
    btnDelete.onclick = async ()=>{ if(!confirm('确认永久删除此备份？')) return; btnDelete.disabled=true; const res=await api({action:'delete_backup', backup:b.name, csrf: window.CSRF_TOKEN}); alert(res.ok ? '删除成功' : '删除失败: ' + res.msg); btnDelete.disabled=false; refreshBackups(); };
    actionTd.appendChild(btnRestore); actionTd.appendChild(btnDelete);
    tr.appendChild(nameTd); tr.appendChild(hashTd); tr.appendChild(mtd); tr.appendChild(sizeTd); tr.appendChild(actionTd);
    tbody.appendChild(tr);
  }
}

document.getElementById('btnUpdate').addEventListener('click', async ()=>{
  const s = document.getElementById('updateStatus'); 
  const btn = document.getElementById('btnUpdate');
  const btnSync = document.getElementById('btnUpdateSync');
  
  s.textContent='正在启动后台更新...';
  s.className = 'ms-2 text-primary';
  btn.disabled = true;
  if (btnSync) btnSync.disabled = true;
  
  try {
    const res = await api({action:'update', csrf: window.CSRF_TOKEN});
    
    // 检查是否是受限环境
    if (!res.ok && (res.msg === 'restricted_environment' || res.msg === 'execution_failed')) {
      s.textContent = '⚠️ 后台执行失败：' + res.error;
      s.className = 'ms-2 text-warning';
      
      // 显示受限环境提示
      const notice = document.getElementById('restrictedEnvNotice');
      if (notice) {
        notice.style.display = 'block';
        notice.innerHTML = `<strong>⚠️ 受限环境检测</strong><br>` +
          `${res.error}<br>` +
          `<strong>建议：</strong>${res.suggestion}<br>` +
          `<small>禁用的函数: ${res.disabledFunctions || 'N/A'}</small>`;
      }
      
      // 询问是否使用同步更新
      if (confirm('后台更新失败。是否立即使用同步更新？\n\n注意：同步更新会等待完成，可能需要几分钟时间。')) {
        document.getElementById('btnUpdateSync').click();
      }
    } else if (res.ok) {
      s.textContent = '✅ 更新已启动（' + (res.method || 'unknown') + '）- 请查看日志';
      s.className = 'ms-2 text-success';
      
      // 自动启用日志刷新
      document.getElementById('autoRefreshLog').checked = true;
      startLogRefresh();
    } else {
      s.textContent = '❌ 启动失败: ' + (res.msg || JSON.stringify(res));
      s.className = 'ms-2 text-danger';
    }
  } catch (e) {
    s.textContent = '❌ 请求失败: ' + e.message;
    s.className = 'ms-2 text-danger';
  }
  
  btn.disabled = false;
  if (btnSync) btnSync.disabled = false;
  refreshBackups();
});

document.getElementById('btnUpdateSync').addEventListener('click', async ()=>{
  const s = document.getElementById('updateStatus');
  const btn = document.getElementById('btnUpdateSync');
  const btnAsync = document.getElementById('btnUpdate');
  
  if (!confirm('同步更新会阻塞页面直到完成（可能需要几分钟），确认继续？')) {
    return;
  }
  
  s.textContent='正在同步执行更新，请耐心等待...';
  s.className = 'ms-2 text-warning';
  btn.disabled = true;
  btnAsync.disabled = true;
  
  // 自动启用日志刷新
  document.getElementById('autoRefreshLog').checked = true;
  startLogRefresh();
  
  try {
    const res = await api({action:'update', forceSync:true, csrf: window.CSRF_TOKEN});
    
    if (res.ok) {
      s.textContent = '✅ 同步更新完成';
      s.className = 'ms-2 text-success';
    } else {
      s.textContent = '❌ 更新失败: ' + (res.msg || JSON.stringify(res));
      s.className = 'ms-2 text-danger';
    }
  } catch (e) {
    s.textContent = '❌ 更新失败: ' + e.message;
    s.className = 'ms-2 text-danger';
  }
  
  btn.disabled = false;
  btnAsync.disabled = false;
  refreshBackups();
  refreshLog();
});

document.getElementById('enableBackupsToggle').addEventListener('change', async (e)=>{
  const v = e.target.checked ? '1' : '0';
  const res = await api({action:'set_backups', val:v, csrf: window.CSRF_TOKEN});
  alert('备份已' + (res.ok ? (res.val==='0' ? '禁用' : '启用') : '操作失败'));
});

// 日志刷新功能
let logRefreshInterval = null;

async function refreshLog() {
  try {
    const res = await fetch('api.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'get_log', csrf: window.CSRF_TOKEN})
    });
    const data = await res.json();
    if (data.ok && data.log) {
      const logContent = document.getElementById('logContent');
      const wasAtBottom = logContent.scrollHeight - logContent.scrollTop <= logContent.clientHeight + 50;
      logContent.textContent = data.log;
      // 如果之前在底部，自动滚动到底部
      if (wasAtBottom) {
        logContent.scrollTop = logContent.scrollHeight;
      }
    }
  } catch(e) {
    console.error('Failed to refresh log:', e);
  }
}

function startLogRefresh() {
  if (logRefreshInterval) return;
  logRefreshInterval = setInterval(refreshLog, 2000); // 每2秒刷新一次
}

function stopLogRefresh() {
  if (logRefreshInterval) {
    clearInterval(logRefreshInterval);
    logRefreshInterval = null;
  }
}

document.getElementById('btnRefreshLog').addEventListener('click', refreshLog);

document.getElementById('autoRefreshLog').addEventListener('change', (e) => {
  if (e.target.checked) {
    startLogRefresh();
  } else {
    stopLogRefresh();
  }
});

// Webhook Logs 功能
async function showWebhookLogsModal() {
  // 创建模态框 HTML
  const modalHtml = `
    <div class="modal fade" id="webhookLogsModal" tabindex="-1" aria-labelledby="webhookLogsModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-xl">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="webhookLogsModalLabel">📊 Webhook 调用历史</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="d-flex justify-content-between mb-3">
              <div>
                <span class="text-muted">共 <strong id="webhookLogsTotal">0</strong> 条记录</span>
              </div>
              <div>
                <button class="btn btn-sm btn-outline-primary" id="btnRefreshWebhookLogs">🔄 刷新</button>
                <button class="btn btn-sm btn-outline-danger" id="btnClearWebhookLogs">🗑️ 清空日志</button>
              </div>
            </div>
            <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
              <table class="table table-sm table-hover" id="webhookLogsTable">
                <thead class="table-light sticky-top">
                  <tr>
                    <th>时间</th>
                    <th>事件类型</th>
                    <th>来源 IP</th>
                    <th>负载大小</th>
                    <th>签名验证</th>
                    <th>状态</th>
                    <th>错误信息</th>
                  </tr>
                </thead>
                <tbody id="webhookLogsTableBody">
                  <tr><td colspan="7" class="text-center">加载中...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
          </div>
        </div>
      </div>
    </div>
  `;
  
  // 添加模态框到页面（如果不存在）
  if (!document.getElementById('webhookLogsModal')) {
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    // 绑定刷新按钮
    document.getElementById('btnRefreshWebhookLogs').addEventListener('click', loadWebhookLogs);
    
    // 绑定清空按钮
    document.getElementById('btnClearWebhookLogs').addEventListener('click', async () => {
      if (!confirm('确认清空所有 Webhook 调用记录？此操作不可恢复。')) return;
      const res = await api({action: 'clear_webhook_logs', csrf: window.CSRF_TOKEN});
      if (res.ok) {
        alert('已清空所有记录');
        loadWebhookLogs();
      } else {
        alert('清空失败：' + (res.msg || '未知错误'));
      }
    });
  }
  
  // 显示模态框
  const modal = new bootstrap.Modal(document.getElementById('webhookLogsModal'));
  modal.show();
  
  // 加载日志
  loadWebhookLogs();
}

async function loadWebhookLogs() {
  const tbody = document.getElementById('webhookLogsTableBody');
  const totalSpan = document.getElementById('webhookLogsTotal');
  
  tbody.innerHTML = '<tr><td colspan="7" class="text-center">加载中...</td></tr>';
  
  try {
    const res = await api({action: 'get_webhook_logs', limit: 100, offset: 0, csrf: window.CSRF_TOKEN});
    
    if (!res.ok) {
      tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger">加载失败：' + (res.msg || '未知错误') + '</td></tr>';
      return;
    }
    
    totalSpan.textContent = res.total || 0;
    
    if (!res.logs || res.logs.length === 0) {
      tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">暂无记录</td></tr>';
      return;
    }
    
    tbody.innerHTML = '';
    for (const log of res.logs) {
      const tr = document.createElement('tr');
      
      // 时间
      const timeTd = document.createElement('td');
      timeTd.textContent = log.created_at;
      timeTd.style.fontSize = '0.85em';
      timeTd.style.whiteSpace = 'nowrap';
      
      // 事件类型
      const eventTd = document.createElement('td');
      const eventBadge = document.createElement('span');
      eventBadge.className = 'badge bg-primary';
      eventBadge.textContent = log.event_type;
      eventTd.appendChild(eventBadge);
      
      // 来源 IP
      const ipTd = document.createElement('td');
      const ipCode = document.createElement('code');
      ipCode.textContent = log.remote_addr || 'N/A';
      ipCode.style.fontSize = '0.85em';
      ipTd.appendChild(ipCode);
      
      // 负载大小
      const sizeTd = document.createElement('td');
      sizeTd.textContent = formatBytes(log.payload_size || 0);
      sizeTd.style.fontSize = '0.85em';
      
      // 签名验证
      const sigTd = document.createElement('td');
      const sigBadge = document.createElement('span');
      if (log.signature_valid === 1 || log.signature_valid === '1') {
        sigBadge.className = 'badge bg-success';
        sigBadge.textContent = '✓ 已验证';
      } else {
        sigBadge.className = 'badge bg-warning';
        sigBadge.textContent = '未验证';
      }
      sigTd.appendChild(sigBadge);
      
      // 状态
      const statusTd = document.createElement('td');
      const statusBadge = document.createElement('span');
      if (log.status === 'success') {
        statusBadge.className = 'badge bg-success';
        statusBadge.textContent = '成功';
      } else {
        statusBadge.className = 'badge bg-danger';
        statusBadge.textContent = '失败';
      }
      statusTd.appendChild(statusBadge);
      
      // 错误信息
      const errorTd = document.createElement('td');
      if (log.error_message) {
        const errorCode = document.createElement('code');
        errorCode.className = 'text-danger';
        errorCode.textContent = log.error_message;
        errorCode.style.fontSize = '0.85em';
        errorTd.appendChild(errorCode);
      } else {
        errorTd.textContent = '-';
      }
      
      tr.appendChild(timeTd);
      tr.appendChild(eventTd);
      tr.appendChild(ipTd);
      tr.appendChild(sizeTd);
      tr.appendChild(sigTd);
      tr.appendChild(statusTd);
      tr.appendChild(errorTd);
      
      tbody.appendChild(tr);
    }
  } catch (e) {
    tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger">加载失败：' + e.message + '</td></tr>';
  }
}

function formatBytes(bytes) {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// 绑定查看 Webhook 日志按钮
const btnViewWebhookLogs = document.getElementById('btnViewWebhookLogs');
if (btnViewWebhookLogs) {
  btnViewWebhookLogs.addEventListener('click', showWebhookLogsModal);
}

// initial load
refreshBackups();
