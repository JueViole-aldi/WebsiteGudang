// assets/js/donasi_realtime_polling_debug.js
(function(){
  const tbody = document.getElementById('donasiTbody');
  if (!tbody) {
    console.warn('[donasi_poll] tbody#donasiTbody tidak ditemukan. Tambahkan id pada tbody daftar donasi.');
    return;
  }

  const statusClass = {
    'menunggu_diproses': 'bg-yellow-100 text-yellow-700',
    'siap_dikirim':      'bg-blue-100 text-blue-700',
    'terkirim':          'bg-green-100 text-green-700'
  };

  function escapeHtml(s){
    return (s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
  }

  function rowHtml(r){
    let aksi = '<span class="text-xs text-gray-500">-</span>';
    if (r.status_hadiah === 'menunggu_diproses') {
      aksi = `<button type="button" onclick="openProcessModal(${r.id})"
                class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 text-sm rounded-md">
                Proses Hadiah</button>`;
    } else if (r.status_hadiah === 'siap_dikirim') {
      aksi = `<button type="button" onclick="openValidateModal(${r.id})"
                class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1 text-sm rounded-md">
                Kirim Hadiah</button>`;
    }

    const hadiahInfo = (r.status_hadiah === 'terkirim' && r.nama_hadiah_diberikan)
      ? `<p class="text-xs text-gray-500">Hadiah: ${escapeHtml(r.nama_hadiah_diberikan)}</p>` : '';

    return `
      <tr class="border-b hover:bg-slate-50">
        <td class="py-3 px-4">
          <input type="checkbox" class="row-check h-4 w-4 rounded border-gray-300"
                 form="bulkDeleteForm" name="selected_ids[]" value="${r.id}">
        </td>
        <td class="py-3 px-4 text-sm text-gray-600">${r.tanggal_fmt}</td>
        <td class="py-3 px-4 font-medium">${escapeHtml(r.nama_donatur)}</td>
        <td class="py-3 px-4">Rp ${r.jumlah_fmt}</td>
        <td class="py-3 px-4">
          <span class="px-3 py-1 text-xs rounded-md ${statusClass[r.status_hadiah] || 'bg-slate-100 text-slate-700'}">
            ${r.status_hadiah.replace(/_/g,' ').replace(/\b\w/g, s=>s.toUpperCase())}
          </span>
          ${hadiahInfo}
        </td>
        <td class="py-3 px-4 text-center">${aksi}</td>
      </tr>
    `;
  }

  async function checkUpdates(){
    try{
      const url = (window.DONASI_UPDATES_URL || 'ajax/donasi_updates_v2.php') + '?after=' + (window.DONASI_LAST_ID || 0);
      console.debug('[donasi_poll] GET', url);
      const res = await fetch(url, { credentials: 'same-origin' });
      const txt = await res.text();
      let data;
      try { data = JSON.parse(txt); }
      catch(e){ console.error('[donasi_poll] Respon bukan JSON:', txt); return; }

      if (data && Array.isArray(data.rows) && data.rows.length){
        for (const r of data.rows.reverse()){
          tbody.insertAdjacentHTML('afterbegin', rowHtml(r));
        }
        window.DONASI_LAST_ID = data.max_id || window.DONASI_LAST_ID;
        console.debug('[donasi_poll] inserted', data.rows.length, 'rows. last_id=', window.DONASI_LAST_ID);
      }
    } catch(e){
      console.error('[donasi_poll] error', e);
    }
  }

  setInterval(checkUpdates, window.DONASI_POLL_INTERVAL || 5000);
})();
