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
    tbody.innerHTML = '<tr><td colspan="4">无备份</td></tr>';
    return;
  }
  for (const b of r.backups) {
    const tr = document.createElement('tr');
    const nameTd = document.createElement('td'); nameTd.textContent = b.name;
    const mtd = document.createElement('td'); mtd.textContent = new Date(b.mtime * 1000).toLocaleString();
    const sizeTd = document.createElement('td'); sizeTd.textContent = b.size_human || '--';
    const actionTd = document.createElement('td');
    const btnRestore = document.createElement('button'); btnRestore.className='btn btn-sm btn-outline-primary'; btnRestore.textContent='恢复';
    btnRestore.onclick = async ()=>{ if(!confirm('确认恢复此备份？当前内容将被替换。')) return; btnRestore.disabled=true; const res=await api({action:'restore', backup:b.name, csrf: window.CSRF_TOKEN}); alert(res.ok ? '恢复成功' : '恢复失败: ' + res.msg); btnRestore.disabled=false; refreshBackups(); };
    const btnDelete = document.createElement('button'); btnDelete.className='btn btn-sm btn-outline-danger ms-2'; btnDelete.textContent='删除';
    btnDelete.onclick = async ()=>{ if(!confirm('确认永久删除此备份？')) return; btnDelete.disabled=true; const res=await api({action:'delete_backup', backup:b.name, csrf: window.CSRF_TOKEN}); alert(res.ok ? '删除成功' : '删除失败: ' + res.msg); btnDelete.disabled=false; refreshBackups(); };
    actionTd.appendChild(btnRestore); actionTd.appendChild(btnDelete);
    tr.appendChild(nameTd); tr.appendChild(mtd); tr.appendChild(sizeTd); tr.appendChild(actionTd);
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

document.getElementById('btnViewExecLog').addEventListener('click', async () => {
  try {
    const res = await fetch('api.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'get_exec_log', csrf: window.CSRF_TOKEN})
    });
    const data = await res.json();
    if (data.ok) {
      const win = window.open('', '执行日志', 'width=800,height=600');
      win.document.write('<html><head><title>执行日志</title></head><body>');
      win.document.write('<h3>后台执行日志 (update_exec.log)</h3>');
      win.document.write('<pre style="background:#f8f9fa;padding:15px;border:1px solid #ddd;">' + 
        (data.log || 'no exec log yet').replace(/</g, '&lt;').replace(/>/g, '&gt;') + 
        '</pre>');
      win.document.write('</body></html>');
      win.document.close();
    }
  } catch(e) {
    alert('Failed to fetch exec log: ' + e.message);
  }
});

document.getElementById('autoRefreshLog').addEventListener('change', (e) => {
  if (e.target.checked) {
    startLogRefresh();
  } else {
    stopLogRefresh();
  }
});

// initial load
refreshBackups();
